<?php
/**
 * Tests for Send Emails with Mandrill plugin activation.
 */
class SEWM_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'wpMandrill' ), 'wpMandrill class should exist.' );
		$this->assertTrue( class_exists( 'Mandrill' ), 'Mandrill class should exist.' );
		$this->assertTrue( class_exists( 'Mandrill_Exception' ), 'Mandrill_Exception class should exist.' );
	}

	public function test_constants_defined() {
		$this->assertTrue( defined( 'SEWM_VERSION' ) );
		$this->assertEquals( $this->plugin_version( 'send-emails-with-mandrill' ), SEWM_VERSION );
	}
}
