<?php
/**
 * Tests for Miller Media Server File Import plugin activation.
 */
class MMSFI_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'MMSFI_FileBrowser' ), 'MMSFI_FileBrowser class should exist.' );
		$this->assertTrue( class_exists( 'MMSFI_Importer' ), 'MMSFI_Importer class should exist.' );
		$this->assertTrue( class_exists( 'MMSFI_Settings' ), 'MMSFI_Settings class should exist.' );
		$this->assertTrue( class_exists( 'MMSFI_Plugin' ), 'MMSFI_Plugin class should exist.' );
	}

	public function test_constant_matches_header_version() {
		$this->assertTrue( defined( 'MMSFI_PLUGIN_VERSION' ) );
		$this->assertEquals( $this->plugin_version( 'import-from-server' ), MMSFI_PLUGIN_VERSION );
	}

	public function test_settings_registers_hooks() {
		new MMSFI_Settings();

		$this->assertHookHasCallback( 'admin_menu', 'addSettingsPage' );
		$this->assertHookHasCallback( 'admin_init', 'registerSettings' );
	}

	public function test_plugin_registers_admin_hooks() {
		new MMSFI_Plugin();

		$this->assertHookHasCallback( 'admin_menu', 'addAdminPage' );
		$this->assertHookHasCallback( 'admin_enqueue_scripts', 'enqueueAssets' );
	}
}
