<?php
/**
 * Tests for the child protection save/meta logic.
 */
class PTC_ProtectionTest extends PluginTestCase {

	/**
	 * Build a $_POST array carrying a valid nonce for the plugin's own
	 * classic-editor checkbox field, as rendered by add_classic_checkbox().
	 *
	 * @param array $extra Additional $_POST fields to merge in.
	 * @return array
	 */
	private function post_with_valid_nonce( $extra = array() ) {
		return array_merge( array(
			'ptc_save_post_meta_nonce' => wp_create_nonce( 'ptc_save_post_meta' ),
		), $extra );
	}

	public function test_save_post_meta_stores_protect_children() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );

		// Simulate submitting the plugin's own classic-editor checkbox, checked.
		$_POST = $this->post_with_valid_nonce( array( 'protect_children' => '1' ) );

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta );
	}

	public function test_save_post_meta_clears_protect_children_when_explicitly_unchecked() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		// Simulate submitting the plugin's own classic-editor checkbox, unchecked —
		// the nonce is present (our form was rendered) but the checkbox field is not.
		$_POST = $this->post_with_valid_nonce( array( 'some_field' => 'value' ) );

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEmpty( $meta );
	}

	public function test_save_post_meta_without_nonce_keeps_protection() {
		// Simulates Elementor's AJAX save, or any other code path that calls
		// wp_update_post() / triggers save_post without going through this
		// plugin's own classic-editor checkbox (and therefore without its nonce).
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		$_POST = array(
			'action'         => 'elementor_ajax',
			'editor_post_id' => $page->ID,
		);

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta, 'Protection must survive a save that does not include the plugin\'s own nonce.' );
	}

	public function test_save_post_meta_quick_edit_keeps_protection() {
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		// Simulate a Quick Edit (inline-save) submission, which never renders
		// the plugin's checkbox or nonce.
		$_POST = array(
			'action'       => 'inline-save',
			'_inline_edit' => wp_create_nonce( 'inlineeditnonce' ),
		);

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta, 'Protection must survive a Quick Edit save.' );
	}

	public function test_save_post_meta_wp_update_post_from_code_keeps_protection() {
		// Simulates another plugin or a cron job calling wp_update_post() directly
		// during a request whose $_POST happens to be non-empty for unrelated reasons.
		$admin = $this->create_test_user( 'administrator' );
		wp_set_current_user( $admin->ID );

		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		$_POST = array( 'unrelated_plugin_field' => 'value' );

		$ptc = new ProtectTheChildren();
		add_action( 'save_post', array( $ptc, 'ptc_save_post_meta' ), 10, 3 );

		wp_update_post( array(
			'ID'         => $page->ID,
			'post_title' => 'Updated Title',
		) );

		remove_action( 'save_post', array( $ptc, 'ptc_save_post_meta' ), 10 );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta, 'Protection must survive a programmatic wp_update_post() call.' );
	}

	public function test_rest_update_without_meta_keeps_protection() {
		// Simulates the block editor / REST API updating a page without including
		// the protect_children field in the request's meta.
		$page = $this->create_test_page( array(
			'post_password' => 'secret123',
		) );
		update_post_meta( $page->ID, 'protect_children', '1' );

		$_POST = array();

		$ptc = new ProtectTheChildren();
		$ptc->ptc_save_post_meta( $page->ID, $page, true );

		// REST's own meta handling runs before rest_after_insert_page fires; since
		// it didn't touch protect_children, the meta here is unchanged from before.
		do_action( 'rest_after_insert_page', get_post( $page->ID ) );

		$meta = get_post_meta( $page->ID, 'protect_children', true );
		$this->assertEquals( '1', $meta, 'Protection must survive a REST update that omits the meta field.' );
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
