<?php
/**
 * Tests for WOOCF_CloudflareAPIController.
 */
class WOOCF_APIControllerTest extends PluginTestCase {

	private $api;

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocf_settings', array(
			'cf_email' => 'test@example.com',
			'cf_key'   => 'fake_api_key_123',
			'zone_id'  => 'fake_zone_id_123',
		) );
		update_option( 'active_plugins', array() );

		$this->api = new WOOCF_CloudflareAPIController();
	}

	public function test_request_sends_correct_method() {
		$this->api->request( 'GET', $this->api->url . 'zones' );

		$log = CloudflareAPIMock::get_last_request();
		$this->assertEquals( 'GET', $log['method'] );
	}

	public function test_request_sends_post_with_body() {
		$body = json_encode( array( 'files' => array( 'https://example.org/' ) ) );
		$this->api->request( 'POST', $this->api->url . 'zones/fake_zone_id_123/purge_cache', $body );

		$log = CloudflareAPIMock::get_last_request();
		$this->assertEquals( 'POST', $log['method'] );
		$this->assertEquals( $body, $log['body'] );
	}

	public function test_request_returns_false_without_credentials() {
		update_option( 'woocf_settings', array() );
		$api = new WOOCF_CloudflareAPIController();

		$result = $api->request( 'GET', $api->url . 'zones' );
		$this->assertFalse( $result );
	}

	public function test_get_zone_id_uses_cache() {
		$result = $this->api->getZoneId( 'https://example.org' );

		$this->assertEquals( 'fake_zone_id_123', $result );
		// Should not have made any API calls because zone_id is cached
		$this->assertEquals( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_request_uses_bearer_token_when_api_token_auth() {
		update_option( 'woocf_settings', array(
			'cf_auth_type' => 'api_token',
			'cf_token'     => 'fake_bearer_token_123',
			'zone_id'      => 'fake_zone_id_123',
		) );
		$api = new WOOCF_CloudflareAPIController();

		$api->request( 'GET', $api->url . 'zones' );

		$log = CloudflareAPIMock::get_last_request();
		$this->assertNotNull( $log );
		$this->assertEquals( 'Bearer fake_bearer_token_123', $log['headers']['Authorization'] );
		$this->assertArrayNotHasKey( 'X-Auth-Email', $log['headers'] );
		$this->assertArrayNotHasKey( 'X-Auth-Key', $log['headers'] );
	}

	public function test_request_returns_false_without_token_when_api_token_auth() {
		update_option( 'woocf_settings', array(
			'cf_auth_type' => 'api_token',
			'cf_token'     => '',
			'zone_id'      => 'fake_zone_id_123',
		) );
		$api = new WOOCF_CloudflareAPIController();

		$result = $api->request( 'GET', $api->url . 'zones' );
		$this->assertFalse( $result );
	}
}
