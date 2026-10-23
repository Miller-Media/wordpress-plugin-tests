<?php
/**
 * Bootstrap file for the WordPress plugin test suite.
 *
 * Downloads and configures the WordPress test library, loads every plugin
 * declared in plugins.json, and registers mock API interceptors.
 */

// Load .env file if it exists (for integration tests)
$env_file = __DIR__ . '/.env';
if ( file_exists( $env_file ) ) {
	$lines = file( $env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( strpos( $line, '=' ) !== false ) {
			list( $key, $value ) = explode( '=', $line, 2 );
			$key   = trim( $key );
			$value = trim( $value, " \t\n\r\0\x0B\"'" );
			putenv( "$key=$value" );
			$_ENV[ $key ] = $value;
		}
	}
}

// Manifest-driven helpers (plugins.json access, version-header parsing).
require_once __DIR__ . '/_helpers/Manifest.php';

$manifest = mm_tests_load_manifest();

// PHPUnit Polyfills (required by WP test suite). Honor an explicit env var
// first, then COMPOSER_HOME (falling back to the conventional root-user path).
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$polyfills_candidates = array();

	$env_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	if ( $env_polyfills_path ) {
		$polyfills_candidates[] = $env_polyfills_path;
	}

	$composer_home          = getenv( 'COMPOSER_HOME' ) ?: '/root/.composer';
	$polyfills_candidates[] = $composer_home . '/vendor/yoast/phpunit-polyfills';

	foreach ( $polyfills_candidates as $candidate ) {
		if ( $candidate && file_exists( $candidate . '/phpunitpolyfills-autoload.php' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $candidate );
			break;
		}
	}
}

// Configurable paths
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
$_core_dir  = getenv( 'WP_CORE_DIR' ) ?: '/var/www/html';

/**
 * Resolve which develop.svn ref the test library should come from: an
 * explicit WP_VERSION wins, "trunk" means trunk, and otherwise (unset or
 * "latest") we read the *actually installed* core version straight out of
 * wp-includes/version.php — never assume "latest" means trunk, that's what
 * caused the test suite to silently pin to trunk against a pinned core.
 *
 * @return array{0: string, 1: string} [ref for develop.svn, label for the marker file]
 */
function mm_tests_resolve_test_lib_ref( $core_dir ) {
	$wp_version = getenv( 'WP_VERSION' ) ?: '';

	if ( 'trunk' === $wp_version ) {
		return array( 'trunk', 'trunk' );
	}

	if ( '' !== $wp_version && 'latest' !== $wp_version ) {
		return array( "tags/{$wp_version}", $wp_version );
	}

	// Unset or "latest" — sniff the exact installed core version.
	$version_file = $core_dir . '/wp-includes/version.php';
	if ( file_exists( $version_file ) ) {
		$contents = file_get_contents( $version_file );
		if ( preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $m ) ) {
			$installed = $m[1];
			// Trunk/dev snapshots look like "6.9-alpha-60000".
			if ( false !== strpos( $installed, '-alpha' ) ) {
				return array( 'trunk', 'trunk' );
			}
			// Some develop.svn tag exports append "-src" to the source
			// package's own version string (e.g. "6.4.5-src") — strip it so
			// it still resolves to the real "tags/6.4.5" ref.
			$installed = preg_replace( '/-src$/', '', $installed );
			return array( "tags/{$installed}", $installed );
		}
	}

	echo "WARNING: could not determine the installed core version from {$version_file}; falling back to trunk for the test suite. Set WP_VERSION to pin it explicitly.\n";
	return array( 'trunk', 'trunk' );
}

list( $_test_lib_svn_ref, $_test_lib_label ) = mm_tests_resolve_test_lib_ref( $_core_dir );

// Download the WP test suite if it doesn't exist
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Downloading WordPress test suite ({$_test_lib_svn_ref})...\n";

	// Create the directory
	@mkdir( $_tests_dir, 0777, true );

	$svn_url = "https://develop.svn.wordpress.org/{$_test_lib_svn_ref}/tests/phpunit";

	exec( "svn co --quiet {$svn_url}/includes/ {$_tests_dir}/includes/" );
	exec( "svn co --quiet {$svn_url}/data/ {$_tests_dir}/data/" );

	file_put_contents( $_tests_dir . '/.wp-tests-ref', $_test_lib_label );
}

