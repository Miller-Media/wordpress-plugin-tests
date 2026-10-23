<?php
/**
 * Tests for MMCSV_Import_Post_Helper, which wraps WP's own post/meta/term
 * APIs — no filesystem or network access required.
 */
class MMCSV_ImportPostHelperTest extends PluginTestCase {

	public function test_add_creates_a_post() {
		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title'   => 'Harness Test Post',
			'post_content' => 'This is test content.',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		) );

		$this->assertFalse( $helper->isError() );
		$post = $helper->getPost();

		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertEquals( 'Harness Test Post', $post->post_title );
		$this->assertEquals( 'publish', $post->post_status );
	}

	public function test_add_records_error_for_invalid_post_type() {
		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title' => 'Invalid',
			'post_type'  => 'no_such_post_type_exists',
		) );

		$this->assertTrue( $helper->isError() );
	}

	public function test_set_meta_writes_plain_custom_field() {
		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title'  => 'Post With Meta',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$helper->setMeta( array( 'custom_field' => 'hello-world' ) );

		$this->assertEquals( 'hello-world', get_post_meta( $helper->getPost()->ID, 'custom_field', true ) );
	}

	public function test_set_meta_sanitizes_the_key() {
		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title'  => 'Post With Odd Meta Key',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$helper->setMeta( array( 'Weird Key!' => 'value' ) );

		$this->assertEquals( 'value', get_post_meta( $helper->getPost()->ID, sanitize_key( 'Weird Key!' ), true ) );
	}

	public function test_set_post_tags_assigns_tags() {
		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title'  => 'Tagged Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$helper->setPostTags( array( 'fruits', 'apple' ) );

		$tags = wp_get_post_tags( $helper->getPost()->ID, array( 'fields' => 'names' ) );
		$this->assertEqualsCanonicalizing( array( 'fruits', 'apple' ), $tags );
	}

	public function test_set_object_terms_assigns_category() {
		$category_id = self::factory()->category->create( array( 'name' => 'Harness Category' ) );

		$helper = MMCSV_Import_Post_Helper::add( array(
			'post_title'  => 'Categorized Post',
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );

		$helper->setObjectTerms( 'category', array( $category_id ) );

		$terms = wp_get_post_categories( $helper->getPost()->ID );
		$this->assertContains( $category_id, $terms );
	}

	public function test_update_changes_existing_post() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original Title' ) );

		$helper = MMCSV_Import_Post_Helper::getByID( $post_id );
		$helper->update( array( 'post_title' => 'Updated Title' ) );

		$this->assertFalse( $helper->isError() );
		$this->assertEquals( 'Updated Title', get_post( $post_id )->post_title );
	}

	public function test_get_by_id_records_error_for_missing_post() {
		$helper = MMCSV_Import_Post_Helper::getByID( PHP_INT_MAX );

		$this->assertTrue( $helper->isError() );
		$this->assertNull( $helper->getPost() );
	}
}
