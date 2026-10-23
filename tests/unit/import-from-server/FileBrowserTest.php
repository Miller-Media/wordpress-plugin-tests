<?php
/**
 * Tests for MMSFI_FileBrowser against the fixture directory in
 * tests/_fixtures/import-from-server/ (never the real filesystem root).
 */
class MMSFI_FileBrowserTest extends PluginTestCase {

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

	public function test_validate_path_accepts_root_itself() {
		$result = MMSFI_FileBrowser::validatePath( $this->fixtures_dir );

		$this->assertIsString( $result );
		$this->assertEquals( $this->fixtures_dir, $result );
	}

	public function test_validate_path_accepts_file_within_root() {
		$result = MMSFI_FileBrowser::validatePath( $this->fixtures_dir . '/sample.txt' );

		$this->assertIsString( $result );
	}

	public function test_validate_path_rejects_path_outside_root() {
		$result = MMSFI_FileBrowser::validatePath( dirname( $this->fixtures_dir ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_path_outside_root', $result->get_error_code() );
	}

	public function test_validate_path_rejects_nonexistent_path() {
		$result = MMSFI_FileBrowser::validatePath( $this->fixtures_dir . '/does-not-exist.txt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_path_not_found', $result->get_error_code() );
	}

	public function test_validate_path_rejects_invalid_configured_root() {
		$this->set_option( 'mmsfi_settings', array(
			'root_path'       => $this->fixtures_dir . '/does-not-exist-root',
			'import_behavior' => 'copy',
			'allowed_types'   => '',
		) );

		$result = MMSFI_FileBrowser::validatePath( $this->fixtures_dir . '/sample.txt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'mmsfi_invalid_root', $result->get_error_code() );
	}

	public function test_list_directory_returns_files_and_subdirectories() {
		$listing = MMSFI_FileBrowser::listDirectory( $this->fixtures_dir );

		$this->assertIsArray( $listing );
		$this->assertArrayHasKey( 'directories', $listing );
		$this->assertArrayHasKey( 'files', $listing );

		$dir_names = array_column( $listing['directories'], 'name' );
		$this->assertContains( 'subdir', $dir_names );

		$file_names = array_column( $listing['files'], 'name' );
		$this->assertContains( 'sample.txt', $file_names );
		$this->assertContains( 'pixel.gif', $file_names );
	}

	public function test_list_directory_marks_files_importable_by_default() {
		$listing = MMSFI_FileBrowser::listDirectory( $this->fixtures_dir );

		$by_name = array();
		foreach ( $listing['files'] as $file ) {
			$by_name[ $file['name'] ] = $file;
		}

		$this->assertTrue( $by_name['pixel.gif']['importable'] );
		$this->assertEquals( 'image/gif', $by_name['pixel.gif']['mime_type'] );
	}

	public function test_is_file_importable_respects_allowed_types_setting() {
		$this->set_option( 'mmsfi_settings', array(
			'root_path'       => $this->fixtures_dir,
			'import_behavior' => 'copy',
			'allowed_types'   => 'gif',
		) );

		$this->assertTrue( MMSFI_FileBrowser::isFileImportable( $this->fixtures_dir . '/pixel.gif' ) );
		$this->assertFalse( MMSFI_FileBrowser::isFileImportable( $this->fixtures_dir . '/sample.txt' ) );
	}

	public function test_is_already_imported_false_for_untouched_fixture() {
		$this->assertFalse( MMSFI_FileBrowser::isAlreadyImported( $this->fixtures_dir . '/pixel.gif' ) );
	}
}
