<?php
/**
 * Tests for CFIP_SiteSettings credential state detection.
 *
 * Verifies that areCredentialsSet() returns the correct state
 * for each authentication scenario (API Token, Global API Key,
 * CF plugin, not connected).
 */
class CFIP_CredentialStateTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'cfip_settings' );
		update_option( 'active_plugins', array() );
	}

	public function test_no_credentials_returns_false() {
		$settings = new CFIP_SiteSettings();

		$this->assertFalse( $settings->areCredentialsSet() );
	}

	public function test_api_token_credentials_return_manual_source() {
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'api_token',
				'cf_token'     => 'abc123def456',
			)
		);

		$settings    = new CFIP_SiteSettings();
		$credentials = $settings->areCredentialsSet();

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'manual', $credentials['source'] );
		$this->assertEquals( 'api_token', $credentials['auth_type'] );
		$this->assertEquals( 'abc123def456', $credentials['cf_token'] );
	}

	public function test_global_key_credentials_return_manual_source() {
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'global_key',
				'cf_key'       => 'abc123def456',
				'cf_email'     => 'user@example.com',
			)
		);

		$settings    = new CFIP_SiteSettings();
		$credentials = $settings->areCredentialsSet();

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'manual', $credentials['source'] );
		$this->assertEquals( 'user@example.com', $credentials['cf_email'] );
	}

	public function test_legacy_global_key_without_auth_type_returns_manual() {
		// Simulates a user who saved credentials before cf_auth_type existed.
		update_option(
			'cfip_settings',
			array(
				'cf_key'   => 'abc123def456',
				'cf_email' => 'legacy@example.com',
			)
		);

		$settings    = new CFIP_SiteSettings();
		$credentials = $settings->areCredentialsSet();

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'manual', $credentials['source'] );
		$this->assertEquals( 'legacy@example.com', $credentials['cf_email'] );
	}

	public function test_empty_token_returns_false() {
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'api_token',
				'cf_token'     => '',
			)
		);

		$settings = new CFIP_SiteSettings();

		$this->assertFalse( $settings->areCredentialsSet() );
	}

	public function test_partial_global_key_returns_false() {
		// Email set but no key.
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'global_key',
				'cf_email'     => 'user@example.com',
				'cf_key'       => '',
			)
		);

		$settings = new CFIP_SiteSettings();

		$this->assertFalse( $settings->areCredentialsSet() );
	}

	public function test_cf_plugin_credentials_return_cf_plugin_source() {
		// Simulate the official Cloudflare plugin being active with credentials.
		update_option( 'active_plugins', array( 'cloudflare/cloudflare.php' ) );
		update_option( 'cloudflare_api_key', 'cf_plugin_key_123' );
		update_option( 'cloudflare_api_email', 'cfplugin@example.com' );

		$settings    = new CFIP_SiteSettings();
		$credentials = $settings->areCredentialsSet();

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'cf_plugin', $credentials['source'] );
		$this->assertEquals( 'cfplugin@example.com', $credentials['cf_email'] );
	}

	public function test_cf_plugin_active_without_credentials_falls_back_to_manual() {
		// CF plugin active but no credentials saved in it — fall back to manual.
		update_option( 'active_plugins', array( 'cloudflare/cloudflare.php' ) );
		delete_option( 'cloudflare_api_key' );
		delete_option( 'cloudflare_api_email' );

		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'api_token',
				'cf_token'     => 'my_manual_token',
			)
		);

		$settings    = new CFIP_SiteSettings();
		$credentials = $settings->areCredentialsSet();

		$this->assertIsArray( $credentials );
		$this->assertEquals( 'manual', $credentials['source'] );
		$this->assertEquals( 'api_token', $credentials['auth_type'] );
	}

	public function test_disconnect_clears_credentials() {
		// Start with valid credentials.
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'api_token',
				'cf_token'     => 'abc123def456',
			)
		);

		$settings = new CFIP_SiteSettings();
		$this->assertIsArray( $settings->areCredentialsSet() );

		// Simulate disconnect: clear credential fields.
		update_option(
			'cfip_settings',
			array(
				'cf_auth_type' => 'api_token',
				'cf_token'     => '',
				'cf_email'     => '',
				'cf_key'       => '',
			)
		);

		$settings = new CFIP_SiteSettings();
		$this->assertFalse( $settings->areCredentialsSet() );
	}
}
