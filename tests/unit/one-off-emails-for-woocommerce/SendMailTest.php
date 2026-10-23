<?php
/**
 * Tests for WooOneOffEmails::sendMail() / previewMail() — the plugin's
 * only real behavior, built entirely on top of WooCommerce's mailer.
 */
class WOOE_SendMailTest extends PluginTestCase {

	/**
	 * @var array Captured wp_mail() arguments.
	 */
	private $captured;

	public function setUp(): void {
		parent::setUp();
		$this->captured = null;

		add_filter( 'wp_mail', array( $this, 'capture_wp_mail' ) );
	}

	public function tearDown(): void {
		remove_filter( 'wp_mail', array( $this, 'capture_wp_mail' ) );
		parent::tearDown();
	}

	public function capture_wp_mail( $args ) {
		$this->captured = $args;
		return $args;
	}

	public function test_send_mail_dispatches_wp_mail_with_expected_content() {
		$plugin = new WooOneOffEmails();

		$result = $plugin->sendMail(
			'customer@example.com',
			'',
			'',
			'Test Subject',
			'Test Heading',
			'Hello from the test suite.'
		);

		$this->assertTrue( $result );
		$this->assertNotNull( $this->captured, 'wp_mail() should have been called.' );
		$this->assertEquals( 'customer@example.com', $this->captured['to'] );
		$this->assertEquals( 'Test Subject', $this->captured['subject'] );
		$this->assertStringContainsString( 'Hello from the test suite.', $this->captured['message'] );
	}

	public function test_send_mail_uses_reply_to_email_header() {
		$plugin = new WooOneOffEmails();

		$plugin->sendMail(
			'customer@example.com',
			'Jane Support',
			'jane@example.com',
			'Subject',
			'Heading',
			'Body content'
		);

		$this->assertNotNull( $this->captured );
		$this->assertNotEmpty( $this->captured['headers'] );

		$headers = is_array( $this->captured['headers'] ) ? implode( ' ', $this->captured['headers'] ) : $this->captured['headers'];
		$this->assertStringContainsString( 'jane@example.com', $headers );
	}

	public function test_preview_mail_wraps_message_with_woocommerce_template() {
		$plugin = new WooOneOffEmails();

		$preview = $plugin->previewMail( 'Preview Heading', 'Preview body text.' );

		$this->assertIsString( $preview );
		$this->assertStringContainsString( 'Preview body text.', $preview );
	}
}