// ── Guard against a stale/mismatched test library ──────────────
//
// bin/setup-native.sh (and this file's own downloader above) stamp
// .wp-tests-ref with the version the test library was fetched for. If a
// test library is already present (e.g. reused from a previous run) and
// its stamp disagrees with what we'd resolve *now*, that's exactly the
// "6.9.1 core against the trunk test suite" class of bug — fail loudly
// rather than silently running mismatched code. If there's no stamp at
// all (e.g. a hand-provisioned environment), we can't be sure, so warn
// instead of failing.
$_tests_ref_marker = $_tests_dir . '/.wp-tests-ref';
if ( file_exists( $_tests_ref_marker ) ) {
	$_stamped_label = trim( file_get_contents( $_tests_ref_marker ) );
	if ( '' !== $_stamped_label && $_stamped_label !== $_test_lib_label ) {
		fwrite( STDERR, "FATAL: WP test library at {$_tests_dir} was fetched for version '{$_stamped_label}' but the running core resolves to '{$_test_lib_label}'. Re-run bin/setup-native.sh (or clear WP_TESTS_DIR) so core and the test suite match.\n" );
		exit( 1 );
	}
} else {
	echo "WARNING: {$_tests_dir}/.wp-tests-ref not found — cannot confirm the test library matches the installed core version ({$_test_lib_label}).\n";
}

