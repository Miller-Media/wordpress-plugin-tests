<?php
/**
 * Tests for Miller Media CSV Importer plugin activation.
 *
 * This plugin is not published to wp.org (see plugins.json: wporg=false,
 * svn_slug=null) but is still covered by the same manifest-driven harness.
 */
class MMCSV_ActivationTest extends PluginTestCase {

	public function test_plugin_classes_exist() {
		$this->assertTrue( class_exists( 'MMCSV_CSV_Helper' ), 'MMCSV_CSV_Helper class should exist.' );
		$this->assertTrue( class_exists( 'MMCSV_Import_Post_Helper' ), 'MMCSV_Import_Post_Helper class should exist.' );
		$this->assertTrue( class_exists( 'MMCSV_CSV_Importer' ), 'MMCSV_CSV_Importer class should exist.' );
	}

	public function test_constant_matches_header_version() {
		$this->assertTrue( defined( 'MMCSV_PLUGIN_VERSION' ) );
		$this->assertEquals( $this->plugin_version( 'miller-media-csv-importer' ), MMCSV_PLUGIN_VERSION );
	}

	public function test_importer_is_registered_on_plugins_loaded() {
		global $wp_importers;

		$this->assertNotFalse( has_action( 'plugins_loaded', 'mmcsv_initialize_importer' ) );

		if ( ! function_exists( 'register_importer' ) ) {
			require_once ABSPATH . 'wp-admin/includes/import.php';
		}

		mmcsv_initialize_importer();

		$this->assertIsArray( $wp_importers );
		$this->assertArrayHasKey( 'csv', $wp_importers );

		$callback = $wp_importers['csv'][2];
		$this->assertIsArray( $callback );
		$this->assertInstanceOf( 'MMCSV_CSV_Importer', $callback[0] );
		$this->assertSame( 'dispatch', $callback[1] );
	}
}
