<?php
/**
 * Tests for GitHub issue #43: a non-200 Mandrill API response must not
 * escape admin/stats code paths as an uncaught Mandrill_Exception and
 * fatal wp-admin (dashboard, settings page, login redirect).
 */
class SEWM_ApiErrorHandlingTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		wpMandrill::$mandrill = null;

		update_option( 'wpmandrill', array(
			'api_key'       => 'test_api_key',
			'from_username' => 'sender@example.com',
			'from_name'     => 'Sender',
		) );
	}

	public function tearDown(): void {
		wpMandrill::$mandrill = null;
		delete_option( 'wpmandrill' );

		parent::tearDown();
	}

	/**
	 * users/ping2 (used by the Mandrill constructor) keeps succeeding via
	 * the mock's default fixture; only users/info errors out.
	 */
	private function mock_error_response( $url_pattern ) {
		MandrillAPIMock::set_response(
			$url_pattern,
			array(
				'status'  => 'error',
				'code'    => -1,
				'name'    => 'Unknown',
				'message' => 'boom',
			),
			500
		);
	}

	public function test_get_raw_statistics_returns_empty_array_on_api_error() {
		$this->mock_error_response( '#/users/info$#' );

		$result = wpMandrill::getRawStatistics();

		$this->assertSame( array(), $result );
	}

	public function test_ask_api_key_does_not_fatal_when_users_ping_errors() {
		$this->mock_error_response( '#/users/ping$#' );

		ob_start();
		wpMandrill::askAPIKey();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Invalid API key', $output );
	}
}
