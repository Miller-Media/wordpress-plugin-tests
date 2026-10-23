<?php
/**
 * Tests for GitHub issue #46: wp_mail() "Cc:" headers were silently
 * dropped by wpMandrill::sendEmail() instead of being passed through
 * to Mandrill as additional 'to' recipients of type 'cc'.
 */
class SEWM_CcHeaderTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'wpmandrill', array(
			'api_key'      => 'test_api_key',
			'from_username' => 'sender@example.com',
			'from_name'    => 'Sender',
		) );
		wpMandrill::$mandrill = null;
	}

	public function tearDown(): void {
		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill = null;

		parent::tearDown();
	}

	/**
	 * Fetches the 'message' args of the last messages/send request made
	 * through the Mandrill API mock.
	 *
	 * @return array
	 */
	private function get_last_sent_message() {
		$request = MandrillAPIMock::get_last_request();
		$this->assertNotNull( $request, 'Expected a request to have been sent to Mandrill.' );

		parse_str( $request['body'], $params );

		return $params['message'];
	}

	public function test_cc_header_as_string_is_added_to_to_array() {
		$headers = "Cc: a@example.com, B Name <b@example.com>";

		$result = wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', $headers );

		$this->assertIsArray( $result );

		$message = $this->get_last_sent_message();

		$this->assertCount( 3, $message['to'] );

		$this->assertSame( 'recipient@example.com', $message['to'][0]['email'] );
		$this->assertArrayNotHasKey( 'type', $message['to'][0] );

		$this->assertSame( 'a@example.com', $message['to'][1]['email'] );
		$this->assertSame( 'cc', $message['to'][1]['type'] );

		$this->assertSame( 'b@example.com', $message['to'][2]['email'] );
		$this->assertSame( 'cc', $message['to'][2]['type'] );
		$this->assertSame( 'B Name', $message['to'][2]['name'] );
	}

	public function test_cc_header_as_array_with_multiple_entries_is_added_to_to_array() {
		$headers = array(
			'From: sender@example.com',
			'Cc: a@example.com',
			'Cc: B Name <b@example.com>',
		);

		wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', $headers );

		$message = $this->get_last_sent_message();

		$this->assertCount( 3, $message['to'] );
		$this->assertSame( 'a@example.com', $message['to'][1]['email'] );
		$this->assertSame( 'cc', $message['to'][1]['type'] );
		$this->assertSame( 'b@example.com', $message['to'][2]['email'] );
		$this->assertSame( 'cc', $message['to'][2]['type'] );
		$this->assertSame( 'B Name', $message['to'][2]['name'] );
	}

	public function test_message_without_cc_header_only_has_to_recipient() {
		wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body' );

		$message = $this->get_last_sent_message();

		$this->assertCount( 1, $message['to'] );
		$this->assertSame( 'recipient@example.com', $message['to'][0]['email'] );
		$this->assertArrayNotHasKey( 'type', $message['to'][0] );
	}

	public function test_bcc_header_still_maps_to_bcc_address_and_is_not_added_to_to_array() {
		$headers = "Bcc: bcc@example.com";

		wpMandrill::mail( 'recipient@example.com', 'Test Subject', 'Test message body', $headers );

		$message = $this->get_last_sent_message();

		$this->assertSame( 'bcc@example.com', $message['bcc_address'] );
		$this->assertCount( 1, $message['to'] );
		$this->assertSame( 'recipient@example.com', $message['to'][0]['email'] );
	}
}
