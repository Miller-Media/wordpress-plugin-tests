<?php
/**
 * Tests for the Mandrill API wrapper class.
 */
class SEWM_MandrillClassTest extends PluginTestCase {

	public function test_mandrill_constructor_validates_api_key() {
		// The mock returns a valid ping response
		$mandrill = new Mandrill( 'test_api_key' );
		$this->assertEquals( 'test_api_key', $mandrill->api );
	}

	public function test_mandrill_constructor_rejects_empty_key() {
		$this->expectException( Mandrill_Exception::class );
		new Mandrill( '' );
	}

	public function test_mandrill_request_sends_to_api() {
		$mandrill = new Mandrill( 'test_api_key' );

		$log = MandrillAPIMock::get_request_log();
		// Constructor sends ping2
		$found_ping = false;
		foreach ( $log as $entry ) {
			if ( strpos( $entry['url'], 'users/ping2' ) !== false ) {
				$found_ping = true;
				break;
			}
		}
		$this->assertTrue( $found_ping, 'Constructor should ping the API.' );
	}

	public function test_mandrill_api_version() {
		$this->assertEquals( '1.0', Mandrill::API_VERSION );
	}

	public function test_mandrill_endpoint() {
		$this->assertEquals( 'https://mandrillapp.com/api/', Mandrill::END_POINT );
	}

	public function test_get_attachment_struct_validates_content_type() {
		// isValidContentType always returns true in current implementation
		$this->assertTrue( Mandrill::isValidContentType( 'application/pdf' ) );
		$this->assertTrue( Mandrill::isValidContentType( 'text/plain' ) );
		$this->assertTrue( Mandrill::isValidContentType( 'anything' ) );
	}
}
