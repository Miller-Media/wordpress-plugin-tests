<?php
/**
 * Tests for wpMandrill::evaluate_response().
 */
class SEWM_EvaluateResponseTest extends PluginTestCase {

	public function test_evaluate_response_sent() {
		$response = array(
			array(
				'email'  => 'test@example.com',
				'status' => 'sent',
				'_id'    => 'msg_001',
			),
		);

		$result = wpMandrill::evaluate_response( $response );
		$this->assertTrue( $result );
	}

	public function test_evaluate_response_queued() {
		$response = array(
			array(
				'email'  => 'test@example.com',
				'status' => 'queued',
				'_id'    => 'msg_002',
			),
		);

		$result = wpMandrill::evaluate_response( $response );
		$this->assertTrue( $result );
	}

	public function test_evaluate_response_soft_bounce_does_not_throw() {
		// 'soft-bounce' is a recipient-level rejection (tied to the suppressed
		// address), so as of the suppressed-recipients fix it must NOT fall
		// back to the native mailer: evaluate_response() reports failure
		// honestly (false) instead of throwing.
		$response = array(
			array(
				'email'         => 'test@example.com',
				'status'        => 'rejected',
				'reject_reason' => 'soft-bounce',
				'_id'           => 'msg_003',
			),
		);

		$result = wpMandrill::evaluate_response( $response );
		$this->assertFalse( $result );
	}

	public function test_evaluate_response_hard_bounce_does_not_throw() {
		// Same reasoning as the soft-bounce case above: 'hard-bounce' is
		// recipient-level, so it must not throw (and therefore must not
		// trigger the native-mailer fallback).
		$response = array(
			array(
				'email'         => 'test@example.com',
				'status'        => 'rejected',
				'reject_reason' => 'hard-bounce',
				'_id'           => 'msg_004',
			),
		);

		$result = wpMandrill::evaluate_response( $response );
		$this->assertFalse( $result );
	}

	public function test_evaluate_response_invalid_sender_throws_exception() {
		// 'invalid-sender' is a message/account-level failure (not tied to
		// the recipient's address), so it must still throw so that
		// send_mail() falls back to the native mailer.
		$response = array(
			array(
				'email'         => 'test@example.com',
				'status'        => 'rejected',
				'reject_reason' => 'invalid-sender',
				'_id'           => 'msg_006',
			),
		);

		$this->expectException( Exception::class );
		wpMandrill::evaluate_response( $response );
	}

	public function test_evaluate_response_wp_error_throws_exception() {
		$response = new WP_Error( 'http_error', 'Connection failed' );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Connection failed' );
		wpMandrill::evaluate_response( $response );
	}

	public function test_evaluate_response_missing_status_throws_exception() {
		$response = array(
			array(
				'email' => 'test@example.com',
			),
		);

		$this->expectException( Exception::class );
		wpMandrill::evaluate_response( $response );
	}

	public function test_evaluate_response_fires_action() {
		$action_fired = false;
		add_action( 'mandrill_response_received', function () use ( &$action_fired ) {
			$action_fired = true;
		} );

		$response = array(
			array(
				'email'  => 'test@example.com',
				'status' => 'sent',
				'_id'    => 'msg_005',
			),
		);

		wpMandrill::evaluate_response( $response );
		$this->assertTrue( $action_fired );
	}
}
