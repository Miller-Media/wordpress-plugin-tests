<?php
/**
 * Tests for WooOneOffEmails::ajaxSendEmail() — the wp_ajax_wooe_sendemail handler.
 */
class WOOE_AjaxSendEmailTest extends WP_Ajax_UnitTestCase {

	/**
	 * @var array|null Captured wp_mail() args, via the pre_wp_mail short-circuit.
	 */
	private $captured;

	public function setUp(): void {
		parent::setUp();
		$this->captured = null;

		add_filter( 'pre_wp_mail', array( $this, 'capture_pre_wp_mail' ), 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_pre_wp_mail' ) );
		parent::tearDown();
	}

	public function capture_pre_wp_mail( $pre, $atts ) {
		$this->captured = $atts;
		return true;
	}

	/**
	 * Submits the wooe_sendemail Ajax action with the given $_POST['data']
	 * payload, and returns the decoded JSON response body regardless of
	 * whether the handler died via wp_die() directly (no prior output) or
	 * via wp_send_json_error() (which echoes before calling wp_die()).
	 *
	 * @param array $data Payload for $_POST['data'].
	 * @return array
	 */
	private function send_email_ajax( $data ) {
		$_POST['data'] = $data;

		try {
			$this->_handleAjax( 'wooe_sendemail' );
			$this->fail( 'Expected the Ajax handler to terminate via wp_die().' );
		} catch ( WPAjaxDieStopException $e ) {
			$raw = $e->getMessage();
		} catch ( WPAjaxDieContinueException $e ) {
			$raw = $this->_last_response;
		}

		$response = json_decode( $raw, true );
		$this->assertIsArray( $response, 'Ajax response should be valid JSON: ' . $raw );

		return $response;
	}

	public function test_non_admin_with_valid_nonce_is_rejected() {
		new WooOneOffEmails();

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$_POST['nonce'] = wp_create_nonce( 'ajax_nonce' );

		$data = array(
			'to'      => 'customer@example.com',
			'subject' => 'Subject',
			'heading' => 'Heading',
			'message' => 'Message body',
		);

		$response = $this->send_email_ajax( $data );

		$this->assertArrayHasKey( 'success', $response );
		$this->assertFalse( $response['success'] );
		$this->assertNull( $this->captured, 'No mail should be sent for a rejected request.' );
	}

	public function test_admin_without_reply_to_fields_succeeds_with_no_warnings() {
		new WooOneOffEmails();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_POST['nonce'] = wp_create_nonce( 'ajax_nonce' );

		$warnings      = array();
		$error_handler = function ( $errno, $errstr ) use ( &$warnings ) {
			// WP_Ajax_UnitTestCase's own _handleAjax() fires do_action( 'admin_init' ),
			// which runs core's wp_admin_headers()/send_frame_options_header() — real
			// header() calls that always warn "headers already sent" in this harness
			// (bootstrap.php has already echoed to stdout before any output buffering
			// starts). That's environment noise unrelated to the plugin, which is why
			// WP_Ajax_UnitTestCase::set_up() itself disables E_WARNING reporting around
			// Ajax calls — filter it out here too so it doesn't mask real warnings.
			if ( ( $errno & ( E_WARNING | E_NOTICE ) ) && false === strpos( $errstr, 'headers already sent' ) ) {
				$warnings[] = $errstr;
			}
			return true;
		};

		$data = array(
			'to'      => 'customer@example.com',
			'subject' => 'Subject',
			'heading' => 'Heading',
			'message' => 'Message body',
		);

		set_error_handler( $error_handler );
		try {
			$response = $this->send_email_ajax( $data );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $warnings, 'No PHP warnings/notices should be raised: ' . implode( '; ', $warnings ) );

		$this->assertArrayHasKey( 'success', $response );
		$this->assertTrue( $response['success'] );
		$this->assertSame( '', $response['reply_to_name'] );
		$this->assertSame( '', $response['reply_to_email'] );
		$this->assertNotNull( $this->captured, 'wp_mail() should have been invoked.' );
	}

	public function test_admin_with_full_payload_succeeds_and_sets_reply_to_header() {
		new WooOneOffEmails();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_POST['nonce'] = wp_create_nonce( 'ajax_nonce' );

		$data = array(
			'to'             => 'customer@example.com',
			'subject'        => 'Subject',
			'heading'        => 'Heading',
			'message'        => 'Message body',
			'reply_to_name'  => 'Jane Support',
			'reply_to_email' => 'jane@example.com',
		);

		$response = $this->send_email_ajax( $data );

		$this->assertArrayHasKey( 'success', $response );
		$this->assertTrue( $response['success'] );
		$this->assertNotNull( $this->captured );
		$this->assertNotEmpty( $this->captured['headers'] );

		$headers = is_array( $this->captured['headers'] ) ? implode( ' ', $this->captured['headers'] ) : $this->captured['headers'];
		$this->assertStringContainsString( 'jane@example.com', $headers );
	}
}
