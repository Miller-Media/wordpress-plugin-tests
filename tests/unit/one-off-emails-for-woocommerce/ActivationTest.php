<?php
/**
 * Tests for One-Off Emails for WooCommerce plugin activation.
 */
class WOOE_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'WooOneOffEmails' ), 'WooOneOffEmails class should exist.' );
		$this->assertTrue( class_exists( 'WooOneOffEmailsSettings' ), 'WooOneOffEmailsSettings class should exist.' );
	}

	public function test_constant_matches_header_version() {
		$this->assertTrue( defined( 'WOOE_PLUGIN_VERSION' ) );
		$this->assertEquals( $this->plugin_version( 'one-off-emails-for-woocommerce' ), WOOE_PLUGIN_VERSION );
	}

	public function test_is_woocommerce_activated_helper_exists() {
		$this->assertTrue( function_exists( 'is_woocommerce_activated' ) );
		$this->assertTrue( is_woocommerce_activated(), 'WooCommerce should be loaded for this plugin in tests.' );
	}

	public function test_construct_registers_hooks() {
		new WooOneOffEmails();

		$this->assertHookHasCallback( 'admin_enqueue_scripts', 'adminEnqueueScripts' );
		$this->assertHookHasCallback( 'wp_ajax_wooe_sendemail', 'ajaxSendEmail' );
		$this->assertHookHasCallback( 'wp_ajax_wooe_previewemail', 'ajaxPreviewEmail' );
	}

	public function test_construct_builds_settings_page() {
		$plugin = new WooOneOffEmails();

		$this->assertInstanceOf( 'WooOneOffEmailsSettings', $plugin->settingsPage );
	}
}
