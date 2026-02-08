<?php
/**
 * Tests for password expiration logic.
 */

use MillerMedia\ExpireUserPasswords\Expire_User_Passwords;

class ExPass_ExpirationTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		// Initialize the plugin
		Expire_User_Passwords::instance();
		// Trigger init to set default_limit
		Expire_User_Passwords::$default_limit = 90;
	}

	public function test_default_limit_is_90_days() {
		delete_option( 'user_expass_settings' );
		$limit = Expire_User_Passwords::get_limit();
		$this->assertEquals( 90, $limit );
	}

	public function test_custom_limit() {
		update_option( 'user_expass_settings', array( 'limit' => 30 ) );
		$limit = Expire_User_Passwords::get_limit();
		$this->assertEquals( 30, $limit );
	}

	public function test_limit_capped_at_365() {
		update_option( 'user_expass_settings', array( 'limit' => 500 ) );
		$limit = Expire_User_Passwords::get_limit();
		// Should return default because 500 > 365
		$this->assertEquals( 90, $limit );
	}

	public function test_save_user_meta() {
		$user = $this->create_test_user();

		Expire_User_Passwords::save_user_meta( $user );

		$meta = get_user_meta( $user->ID, 'user_expass_password_reset', true );
		$this->assertNotEmpty( $meta );
		$this->assertIsNumeric( $meta );
	}

	public function test_get_user_meta() {
		$user = $this->create_test_user();

		// Meta may already be set from user_register hook.
		// Clear it to test the false case.
		delete_user_meta( $user->ID, 'user_expass_password_reset' );
		$this->assertFalse( Expire_User_Passwords::get_user_meta( $user ) );

		// Set meta
		Expire_User_Passwords::save_user_meta( $user );

		$meta = Expire_User_Passwords::get_user_meta( $user );
		$this->assertIsInt( $meta );
		$this->assertGreaterThan( 0, $meta );
	}

	public function test_get_roles_defaults_to_all_except_admin() {
		delete_option( 'user_expass_settings' );

		$roles = Expire_User_Passwords::get_roles();
		$this->assertIsArray( $roles );
	}

	public function test_get_roles_from_settings() {
		update_option( 'user_expass_settings', array(
			'roles' => array(
				'subscriber' => 'on',
				'editor'     => 'on',
			),
		) );

		$roles = Expire_User_Passwords::get_roles();
		$this->assertContains( 'subscriber', $roles );
		$this->assertContains( 'editor', $roles );
		$this->assertNotContains( 'administrator', $roles );
	}

	public function test_has_expirable_role() {
		update_option( 'user_expass_settings', array(
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$subscriber = $this->create_test_user( 'subscriber' );
		$admin      = $this->create_test_user( 'administrator' );

		$this->assertTrue( Expire_User_Passwords::has_expirable_role( $subscriber ) );
		$this->assertFalse( Expire_User_Passwords::has_expirable_role( $admin ) );
	}

	public function test_is_expired_for_fresh_password() {
		update_option( 'user_expass_settings', array(
			'limit' => 90,
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );
		Expire_User_Passwords::save_user_meta( $user );

		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_is_expired_for_old_password() {
		update_option( 'user_expass_settings', array(
			'limit' => 30,
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );

		// Set reset time to 60 days ago
		$old_time = gmdate( 'U' ) - ( 60 * DAY_IN_SECONDS );
		update_user_meta( $user->ID, 'user_expass_password_reset', $old_time );

		$this->assertTrue( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_get_expiration_returns_timestamp() {
		update_option( 'user_expass_settings', array(
			'limit' => 90,
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );
		Expire_User_Passwords::save_user_meta( $user );

		$expiration = Expire_User_Passwords::get_expiration( $user );
		$this->assertNotFalse( $expiration );
		$this->assertIsNumeric( $expiration );
		$this->assertGreaterThan( time(), (int) $expiration );
	}

	public function test_get_expiration_returns_false_for_non_expirable_role() {
		update_option( 'user_expass_settings', array(
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$admin = $this->create_test_user( 'administrator' );
		Expire_User_Passwords::save_user_meta( $admin );

		$this->assertFalse( Expire_User_Passwords::get_expiration( $admin ) );
	}
}
