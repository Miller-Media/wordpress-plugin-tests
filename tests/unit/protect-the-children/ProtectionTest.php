<?php
/**
 * Tests for the child protection save/meta logic.
 */
class PTC_ProtectionTest extends PluginTestCase {

	public function test_save_post_meta_stores_protect_children() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );

		// Simulate form submission
		$_POST['protect_children'] = '1';

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta );
	}

	public function test_save_post_meta_clears_protect_children() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		// Simulate form submission without the checkbox
		$_POST = array( 'some_field' => 'value' );
		unset( $_POST['protect_children'] );

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEmpty( $meta );
	}

	public function test_protect_meta_filter() {
		$ptc = new ProtectTheChildren();

		$this->assertTrue( $ptc->protect_meta( false, 'protect_children' ) );
		$this->assertFalse( $ptc->protect_meta( false, 'other_meta_key' ) );
	}

	public function test_register_post_meta_gutenberg() {
		$ptc = new ProtectTheChildren();
		$ptc->register_post_meta_gutenberg();

		// Verify meta is registered
		$registered = registered_meta_key_exists( 'post', 'protect_children' );
		$this->assertTrue( $registered );
	}

	public function test_child_page_inherits_protection() {
		$parent = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $parent->ID, 'protect_children', '1' );

		$child = $this->create_test_page( array(
			'post_parent' => $parent->ID,
		) );

		$ancestors = get_post_ancestors( $child->ID );
		$this->assertContains( $parent->ID, $ancestors );

		$protected_parent = ProtectTheChildren_Helpers::isEnabled( $ancestors );
		$this->assertEquals( $parent->ID, $protected_parent );
	}

	public function test_grandchild_page_inherits_protection() {
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

		$ancestors = get_post_ancestors( $grandchild->ID );
		$protected_parent = ProtectTheChildren_Helpers::isEnabled( $ancestors );
		$this->assertEquals( $grandparent->ID, $protected_parent );
	}
}
