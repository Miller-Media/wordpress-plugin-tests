<?php
/**
 * Base test case for plugin unit tests.
 *
 * Provides mock reset, factory helpers, and common assertions
 * for testing WordPress plugins without external API calls.
 */
class PluginTestCase extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset API mocks before each test
		CloudflareAPIMock::reset();
		MandrillAPIMock::reset();

		// Activate the pre_http_request filter mocks
		CloudflareAPIMock::activate();
		MandrillAPIMock::activate();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Deactivate mocks
		CloudflareAPIMock::deactivate();
		MandrillAPIMock::deactivate();

		parent::tearDown();
	}

	/**
	 * Create a test user with specified role and return the WP_User object.
	 *
	 * @param string $role    User role (default 'subscriber').
	 * @param array  $args    Additional user args.
	 * @return WP_User
	 */
	protected function create_test_user( $role = 'subscriber', $args = array() ) {
		$defaults = array(
			'user_login' => 'testuser_' . wp_generate_password( 6, false ),
			'user_pass'  => 'password123',
			'user_email' => 'test_' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		);

		$user_id = $this->factory->user->create( array_merge( $defaults, $args ) );
		return get_user_by( 'ID', $user_id );
	}

	/**
	 * Create a test page with optional parent and password.
	 *
	 * @param array $args Post args.
	 * @return WP_Post
	 */
	protected function create_test_page( $args = array() ) {
		$defaults = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Test Page ' . wp_generate_password( 6, false ),
		);

		$post_id = $this->factory->post->create( array_merge( $defaults, $args ) );
		return get_post( $post_id );
	}

	/**
	 * Set a plugin option.
	 *
	 * @param string $option_name  Option name.
	 * @param mixed  $value        Option value.
	 */
	protected function set_option( $option_name, $value ) {
		update_option( $option_name, $value );
	}

	/**
	 * Set a site option (for multisite-aware plugins).
	 *
	 * @param string $option_name  Option name.
	 * @param mixed  $value        Option value.
	 */
	protected function set_site_option( $option_name, $value ) {
		update_site_option( $option_name, $value );
	}

	/**
	 * Get the real version of a plugin, as declared in its own main file
	 * header — read live from disk rather than hardcoded, so tests never
	 * drift out of sync with the plugin being tested.
	 *
	 * @param string $slug Manifest slug (see plugins.json).
	 * @return string|null
	 */
	protected function plugin_version( $slug ) {
		return mm_tests_plugin_header_version( mm_tests_load_manifest(), WP_PLUGIN_DIR, $slug );
	}

	/**
	 * Assert that a WordPress hook has a specific callback registered.
	 *
	 * @param string $hook     Hook name.
	 * @param string $callback Callback function/method name.
	 */
	protected function assertHookHasCallback( $hook, $callback ) {
		global $wp_filter;

		$this->assertArrayHasKey( $hook, $wp_filter, "Hook '{$hook}' is not registered." );

		$found = false;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$func = $cb['function'];
				if ( is_string( $func ) && $func === $callback ) {
					$found = true;
					break 2;
				}
				if ( is_array( $func ) && $func[1] === $callback ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $found, "Callback '{$callback}' is not registered on hook '{$hook}'." );
	}
}