// Create wp-tests-config.php if it doesn't exist
$_config_file = $_tests_dir . '/wp-tests-config.php';
if ( ! file_exists( $_config_file ) ) {
	$db_host = getenv( 'WP_TESTS_DB_HOST' ) ?: 'db-test';
	$db_name = getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_test';
	$db_user = getenv( 'WP_TESTS_DB_USER' ) ?: 'wordpress';
	$db_pass = getenv( 'WP_TESTS_DB_PASS' ) ?: 'wordpress';

	$config = <<<PHP
<?php
define( 'ABSPATH', '{$_core_dir}/' );
define( 'DB_NAME', '{$db_name}' );
define( 'DB_USER', '{$db_user}' );
define( 'DB_PASSWORD', '{$db_pass}' );
define( 'DB_HOST', '{$db_host}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP;

	file_put_contents( $_config_file, $config );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "ERROR: Could not find WordPress test suite at {$_tests_dir}/includes/functions.php\n";
	echo "Run `bin/setup-native.sh` (native) or `make setup-tests` (Docker) first.\n";
	exit( 1 );
}

// Load the WP test suite functions
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load plugins before WordPress finishes loading.
 *
 * Which files get required (the whole main plugin file vs. just its class
 * files) mirrors each plugin's own side effects: plugins whose main file
 * unconditionally instantiates objects / registers hooks that would be
 * noisy or redundant in tests only have their class files required, with
 * their version (and any other) constants predefined from the manifest.
 * Plugins whose main file is otherwise side-effect-light are required in
 * full, so their constants are simply defined by the plugin itself.
 */
tests_add_filter( 'muplugins_loaded', function () use ( $manifest ) {
	$plugins_dir = WP_PLUGIN_DIR;

	// When MM_TESTS_PLUGIN is set (always true for the Makefile's per-plugin
	// test loop and the CI matrix — see bin/manifest.php's build_matrix()),
	// this process loads only that one plugin's code, never the other eight.
	// Process isolation, not this flag alone, is what actually protects
	// against cross-plugin leakage (e.g. a WooCommerce class stub some
	// plugin's tests define at file scope breaking another plugin's
	// class_exists('WooCommerce') checks) — but every call site below reads
	// paths through $dir(), so gating it here is enough to realize that
	// isolation whenever a run has opted in via the env var. Leaving
	// MM_TESTS_PLUGIN unset preserves the old "load every plugin" behavior
	// (e.g. Docker's make test-unit/test-integration, which still run the
	// whole suite in one process).
	$only_plugin = getenv( 'MM_TESTS_PLUGIN' ) ?: null;

	$dir = function ( $slug ) use ( $manifest, $plugins_dir, $only_plugin ) {
		if ( null !== $only_plugin && $slug !== $only_plugin ) {
			return null;
		}
		$plugin = mm_tests_find_plugin( $manifest, $slug );
		return $plugin ? $plugins_dir . '/' . $plugin['install_dir'] : null;
	};

	$version = function ( $slug ) use ( $manifest, $plugins_dir ) {
		return mm_tests_plugin_header_version( $manifest, $plugins_dir, $slug );
	};

	// ── IP Blacklist for Cloudflare ────────────────────────────
	$cfip_path = $dir( 'cloudflare-ip-blacklist' );
	if ( $cfip_path && file_exists( $cfip_path . '/classes/Helpers.php' ) ) {
		if ( ! defined( 'CFIP_PLUGIN_VERSION' ) ) {
			define( 'CFIP_PLUGIN_VERSION', $version( 'cloudflare-ip-blacklist' ) );
		}
		if ( ! defined( 'CFIP_MAIN_CLOUDFLARE_PLUGIN_DIR' ) ) {
			define( 'CFIP_MAIN_CLOUDFLARE_PLUGIN_DIR', $plugins_dir . '/cloudflare' );
		}

		require_once $cfip_path . '/classes/Helpers.php';
		require_once $cfip_path . '/classes/SiteSettings.php';
		require_once $cfip_path . '/classes/CloudflareAPIController.php';
		require_once $cfip_path . '/classes/Plugin.php';
	}

	// ── Edit Usernames ──────────────────────────────────────────
	$eun_path = $dir( 'edit-usernames' );
	if ( $eun_path && file_exists( $eun_path . '/inc/edit-usernames.php' ) ) {
		if ( ! defined( 'EUN_SELFEDIT' ) ) {
			define( 'EUN_SELFEDIT', false );
		}
		if ( ! defined( 'EUN_PLUGIN_PATH' ) ) {
			define( 'EUN_PLUGIN_PATH', $eun_path . '/' );
		}
		if ( ! defined( 'EUN_PLUGIN_NAME' ) ) {
			define( 'EUN_PLUGIN_NAME', 'Edit Usernames' );
		}
		if ( ! defined( 'EUN_SETTINGS_PREFIX' ) ) {
			define( 'EUN_SETTINGS_PREFIX', 'eun_setting_' );
		}

		require_once $eun_path . '/inc/settings.php';
		require_once $eun_path . '/inc/helpers.php';
		require_once $eun_path . '/inc/edit-usernames.php';
	}

	// ── Expire User Passwords (main file is self-contained; load in full) ─
	$expass_path = $dir( 'expire-passwords' );
	if ( $expass_path && file_exists( $expass_path . '/expire-user-passwords.php' ) ) {
		require_once $expass_path . '/expire-user-passwords.php';
	}

	// ── Protect the Children ────────────────────────────────────
	$ptc_path = $dir( 'protect-the-children' );
	if ( $ptc_path && file_exists( $ptc_path . '/_inc/helpers.php' ) ) {
		if ( ! defined( 'PTC_PLUGIN_PATH' ) ) {
			define( 'PTC_PLUGIN_PATH', $ptc_path . '/' );
		}
		if ( ! defined( 'PTC_PLUGIN_URL' ) ) {
			define( 'PTC_PLUGIN_URL', 'http://example.org/wp-content/plugins/protect-the-children/' );
		}
		if ( ! defined( 'PROTECT_THE_CHILDREN_PLUGIN_VERSION' ) ) {
			define( 'PROTECT_THE_CHILDREN_PLUGIN_VERSION', $version( 'protect-the-children' ) );
		}

		require_once $ptc_path . '/_inc/helpers.php';
		require_once $ptc_path . '/_inc/admin.php';
	}

	// ── Send Emails with Mandrill (load class files but don't call
	//    on_load so wp_mail() is never redefined) ─────────────────
	$mandrill_path = $dir( 'send-emails-with-mandrill' );
	if ( $mandrill_path && file_exists( $mandrill_path . '/lib/mandrill.class.php' ) ) {
		if ( ! defined( 'SEWM_VERSION' ) ) {
			define( 'SEWM_VERSION', $version( 'send-emails-with-mandrill' ) );
		}
		if ( ! defined( 'SEWM_URL' ) ) {
			define( 'SEWM_URL', 'http://example.org/wp-content/plugins/send-emails-with-mandrill/' );
		}
		if ( ! defined( 'SEWM_PATH' ) ) {
			define( 'SEWM_PATH', $mandrill_path . '/' );
		}
		if ( ! defined( 'SEWM_BASE' ) ) {
			define( 'SEWM_BASE', 'send-emails-with-mandrill/wpmandrill.php' );
		}

		require_once $mandrill_path . '/lib/mandrill.class.php';
		require_once $mandrill_path . '/lib/wpMandrill.class.php';
	}

	// ── WooCommerce (loaded if a checked-out plugin needs it, or the
	//    MM_TESTS_WITH_WC=1 optional-WC-leg env var is set for a plugin
	//    that declares a manifest "wc_optional_leg", e.g. send-emails-with-mandrill) ─
	$needs_woocommerce = ( '1' === getenv( 'MM_TESTS_WITH_WC' ) );
	foreach ( $manifest['plugins'] as $plugin ) {
		if ( in_array( 'woocommerce', $plugin['dependencies'], true ) && $dir( $plugin['slug'] ) && is_dir( $dir( $plugin['slug'] ) ) ) {
			$needs_woocommerce = true;
			break;
		}
	}
	if ( $needs_woocommerce ) {
		$wc_path = $plugins_dir . '/woocommerce/woocommerce.php';
		if ( file_exists( $wc_path ) ) {
			require_once $wc_path;
		}
	}

	// ── Wooflare ─────────────────────────────────────────────────
	$woocf_path = $dir( 'wooflare' );
	if ( $woocf_path && file_exists( $woocf_path . '/classes/Helpers.php' ) ) {
		if ( ! defined( 'WOOCF_PLUGIN_VERSION' ) ) {
			define( 'WOOCF_PLUGIN_VERSION', $version( 'wooflare' ) );
		}
		if ( ! defined( 'WOOCF_MAIN_CLOUDFLARE_PLUGIN_DIR' ) ) {
			define( 'WOOCF_MAIN_CLOUDFLARE_PLUGIN_DIR', $plugins_dir . '/cloudflare' );
		}

		require_once $woocf_path . '/classes/Helpers.php';
		require_once $woocf_path . '/classes/SiteSettings.php';
		require_once $woocf_path . '/classes/CloudflareAPIController.php';
		require_once $woocf_path . '/classes/Plugin.php';
	}

	// ── One-Off Emails for WooCommerce ──────────────────────────
	$wooe_path = $dir( 'one-off-emails-for-woocommerce' );
	if ( $wooe_path && file_exists( $wooe_path . '/classes/Plugin.php' ) ) {
		if ( ! defined( 'WOOE_PLUGIN_VERSION' ) ) {
			define( 'WOOE_PLUGIN_VERSION', $version( 'one-off-emails-for-woocommerce' ) );
		}

		require_once $wooe_path . '/classes/Plugin.php';
	}

	// ── Miller Media Server File Import ─────────────────────────
	$mmsfi_path = $dir( 'import-from-server' );
	if ( $mmsfi_path && file_exists( $mmsfi_path . '/inc/class-mmsfi-file-browser.php' ) ) {
		if ( ! defined( 'MMSFI_PLUGIN_VERSION' ) ) {
			define( 'MMSFI_PLUGIN_VERSION', $version( 'import-from-server' ) );
		}
		if ( ! defined( 'MMSFI_PLUGIN_PATH' ) ) {
			define( 'MMSFI_PLUGIN_PATH', $mmsfi_path . '/' );
		}
		if ( ! defined( 'MMSFI_PLUGIN_URL' ) ) {
			define( 'MMSFI_PLUGIN_URL', 'http://example.org/wp-content/plugins/miller-media-server-file-import/' );
		}

		require_once $mmsfi_path . '/inc/class-mmsfi-file-browser.php';
		require_once $mmsfi_path . '/inc/class-mmsfi-importer.php';
		require_once $mmsfi_path . '/inc/class-mmsfi-settings.php';
		require_once $mmsfi_path . '/inc/class-mmsfi-plugin.php';
	}

	// ── Miller Media CSV Importer (needs WP_LOAD_IMPORTERS to load
	//    at all — its own main file is otherwise side-effect-light,
	//    so it's loaded in full for complete fidelity) ─────────────
	$mmcsv_path = $dir( 'miller-media-csv-importer' );
	if ( $mmcsv_path && file_exists( $mmcsv_path . '/miller-media-csv-importer.php' ) ) {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		require_once $mmcsv_path . '/miller-media-csv-importer.php';
	}
} );

// Load the WP test suite
require $_tests_dir . '/includes/bootstrap.php';

// Load test helpers
require_once __DIR__ . '/_helpers/PluginTestCase.php';
require_once __DIR__ . '/_helpers/IntegrationTestCase.php';

// Load API mocks
require_once __DIR__ . '/_mocks/CloudflareAPI.php';
require_once __DIR__ . '/_mocks/MandrillAPI.php';
