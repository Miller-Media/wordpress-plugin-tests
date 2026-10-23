#!/usr/bin/env php
<?php
/**
 * Decide (and optionally apply) a "Tested up to" / "WC tested up to" bump
 * for one plugin, by comparing its readme.txt against the latest published
 * WordPress / WooCommerce versions.
 *
 * This never touches Version / Stable tag — only the "tested up to" headers.
 * It is meant to be run by the bump-tested-up-to.yml workflow against a
 * fresh checkout of the plugin's own repo, but is fully runnable (and safe)
 * locally against any plugin directory.
 *
 * Usage:
 *   bin/bump-tested-up-to.php <slug> [options]
 *
 * Options:
 *   --plugin-dir=PATH     Plugin checkout to inspect/modify (default: manifest dir under harness root)
 *   --wp-version=X.Y.Z    Latest WP version (skips the network call to api.wordpress.org)
 *   --wc-version=X.Y.Z    Latest WooCommerce version (skips the network call)
 *   --dry-run              Report what would change without writing any files
 *   --json                  Emit machine-readable JSON (default when --dry-run is not a TTY)
 */

$root          = dirname( __DIR__ );
$manifest_path = $root . '/plugins.json';

$args = $argv;
array_shift( $args );
$slug = null;
$opts = array(
	'plugin-dir'  => null,
	'wp-version'  => null,
	'wc-version'  => null,
	'dry-run'     => false,
);

foreach ( $args as $arg ) {
	if ( 0 === strpos( $arg, '--' ) && false !== strpos( $arg, '=' ) ) {
		list( $key, $value ) = explode( '=', substr( $arg, 2 ), 2 );
		$opts[ $key ] = $value;
	} elseif ( '--dry-run' === $arg ) {
		$opts['dry-run'] = true;
	} elseif ( null === $slug ) {
		$slug = $arg;
	}
}

if ( null === $slug ) {
	fwrite( STDERR, "Usage: bump-tested-up-to.php <slug> [--plugin-dir=PATH] [--wp-version=X.Y.Z] [--wc-version=X.Y.Z] [--dry-run]\n" );
	exit( 1 );
}

$manifest = json_decode( file_get_contents( $manifest_path ), true );
$plugin   = null;
foreach ( $manifest['plugins'] as $entry ) {
	if ( $entry['slug'] === $slug ) {
		$plugin = $entry;
		break;
	}
}

if ( null === $plugin ) {
	fwrite( STDERR, "Unknown plugin slug: {$slug}\n" );
	exit( 1 );
}

$plugin_dir = $opts['plugin-dir'] ?? ( $root . '/' . $plugin['dir'] );
$readme_file = rtrim( $plugin_dir, '/' ) . '/readme.txt';
$main_file   = rtrim( $plugin_dir, '/' ) . '/' . $plugin['main_file'];

if ( ! file_exists( $readme_file ) ) {
	fwrite( STDERR, "readme.txt not found: {$readme_file}\n" );
	exit( 1 );
}

function major_minor( $version ) {
	$parts = explode( '.', $version );
	return $parts[0] . '.' . ( $parts[1] ?? '0' );
}

function fetch_json( $url ) {
	$context = stream_context_create( array( 'http' => array( 'timeout' => 15, 'user_agent' => 'wordpress-plugin-tests bump-bot' ) ) );
	$body    = @file_get_contents( $url, false, $context );
	if ( false === $body ) {
		return null;
	}
	return json_decode( $body, true );
}

function latest_wp_version( $override ) {
	if ( null !== $override ) {
		return $override;
	}
	$data = fetch_json( 'https://api.wordpress.org/core/version-check/1.7/' );
	return $data['offers'][0]['version'] ?? null;
}

function latest_wc_version( $override ) {
	if ( null !== $override ) {
		return $override;
	}
	$data = fetch_json( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce' );
	return $data['version'] ?? null;
}

$readme_contents = file_get_contents( $readme_file );

function read_header_line( $contents, $label ) {
	if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $contents, $m ) ) {
		return trim( $m[1] );
	}
	return null;
}

function replace_header_line( $contents, $label, $new_value ) {
	$pattern = '/^([ \t\/*#@]*' . preg_quote( $label, '/' ) . ':\s*).+$/mi';
	return preg_replace( $pattern, '${1}' . $new_value, $contents, 1 );
}

$result = array(
	'slug'    => $slug,
	'changed' => false,
	'wp'      => null,
	'wc'      => null,
);

// ── WordPress "Tested up to" ────────────────────────────────────

$current_wp_tested = read_header_line( $readme_contents, 'Tested up to' );
$latest_wp          = latest_wp_version( $opts['wp-version'] );

if ( null !== $current_wp_tested && null !== $latest_wp ) {
	$latest_wp_mm = major_minor( $latest_wp );
	$needs_bump   = version_compare( major_minor( $current_wp_tested ), $latest_wp_mm, '<' );

	$result['wp'] = array(
		'from'   => $current_wp_tested,
		'to'     => $latest_wp_mm,
		'bumped' => $needs_bump,
	);

	if ( $needs_bump ) {
		$result['changed'] = true;
		if ( ! $opts['dry-run'] ) {
			$readme_contents = replace_header_line( $readme_contents, 'Tested up to', $latest_wp_mm );
		}
	}
}

// ── WooCommerce "WC tested up to" (only for WC-dependent plugins) ─

if ( in_array( 'woocommerce', $plugin['dependencies'], true ) ) {
	$main_contents = file_exists( $main_file ) ? file_get_contents( $main_file ) : '';

	// "WC tested up to" may live in readme.txt, the main file header, or both.
	$in_readme  = null !== read_header_line( $readme_contents, 'WC tested up to' );
	$in_main    = '' !== $main_contents && null !== read_header_line( $main_contents, 'WC tested up to' );
	$current_wc_tested = $in_readme
		? read_header_line( $readme_contents, 'WC tested up to' )
		: read_header_line( $main_contents, 'WC tested up to' );
	$latest_wc = latest_wc_version( $opts['wc-version'] );

	if ( null !== $current_wc_tested && null !== $latest_wc ) {
		$latest_wc_mm = major_minor( $latest_wc );
		$needs_bump   = version_compare( major_minor( $current_wc_tested ), $latest_wc_mm, '<' );

		$result['wc'] = array(
			'from'   => $current_wc_tested,
			'to'     => $latest_wc_mm,
			'bumped' => $needs_bump,
		);

		if ( $needs_bump ) {
			$result['changed'] = true;
			if ( ! $opts['dry-run'] ) {
				if ( $in_readme ) {
					$readme_contents = replace_header_line( $readme_contents, 'WC tested up to', $latest_wc_mm );
				}
				if ( $in_main ) {
					$main_contents = replace_header_line( $main_contents, 'WC tested up to', $latest_wc_mm );
					file_put_contents( $main_file, $main_contents );
				}
			}
		}
	}
}

if ( $result['changed'] && ! $opts['dry-run'] ) {
	file_put_contents( $readme_file, $readme_contents );
}

echo json_encode( $result, JSON_PRETTY_PRINT ) . "\n";

if ( $result['changed'] ) {
	exit( 0 );
}

// Nothing to do — exit code 2 lets the calling workflow distinguish
// "ran fine, no bump needed" from an actual failure (non-zero exit above).
exit( $result['changed'] ? 0 : 2 );
