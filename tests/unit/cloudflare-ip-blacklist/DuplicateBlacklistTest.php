<?php
/**
 * Regression tests for GitHub issue #7: avoid re-requesting a Cloudflare
 * block for an IP that's already recorded as blacklisted, and make sure a
 * 10009 (duplicate_of_existing) response from Cloudflare is treated as
 * "already blocked" rather than a failure that could clobber stored data.
 */
class CFIP_DuplicateBlacklistTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'cfip_settings', array(
			'cf_email'            => 'test@example.com',
			'cf_key'              => 'fake_api_key_123',
			'zone_id'             => 'fake_zone_id_123',
			'enable_ip_blacklist' => 'on',
			'banned_usernames'    => 'admin, root, test',
			'enable_logging'      => '',
		) );
		update_option( 'active_plugins', array() );
		delete_site_option( 'cfip_banned_addresses' );
	}

	/**
	 * Count only the requests that hit the firewall access-rules (blacklist) endpoint.
	 */
	private function count_access_rule_requests() {
		$count = 0;
		foreach ( CloudflareAPIMock::get_request_log() as $request ) {
			if ( 'POST' === $request['method'] && preg_match( '#/firewall/access_rules/rules$#', $request['url'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	public function test_first_block_makes_request_and_stores_rule_id() {
		$plugin = new CFIP_Blacklist();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

		$plugin->blacklistIPAddress();

		$this->assertEquals( 1, $this->count_access_rule_requests() );

		$banned = $plugin->getBannedIPs();
		$this->assertArrayHasKey( '198.51.100.1', $banned );
		$this->assertEquals(
			'fake_rule_id_456',
			$banned['198.51.100.1']['zones']['fake_zone_id_123']['request_id']
		);
	}

	public function test_second_attempt_for_already_stored_ip_skips_api_call() {
		$plugin = new CFIP_Blacklist();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

		// First attempt: IP isn't stored yet, so it hits the API and gets stored.
		$plugin->blacklistIPAddress();
		$this->assertEquals( 1, $this->count_access_rule_requests() );

		// Second attempt for the same IP: already recorded as blocked for this
		// zone, so no request should be made at all (not even a duplicate one).
		$plugin->blacklistIPAddress();
		$this->assertEquals( 1, $this->count_access_rule_requests() );

		$banned = $plugin->getBannedIPs();
		$this->assertEquals(
			'fake_rule_id_456',
			$banned['198.51.100.1']['zones']['fake_zone_id_123']['request_id']
		);
	}

	public function test_duplicate_error_response_preserves_other_stored_ips() {
		$plugin = new CFIP_Blacklist();

		// Seed an existing banned IP unrelated to the one we're about to block.
		$plugin->updateBannedIPs( array(
			'203.0.113.9' => array(
				'zones' => array(
					'fake_zone_id_123' => array( 'request_id' => 'other_rule_id' ),
				),
			),
		) );

		// Cloudflare already has a rule for the IP we're about to try (not yet
		// reflected in our local store) and responds with a duplicate error.
		CloudflareAPIMock::set_response(
			'#/firewall/access_rules/rules$#',
			array(
				'success' => false,
				'errors'  => array(
					array( 'code' => 10009, 'message' => 'firewallaccessrules.api.duplicate_of_existing' ),
				),
				'result'  => null,
			)
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$plugin->blacklistIPAddress();

		$this->assertEquals( 1, $this->count_access_rule_requests() );

		$banned = $plugin->getBannedIPs();
		$this->assertArrayHasKey( '203.0.113.9', $banned );
		$this->assertEquals(
			'other_rule_id',
			$banned['203.0.113.9']['zones']['fake_zone_id_123']['request_id']
		);
	}

	public function test_update_banned_ips_merges_zones_instead_of_clobbering() {
		$plugin = new CFIP_Blacklist();

		$plugin->updateBannedIPs( array(
			'198.51.100.1' => array(
				'zones' => array(
					'zone_a' => array( 'request_id' => 'rule_a' ),
				),
			),
		) );

		// A later update covering a different zone for the same IP shouldn't
		// drop the previously recorded zone.
		$plugin->updateBannedIPs( array(
			'198.51.100.1' => array(
				'zones' => array(
					'zone_b' => array( 'request_id' => 'rule_b' ),
				),
			),
		) );

		$banned = $plugin->getBannedIPs();
		$this->assertEquals( 'rule_a', $banned['198.51.100.1']['zones']['zone_a']['request_id'] );
		$this->assertEquals( 'rule_b', $banned['198.51.100.1']['zones']['zone_b']['request_id'] );
	}
}
