<?php
/**
 * Tests for the fix to "Users who've unsubscribed still get emails (in a
 * roundabout way)": a recipient-level rejection (e.g. 'unsub', 'spam',
 * 'hard-bounce', or an 'invalid' recipient) must not trigger the native
 * WordPress mailer fallback, since that would bypass Mandrill's rejection
 * list. Message/account-level failures (e.g. 'invalid-sender') must still
 * fall back, but with any recipient-level-rejected addresses excluded from
 * that fallback so they aren't re-sent to outside of Mandrill.
 */
class SEWM_SuppressedRecipientsTest extends PluginTestCase {

	/**
	 * Custom 'wp_mail_native' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $custom_native_handler;

	/**
	 * Custom 'wp_mail_failed' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $wp_mail_failed_handler;

	public function setUp(): void {
		parent::setUp();

		update_option( 'wpmandrill', array(
			'api_key'       => 'test_api_key',
			'from_username' => 'sender@example.com',
			'from_name'     => 'Sender',
		) );

		wpMandrill::$mandrill              = null;
		wpMandrill::$native_mail_result    = null;
		wpMandrill::$suppressed_recipients = array();
		wpMandrill::$last_rejections       = array();
	}

	public function tearDown(): void {
		// Restore the default native handler to a known, single registration.
		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		if ( null !== $this->custom_native_handler ) {
			remove_action( 'wp_mail_native', $this->custom_native_handler, 10 );
			$this->custom_native_handler = null;
		}
		add_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10, 5 );

		if ( null !== $this->wp_mail_failed_handler ) {
			remove_action( 'wp_mail_failed', $this->wp_mail_failed_handler, 10 );
			$this->wp_mail_failed_handler = null;
		}

		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill              = null;
		wpMandrill::$native_mail_result    = null;
		wpMandrill::$suppressed_recipients = array();
		wpMandrill::$last_rejections       = array();

		parent::tearDown();
	}

	/******************************************************************
	 **  evaluate_response()
	 *******************************************************************/

