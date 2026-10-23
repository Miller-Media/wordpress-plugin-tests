<?php
/**
 * Tests for the 'wpmandrill_enable_reports' filter actually preventing any
 * Mandrill API calls (and the hourly stats cron) when reports are disabled,
 * instead of just hiding the Reports page/widget.
 */
class SEWM_ReportsDisabledTest extends PluginTestCase {

	public function setUp(): void {
		parent::setUp();

		wpMandrill::$mandrill = null;

		delete_option( 'wpmandrill' );
		update_option( 'wpmandrill', array(
			'api_key'       => 'test_api_key',
			'from_username' => 'sender@example.com',
			'from_name'     => 'Sender',
		) );

		delete_option( 'wpmandrill-stats' );
		delete_transient( 'wpmandrill-stats' );
	}

	public function tearDown(): void {
		remove_filter( 'wpmandrill_enable_reports', '__return_false' );
		unset( $_GET['fetch_new'] );

		wpMandrill::$mandrill = null;

		delete_option( 'wpmandrill' );
		delete_option( 'wpmandrill-stats' );
		delete_transient( 'wpmandrill-stats' );

		wp_clear_scheduled_hook( 'wpm_update_stats' );

		parent::tearDown();
	}

	public function test_reports_enabled_by_default() {
		$this->assertTrue( wpMandrill::reportsEnabled() );
	}

	public function test_reports_disabled_via_filter() {
		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		$this->assertFalse( wpMandrill::reportsEnabled() );
	}

	public function test_get_raw_statistics_makes_no_api_calls_when_disabled() {
		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		$result = wpMandrill::getRawStatistics();

		$this->assertSame( array(), $result );
		$this->assertSame( 0, MandrillAPIMock::get_request_count() );
	}

	public function test_save_processed_stats_does_not_fetch_or_overwrite_saved_stats_when_disabled() {
		$saved_stats = array( 'existing' => 'data' );
		update_option( 'wpmandrill-stats', $saved_stats, false );

		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		$result = wpMandrill::saveProcessedStats();

		$this->assertSame( array(), $result );
		$this->assertSame( 0, MandrillAPIMock::get_request_count() );
		$this->assertSame( $saved_stats, get_option( 'wpmandrill-stats' ) );
	}

	public function test_get_current_stats_makes_no_api_calls_when_disabled() {
		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		$result = wpMandrill::getCurrentStats();

		$this->assertSame( array(), $result );
		$this->assertSame( 0, MandrillAPIMock::get_request_count() );
	}

	public function test_get_current_stats_fetch_new_asap_makes_no_api_calls_when_disabled() {
		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		$_GET['fetch_new'] = 'asap';

		$result = wpMandrill::getCurrentStats();

		$this->assertSame( array(), $result );
		$this->assertSame( 0, MandrillAPIMock::get_request_count() );
	}

	public function test_get_raw_statistics_makes_api_calls_when_enabled() {
		// No filter added: reports are enabled by default.
		// Provide well-formed, empty-but-valid responses for the stats
		// endpoints so the real collection logic can run to completion.
		MandrillAPIMock::set_response( '#users/info#', array(
			'stats' => array(
				'all_time'     => array(),
				'today'        => array(),
				'last_7_days'  => array(),
				'last_30_days' => array(),
				'last_60_days' => array(),
				'last_90_days' => array(),
			),
		) );
		MandrillAPIMock::set_response( '#tags/list#', array() );
		MandrillAPIMock::set_response( '#senders/list#', array() );
		MandrillAPIMock::set_response( '#tags/all-time-series#', array() );

		$result = wpMandrill::getRawStatistics();

		$this->assertGreaterThan( 0, MandrillAPIMock::get_request_count() );
		$this->assertNotSame( array(), $result );
	}

	public function test_admin_init_unschedules_cron_when_reports_disabled() {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpm_update_stats' );
		$this->assertNotFalse( wp_next_scheduled( 'wpm_update_stats' ) );

		add_filter( 'wpmandrill_enable_reports', '__return_false' );

		wpMandrill::adminInit();

		$this->assertFalse( wp_next_scheduled( 'wpm_update_stats' ) );
	}

	public function test_admin_init_schedules_cron_when_reports_enabled() {
		wp_clear_scheduled_hook( 'wpm_update_stats' );
		$this->assertFalse( wp_next_scheduled( 'wpm_update_stats' ) );

		// No filter added: reports are enabled by default.
		wpMandrill::adminInit();

		$this->assertNotFalse( wp_next_scheduled( 'wpm_update_stats' ) );
	}
}
