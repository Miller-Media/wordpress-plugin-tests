<?php
/**
 * Integration tests for Mandrill using live API.
 *
 * These tests are skipped unless MANDRILL_API_KEY is set in tests/.env.
 */
class SEWM_LiveAPITest extends IntegrationTestCase {

	protected $required_env_vars = array(
		'MANDRILL_API_KEY',
	);

	public function setUp(): void {
		parent::setUp();

		// Deactivate mocks so real HTTP requests go through
		MandrillAPIMock::deactivate();
	}

	public function tearDown(): void {
		MandrillAPIMock::activate();
		parent::tearDown();
	}

	public function test_mandrill_ping() {
		$mandrill = new Mandrill( $this->env( 'MANDRILL_API_KEY' ) );
		$result   = $mandrill->users_ping();

		$this->assertEquals( 'PONG!', $result );
	}

	public function test_mandrill_users_info() {
		$mandrill = new Mandrill( $this->env( 'MANDRILL_API_KEY' ) );
		$result   = $mandrill->users_info();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'username', $result );
	}
}
