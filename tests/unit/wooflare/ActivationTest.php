<?php
/**
 * Tests for Wooflare plugin activation and initialization.
 */
class WOOCF_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'WOOCF_Main' ), 'WOOCF_Main class should exist.' );
		$this->assertTrue( class_exists( 'WOOCF_CloudflareAPIController' ), 'WOOCF_CloudflareAPIController class should exist.' );
		$this->assertTrue( class_exists( 'WOOCF_SiteSettings' ), 'WOOCF_SiteSettings class should exist.' );
		$this->assertTrue( class_exists( 'WOOCF_Helpers' ), 'WOOCF_Helpers class should exist.' );
	}

	public function test_constants_defined() {
		$this->assertTrue( defined( 'WOOCF_PLUGIN_VERSION' ) );
		$this->assertEquals( $this->plugin_version( 'wooflare' ), WOOCF_PLUGIN_VERSION );
	}

	public function test_helpers_parse_base_domain() {
		$helpers = new WOOCF_Helpers();
		$result  = $helpers->parseBaseDomainFromURL( 'https://shop.example.com/product/test' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'domain', $result );
	}
}
