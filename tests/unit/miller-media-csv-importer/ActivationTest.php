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

	public function test_importer_is_registered() {
		global $wp_importers;

		$hook = has_action( 'admin_init', 'mmcsv_initialize_importer' )
			? 'admin_init'
			: 'plugins_loaded';
		$this->assertNotFalse( has_action( $hook, 'mmcsv_initialize_importer' ) );

		if ( ! function_exists( 'register_importer' ) ) {
			require_once ABSPATH . 'wp-admin/includes/import.php';
		}

		mmcsv_initialize_importer();

		$importer_id = defined( 'MMCSV_IMPORTER_ID' ) ? MMCSV_IMPORTER_ID : 'csv';

		$this->assertIsArray( $wp_importers );
		$this->assertArrayHasKey( $importer_id, $wp_importers );

		$callback = $wp_importers[ $importer_id ][2];
		$this->assertIsArray( $callback );
		$this->assertInstanceOf( 'MMCSV_CSV_Importer', $callback[0] );
		$this->assertSame( 'dispatch', $callback[1] );
	}
}
