<?php
/**
 * Tests for GitHub issues #82 and #84: the "WooCommerce Fix" setting must
 * skip the nl2br transformation for WooCommerce-sent emails on every
 * request (not just wp-admin), and must only affect the WooCommerce email
 * itself rather than every email sent during the same request.
 */

class SEWM_WooCommerceNl2brTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill                    = null;
		wpMandrill::$woocommerce_email_subject   = null;

		// WooCommerce isn't loaded in the test environment; wpMandrill gates
		// the setting on isWooCommerceActive(), so simulate it being active.
		add_filter( 'wpmandrill_is_woocommerce_active', '__return_true' );
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_mail_callback_params', array( 'wpMandrill', 'markWooCommerceEmail' ), PHP_INT_MAX );
		remove_action( 'woocommerce_email_sent', array( 'wpMandrill', 'clearWooCommerceEmail' ), 10 );
		remove_filter( 'mandrill_nl2br', array( 'wpMandrill', 'disableNl2brForWooCommerce' ), 10 );
		remove_all_actions( 'woocommerce_email' );
		remove_filter( 'wpmandrill_is_woocommerce_active', '__return_true' );

		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill                  = null;
		wpMandrill::$woocommerce_email_subject = null;

		parent::tearDown();
	}

	/**
	 * Configures the plugin options and (re-)registers the WooCommerce fix,
	 * the way wpMandrill::on_load() would on every request.
	 *
	 * @param bool $nl2br_woocommerce Value of the 'WooCommerce Fix' setting.
	 */
	private function configure( $nl2br_woocommerce ) {
		update_option( 'wpmandrill', array(
			'api_key'           => 'test_api_key',
			'from_username'     => 'sender@example.com',
			'from_name'         => 'Sender',
			'nl2br'             => 1,
			'nl2br_woocommerce' => $nl2br_woocommerce,
		) );

		wpMandrill::registerWooCommerceFix();
	}

	/**
	 * @return string The 'html' field submitted in the last messages/send request.
	 */
	private function get_last_sent_html() {
		$last = MandrillAPIMock::get_last_request();
		parse_str( $last['body'], $parsed );

		return $parsed['message']['html'];
	}

	public function test_woocommerce_email_is_not_nl2bred() {
		$this->configure( 1 );

		$subject = 'Your order is complete';
		apply_filters( 'woocommerce_mail_callback_params', array( 'to@example.com', $subject, "line1\nline2", '', array() ), null );

		wpMandrill::mail( 'to@example.com', $subject, "line1\nline2" );

		$this->assertStringNotContainsString( '<br', $this->get_last_sent_html() );
	}

	public function test_non_woocommerce_email_after_woocommerce_email_is_still_nl2bred() {
		$this->configure( 1 );

		$subject = 'Your order is complete';
		apply_filters( 'woocommerce_mail_callback_params', array( 'to@example.com', $subject, "line1\nline2", '', array() ), null );
		wpMandrill::mail( 'to@example.com', $subject, "line1\nline2" );

		// Don't fire the real action: WooCommerce's own listener on
		// 'woocommerce_email_sent' requires a real WC_Email argument.
		$this->assertNotFalse( has_action( 'woocommerce_email_sent', array( 'wpMandrill', 'clearWooCommerceEmail' ) ) );
		wpMandrill::clearWooCommerceEmail();

		// A completely unrelated email sent later in the same request.
		wpMandrill::mail( 'someone-else@example.com', 'Newsletter', "line1\nline2" );

		$this->assertStringContainsString( '<br', $this->get_last_sent_html() );
	}

	public function test_pending_marker_does_not_affect_email_with_different_subject() {
		$this->configure( 1 );

		// WooCommerce marks its email as pending, but never fires
		// woocommerce_email_sent before this unrelated email with a
		// different subject is sent in the same request.
		apply_filters( 'woocommerce_mail_callback_params', array( 'to@example.com', 'Your order is complete', "line1\nline2", '', array() ), null );

		wpMandrill::mail( 'someone-else@example.com', 'A totally different subject', "line1\nline2" );

		$this->assertStringContainsString( '<br', $this->get_last_sent_html() );
	}

	public function test_setting_disabled_registers_nothing_and_nl2br_still_applies() {
		$this->configure( 0 );

		$subject = 'Your order is complete';
		apply_filters( 'woocommerce_mail_callback_params', array( 'to@example.com', $subject, "line1\nline2", '', array() ), null );

		wpMandrill::mail( 'to@example.com', $subject, "line1\nline2" );

		$this->assertStringContainsString( '<br', $this->get_last_sent_html() );
	}

	public function test_woocommerce_not_active_registers_nothing_even_when_setting_enabled() {
		add_filter( 'wpmandrill_is_woocommerce_active', '__return_false', 20 );

		$this->configure( 1 );

		remove_filter( 'wpmandrill_is_woocommerce_active', '__return_false', 20 );

		$this->assertFalse( has_filter( 'mandrill_nl2br', array( 'wpMandrill', 'disableNl2brForWooCommerce' ) ) );
	}

	public function test_marker_is_cleared_after_woocommerce_email_sent() {
		$this->configure( 1 );

		$subject = 'Your order is complete';
		apply_filters( 'woocommerce_mail_callback_params', array( 'to@example.com', $subject, "line1\nline2", '', array() ), null );

		// Don't fire the real action: WooCommerce's own listener on
		// 'woocommerce_email_sent' requires a real WC_Email argument.
		$this->assertNotFalse( has_action( 'woocommerce_email_sent', array( 'wpMandrill', 'clearWooCommerceEmail' ) ) );
		wpMandrill::clearWooCommerceEmail();

		// Marker was cleared before the send, so even the same subject gets nl2br'd now.
		wpMandrill::mail( 'to@example.com', $subject, "line1\nline2" );

		$this->assertStringContainsString( '<br', $this->get_last_sent_html() );
	}

	public function test_legacy_woocommerce_email_action_no_longer_disables_nl2br_globally() {
		$this->configure( 1 );

		// Simulates a user's own snippet (or WooCommerce itself, pre-fix)
		// hooking the old 'woocommerce_email' action; it must no longer be
		// wired up by the plugin to blanket-disable nl2br.
		do_action( 'woocommerce_email', null );

		wpMandrill::mail( 'to@example.com', 'Plain email', "line1\nline2" );

		$this->assertStringContainsString( '<br', $this->get_last_sent_html() );
	}
}
