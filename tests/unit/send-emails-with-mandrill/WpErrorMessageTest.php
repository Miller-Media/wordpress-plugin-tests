<?php
/**
 * Tests for GitHub issue #90: wpMandrill must produce WP_Error objects
 * with a descriptive message, not an empty one. The bug was passing the
 * exception message as the WP_Error CODE (the first constructor arg)
 * instead of the MESSAGE (the second arg), which left get_error_message()
 * empty.
 */
class SEWM_WpErrorMessageTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill = null;
	}

	public function tearDown(): void {
		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill = null;

		parent::tearDown();
	}

	public function test_sendEmail_without_api_key_returns_descriptive_wp_error() {
		$result = wpMandrill::sendEmail( array(
			'to'      => 'a@example.com',
			'subject' => 's',
			'html'    => 'b',
		) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'wpmandrill_error', $result->get_error_code() );
		$this->assertSame( 'Invalid API Key', $result->get_error_message() );
	}

	public function test_processAttachments_with_missing_file_returns_descriptive_wp_error() {
		$result = wpMandrill::processAttachments( array( '/nonexistent/file.pdf' ) );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNotEmpty( $result->get_error_message() );
	}

	public function test_evaluate_response_throws_exception_with_descriptive_message() {
		$error = wpMandrill::processAttachments( array( '/nonexistent/file.pdf' ) );

		$this->assertTrue( is_wp_error( $error ) );

		try {
			wpMandrill::evaluate_response( $error );
			$this->fail( 'Expected an Exception to be thrown.' );
		} catch ( Exception $e ) {
			$this->assertNotEmpty( $e->getMessage() );
		}
	}
}
