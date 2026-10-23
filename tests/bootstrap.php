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

// Download the WP test suite if it doesn't exist
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$wp_version = getenv( 'WP_VERSION' ) ?: 'latest';

	echo "Downloading WordPress test suite...\n";

	// Create the directory
	@mkdir( $_tests_dir, 0777, true );

	// Download using svn
	$svn_url = ( 'latest' === $wp_version )
		? 'https://develop.svn.wordpress.org/trunk/tests/phpunit'
		: "https://develop.svn.wordpress.org/tags/{$wp_version}/tests/phpunit";

	exec( "svn co --quiet {$svn_url}/includes/ {$_tests_dir}/includes/" );
	exec( "svn co --quiet {$svn_url}/data/ {$_tests_dir}/data/" );
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

	$dir = function ( $slug ) use ( $manifest, $plugins_dir ) {
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

	// ── WooCommerce (only loaded if a checked-out plugin needs it) ─
	$needs_woocommerce = false;
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
