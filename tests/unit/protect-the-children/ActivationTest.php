<?php
/**
 * Tests for Protect the Children plugin activation and initialization.
 */
class PTC_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'ProtectTheChildren' ), 'ProtectTheChildren class should exist.' );
		$this->assertTrue( class_exists( 'ProtectTheChildren_Helpers' ), 'ProtectTheChildren_Helpers class should exist.' );
	}

	public function test_constants_defined() {
		$this->assertTrue( defined( 'PTC_PLUGIN_PATH' ) );
		$this->assertTrue( defined( 'PROTECT_THE_CHILDREN_PLUGIN_VERSION' ) );
		$this->assertEquals( '1.4.0', PROTECT_THE_CHILDREN_PLUGIN_VERSION );
	}
}
