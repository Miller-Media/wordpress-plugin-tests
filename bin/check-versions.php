#!/usr/bin/env php
<?php
/**
 * Verify that a plugin's version is consistent everywhere it's declared:
 *   - main file "Version:" header
 *   - main file version constant (if the manifest declares one)
 *   - readme.txt "Stable tag:"
 *   - readme.txt has a "== Changelog ==" entry for that version
 *
 * Usage:
 *   bin/check-versions.php <slug> [expected-version]
 *
 * expected-version (e.g. parsed from a release tag "vX.Y.Z" -> "X.Y.Z")
 * is optionally checked against all of the above too.
 *
 * Exits non-zero with a clear message on any mismatch.
 */

$root          = dirname( __DIR__ );
$manifest_path = $root . '/plugins.json';

$args     = $argv;
array_shift( $args );
$slug     = $args[0] ?? null;
$expected = $args[1] ?? null;

if ( null === $slug ) {
	fwrite( STDERR, "Usage: check-versions.php <slug> [expected-version]\n" );
	exit( 1 );
}

if ( ! file_exists( $manifest_path ) ) {
	fwrite( STDERR, "Manifest not found: {$manifest_path}\n" );
	exit( 1 );
}

$manifest = json_decode( file_get_contents( $manifest_path ), true );
if ( null === $manifest ) {
	fwrite( STDERR, "Failed to parse manifest JSON.\n" );
	exit( 1 );
}

$plugin = null;
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

$plugin_dir  = $root . '/' . $plugin['dir'];
$main_file   = $plugin_dir . '/' . $plugin['main_file'];
$readme_file = $plugin_dir . '/readme.txt';

$errors = array();

if ( ! file_exists( $main_file ) ) {
	fwrite( STDERR, "Main file not found: {$main_file}\n" );
	exit( 1 );
}

if ( ! file_exists( $readme_file ) ) {
	fwrite( STDERR, "readme.txt not found: {$readme_file}\n" );
	exit( 1 );
}

$main_contents = file_get_contents( $main_file );

// Version header, from the plugin docblock only (first 8KB).
$header_version = null;
if ( preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', substr( $main_contents, 0, 8192 ), $m ) ) {
	$header_version = trim( $m[1] );
}

if ( null === $header_version ) {
	$errors[] = "Could not find a 'Version:' header in {$plugin['main_file']}.";
}

// Version constant, if the manifest declares one.
$constant_version = null;
if ( null !== $plugin['version_constant'] ) {
	$pattern = '/define\(\s*[\'"]' . preg_quote( $plugin['version_constant'], '/' ) . '[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/';
	if ( preg_match( $pattern, $main_contents, $m ) ) {
		$constant_version = $m[1];
	} else {
		$errors[] = "Could not find define('{$plugin['version_constant']}', ...) in {$plugin['main_file']}.";
	}
}

$readme_contents = file_get_contents( $readme_file );

$stable_tag = null;
if ( preg_match( '/^Stable tag:\s*(.+)$/mi', $readme_contents, $m ) ) {
	$stable_tag = trim( $m[1] );
} else {
	$errors[] = 'Could not find a "Stable tag:" line in readme.txt.';
}

// Collect every "= X.Y.Z =" changelog entry.
$changelog_versions = array();
if ( preg_match( '/==\s*Changelog\s*==(.*)$/is', $readme_contents, $m ) ) {
	if ( preg_match_all( '/^=\s*([0-9][0-9A-Za-z.\-]*)\s*=$/m', $m[1], $cm ) ) {
		$changelog_versions = $cm[1];
	}
} else {
	$errors[] = 'Could not find a "== Changelog ==" section in readme.txt.';
}

// ── Cross-check consistency ──────────────────────────────────

if ( empty( $errors ) ) {
	if ( $header_version !== $stable_tag ) {
		$errors[] = "Version header ({$header_version}) does not match readme Stable tag ({$stable_tag}).";
	}

	if ( null !== $plugin['version_constant'] && $constant_version !== $header_version ) {
		$errors[] = "Version constant {$plugin['version_constant']} ({$constant_version}) does not match Version header ({$header_version}).";
	}

	if ( ! in_array( $header_version, $changelog_versions, true ) ) {
		$errors[] = "No changelog entry '= {$header_version} =' found for current version in readme.txt.";
	}

	if ( null !== $expected ) {
		foreach ( array(
			'Version header'  => $header_version,
			'readme Stable tag' => $stable_tag,
		) as $label => $value ) {
			if ( $value !== $expected ) {
				$errors[] = "{$label} ({$value}) does not match expected release version ({$expected}).";
			}
		}
		if ( null !== $plugin['version_constant'] && $constant_version !== $expected ) {
			$errors[] = "Version constant {$plugin['version_constant']} ({$constant_version}) does not match expected release version ({$expected}).";
		}
		if ( ! in_array( $expected, $changelog_versions, true ) ) {
			$errors[] = "No changelog entry '= {$expected} =' found for expected release version in readme.txt.";
		}
	}
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, "check-versions FAILED for '{$slug}':\n" );
	foreach ( $errors as $error ) {
		fwrite( STDERR, "  - {$error}\n" );
	}
	exit( 1 );
}

echo "OK [{$slug}]: version {$header_version} is consistent across header, readme, and changelog.\n";
