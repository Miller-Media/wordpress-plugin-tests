<?php
/**
 * Tests for the WordPress.org support thread "Mandrill wp_mail filter
 * should use message instead of html": wpMandrill::mail() must expose the
 * email body to 'wp_mail' filters under the 'message' key (as WordPress
 * core does), while still honoring the deprecated 'html' key for existing
 * callbacks, and must not throw on PHP 8 if a filter returns a non-array.
 */
class SEWM_WpMailFilterTest extends PluginTestCase {

	/**
	 * Custom 'wp_mail' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $wp_mail_filter;

	public function setUp(): void {
		parent::setUp();

		update_option( 'wpmandrill', array(
			'api_key'       => 'test_api_key',
			'from_username' => 'sender@example.com',
			'from_name'     => 'Sender',
			'nl2br'         => 0,
		) );
		wpMandrill::$mandrill = null;
	}

	public function tearDown(): void {
		if ( null !== $this->wp_mail_filter ) {
			remove_filter( 'wp_mail', $this->wp_mail_filter );
			$this->wp_mail_filter = null;
		}

		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill = null;

		parent::tearDown();
	}

	/**
	 * @return array Parsed 'message' field sent in the last Mandrill request.
	 */
	private function get_last_sent_message() {
		$request = MandrillAPIMock::get_last_request();
		$this->assertNotNull( $request, 'No request was intercepted by the Mandrill API mock.' );

		parse_str( $request['body'], $parsed_body );

		return $parsed_body['message'];
	}

	public function test_filter_receives_body_under_message_key() {
		$body           = 'Original body content';
		$received_args  = null;

		$this->wp_mail_filter = function ( $args ) use ( &$received_args ) {
			$received_args = $args;
			return $args;
		};
		add_filter( 'wp_mail', $this->wp_mail_filter );

		wpMandrill::mail( 'to@example.com', 'Test Subject', $body );

		$this->assertIsArray( $received_args );
		$this->assertSame( $body, $received_args['message'] );
	}

	public function test_filter_changing_message_changes_sent_html() {
		$original_body = 'Original body content';
		$filtered_body = 'Filtered via message key';

		$this->wp_mail_filter = function ( $args ) use ( $filtered_body ) {
			$args['message'] = $filtered_body;
			return $args;
		};
		add_filter( 'wp_mail', $this->wp_mail_filter );

		wpMandrill::mail( 'to@example.com', 'Test Subject', $original_body );

		$sent_message = $this->get_last_sent_message();
		$this->assertSame( $filtered_body, $sent_message['html'] );
	}

	public function test_legacy_filter_changing_html_changes_sent_html() {
		$original_body = 'Original body content';
		$filtered_body = 'Filtered via legacy html key';

		$this->wp_mail_filter = function ( $args ) use ( $filtered_body ) {
			$args['html'] = $filtered_body;
			return $args;
		};
		add_filter( 'wp_mail', $this->wp_mail_filter );

		wpMandrill::mail( 'to@example.com', 'Test Subject', $original_body );

		$sent_message = $this->get_last_sent_message();
		$this->assertSame( $filtered_body, $sent_message['html'] );
	}

	public function test_filter_returning_non_array_does_not_throw_and_keeps_original_body() {
		$original_body = 'Original body content';

		$this->wp_mail_filter = function ( $args ) {
			return 'not an array';
		};
		add_filter( 'wp_mail', $this->wp_mail_filter );

		$response = wpMandrill::mail( 'to@example.com', 'Test Subject', $original_body );

		$this->assertIsArray( $response );
		$this->assertSame( 'sent', $response[0]['status'] );

		$sent_message = $this->get_last_sent_message();
		$this->assertSame( $original_body, $sent_message['html'] );
	}

	public function test_filter_can_change_to_and_subject() {
		$this->wp_mail_filter = function ( $args ) {
			$args['to']      = 'changed@example.com';
			$args['subject'] = 'Changed Subject';
			return $args;
		};
		add_filter( 'wp_mail', $this->wp_mail_filter );

		wpMandrill::mail( 'to@example.com', 'Test Subject', 'Body' );

		$sent_message = $this->get_last_sent_message();
		$this->assertSame( 'Changed Subject', $sent_message['subject'] );

		$found = false;
		foreach ( $sent_message['to'] as $recipient ) {
			if ( isset( $recipient['email'] ) && 'changed@example.com' === $recipient['email'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Filtered "to" address was not used for the sent message.' );
	}
}
