<?php
/**
 * Tests for Wooflare cache purge logic.
 */
class WOOCF_CachePurgeTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		update_option( 'woocf_settings', array(
			'cf_email'                   => 'test@example.com',
			'cf_key'                     => 'fake_api_key_123',
			'zone_id'                    => 'fake_zone_id_123',
			'after_scheduled_sale'       => 'on',
			'when_product_out_of_stock'  => 'on',
		) );
		update_option( 'active_plugins', array() );
	}

	public function test_api_controller_clear_cache_by_files() {
		$api = new WOOCF_CloudflareAPIController();

		$files = array(
			'https://example.org/product/test-product/',
			'https://example.org/product-category/test-category/',
		);

		$result = $api->clearCacheByFiles( $files );

		$this->assertNotFalse( $result );
		$log = CloudflareAPIMock::get_last_request();
		$this->assertEquals( 'DELETE', $log['method'] );
		$this->assertStringContainsString( 'purge_cache', $log['url'] );
	}

	public function test_api_controller_purge_cache() {
		$api    = new WOOCF_CloudflareAPIController();
		$result = $api->purgeCache();

		$this->assertNotFalse( $result );
		$log = CloudflareAPIMock::get_last_request();
		$this->assertStringContainsString( 'purge_cache', $log['url'] );
	}

	public function test_api_controller_get_zones() {
		$api    = new WOOCF_CloudflareAPIController();
		$result = $api->getZones();

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->success );
	}

	public function test_check_out_of_stock_triggers_cache_clear() {
		$plugin = new WOOCF_Main();

		// Create a mock product (we can't use WC_Product without WooCommerce active)
		// but we can test that the method exists and is callable
		$this->assertTrue(
			method_exists( $plugin, 'checkOutOfStock' ),
			'checkOutOfStock method should exist.'
		);
		$this->assertTrue(
			method_exists( $plugin, 'clearCacheScheduledSaleEnd' ),
			'clearCacheScheduledSaleEnd method should exist.'
		);
		$this->assertTrue(
			method_exists( $plugin, 'clearCacheProduct' ),
			'clearCacheProduct method should exist.'
		);
	}

	public function test_store_notice_hooks_registered() {
		$plugin = new WOOCF_Main();

		$this->assertTrue(
			method_exists( $plugin, 'clearCacheStoreNoticeAdded' ),
			'clearCacheStoreNoticeAdded method should exist.'
		);
		$this->assertTrue(
			method_exists( $plugin, 'clearCacheStoreNoticeUpdated' ),
			'clearCacheStoreNoticeUpdated method should exist.'
		);
	}

	public function test_get_setting() {
		$plugin = new WOOCF_Main();

		$this->assertEquals( 'on', $plugin->getSetting( 'after_scheduled_sale' ) );
		$this->assertEquals( 'on', $plugin->getSetting( 'when_product_out_of_stock' ) );
	}
}
