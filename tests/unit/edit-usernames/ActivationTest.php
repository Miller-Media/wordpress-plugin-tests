<?php
/**
 * Tests for Edit Usernames plugin activation and initialization.
 */
class EUN_ActivationTest extends PluginTestCase {

	public function test_plugin_class_exists() {
		$this->assertTrue( class_exists( 'EUN_EditUsernames' ), 'EUN_EditUsernames class should exist.' );
	}

	public function test_constants_defined() {
		$this->assertTrue( defined( 'EUN_SELFEDIT' ) );
		$this->assertTrue( defined( 'EUN_PLUGIN_PATH' ) );
		$this->assertTrue( defined( 'EUN_PLUGIN_NAME' ) );
		$this->assertTrue( defined( 'EUN_SETTINGS_PREFIX' ) );
	}

	public function test_selfedit_default_is_false() {
		$this->assertFalse( EUN_SELFEDIT );
	}
}
