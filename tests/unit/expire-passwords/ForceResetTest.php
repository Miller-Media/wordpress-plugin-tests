<?php
/**
 * Tests for the "Force Password Reset" row action and bulk handler.
 */

use MillerMedia\ExpireUserPasswords\Expire_User_Passwords;
use MillerMedia\ExpireUserPasswords\Expire_User_Passwords_List_Table;
use MillerMedia\ExpireUserPasswords\Expire_User_Passwords_Settings;

/**
 * Thrown from the wp_redirect filter so handlers that redirect and exit
 * can be tested without the exit killing the PHPUnit process.
 */
class ExPass_RedirectException extends Exception {}

class ExPass_ForceResetTest extends PluginTestCase {

	/**
	 * @var WP_User
	 */
	private $admin;

	public function setUp(): void {
		parent::setUp();

		Expire_User_Passwords::instance();
		Expire_User_Passwords::$default_limit = 90;

		update_option(
			'user_expass_settings',
			array(
				'limit'      => 90,
				'roles'      => array( 'subscriber' => 1 ),
				'send_email' => '0',
			)
		);

		$this->admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $this->admin->ID );

		$_SERVER['REQUEST_METHOD'] = 'POST';

		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );

		unset( $_POST['user_id'], $_POST['paged'], $_POST['count'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		parent::tearDown();
	}

	public function throw_on_redirect( $location ) {
		throw new ExPass_RedirectException( $location );
	}

	private function set_nonce( $nonce ) {
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	private function force_reset_user( $user_id ) {
		$_POST['user_id'] = $user_id;

		( new Expire_User_Passwords_List_Table() )->handle_force_reset_user();
	}

	private function force_reset_all() {
		( new Expire_User_Passwords_Settings() )->handle_force_reset_all();
	}

	private function is_flagged( $user ) {
		return (bool) get_user_meta( $user->ID, 'user_expass_force_reset', true );
	}

	public function test_force_password_reset_sets_flag_and_expires_user() {
		$user = $this->create_test_user( 'subscriber' );
		Expire_User_Passwords::save_user_meta( $user );

		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );

		$this->assertTrue( Expire_User_Passwords::force_password_reset( $user->ID ) );

		$this->assertTrue( $this->is_flagged( $user ) );
		$this->assertTrue( Expire_User_Passwords::is_force_reset( $user ) );
		$this->assertTrue( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_force_password_reset_ignores_non_expirable_role() {
		$editor = $this->create_test_user( 'editor' );

		$this->assertFalse( Expire_User_Passwords::force_password_reset( $editor->ID ) );
		$this->assertFalse( $this->is_flagged( $editor ) );
	}

	public function test_force_password_reset_destroys_sessions() {
		$user    = $this->create_test_user( 'subscriber' );
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$manager->create( time() + HOUR_IN_SECONDS );

		$this->assertNotEmpty( $manager->get_all() );

		Expire_User_Passwords::force_password_reset( $user->ID );

		$this->assertEmpty( WP_Session_Tokens::get_instance( $user->ID )->get_all() );
	}

	public function test_flag_cleared_after_password_reset() {
		$user = $this->create_test_user( 'subscriber' );

		Expire_User_Passwords::force_password_reset( $user->ID );
		$this->assertTrue( $this->is_flagged( $user ) );

		reset_password( $user, 'a-new-password-123' );

		$this->assertFalse( $this->is_flagged( $user ) );
		$this->assertFalse( Expire_User_Passwords::is_expired( $user ) );
	}

	public function test_user_handler_forces_reset_with_valid_nonce() {
		$user = $this->create_test_user( 'subscriber' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_user_' . $user->ID ) );

		try {
			$this->force_reset_user( $user->ID );
			$this->fail( 'Expected a redirect.' );
		} catch ( ExPass_RedirectException $e ) {
			$this->assertStringContainsString( 'user_expass_force_reset=1', $e->getMessage() );
		}

		$this->assertTrue( $this->is_flagged( $user ) );
	}

	public function test_user_handler_rejects_missing_nonce() {
		$user = $this->create_test_user( 'subscriber' );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_user( $user->ID );
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_user_handler_rejects_invalid_nonce() {
		$user = $this->create_test_user( 'subscriber' );
		$this->set_nonce( 'invalid' );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_user( $user->ID );
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_user_handler_rejects_nonce_for_another_user() {
		$user  = $this->create_test_user( 'subscriber' );
		$other = $this->create_test_user( 'subscriber' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_user_' . $other->ID ) );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_user( $user->ID );
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_user_handler_rejects_user_without_capability() {
		$user   = $this->create_test_user( 'subscriber' );
		$editor = $this->create_test_user( 'editor' );
		wp_set_current_user( $editor->ID );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_user_' . $user->ID ) );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_user( $user->ID );
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_user_handler_rejects_non_post_request() {
		$user = $this->create_test_user( 'subscriber' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_user_' . $user->ID ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_user( $user->ID );
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_all_handler_rejects_missing_nonce() {
		$user = $this->create_test_user( 'subscriber' );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_all();
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_all_handler_rejects_invalid_nonce() {
		$user = $this->create_test_user( 'subscriber' );
		$this->set_nonce( 'invalid' );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_all();
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_all_handler_rejects_user_without_capability() {
		$user   = $this->create_test_user( 'subscriber' );
		$editor = $this->create_test_user( 'editor' );
		wp_set_current_user( $editor->ID );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_all' ) );

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_all();
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_all_handler_rejects_non_post_request() {
		$user = $this->create_test_user( 'subscriber' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_all' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->expectException( WPDieException::class );

		try {
			$this->force_reset_all();
		} finally {
			$this->assertFalse( $this->is_flagged( $user ) );
		}
	}

	public function test_all_handler_forces_reset_for_expirable_roles_only() {
		$subscriber = $this->create_test_user( 'subscriber' );
		$editor     = $this->create_test_user( 'editor' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_all' ) );

		try {
			$this->force_reset_all();
			$this->fail( 'Expected a redirect.' );
		} catch ( ExPass_RedirectException $e ) {
			$this->assertStringContainsString( 'user_expass_force_reset=1', $e->getMessage() );
		}

		$this->assertTrue( $this->is_flagged( $subscriber ) );
		$this->assertFalse( $this->is_flagged( $editor ) );
	}

	public function test_all_handler_skips_current_user() {
		update_option(
			'user_expass_settings',
			array(
				'roles'      => array(
					'subscriber'    => 1,
					'administrator' => 1,
				),
				'send_email' => '0',
			)
		);

		$other_admin = $this->create_test_user( 'administrator' );
		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_all' ) );

		try {
			$this->force_reset_all();
			$this->fail( 'Expected a redirect.' );
		} catch ( ExPass_RedirectException $e ) {
			// Redirected back to the settings page.
		}

		$this->assertFalse( $this->is_flagged( $this->admin ) );
		$this->assertTrue( $this->is_flagged( $other_admin ) );
	}

	public function test_all_handler_processes_users_in_batches() {
		$batch_size = static function () {
			return 2;
		};
		add_filter( 'user_expass_force_reset_batch_size', $batch_size );

		$users = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$users[] = $this->create_test_user( 'subscriber' );
		}

		$this->set_nonce( wp_create_nonce( 'user_expass_force_reset_all' ) );

		// The first batch renders a form that POSTs the next batch.
		try {
			$this->force_reset_all();
			$this->fail( 'Expected the next batch form.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'name="paged" value="2"', $e->getMessage() );
			$this->assertStringContainsString( 'name="count" value="2"', $e->getMessage() );
		}

		$this->assertSame( 2, count( array_filter( $users, array( $this, 'is_flagged' ) ) ) );

		$_POST['paged'] = 2;
		$_POST['count'] = 2;

		try {
			$this->force_reset_all();
			$this->fail( 'Expected a redirect.' );
		} catch ( ExPass_RedirectException $e ) {
			$this->assertStringContainsString( 'user_expass_force_reset=3', $e->getMessage() );
		} finally {
			remove_filter( 'user_expass_force_reset_batch_size', $batch_size );
		}

		$this->assertSame( 3, count( array_filter( $users, array( $this, 'is_flagged' ) ) ) );
	}
}
