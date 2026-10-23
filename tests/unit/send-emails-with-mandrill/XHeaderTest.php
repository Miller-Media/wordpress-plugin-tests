<?php
/**
 * Tests for the X- custom header case-sensitivity bug (GitHub issue #1):
 * wpMandrill::sendEmail() used to only keep headers whose name was already
 * lowercase ("x-..."), silently dropping normally-cased ones like
 * "X-Custom-Header" even though the plugin's own docs say X-* headers
 * are supported.
 */
class SEWM_XHeaderTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option(
			'wpmandrill',
			array(
				'api_key'       => 'test_api_key',
				'from_username' => 'sender@example.com',
				'from_name'     => 'Sender',
			)
		);
		wpMandrill::$mandrill = null;
	}

	public function tearDown(): void {
		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill = null;

		parent::tearDown();
	}

	/**
	 * Parses the message[headers] sent to Mandrill out of the last
	 * intercepted messages/send request body.
	 *
	 * @return array
	 */
	private function get_sent_headers() {
		$request = MandrillAPIMock::get_last_request();
		$this->assertNotNull( $request, 'No request was intercepted by MandrillAPIMock.' );

		parse_str( $request['body'], $parsed );

		return isset( $parsed['message']['headers'] ) ? $parsed['message']['headers'] : array();
	}

	public function test_mixed_case_x_header_is_preserved_as_string() {
		$result = wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', 'X-Custom-Header: abc' );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$headers = $this->get_sent_headers();

		$this->assertArrayHasKey( 'X-Custom-Header', $headers );
		$this->assertSame( 'abc', $headers['X-Custom-Header'] );
	}

	public function test_mixed_case_x_header_is_preserved_as_array() {
		$result = wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', array( 'X-Another: def' ) );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$headers = $this->get_sent_headers();

		$this->assertArrayHasKey( 'X-Another', $headers );
		$this->assertSame( 'def', $headers['X-Another'] );
	}

	public function test_lowercase_x_header_still_passes() {
		$result = wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', 'x-lower: 1' );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$headers = $this->get_sent_headers();

		$this->assertArrayHasKey( 'x-lower', $headers );
		$this->assertSame( '1', $headers['x-lower'] );
	}

	public function test_non_x_header_is_still_dropped() {
		$result = wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', 'Foo: bar' );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		$headers = $this->get_sent_headers();

		$this->assertArrayNotHasKey( 'Foo', $headers );
	}
}
