#!/usr/bin/env php
<?php
/**
 * Print the "== Changelog ==" entry for one version out of a readme.txt —
 * used by release.yml to build GitHub release notes.
 *
 * Usage:
 *   bin/extract-changelog.php <path-to-readme.txt> <version>
 */

$args = $argv;
array_shift( $args );
list( $readme_path, $version ) = array_pad( $args, 2, null );

if ( null === $readme_path || null === $version ) {
	fwrite( STDERR, "Usage: extract-changelog.php <path-to-readme.txt> <version>\n" );
	exit( 1 );
}

if ( ! file_exists( $readme_path ) ) {
	fwrite( STDERR, "readme.txt not found: {$readme_path}\n" );
	exit( 1 );
}

$contents = file_get_contents( $readme_path );

$pattern = '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$(.*?)(?=^=\s*[0-9][0-9A-Za-z.\-]*\s*=\s*$|\z)/ms';

if ( ! preg_match( $pattern, $contents, $m ) ) {
	fwrite( STDERR, "No changelog entry found for version {$version} in {$readme_path}\n" );
	exit( 1 );
}

echo trim( $m[1] ) . "\n";
