<?php
/**
 * Bootstrap file for the WordPress plugin test suite.
 *
 * Downloads and configures the WordPress test library, loads all plugins,
 * and registers mock API interceptors.
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

// PHPUnit Polyfills (required by WP test suite)
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$polyfills_path = '/root/.composer/vendor/yoast/phpunit-polyfills';
	if ( file_exists( $polyfills_path . '/phpunitpolyfills-autoload.php' ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
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
	echo "Run `make setup-tests` inside the Docker container first.\n";
	exit( 1 );
}

// Load the WP test suite functions
require_once $_tests_dir . '/includes/functions.php';

/**
 * Load plugins before WordPress finishes loading.
 */
tests_add_filter( 'muplugins_loaded', function () {
	$plugins_dir = WP_PLUGIN_DIR;

	// Define constants that plugins expect
	if ( ! defined( 'CFIP_PLUGIN_VERSION' ) ) {
		define( 'CFIP_PLUGIN_VERSION', '1.0.1' );
	}
	if ( ! defined( 'CFIP_MAIN_CLOUDFLARE_PLUGIN_DIR' ) ) {
		define( 'CFIP_MAIN_CLOUDFLARE_PLUGIN_DIR', $plugins_dir . '/cloudflare' );
	}
	if ( ! defined( 'WOOCF_PLUGIN_VERSION' ) ) {
		define( 'WOOCF_PLUGIN_VERSION', '1.0.0' );
	}
	if ( ! defined( 'WOOCF_MAIN_CLOUDFLARE_PLUGIN_DIR' ) ) {
		define( 'WOOCF_MAIN_CLOUDFLARE_PLUGIN_DIR', $plugins_dir . '/cloudflare' );
	}
	if ( ! defined( 'EUN_SELFEDIT' ) ) {
		define( 'EUN_SELFEDIT', false );
	}
	if ( ! defined( 'EUN_PLUGIN_PATH' ) ) {
		define( 'EUN_PLUGIN_PATH', $plugins_dir . '/edit-usernames/' );
	}
	if ( ! defined( 'EUN_PLUGIN_NAME' ) ) {
		define( 'EUN_PLUGIN_NAME', 'Edit Usernames' );
	}
	if ( ! defined( 'EUN_SETTINGS_PREFIX' ) ) {
		define( 'EUN_SETTINGS_PREFIX', 'eun_setting_' );
	}
	if ( ! defined( 'PTC_PLUGIN_PATH' ) ) {
		define( 'PTC_PLUGIN_PATH', $plugins_dir . '/protect-the-children/' );
	}
	if ( ! defined( 'PTC_PLUGIN_URL' ) ) {
		define( 'PTC_PLUGIN_URL', 'http://example.org/wp-content/plugins/protect-the-children/' );
	}
	if ( ! defined( 'PROTECT_THE_CHILDREN_PLUGIN_VERSION' ) ) {
		define( 'PROTECT_THE_CHILDREN_PLUGIN_VERSION', '1.4.0' );
	}
	if ( ! defined( 'SEWM_VERSION' ) ) {
		define( 'SEWM_VERSION', '1.4.3' );
	}
	if ( ! defined( 'SEWM_URL' ) ) {
		define( 'SEWM_URL', 'http://example.org/wp-content/plugins/send-emails-with-mandrill/' );
	}
	if ( ! defined( 'SEWM_PATH' ) ) {
		define( 'SEWM_PATH', $plugins_dir . '/send-emails-with-mandrill/' );
	}
	if ( ! defined( 'SEWM_BASE' ) ) {
		define( 'SEWM_BASE', 'send-emails-with-mandrill/wpmandrill.php' );
	}

	// Load Cloudflare IP Blacklist
	$cfip_path = $plugins_dir . '/ip-blacklist-for-cloudflare';
	if ( file_exists( $cfip_path . '/classes/Helpers.php' ) ) {
		require_once $cfip_path . '/classes/Helpers.php';
		require_once $cfip_path . '/classes/SiteSettings.php';
		require_once $cfip_path . '/classes/CloudflareAPIController.php';
		require_once $cfip_path . '/classes/Plugin.php';
	}

	// Load Edit Usernames
	$eun_path = $plugins_dir . '/edit-usernames';
	if ( file_exists( $eun_path . '/inc/edit-usernames.php' ) ) {
		require_once $eun_path . '/inc/settings.php';
		require_once $eun_path . '/inc/helpers.php';
		require_once $eun_path . '/inc/edit-usernames.php';
	}

	// Load Expire Passwords
	$expass_path = $plugins_dir . '/expire-user-passwords';
	if ( file_exists( $expass_path . '/expire-user-passwords.php' ) ) {
		require_once $expass_path . '/expire-user-passwords.php';
	}

	// Load Protect the Children
	$ptc_path = $plugins_dir . '/protect-the-children';
	if ( file_exists( $ptc_path . '/_inc/helpers.php' ) ) {
		require_once $ptc_path . '/_inc/helpers.php';
		require_once $ptc_path . '/_inc/admin.php';
	}

	// Load Send Emails with Mandrill (load class files but don't call on_load to avoid redefining wp_mail)
	$mandrill_path = $plugins_dir . '/send-emails-with-mandrill';
	if ( file_exists( $mandrill_path . '/lib/mandrill.class.php' ) ) {
		require_once $mandrill_path . '/lib/mandrill.class.php';
		require_once $mandrill_path . '/lib/wpMandrill.class.php';
	}

	// Load WooCommerce if available (required for Wooflare)
	$wc_path = $plugins_dir . '/woocommerce/woocommerce.php';
	if ( file_exists( $wc_path ) ) {
		require_once $wc_path;
	}

	// Load Wooflare
	$woocf_path = $plugins_dir . '/wooflare';
	if ( file_exists( $woocf_path . '/classes/Helpers.php' ) ) {
		require_once $woocf_path . '/classes/Helpers.php';
		require_once $woocf_path . '/classes/SiteSettings.php';
		require_once $woocf_path . '/classes/CloudflareAPIController.php';
		require_once $woocf_path . '/classes/Plugin.php';
	}
});

// Load the WP test suite
require $_tests_dir . '/includes/bootstrap.php';

// Load test helpers
require_once __DIR__ . '/_helpers/PluginTestCase.php';
require_once __DIR__ . '/_helpers/IntegrationTestCase.php';

// Load API mocks
require_once __DIR__ . '/_mocks/CloudflareAPI.php';
require_once __DIR__ . '/_mocks/MandrillAPI.php';
