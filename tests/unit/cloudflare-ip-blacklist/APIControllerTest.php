<?php
/**
 * Tests for CFIP_CloudflareAPIController.
 */
class CFIP_APIControllerTest extends PluginTestCase {

	private $api;

	public function setUp(): void {
		parent::setUp();

		// Set up valid credentials
		update_option( 'cfip_settings', array(
			'cf_email' => 'test@example.com',
			'cf_key'   => 'fake_api_key_123',
			'zone_id'  => 'fake_zone_id_123',
		) );
		update_option( 'active_plugins', array() );

		$this->api = new CFIP_CloudflareAPIController();
	}

	public function test_request_sends_to_cloudflare() {
		$result = $this->api->request( 'GET', $this->api->url . 'zones' );

		$this->assertNotFalse( $result );
		$log = CloudflareAPIMock::get_request_log();
		$this->assertCount( 1, $log );
		$this->assertStringContainsString( 'zones', $log[0]['url'] );
	}

	public function test_request_includes_auth_headers() {
		$this->api->request( 'GET', $this->api->url . 'zones' );

		$log = CloudflareAPIMock::get_last_request();
		$this->assertNotNull( $log );
		$this->assertEquals( 'test@example.com', $log['headers']['X-Auth-Email'] );
		$this->assertEquals( 'fake_api_key_123', $log['headers']['X-Auth-Key'] );
	}

	public function test_request_returns_false_without_credentials() {
		update_option( 'cfip_settings', array() );
		$api = new CFIP_CloudflareAPIController();

		$result = $api->request( 'GET', $api->url . 'zones' );
		$this->assertFalse( $result );
	}

	public function test_get_zones() {
		$result = $this->api->getZones();

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->success );
		$this->assertNotEmpty( $result->result );
		$this->assertEquals( 'example.org', $result->result[0]->name );
	}

	public function test_blacklist_ip() {
		$result = $this->api->blacklistIP( 'fake_zone_id_123', '198.51.100.1' );

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->success );
		$this->assertEquals( 'block', $result->result->mode );

		// Verify the request was sent correctly
		$log = CloudflareAPIMock::get_last_request();
		$this->assertEquals( 'POST', $log['method'] );
		$this->assertStringContainsString( 'firewall/access_rules/rules', $log['url'] );
	}

	public function test_delete_blacklisted_ip() {
		$result = $this->api->deleteBlacklistedIP( 'fake_zone_id_123', 'fake_rule_id_456' );

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->success );

		$log = CloudflareAPIMock::get_last_request();
		$this->assertEquals( 'DELETE', $log['method'] );
	}

	public function test_get_zone_id_returns_cached_value() {
		$result = $this->api->getZoneId( 'https://example.org' );

		// Should return the cached zone_id from settings without API call
		$this->assertEquals( 'fake_zone_id_123', $result );
		$this->assertEquals( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_get_zone_id_fetches_from_api() {
		// Clear the cached zone_id
		update_option( 'cfip_settings', array(
			'cf_email' => 'test@example.com',
			'cf_key'   => 'fake_api_key_123',
			'zone_id'  => '',
		) );
		$api = new CFIP_CloudflareAPIController();

		$result = $api->getZoneId( 'https://example.org' );

		$this->assertEquals( 'fake_zone_id_123', $result );
		$this->assertGreaterThan( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_get_setting() {
		$this->assertEquals( 'test@example.com', $this->api->getSetting( 'cf_email' ) );
		$this->assertEquals( 'fake_api_key_123', $this->api->getSetting( 'cf_key' ) );
	}
}
