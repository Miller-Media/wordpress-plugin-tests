<?php
/**
 * Regression: the file browser JS must read the same object PHP localizes.
 *
 * 1.0.0 localized `mmsfiData` but admin.js still read `ifsData`, so the
 * browser never loaded. These assertions should fail if a rebuild regresses.
 */
class MMSFI_AdminAssetsTest extends PluginTestCase {

	public function test_admin_js_reads_the_localized_mmsfiData_object() {
		$js = file_get_contents( MMSFI_PLUGIN_PATH . 'dist/js/admin.js' );

		$this->assertNotFalse( $js );
		$this->assertDoesNotMatchRegularExpression(
			'/\bifsData\b/',
			$js,
			'admin.js must not read the old ifsData object.'
		);
		$this->assertMatchesRegularExpression(
			'/\bmmsfiData\b/',
			$js,
			'admin.js must read mmsfiData.'
		);
	}

	public function test_enqueue_localizes_mmsfiData_on_the_admin_script() {
		$user = $this->create_test_user( 'administrator' );
		wp_set_current_user( $user->ID );

		$plugin = new MMSFI_Plugin();
		$plugin->addAdminPage();

		$prop = new ReflectionProperty( MMSFI_Plugin::class, 'hook_suffix' );
		$prop->setAccessible( true );
		$hook = $prop->getValue( $plugin );
		$this->assertNotEmpty( $hook, 'addAdminPage() should record a hook suffix.' );

		$plugin->enqueueAssets( $hook );

		$this->assertTrue( wp_script_is( 'mmsfi-admin-js', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'mmsfi-admin-js', 'data' );
		$this->assertIsString( $data );
		$this->assertStringContainsString( 'mmsfiData', $data );
		$this->assertStringNotContainsString( 'ifsData', $data );
	}
}
