<?php
/**
 * Tests for GitHub issue #152: wpMandrill::send_mail() (the body of the
 * plugin's wp_mail() replacement) and wpMandrill::wp_mail_native() must
 * always return a bool, since WooCommerce 10.9+ relies on that contract.
 */
class SEWM_WpMailReturnTest extends PluginTestCase {

	/**
	 * Custom 'wp_mail_native' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $custom_native_handler;

	/**
	 * Custom 'phpmailer_init' callback added by the current test, if any.
	 *
	 * @var callable|null
	 */
	private $phpmailer_block_handler;

	public function setUp(): void {
		parent::setUp();

		// Make sure Mandrill itself fails deterministically: no API key
		// configured means Mandrill's constructor throws, so send_mail()
		// always takes the native-mail fallback path in these tests.
		delete_option( 'wpmandrill' );
		wpMandrill::$mandrill           = null;
		wpMandrill::$native_mail_result = null;
	}

	public function tearDown(): void {
		// Restore the default native handler to a known, single registration.
		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );
		if ( null !== $this->custom_native_handler ) {
			remove_action( 'wp_mail_native', $this->custom_native_handler, 10 );
			$this->custom_native_handler = null;
		}
		add_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10, 5 );

		if ( null !== $this->phpmailer_block_handler ) {
			remove_action( 'phpmailer_init', $this->phpmailer_block_handler );
			$this->phpmailer_block_handler = null;
		}

		wpMandrill::$native_mail_result = null;

		parent::tearDown();
	}

	public function test_send_mail_returns_true_when_custom_native_handler_succeeds() {
		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );

		$received_args              = null;
		$this->custom_native_handler = function ( $to, $subject, $message, $headers, $attachments ) use ( &$received_args ) {
			$received_args = array( $to, $subject, $message, $headers, $attachments );
			wpMandrill::$native_mail_result = true;
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$to          = 'recipient@example.com';
		$subject     = 'Test Subject';
		$message     = 'Test message body';
		$headers     = 'From: sender@example.com';
		$attachments = array();

		$result = wpMandrill::send_mail( $to, $subject, $message, $headers, $attachments );

		$this->assertTrue( $result );
		$this->assertIsBool( $result );
		$this->assertSame( array( $to, $subject, $message, $headers, $attachments ), $received_args );
	}

	public function test_send_mail_returns_false_when_custom_native_handler_sets_nothing() {
		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );

		$this->custom_native_handler = function () {
			// Intentionally does not touch wpMandrill::$native_mail_result.
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$result = wpMandrill::send_mail( 'recipient@example.com', 'Test Subject', 'Test message body' );

		$this->assertFalse( $result );
		$this->assertIsBool( $result );
		$this->assertNotNull( $result );
	}

	public function test_send_mail_returns_false_when_custom_native_handler_throws() {
		remove_action( 'wp_mail_native', array( 'wpMandrill', 'wp_mail_native' ), 10 );

		$this->custom_native_handler = function () {
			throw new Exception( 'Simulated native mailer failure.' );
		};
		add_action( 'wp_mail_native', $this->custom_native_handler, 10, 5 );

		$result = wpMandrill::send_mail( 'recipient@example.com', 'Test Subject', 'Test message body' );

		$this->assertFalse( $result );
		$this->assertIsBool( $result );
	}

	public function test_send_mail_with_default_native_handler_returns_bool() {
		$deprecated_files = array();
		$this->watch_deprecated_files( $deprecated_files );
		$this->block_real_phpmailer_send();

		$result = wpMandrill::send_mail( 'recipient@example.com', 'Test Subject', 'Test message body' );

		$this->expect_watched_deprecated_files( $deprecated_files );

		$this->assertIsBool( $result );
	}

	public function test_wp_mail_native_returns_bool_directly() {
		$deprecated_files = array();
		$this->watch_deprecated_files( $deprecated_files );
		$this->block_real_phpmailer_send();

		$result = wpMandrill::wp_mail_native( 'recipient@example.com', 'Test Subject', 'Test message body' );

		$this->expect_watched_deprecated_files( $deprecated_files );

		$this->assertIsBool( $result );
	}

	/**
	 * Prevents the legacy mailer from actually dispatching mail: the legacy
	 * code path always replaces global $phpmailer with a real PHPMailer
	 * instance, so a 'phpmailer_init' callback that throws stops it before
	 * Send() is ever reached (and is itself caught by the plugin's own
	 * try/catch(\Throwable), keeping the result deterministic).
	 */
	private function block_real_phpmailer_send() {
		$this->phpmailer_block_handler = function () {
			throw new Exception( 'Blocked real mail delivery during test.' );
		};
		add_action( 'phpmailer_init', $this->phpmailer_block_handler );
	}

	/**
	 * Starts recording which files trigger _deprecated_file() during the
	 * test. The legacy mailer require()s wp-includes/class-phpmailer.php
	 * (and class-smtp.php), but those only fire the deprecation notice the
	 * first time they're included in the whole test run, so we can't
	 * hardcode which files (if any) will be caught.
	 *
	 * @param array $deprecated_files Populated by reference as files are included.
	 */
	private function watch_deprecated_files( array &$deprecated_files ) {
		add_action(
			'deprecated_file_included',
			function ( $file ) use ( &$deprecated_files ) {
				$deprecated_files[] = $file;
			},
			10,
			1
		);
	}

	/**
	 * Declares whatever deprecated-file notices were actually observed as
	 * expected, so WP_UnitTestCase doesn't fail on them either way.
	 *
	 * @param array $deprecated_files Reference container from watch_deprecated_files().
	 */
	private function expect_watched_deprecated_files( &$deprecated_files ) {
		foreach ( array_unique( $deprecated_files ) as $file ) {
			$this->setExpectedDeprecated( $file );
		}
	}
}
