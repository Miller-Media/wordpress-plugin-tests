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

	public function test_evaluate_response_rejected_throws_exception() {
		$response = array(
			array(
				'email'         => 'test@example.com',
				'status'        => 'rejected',
				'reject_reason' => 'soft-bounce',
				'_id'           => 'msg_003',
			),
		);

		$this->expectException( Exception::class );
		wpMandrill::evaluate_response( $response );
	}

	public function test_evaluate_response_hard_bounce_does_not_throw() {
		$response = array(
			array(
				'email'         => 'test@example.com',
				'status'        => 'rejected',
				'reject_reason' => 'hard-bounce',
				'_id'           => 'msg_004',
			),
		);

		// Hard bounces are excluded from the rejection exception,
		// but the status is still not 'sent' or 'queued' so it throws
		// for a different reason.
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
