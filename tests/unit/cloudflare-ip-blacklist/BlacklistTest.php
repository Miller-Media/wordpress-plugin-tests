<?php
/**
 * Tests for IP blacklisting logic in CFIP_Blacklist.
 */
class CFIP_BlacklistTest extends PluginTestCase {

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

	public function test_check_user_login_name_blacklists_banned_username() {
		$plugin = new CFIP_Blacklist();

		// Simulate a remote IP
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';

		$plugin->checkUserLoginName( 'admin' );

		// Should have made API calls
		$this->assertGreaterThan( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_check_user_login_name_ignores_valid_username() {
		$plugin = new CFIP_Blacklist();

		$plugin->checkUserLoginName( 'legitimate_user' );

		// Should not have made any API calls
		$this->assertEquals( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_check_user_login_name_respects_disabled_setting() {
		update_option( 'cfip_settings', array(
			'cf_email'            => 'test@example.com',
			'cf_key'              => 'fake_api_key_123',
			'zone_id'             => 'fake_zone_id_123',
			'enable_ip_blacklist' => '',
			'banned_usernames'    => 'admin',
		) );
		$plugin = new CFIP_Blacklist();

		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$plugin->checkUserLoginName( 'admin' );

		$this->assertEquals( 0, CloudflareAPIMock::get_request_count() );
	}

	public function test_get_user_ip_address_from_remote_addr() {
		$plugin = new CFIP_Blacklist();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		// Clear forwarded headers
		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$ip = $plugin->getUserIPAddress();
		$this->assertEquals( '203.0.113.50', $ip );
	}

	public function test_get_user_ip_address_from_forwarded_for() {
		$plugin = new CFIP_Blacklist();

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.75, 10.0.0.1';
		unset( $_SERVER['HTTP_CLIENT_IP'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$ip = $plugin->getUserIPAddress();
		$this->assertEquals( '203.0.113.75', $ip );
	}

	public function test_get_user_ip_address_skips_private_ranges() {
		$plugin = new CFIP_Blacklist();

		unset( $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '192.168.1.1';

		$ip = $plugin->getUserIPAddress();
		$this->assertFalse( $ip );
	}

	public function test_banned_ips_crud() {
		$plugin = new CFIP_Blacklist();

		// Initially empty
		$this->assertEmpty( $plugin->getBannedIPs() );

		// Update
		$ip_data = array(
			'198.51.100.1' => array(
				'zones' => array(
					'fake_zone_id_123' => array( 'request_id' => 'fake_rule_id_456' ),
				),
			),
		);
		$plugin->updateBannedIPs( $ip_data );

		$banned = $plugin->getBannedIPs();
		$this->assertArrayHasKey( '198.51.100.1', $banned );

		// Remove
		$plugin->removeBannedIP( '198.51.100.1' );
		$banned = $plugin->getBannedIPs();
		$this->assertArrayNotHasKey( '198.51.100.1', $banned );
	}

	public function test_single_site_blacklist_ip() {
		$plugin = new CFIP_Blacklist();

		$result = $plugin->singleSiteBlacklistIP( '198.51.100.1' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'fake_zone_id_123', $result );
	}
}
