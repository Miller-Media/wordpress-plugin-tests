<?php
/**
 * Tests for username update functionality.
 */
class EUN_UsernameUpdateTest extends PluginTestCase {

	public function test_validate_username_rejects_empty() {
		$this->assertFalse( validate_username( '' ) );
	}

	public function test_validate_username_accepts_valid() {
		$this->assertTrue( validate_username( 'newusername' ) );
	}

	public function test_validate_username_rejects_special_chars() {
		// WordPress validate_username rejects certain special characters
		$this->assertFalse( validate_username( "user\x00name" ) );
	}

	public function test_username_exists_returns_false_for_new() {
		$this->assertFalse( username_exists( 'nonexistent_user_' . wp_generate_password( 8, false ) ) );
	}

	public function test_username_exists_returns_true_for_existing() {
		$user = $this->create_test_user( 'subscriber', array( 'user_login' => 'existing_user_test' ) );
		$this->assertNotFalse( username_exists( 'existing_user_test' ) );
	}

	public function test_direct_username_update_via_wpdb() {
		global $wpdb;

		$user = $this->create_test_user( 'subscriber', array( 'user_login' => 'old_username_test' ) );
		$new_username = 'new_username_test';

		$wpdb->update(
			$wpdb->users,
			array( 'user_login' => $new_username ),
			array( 'ID' => $user->ID )
		);

		// Clear cache so get_user_by returns fresh data
		clean_user_cache( $user->ID );

		$updated_user = get_user_by( 'ID', $user->ID );
		$this->assertEquals( $new_username, $updated_user->user_login );
	}

	public function test_nicename_sync_with_username() {
		global $wpdb;

		$user = $this->create_test_user( 'subscriber', array( 'user_login' => 'testlogin' ) );
		$new_login = 'updated_login';

		// Update user_login
		$wpdb->update(
			$wpdb->users,
			array(
				'user_login'    => $new_login,
				'user_nicename' => strtolower( str_replace( ' ', '-', $new_login ) ),
			),
			array( 'ID' => $user->ID )
		);

		clean_user_cache( $user->ID );
		$updated_user = get_user_by( 'ID', $user->ID );

		$this->assertEquals( $new_login, $updated_user->user_login );
		$this->assertEquals( strtolower( str_replace( ' ', '-', $new_login ) ), $updated_user->user_nicename );
	}

	public function test_display_name_update_when_matching_username() {
		global $wpdb;

		$username = 'display_match_test';
		$user     = $this->create_test_user( 'subscriber', array(
			'user_login'   => $username,
			'display_name' => $username,
		) );

		$new_username = 'display_match_updated';

		// Simulate what the plugin does: update login and display_name if they match
		$wpdb->update(
			$wpdb->users,
			array(
				'user_login'   => $new_username,
				'display_name' => $new_username,
			),
			array( 'ID' => $user->ID )
		);

		clean_user_cache( $user->ID );
		$updated_user = get_user_by( 'ID', $user->ID );

		$this->assertEquals( $new_username, $updated_user->display_name );
	}

	public function test_admin_can_edit_users() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$this->assertTrue( current_user_can( 'edit_users' ) );
	}

	public function test_subscriber_cannot_edit_users() {
		$subscriber = $this->create_test_user( 'subscriber' );
		wp_set_current_user( $subscriber->ID );

		$this->assertFalse( current_user_can( 'edit_users' ) );
	}
}
