<?php
/**
 * Tests for Mandrill::http_request(): TLS verification must stay enabled,
 * sending endpoints get a longer timeout than read-only endpoints, and site
 * owners can still override the computed args via the
 * 'mandrill_http_request_args' filter.
 */
class SEWM_HttpRequestArgsTest extends PluginTestCase {

	/**
	 * Captured ( 'url' => ..., 'args' => ... ) pairs for every intercepted
	 * mandrillapp.com request, in request order.
	 *
	 * @var array
	 */
	private $captured_requests = array();

	/**
	 * Custom 'mandrill_http_request_args' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $args_filter_handler;

	public function setUp(): void {
		parent::setUp();

		$this->captured_requests = array();

		// Priority 1 so this runs before MandrillAPIMock (priority 10) and
		// can inspect the real $args without short-circuiting the mock.
		add_filter( 'pre_http_request', array( $this, 'capture_request_args' ), 1, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_request_args' ), 1 );

		if ( null !== $this->args_filter_handler ) {
			remove_filter( 'mandrill_http_request_args', $this->args_filter_handler );
			$this->args_filter_handler = null;
		}

		parent::tearDown();
	}

	/**
	 * Records the $args passed down to wp_remote_request() for mandrillapp.com
	 * requests, then lets the request continue unchanged so MandrillAPIMock
	 * still answers it.
	 */
	public function capture_request_args( $preempt, $args, $url ) {
		if ( false !== strpos( $url, 'mandrillapp.com' ) ) {
			$this->captured_requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
		}

		return $preempt;
	}

	public function test_ping_uses_short_timeout_and_sslverify_true() {
		new Mandrill( 'test_api_key' );

		$this->assertNotEmpty( $this->captured_requests );
		$ping_request = $this->captured_requests[0];

		$this->assertStringContainsString( 'users/ping2', $ping_request['url'] );
		$this->assertTrue( $ping_request['args']['sslverify'] );
		$this->assertSame( 15, $ping_request['args']['timeout'] );
	}

	public function test_messages_send_uses_long_timeout_and_sslverify_true() {
		$mandrill = new Mandrill( 'test_api_key' );
		$this->captured_requests = array();

		$mandrill->messages_send(
			array(
				'to'      => array( array( 'email' => 'recipient@example.com' ) ),
				'subject' => 'Test Subject',
				'html'    => '<p>Test message body</p>',
				'async'   => false,
				'ip_pool' => null,
				'send_at' => null,
			)
		);

		$this->assertNotEmpty( $this->captured_requests );
		$send_request = $this->captured_requests[0];

		$this->assertStringContainsString( '/messages/send', $send_request['url'] );
		$this->assertTrue( $send_request['args']['sslverify'] );
		$this->assertSame( 120, $send_request['args']['timeout'] );
	}

	public function test_mandrill_http_request_args_filter_can_override_timeout_and_sslverify() {
		$seen_url                  = null;
		$this->args_filter_handler = function ( $args, $url ) use ( &$seen_url ) {
			$seen_url          = $url;
			$args['timeout']   = 5;
			$args['sslverify'] = false;
			return $args;
		};
		add_filter( 'mandrill_http_request_args', $this->args_filter_handler, 10, 2 );

		new Mandrill( 'test_api_key' );

		$this->assertNotEmpty( $this->captured_requests );
		$ping_request = $this->captured_requests[0];

		$this->assertStringContainsString( 'users/ping2', $seen_url );
		$this->assertFalse( $ping_request['args']['sslverify'] );
		$this->assertSame( 5, $ping_request['args']['timeout'] );
	}
}
