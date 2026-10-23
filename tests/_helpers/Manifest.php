<?php
/**
 * Shared plugins.json access for the test bootstrap and test helpers.
 */

/**
 * Load and cache the decoded plugins.json manifest.
 *
 * @return array
 */
function mm_tests_load_manifest() {
	static $manifest = null;

	if ( null === $manifest ) {
		$manifest_path = dirname( __DIR__, 2 ) . '/plugins.json';
		$manifest      = json_decode( file_get_contents( $manifest_path ), true );
	}

	return $manifest;
}

/**
 * Find a manifest entry by slug.
 *
 * @param array  $manifest Decoded plugins.json.
 * @param string $slug     Manifest slug.
 * @return array|null
 */
function mm_tests_find_plugin( $manifest, $slug ) {
	foreach ( $manifest['plugins'] as $plugin ) {
		if ( $plugin['slug'] === $slug ) {
			return $plugin;
		}
	}

	return null;
}

/**
 * Parse the real "Version:" header out of a plugin's main file — used so
 * tests and the bootstrap never have to hardcode a version number that
 * will inevitably drift from the plugin's own readme/header.
 *
 * @param array  $manifest    Decoded plugins.json.
 * @param string $plugins_dir Absolute path to wp-content/plugins.
 * @param string $slug        Manifest slug.
 * @return string|null
 */
function mm_tests_plugin_header_version( $manifest, $plugins_dir, $slug ) {
	$plugin = mm_tests_find_plugin( $manifest, $slug );
	if ( null === $plugin ) {
		return null;
	}

	$main_file = $plugins_dir . '/' . $plugin['install_dir'] . '/' . $plugin['main_file'];
	if ( ! file_exists( $main_file ) ) {
		return null;
	}

	$contents = file_get_contents( $main_file, false, null, 0, 8192 );
	if ( preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $contents, $m ) ) {
		return trim( $m[1] );
	}

	return null;
}
