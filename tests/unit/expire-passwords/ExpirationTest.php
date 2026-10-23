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

		// Force 'administrator' to be present in the editable roles list,
		// regardless of what other plugins in the test environment do to
		// the 'editable_roles' filter, so this test exercises the plugin's
		// own default-role exclusion logic in isolation.
		$force_admin_editable = static function ( $roles ) {
			$roles['administrator'] = array( 'name' => 'Administrator' );
			return $roles;
		};
		add_filter( 'editable_roles', $force_admin_editable, 20 );

		$roles = Expire_User_Passwords::get_roles();

		remove_filter( 'editable_roles', $force_admin_editable, 20 );

		$this->assertIsArray( $roles );
		$this->assertNotContains( 'administrator', $roles );
		$this->assertContains( 'subscriber', $roles );
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

	public function test_user_without_reset_meta_is_not_locked_out() {
		update_option( 'user_expass_settings', array(
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );
		delete_user_meta( $user->ID, 'user_expass_password_reset' );

		$this->assertFalse( Expire_User_Passwords::get_user_meta( $user ) );
		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_admin_set_password_updates_reset_timestamp() {
		update_option( 'user_expass_settings', array(
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );

		$old_time = gmdate( 'U' ) - ( 60 * DAY_IN_SECONDS );
		update_user_meta( $user->ID, 'user_expass_password_reset', $old_time );

		// Simulate an admin changing the user's password from the edit-user screen.
		wp_update_user( array(
			'ID'        => $user->ID,
			'user_pass' => 'a-brand-new-password',
		) );

		$meta = Expire_User_Passwords::get_user_meta( $user->ID );
		$this->assertGreaterThan( $old_time, $meta );
	}

	public function test_profile_update_without_password_change_does_not_touch_timestamp() {
		update_option( 'user_expass_settings', array(
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );

		$old_time = gmdate( 'U' ) - ( 60 * DAY_IN_SECONDS );
		update_user_meta( $user->ID, 'user_expass_password_reset', $old_time );

		// Update an unrelated profile field; password is left untouched.
		wp_update_user( array(
			'ID'           => $user->ID,
			'first_name'   => 'Updated',
		) );

		$meta = Expire_User_Passwords::get_user_meta( $user->ID );
		$this->assertEquals( $old_time, $meta );
	}

	public function test_expired_user_can_complete_reset_flow() {
		update_option( 'user_expass_settings', array(
			'limit' => 30,
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$user = $this->create_test_user( 'subscriber' );

		$old_time = gmdate( 'U' ) - ( 60 * DAY_IN_SECONDS );
		update_user_meta( $user->ID, 'user_expass_password_reset', $old_time );

		$this->assertTrue( Expire_User_Passwords::is_expired( $user ) );

		// Completing the core password reset flow (e.g. via the rp/lostpassword
		// screens) must be possible and must clear the expiration.
		$this->assertTrue( (bool) wp_is_password_reset_allowed_for_user( $user ) );

		reset_password( $user, 'a-freshly-reset-password' );

		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_one_day_limit_expires_correctly() {
		update_option( 'user_expass_settings', array(
			'limit' => 1,
			'roles' => array( 'subscriber' => 'on' ),
		) );

		$this->assertEquals( 1, Expire_User_Passwords::get_limit() );

		$user = $this->create_test_user( 'subscriber' );

		// Reset 12 hours ago: within the 1-day window, must not be expired yet.
		update_user_meta( $user->ID, 'user_expass_password_reset', gmdate( 'U' ) - ( 12 * HOUR_IN_SECONDS ) );
		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );

		// Reset 2 days ago: past the 1-day window, must be expired.
		update_user_meta( $user->ID, 'user_expass_password_reset', gmdate( 'U' ) - ( 2 * DAY_IN_SECONDS ) );
		$this->assertTrue( Expire_User_Passwords::is_expired( $user ) );
	}
}
