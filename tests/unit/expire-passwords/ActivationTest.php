<?php
/**
 * Tests for Expire User Passwords plugin activation.
 */
class ExPass_ActivationTest extends PluginTestCase {

	public function test_plugin_class_exists() {
		$this->assertTrue(
			class_exists( 'MillerMedia\ExpireUserPasswords\Expire_User_Passwords' ),
			'Expire_User_Passwords class should exist.'
		);
	}

	public function test_constants_defined() {
		$this->assertTrue( defined( 'EXPIRE_USER_PASSWORDS_VERSION' ) );
		$this->assertEquals( '1.3.1', EXPIRE_USER_PASSWORDS_VERSION );
	}

	public function test_singleton_returns_same_instance() {
		$instance1 = \MillerMedia\ExpireUserPasswords\Expire_User_Passwords::instance();
		$instance2 = \MillerMedia\ExpireUserPasswords\Expire_User_Passwords::instance();

		$this->assertSame( $instance1, $instance2 );
	}
}