	public function test_evaluate_response_unsub_returns_false() {
		$response = array(
			array( 'email' => 'unsub@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
		);

		$this->assertFalse( wpMandrill::evaluate_response( $response ) );
	}

	public function test_evaluate_response_spam_and_hard_bounce_returns_false() {
		$response = array(
			array( 'email' => 'spam@example.com', 'status' => 'rejected', 'reject_reason' => 'spam' ),
			array( 'email' => 'bounced@example.com', 'status' => 'rejected', 'reject_reason' => 'hard-bounce' ),
		);

		$this->assertFalse( wpMandrill::evaluate_response( $response ) );
	}

	public function test_evaluate_response_rejected_unsub_and_sent_returns_true() {
		$response = array(
			array( 'email' => 'unsub@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
			array( 'email' => 'ok@example.com', 'status' => 'sent' ),
		);

		$this->assertTrue( wpMandrill::evaluate_response( $response ) );
	}

	public function test_evaluate_response_rejected_invalid_sender_throws() {
		$response = array(
			array( 'email' => 'test@example.com', 'status' => 'rejected', 'reject_reason' => 'invalid-sender' ),
		);

		$this->expectException( Exception::class );
		wpMandrill::evaluate_response( $response );
	}

	public function test_evaluate_response_status_invalid_returns_false() {
		$response = array(
			array( 'email' => 'bad-address@example.com', 'status' => 'invalid' ),
		);

		$this->assertFalse( wpMandrill::evaluate_response( $response ) );
	}

	public function test_evaluate_response_rejected_unsub_and_invalid_sender_throws_and_records_suppressed() {
		$response = array(
			array( 'email' => 'unsub@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
			array( 'email' => 'badsender@example.com', 'status' => 'rejected', 'reject_reason' => 'invalid-sender' ),
		);

		try {
			wpMandrill::evaluate_response( $response );
			$this->fail( 'Expected an Exception to be thrown.' );
		} catch ( Exception $e ) {
			// Expected: a message/account-level failure is present, so the
			// usual fallback-triggering exception is still thrown.
		}

		$this->assertContains( 'unsub@example.com', wpMandrill::$suppressed_recipients );
	}

	/******************************************************************
	 **  send_mail() fallback
	 *******************************************************************/

	public function test_send_mail_all_unsub_returns_false_and_skips_native_fallback() {
		MandrillAPIMock::set_response( '#messages/send#', array(
			array( 'email' => 'a@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
		) );

		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		$native_called                = false;
		$this->custom_native_handler  = function () use ( &$native_called ) {
			$native_called = true;
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$result = wpMandrill::send_mail( 'a@example.com', 'Subject', 'Message body' );

		$this->assertFalse( $result );
		$this->assertIsBool( $result );
		$this->assertFalse( $native_called, 'Native mailer must not be used when every recipient was suppressed by Mandrill.' );
	}

	public function test_send_mail_fallback_excludes_suppressed_recipients() {
		MandrillAPIMock::set_response( '#messages/send#', array(
			array( 'email' => 'a@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
			array( 'email' => 'b@example.com', 'status' => 'rejected', 'reject_reason' => 'invalid-sender' ),
		) );

		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		$received_to                 = null;
		$this->custom_native_handler  = function ( $to ) use ( &$received_to ) {
			$received_to = $to;
			wpMandrill::$native_mail_result = true;
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$result = wpMandrill::send_mail( 'a@example.com, b@example.com', 'Subject', 'Message body' );

		$this->assertTrue( $result );
		$this->assertNotNull( $received_to, 'Native mailer should still run for the message-level failure.' );
		$this->assertStringNotContainsString( 'a@example.com', $received_to, 'The suppressed recipient must be excluded from the native fallback.' );
		$this->assertStringContainsString( 'b@example.com', $received_to );
	}

	public function test_send_mail_all_accepted_returns_true_and_skips_native_fallback() {
		MandrillAPIMock::set_response( '#messages/send#', array(
			array( 'email' => 'a@example.com', 'status' => 'sent' ),
			array( 'email' => 'b@example.com', 'status' => 'queued' ),
		) );

		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		$native_called                = false;
		$this->custom_native_handler  = function () use ( &$native_called ) {
			$native_called = true;
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$result = wpMandrill::send_mail( 'a@example.com, b@example.com', 'Subject', 'Message body' );

		$this->assertTrue( $result );
		$this->assertFalse( $native_called, 'Native mailer must not be used when Mandrill accepted the message.' );
	}

	/******************************************************************
	 **  send_mail() wp_mail_failed
	 *******************************************************************/

	public function test_send_mail_all_rejected_fires_wp_mail_failed_with_core_shape() {
		MandrillAPIMock::set_response( '#messages/send#', array(
			array( 'email' => 'a@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
			array( 'email' => 'b@example.com', 'status' => 'rejected', 'reject_reason' => 'spam' ),
		) );

		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		$native_called                = false;
		$this->custom_native_handler  = function () use ( &$native_called ) {
			$native_called = true;
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$fired_count   = 0;
		$captured_error = null;
		$this->wp_mail_failed_handler = function ( $error ) use ( &$fired_count, &$captured_error ) {
			$fired_count++;
			$captured_error = $error;
		};
		add_action( 'wp_mail_failed', $this->wp_mail_failed_handler );

		$headers     = 'Content-Type: text/plain';
		$attachments = array();
		$result      = wpMandrill::send_mail( 'a@example.com, b@example.com', 'Subject', 'Message body', $headers, $attachments );

		$this->assertFalse( $result );
		$this->assertFalse( $native_called, 'Native mailer must not be used when every recipient was suppressed by Mandrill.' );

		$this->assertSame( 1, $fired_count, 'wp_mail_failed must fire exactly once.' );
		$this->assertInstanceOf( WP_Error::class, $captured_error );
		$this->assertSame( 'wp_mail_failed', $captured_error->get_error_code() );

		$message = $captured_error->get_error_message();
		$this->assertStringContainsString( 'a@example.com', $message );
		$this->assertStringContainsString( 'unsub', $message );
		$this->assertStringContainsString( 'b@example.com', $message );
		$this->assertStringContainsString( 'spam', $message );

		$data = $captured_error->get_error_data();
		$this->assertSame( 'a@example.com, b@example.com', $data['to'] );
		$this->assertSame( 'Subject', $data['subject'] );
		$this->assertSame( 'Message body', $data['message'] );
		$this->assertSame( $headers, $data['headers'] );
		$this->assertSame( $attachments, $data['attachments'] );

		$this->assertCount( 2, $data['rejections'] );
		$this->assertSame( 'a@example.com', $data['rejections'][0]['email'] );
		$this->assertSame( 'unsub', $data['rejections'][0]['reject_reason'] );
		$this->assertSame( 'b@example.com', $data['rejections'][1]['email'] );
		$this->assertSame( 'spam', $data['rejections'][1]['reject_reason'] );
	}

	public function test_send_mail_accepted_does_not_fire_wp_mail_failed() {
		MandrillAPIMock::set_response( '#messages/send#', array(
			array( 'email' => 'a@example.com', 'status' => 'rejected', 'reject_reason' => 'unsub' ),
			array( 'email' => 'b@example.com', 'status' => 'sent' ),
		) );

		$fired_count = 0;
		$this->wp_mail_failed_handler = function () use ( &$fired_count ) {
			$fired_count++;
		};
		add_action( 'wp_mail_failed', $this->wp_mail_failed_handler );

		$result = wpMandrill::send_mail( 'a@example.com, b@example.com', 'Subject', 'Message body' );

		$this->assertTrue( $result );
		$this->assertSame( 0, $fired_count, 'wp_mail_failed must not fire when at least one recipient was accepted.' );
	}
}
