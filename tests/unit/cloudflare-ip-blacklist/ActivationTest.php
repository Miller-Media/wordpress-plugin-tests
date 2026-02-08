<?php
/**
 * Tests for Cloudflare IP Blacklist plugin activation and initialization.
 */
class CFIP_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'CFIP_Blacklist' ), 'CFIP_Blacklist class should exist.' );
		$this->assertTrue( class_exists( 'CFIP_CloudflareAPIController' ), 'CFIP_CloudflareAPIController class should exist.' );
		$this->assertTrue( class_exists( 'CFIP_SiteSettings' ), 'CFIP_SiteSettings class should exist.' );
		$this->assertTrue( class_exists( 'CFIP_Helpers' ), 'CFIP_Helpers class should exist.' );
	}

	public function test_helpers_parse_base_domain() {
		$helpers = new CFIP_Helpers();

		$result = $helpers->parseBaseDomainFromURL( 'https://www.example.com/path/to/page' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'domain', $result );
	}

	public function test_site_settings_defaults() {
		// Ensure settings default to empty array when not configured
		delete_option( 'cfip_settings' );

		$settings = new CFIP_SiteSettings();
		$this->assertIsArray( $settings->settings );
	}

	public function test_credentials_not_set_by_default() {
		delete_option( 'cfip_settings' );
		update_option( 'active_plugins', array() );

		$settings = new CFIP_SiteSettings();
		$this->assertFalse( $settings->areCredentialsSet() );
	}

	public function test_logging_disabled_by_default() {
		delete_option( 'cfip_settings' );

		$settings = new CFIP_SiteSettings();
		$this->assertFalse( $settings->isLoggingEnabled() );
	}

	public function test_logging_enabled_when_setting_on() {
		update_option( 'cfip_settings', array( 'enable_logging' => 'on' ) );

		$settings = new CFIP_SiteSettings();
		$this->assertTrue( $settings->isLoggingEnabled() );
	}
}
