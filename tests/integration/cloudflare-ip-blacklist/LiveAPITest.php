<?php
/**
 * Integration tests for Cloudflare IP Blacklist using live API.
 *
 * These tests are skipped unless CLOUDFLARE_API_KEY and
 * CLOUDFLARE_API_EMAIL are set in tests/.env.
 */
class CFIP_LiveAPITest extends IntegrationTestCase {

	protected $required_env_vars = array(
		'CLOUDFLARE_API_KEY',
		'CLOUDFLARE_API_EMAIL',
	);

	public function setUp(): void {
		parent::setUp();

		// Deactivate mocks so real HTTP requests go through
		CloudflareAPIMock::deactivate();

		update_option( 'cfip_settings', array(
			'cf_email' => $this->env( 'CLOUDFLARE_API_EMAIL' ),
			'cf_key'   => $this->env( 'CLOUDFLARE_API_KEY' ),
			'zone_id'  => $this->env( 'CLOUDFLARE_ZONE_ID' ) ?: '',
		) );
		delete_option( 'active_plugins' );
	}

	public function tearDown(): void {
		CloudflareAPIMock::activate();
		parent::tearDown();
	}

	public function test_get_zones_returns_results() {
		$api    = new CFIP_CloudflareAPIController();
		$result = $api->getZones();

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->success );
		$this->assertNotEmpty( $result->result );
	}

	public function test_get_zone_id_resolves() {
		if ( ! $this->env( 'CLOUDFLARE_ZONE_ID' ) ) {
			$this->markTestSkipped( 'CLOUDFLARE_ZONE_ID not set.' );
		}

		$api = new CFIP_CloudflareAPIController();
		$id  = $api->getZoneId( get_site_url() );

		$this->assertNotFalse( $id );
		$this->assertNotEmpty( $id );
	}
}
