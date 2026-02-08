<?php
/**
 * Tests for ProtectTheChildren_Helpers.
 */
class PTC_HelpersTest extends PluginTestCase {

	public function test_is_password_protected_with_password() {
		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );

		$this->assertTrue( ProtectTheChildren_Helpers::isPasswordProtected( $page ) );
	}

	public function test_is_password_protected_without_password() {
		$page = $this->create_test_page();

		$this->assertFalse( ProtectTheChildren_Helpers::isPasswordProtected( $page ) );
	}

	public function test_is_password_protected_private_post_returns_false() {
		$page = $this->create_test_page( array(
			'post_status'   => 'private',
			'post_password' => 'secret123',
		) );

		$this->assertFalse( ProtectTheChildren_Helpers::isPasswordProtected( $page ) );
	}

	public function test_is_password_protected_by_id() {
		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );

		$this->assertTrue( ProtectTheChildren_Helpers::isPasswordProtected( $page->ID ) );
	}

	public function test_is_enabled_with_protected_parent() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$result = ProtectTheChildren_Helpers::isEnabled( array( $parent->ID ) );
		$this->assertEquals( $parent->ID, $result );
	}

	public function test_is_enabled_without_meta() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );

		$result = ProtectTheChildren_Helpers::isEnabled( array( $parent->ID ) );
		$this->assertFalsy( $result );
	}

	public function test_is_enabled_without_password() {
		$parent = $this->create_test_page();
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$result = ProtectTheChildren_Helpers::isEnabled( array( $parent->ID ) );
		$this->assertFalsy( $result );
	}

	public function test_is_enabled_with_empty_array() {
		$result = ProtectTheChildren_Helpers::isEnabled( array() );
		$this->assertFalse( $result );
	}

	public function test_supports_ptc_for_pages() {
		$page = $this->create_test_page();
		$this->assertTrue( ProtectTheChildren_Helpers::supportsPTC( $page ) );
	}

	public function test_supports_ptc_for_regular_posts() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		// Regular posts are not hierarchical, so they don't support PTC
		$this->assertFalse( ProtectTheChildren_Helpers::supportsPTC( $post ) );
	}

	/**
	 * Helper to assert falsy values (false, null, 0, empty).
	 */
	private function assertFalsy( $value ) {
		$this->assertEmpty( $value, 'Expected falsy value, got: ' . var_export( $value, true ) );
	}
}
