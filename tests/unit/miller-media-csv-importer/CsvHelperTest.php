<?php
/**
 * Tests for MMCSV_CSV_Helper against fixture CSVs in
 * tests/_fixtures/miller-media-csv-importer/.
 */
class MMCSV_CsvHelperTest extends PluginTestCase {

	/**
	 * @var string Absolute path to tests/_fixtures/miller-media-csv-importer
	 */
	private $fixtures_dir;

	/**
	 * @var MMCSV_CSV_Helper
	 */
	private $helper;

	public function setUp(): void {
		parent::setUp();

		$this->fixtures_dir = dirname( __DIR__, 2 ) . '/_fixtures/miller-media-csv-importer';
		$this->helper        = new MMCSV_CSV_Helper();
	}

	public function test_parses_header_row_and_data_row() {
		$fp = $this->helper->fopen( $this->fixtures_dir . '/posts.csv' );
		$this->assertNotFalse( $fp );

		$header = $this->helper->fgetcsv( $fp );
		$this->helper->parse_columns( $this->helper, $header );

		$row = $this->helper->fgetcsv( $fp );
		$this->helper->fclose( $fp );

		$this->assertEquals( 'Harness Test Post', $this->helper->get_data( $this->helper, $row, 'post_title' ) );
	}

	public function test_get_data_consumes_values_so_repeat_calls_return_false() {
		$fp     = $this->helper->fopen( $this->fixtures_dir . '/posts.csv' );
		$header = $this->helper->fgetcsv( $fp );
		$this->helper->parse_columns( $this->helper, $header );
		$row = $this->helper->fgetcsv( $fp );
		$this->helper->fclose( $fp );

		$this->helper->get_data( $this->helper, $row, 'post_title' );
		$second_read = $this->helper->get_data( $this->helper, $row, 'post_title' );

		$this->assertFalse( $second_read );
	}

	public function test_get_data_returns_false_for_unknown_column() {
		$fp     = $this->helper->fopen( $this->fixtures_dir . '/posts.csv' );
		$header = $this->helper->fgetcsv( $fp );
		$this->helper->parse_columns( $this->helper, $header );
		$row = $this->helper->fgetcsv( $fp );
		$this->helper->fclose( $fp );

		$this->assertFalse( $this->helper->get_data( $this->helper, $row, 'no_such_column' ) );
	}

	public function test_parse_columns_strips_utf8_bom_from_first_header() {
		$fp     = $this->helper->fopen( $this->fixtures_dir . '/bom.csv' );
		$header = $this->helper->fgetcsv( $fp );
		$this->helper->parse_columns( $this->helper, $header );
		$row = $this->helper->fgetcsv( $fp );
		$this->helper->fclose( $fp );

		$this->assertEquals( 'BOM Post', $this->helper->get_data( $this->helper, $row, 'post_title' ) );
	}

	public function test_parse_columns_returns_false_for_empty_header() {
		$this->assertFalse( $this->helper->parse_columns( $this->helper, array() ) );
	}
}
