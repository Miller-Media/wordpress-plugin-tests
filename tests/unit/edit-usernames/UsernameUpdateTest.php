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

	public function tearDown(): void {
		unset( $_POST['user_login'] );
		parent::tearDown();
	}

	/**
	 * Exercises the plugin's actual save handler (not a simulation) to confirm
	 * an admin-submitted username change is persisted, which is what the
	 * user-edit.php "Edit" button workflow ultimately relies on.
	 */
	public function test_save_handler_updates_login_for_admin_with_capability() {
		$admin  = $this->create_test_user( 'administrator' );
		$target = $this->create_test_user( 'subscriber', array( 'user_login' => 'eun_old_login' ) );

		wp_set_current_user( $admin->ID );
		$_POST['user_login'] = 'eun_new_login';

		( new EUN_EditUsernames() )->update_username( $target->ID );

		clean_user_cache( $target->ID );
		$updated = get_user_by( 'ID', $target->ID );

		$this->assertEquals( 'eun_new_login', $updated->user_login );
	}

	/**
	 * Without edit_users/administrator/manage_woocommerce capability, the save
	 * handler must not touch the target user's login even if user_login is posted.
	 */
	public function test_save_handler_ignores_submission_without_capability() {
		$subscriber = $this->create_test_user( 'subscriber' );
		$target     = $this->create_test_user( 'subscriber', array( 'user_login' => 'eun_untouched_login' ) );

		wp_set_current_user( $subscriber->ID );
		$_POST['user_login'] = 'eun_hacked_login';

		( new EUN_EditUsernames() )->update_username( $target->ID );

		clean_user_cache( $target->ID );
		$updated = get_user_by( 'ID', $target->ID );

		$this->assertEquals( 'eun_untouched_login', $updated->user_login );
	}

	/**
	 * Re-submitting the same username (e.g. form saved without touching the
	 * field) must be a no-op and must not error.
	 */
	public function test_save_handler_noop_when_username_unchanged() {
		$admin  = $this->create_test_user( 'administrator' );
		$target = $this->create_test_user( 'subscriber', array( 'user_login' => 'eun_same_login' ) );

		wp_set_current_user( $admin->ID );
		$_POST['user_login'] = 'eun_same_login';

		( new EUN_EditUsernames() )->update_username( $target->ID );

		clean_user_cache( $target->ID );
		$updated = get_user_by( 'ID', $target->ID );

		$this->assertEquals( 'eun_same_login', $updated->user_login );
	}

	/**
	 * When display_name mirrors the old username (WordPress' default for new
	 * users), the save handler should keep display_name in sync too.
	 */
	public function test_save_handler_syncs_display_name_when_matching_login() {
		$admin  = $this->create_test_user( 'administrator' );
		$target = $this->create_test_user( 'subscriber', array(
			'user_login'   => 'eun_twin_name',
			'display_name' => 'eun_twin_name',
		) );

		wp_set_current_user( $admin->ID );
		$_POST['user_login'] = 'eun_twin_name_updated';

		( new EUN_EditUsernames() )->update_username( $target->ID );

		clean_user_cache( $target->ID );
		$updated = get_user_by( 'ID', $target->ID );

		$this->assertEquals( 'eun_twin_name_updated', $updated->user_login );
		$this->assertEquals( 'eun_twin_name_updated', $updated->display_name );
	}
}
