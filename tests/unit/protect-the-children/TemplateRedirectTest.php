<?php
/**
 * Tests for the front-end template_redirect protection logic.
 *
 * Verifies that child pages of password-protected parents
 * correctly require a password when accessed without a valid cookie.
 *
 * Note: The template_redirect callback is an anonymous closure in index.php,
 * which the test bootstrap doesn't load (to avoid side effects). These tests
 * replicate that callback's logic by calling the same helpers and checking
 * the same conditions, then verifying post_password_required behavior.
 */
class PTC_TemplateRedirectTest extends PluginTestCase {

	/**
	 * Set the postpass cookie with a hashed password value.
	 *
	 * @param string $password The plain-text password.
	 */
	private function set_password_cookie( $password ) {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = new PasswordHash( 8, true );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( $password );
	}

	/**
	 * Clear the postpass cookie.
	 */
	private function clear_password_cookie() {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	/**
	 * Replicate the template_redirect logic from index.php.
	 *
	 * This mirrors the anonymous closure registered via
	 * add_action( 'template_redirect', ... ) in index.php.
	 */
	private function run_protection_check() {
		$post_id    = get_the_ID();
		$parent_ids = get_post_ancestors( $post_id );

		if ( ! $parent_ids || empty( $parent_ids ) ) {
			return;
		}

		$parent_post = ProtectTheChildren_Helpers::isEnabled( $parent_ids );

		if ( ! $parent_post ) {
			return;
		}

		$parent_post_object = is_int( $parent_post ) ? get_post( $parent_post ) : $parent_post;
		$parent_password    = $parent_post_object->post_password;

		// If password cookie does not exist, require password.
		if ( ! array_key_exists( 'wp-postpass_' . COOKIEHASH, $_COOKIE ) ) {
			add_filter( 'post_password_required', '__return_true' );
			return;
		}

		// Check the cookie (hashed password).
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher   = new PasswordHash( 8, true );
		$hash     = wp_unslash( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		$required = ! $hasher->CheckPassword( $parent_password, $hash );

		if ( ! $required ) {
			return;
		}

		add_filter( 'post_password_required', '__return_true' );
	}

	/**
	 * Simulate visiting a post, run the protection check, and return
	 * whether post_password_required is true.
	 *
	 * @param int $post_id The post ID to visit.
	 * @return bool
	 */
	private function visit_and_check_protection( $post_id ) {
		remove_all_filters( 'post_password_required' );
		$this->go_to( '/?page_id=' . $post_id );
		$this->run_protection_check();
		return post_password_required( get_post( $post_id ) );
	}

	public function setUp(): void {
		parent::setUp();
		$this->clear_password_cookie();
	}

	public function tearDown(): void {
		$this->clear_password_cookie();
		remove_all_filters( 'post_password_required' );
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// No cookie — should require password
	// ------------------------------------------------------------------

	public function test_child_page_requires_password_without_cookie() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->assertTrue( $this->visit_and_check_protection( $child->ID ) );
	}

	public function test_grandchild_page_requires_password_without_cookie() {
		$grandparent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $grandparent->ID, 'protect_children', '1' );

		$parent = $this->create_test_page( array(
			'post_parent' => $grandparent->ID,
		) );

		$grandchild = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->assertTrue( $this->visit_and_check_protection( $grandchild->ID ) );
	}

	// ------------------------------------------------------------------
	// No protection — should NOT require password
	// ------------------------------------------------------------------

	public function test_child_page_not_protected_when_meta_disabled() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		// protect_children meta is NOT set.

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->assertFalse( $this->visit_and_check_protection( $child->ID ) );
	}

	public function test_child_page_not_protected_when_parent_has_no_password() {
		$parent = $this->create_test_page();
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->assertFalse( $this->visit_and_check_protection( $child->ID ) );
	}

	public function test_top_level_page_not_affected() {
		$page = $this->create_test_page();

		$this->assertFalse( $this->visit_and_check_protection( $page->ID ) );
	}

	// ------------------------------------------------------------------
	// Cookie tests
	// ------------------------------------------------------------------

	public function test_child_page_accessible_with_correct_password_cookie() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->set_password_cookie( 'secret123' );
		$this->assertFalse( $this->visit_and_check_protection( $child->ID ) );
	}

	public function test_child_page_blocked_with_wrong_password_cookie() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->set_password_cookie( 'wrongpassword' );
		$this->assertTrue( $this->visit_and_check_protection( $child->ID ) );
	}

	public function test_grandchild_accessible_with_correct_password_cookie() {
		$grandparent = $this->create_test_page( array(
			'post_password' => 'mypassword',
		) );
		update_post_meta( $grandparent->ID, 'protect_children', '1' );

		$parent = $this->create_test_page( array(
			'post_parent' => $grandparent->ID,
		) );

		$grandchild = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$this->set_password_cookie( 'mypassword' );
		$this->assertFalse( $this->visit_and_check_protection( $grandchild->ID ) );
	}
}
