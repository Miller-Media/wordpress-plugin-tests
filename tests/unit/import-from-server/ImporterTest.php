<?php
/**
 * Tests for MMSFI_Importer::importFile() validation paths, using the
 * fixture directory in tests/_fixtures/import-from-server/.
 *
 * The success path (actually copying into wp-content/uploads) is skipped
 * when the uploads directory isn't writable in the current environment —
 * the validation/error paths below don't need to write anything and are
 * exercised unconditionally.
 */
class MMSFI_ImporterTest extends PluginTestCase {

	/**
	 * @var string Absolute path to tests/_fixtures/import-from-server
	 */
	private $fixtures_dir;

	public function setUp(): void {
		parent::setUp();

		$this->fixtures_dir = realpath( dirname( __DIR__, 2 ) . '/_fixtures/import-from-server' );
		$this->set_option( 'mmsfi_settings', array(
			'root_path'       => $this->fixtures_dir,
			'import_behavior' => 'copy',
			'allowed_types'   => '',
		) );
	}

	public function test_import_rejects_path_outside_root() {
		$result = MMSFI_Importer::importFile( dirname( $this->fixtures_dir ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_path_outside_root', $result->get_error_code() );
	}

	public function test_import_rejects_directory() {
		$result = MMSFI_Importer::importFile( $this->fixtures_dir . '/subdir' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_not_file', $result->get_error_code() );
	}

	public function test_import_rejects_disallowed_type() {
		$this->set_option( 'mmsfi_settings', array(
			'root_path'       => $this->fixtures_dir,
			'import_behavior' => 'copy',
			'allowed_types'   => 'gif',
		) );

		$result = MMSFI_Importer::importFile( $this->fixtures_dir . '/sample.txt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_type_not_allowed', $result->get_error_code() );
	}

	public function test_import_copies_file_into_media_library() {
		$upload_dir = wp_upload_dir();
		if ( ! is_writable( $upload_dir['path'] ) ) {
			$this->markTestSkipped( 'wp-content/uploads is not writable in this environment.' );
		}

		$attach_id = MMSFI_Importer::importFile( $this->fixtures_dir . '/pixel.gif' );

		$this->assertIsInt( $attach_id, is_wp_error( $attach_id ) ? $attach_id->get_error_message() : '' );
		$this->assertEquals( $this->fixtures_dir . '/pixel.gif', get_post_meta( $attach_id, '_mmsfi_original_path', true ) );

		// Importing the same file again should now be rejected as a duplicate.
		$second = MMSFI_Importer::importFile( $this->fixtures_dir . '/pixel.gif' );
		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertEquals( 'mmsfi_already_imported', $second->get_error_code() );
	}
}
