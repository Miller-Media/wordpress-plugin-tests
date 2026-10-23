#!/usr/bin/env php
<?php
/**
 * CLI for reading plugins.json — the single source of truth for which
 * plugins exist, where they live, and what versions they target.
 *
 * Usage:
 *   bin/manifest.php dirs                          List local checkout dirs (one per line)
 *   bin/manifest.php install-dirs                   List wp-content/plugins install dirs
 *   bin/manifest.php slugs                           List manifest slugs
 *   bin/manifest.php wporg-slugs                     List slugs where wporg=true
 *   bin/manifest.php field <slug> <dotted.field>     Print one field (empty line if null).
 *                                                      "wc.tested" and "tested_up_to" are
 *                                                      special-cased to read live from the
 *                                                      plugin's own readme/header instead of
 *                                                      plugins.json — see live_tested_up_to().
 *   bin/manifest.php get <slug>                       Print the full JSON entry for a slug
 *   bin/manifest.php default <dotted.field>          Print one top-level default
 *   bin/manifest.php text-domains <slug>             List text domains for a slug
 *   bin/manifest.php matrix [--plugins=a,b]           Emit CI matrix JSON
 *   bin/manifest.php nightly-matrix [--plugins=a,b]   Emit nightly-leg matrix JSON
 *                                                      (single php/wp combo per
 *                                                      plugin, wp="nightly")
 *   bin/manifest.php affected <path> [<path> ...]    Map changed paths to affected slugs
 *                                                      (reads stdin if given "-")
 *   bin/manifest.php check-headers [slug]             Verify requires_php/requires_wp
 *                                                      against the plugin's own header.
 *                                                      Exits non-zero on mismatch.
 */

$root          = dirname( __DIR__ );
$manifest_path = $root . '/plugins.json';

function manifest_load( $manifest_path ) {
	if ( ! file_exists( $manifest_path ) ) {
		fwrite( STDERR, "Manifest not found: {$manifest_path}\n" );
		exit( 1 );
	}

	$data = json_decode( file_get_contents( $manifest_path ), true );

	if ( null === $data ) {
		fwrite( STDERR, "Failed to parse manifest JSON: {$manifest_path}\n" );
		exit( 1 );
	}

	return $data;
}

function manifest_find( $manifest, $slug ) {
	foreach ( $manifest['plugins'] as $plugin ) {
		if ( $plugin['slug'] === $slug ) {
			return $plugin;
		}
	}
	return null;
}

function dotted_get( $data, $path ) {
	$parts = explode( '.', $path );
	$value = $data;
	foreach ( $parts as $part ) {
		if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
			$value = $value[ $part ];
		} else {
			return null;
		}
	}
	return $value;
}

function print_scalar( $value ) {
	if ( null === $value ) {
		echo "\n";
	} elseif ( is_bool( $value ) ) {
		echo ( $value ? 'true' : 'false' ) . "\n";
	} elseif ( is_array( $value ) ) {
		echo implode( "\n", $value ) . "\n";
	} else {
		echo $value . "\n";
	}
}

/**
 * Parse the "Version:" header and any "Requires PHP:"/"Requires at least:"
 * headers out of a plugin's main file docblock.
 */
function parse_plugin_header( $main_file_path ) {
	if ( ! file_exists( $main_file_path ) ) {
		return null;
	}

	$contents = file_get_contents( $main_file_path, false, null, 0, 8192 );
	$headers  = array();

	foreach ( array(
		'version'      => 'Version',
		'requires_php' => 'Requires PHP',
		'requires_wp'  => 'Requires at least',
	) as $key => $label ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $contents, $m ) ) {
			$headers[ $key ] = trim( $m[1] );
		} else {
			$headers[ $key ] = null;
		}
	}

	return $headers;
}

/**
 * Parse the readme.txt plugin-header block (Requires at least, Requires PHP,
 * Stable tag, Tested up to).
 */
function parse_readme_header( $readme_path ) {
	if ( ! file_exists( $readme_path ) ) {
		return null;
	}

	$contents = file_get_contents( $readme_path, false, null, 0, 4096 );
	$headers  = array();

	foreach ( array(
		'stable_tag'    => 'Stable tag',
		'requires_php'  => 'Requires PHP',
		'requires_wp'   => 'Requires at least',
		'tested_up_to'  => 'Tested up to',
		'wc_tested_up_to' => 'WC tested up to',
	) as $key => $label ) {
		if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $contents, $m ) ) {
			$headers[ $key ] = trim( $m[1] );
		} else {
			$headers[ $key ] = null;
		}
	}

	return $headers;
}

/**
 * Live "Tested up to" / "WC tested up to" values for a plugin, read
 * straight from its own readme.txt (and, for WC, its main file header too
 * — some plugins only declare it there). Nothing here is ever hand-edited
 * in plugins.json: this is the single source of truth, so a plugin bumping
 * its own header never needs a matching manifest edit and never fails a
 * harness check because of it (see cmd_check_headers()'s advisory warning).
 *
 * @return array{wp: string|null, wc: string|null}
 */
function live_tested_up_to( $root, $plugin ) {
	$readme_path = $root . '/' . $plugin['dir'] . '/readme.txt';
	$main_path   = $root . '/' . $plugin['dir'] . '/' . $plugin['main_file'];

	$readme = parse_readme_header( $readme_path );

	$wc = $readme['wc_tested_up_to'] ?? null;
	if ( null === $wc && file_exists( $main_path ) ) {
		$main_contents = file_get_contents( $main_path, false, null, 0, 8192 );
		if ( preg_match( '/^[ \t\/*#@]*WC tested up to:\s*(.+)$/mi', $main_contents, $m ) ) {
			$wc = trim( $m[1] );
		}
	}

	return array(
		'wp' => $readme['tested_up_to'] ?? null,
		'wc' => $wc,
	);
}

function version_max( $a, $b ) {
	if ( null === $a ) {
		return $b;
	}
	if ( null === $b ) {
		return $a;
	}
	return version_compare( $a, $b, '>=' ) ? $a : $b;
}

/**
 * The fixed PHP sweep every plugin's "wp=latest" leg runs across, newest
 * version first isn't required — order just has to be deterministic.
 */
const CI_PHP_VERSIONS = array( '8.1', '8.2', '8.3', '8.4', '8.5' );

function build_matrix( $manifest, $slugs ) {
	$defaults = $manifest['defaults'];
	$rows     = array();

	foreach ( $manifest['plugins'] as $plugin ) {
		if ( ! in_array( $plugin['slug'], $slugs, true ) ) {
			continue;
		}

		$php_floor = $plugin['requires_php'] ?: CI_PHP_VERSIONS[0];

		$php_versions = array_values( array_filter( CI_PHP_VERSIONS, function ( $php ) use ( $php_floor ) {
			return version_compare( $php, $php_floor, '>=' );
		} ) );

		// A plugin whose real floor sits below our fixed sweep (e.g.
		// one-off-emails-for-woocommerce's PHP 7.4) gets one extra, cheap
		// leg at that exact floor rather than silently never testing it.
		if ( version_compare( $php_floor, CI_PHP_VERSIONS[0], '<' ) ) {
			array_unshift( $php_versions, $php_floor );
		}

		$needs_wc = in_array( 'woocommerce', $plugin['dependencies'], true );

		foreach ( $php_versions as $php ) {
			$rows[] = array(
				'slug'    => $plugin['slug'],
				'php'     => $php,
				'wp'      => 'latest',
				'wc'      => $needs_wc ? $defaults['wc_latest_tested'] : null,
				'with_wc' => false,
			);
		}

		// Only plugins that declare a real requires_wp above the harness's
		// baseline floor get a dedicated WP-floor leg — none currently do
		// (every manifest requires_wp sits below wp_ci_floor), so this is
		// a no-op today but keeps a future higher-floor plugin honest
		// without reintroducing a blanket floor leg for everyone.
		$wp_floor = version_max( $plugin['requires_wp'], $defaults['wp_ci_floor'] );
		if ( null !== $plugin['requires_wp'] && version_compare( $wp_floor, $defaults['wp_ci_floor'], '>' ) ) {
			$rows[] = array(
				'slug'    => $plugin['slug'],
				'php'     => $defaults['php_latest'],
				'wp'      => $wp_floor,
				'wc'      => $needs_wc ? $defaults['wc_latest_tested'] : null,
				'with_wc' => false,
			);
		}

		// A plugin that doesn't require WooCommerce can still opt into one
		// extra leg that activates it (e.g. send-emails-with-mandrill's
		// #152 regression was triggered by WooCommerce being active).
		if ( ! $needs_wc && ! empty( $plugin['wc_optional_leg'] ) ) {
			$rows[] = array(
				'slug'    => $plugin['slug'],
				'php'     => $defaults['php_latest'],
				'wp'      => 'latest',
				'wc'      => $defaults['wc_latest_tested'],
				'with_wc' => true,
			);
		}
	}

	return $rows;
}

/**
 * Nightly leg: one (php_latest, wp=nightly) row per plugin, plus the same
 * wc_optional_leg extra row as build_matrix() — kept intentionally small
 * (no requires_php/wp_floor legs) since this runs non-blocking, continuously,
 * against a moving target (trunk or the latest beta/RC).
 */
function build_nightly_matrix( $manifest, $slugs ) {
	$defaults = $manifest['defaults'];
	$rows     = array();

	foreach ( $manifest['plugins'] as $plugin ) {
		if ( ! in_array( $plugin['slug'], $slugs, true ) ) {
			continue;
		}

		$needs_wc = in_array( 'woocommerce', $plugin['dependencies'], true );

		$rows[] = array(
			'slug'    => $plugin['slug'],
			'php'     => $defaults['php_latest'],
			'wp'      => 'nightly',
			'wc'      => $needs_wc ? $defaults['wc_latest_tested'] : null,
			'with_wc' => false,
		);

		if ( ! $needs_wc && ! empty( $plugin['wc_optional_leg'] ) ) {
			$rows[] = array(
				'slug'    => $plugin['slug'],
				'php'     => $defaults['php_latest'],
				'wp'      => 'nightly',
				'wc'      => $defaults['wc_latest_tested'],
				'with_wc' => true,
			);
		}
	}

	return $rows;
}

/**
 * Map a changed file path (relative to the harness repo root) to the
 * slugs it affects.
 */
function affected_slugs_for_path( $manifest, $path ) {
	$shared_prefixes = array(
		'Makefile',
		'phpcs.xml',
		'plugins.json',
		'bin/',
		'tests/bootstrap.php',
		'tests/_helpers/',
		'tests/_mocks/',
		'tests/phpunit.xml',
		'docker/',
		'.github/',
	);

	foreach ( $shared_prefixes as $prefix ) {
		if ( $path === $prefix || 0 === strpos( $path, $prefix ) ) {
			return array_column( $manifest['plugins'], 'slug' );
		}
	}

	foreach ( array( 'tests/unit/', 'tests/integration/' ) as $test_prefix ) {
		if ( 0 === strpos( $path, $test_prefix ) ) {
			$rest = substr( $path, strlen( $test_prefix ) );
			$dir  = strtok( $rest, '/' );
			foreach ( $manifest['plugins'] as $plugin ) {
				if ( $plugin['tests_dir'] === $dir ) {
					return array( $plugin['slug'] );
				}
			}
			return array();
		}
	}

	foreach ( $manifest['plugins'] as $plugin ) {
		$prefix = rtrim( $plugin['dir'], '/' ) . '/';
		if ( 0 === strpos( $path, $prefix ) ) {
			return array( $plugin['slug'] );
		}
	}

	return array();
}

function cmd_check_headers( $manifest, $root, $only_slug ) {
	$failed = false;

	foreach ( $manifest['plugins'] as $plugin ) {
		if ( null !== $only_slug && $plugin['slug'] !== $only_slug ) {
			continue;
		}

		$main_file_path = $root . '/' . $plugin['dir'] . '/' . $plugin['main_file'];
		$readme_path    = $root . '/' . $plugin['dir'] . '/readme.txt';

		if ( ! file_exists( $main_file_path ) ) {
			// Plugin checkout not present locally — nothing to check against.
			continue;
		}

		$header = parse_plugin_header( $main_file_path );
		$readme = parse_readme_header( $readme_path );

		$actual_requires_php = $header['requires_php'] ?: ( $readme['requires_php'] ?? null );
		$actual_requires_wp  = $header['requires_wp'] ?: ( $readme['requires_wp'] ?? null );

		if ( $plugin['requires_php'] !== $actual_requires_php ) {
			fwrite( STDERR, sprintf(
				"MISMATCH [%s]: manifest requires_php=%s but plugin header says %s\n",
				$plugin['slug'],
				var_export( $plugin['requires_php'], true ),
				var_export( $actual_requires_php, true )
			) );
			$failed = true;
		}

		if ( $plugin['requires_wp'] !== $actual_requires_wp ) {
			fwrite( STDERR, sprintf(
				"MISMATCH [%s]: manifest requires_wp=%s but plugin header says %s\n",
				$plugin['slug'],
				var_export( $plugin['requires_wp'], true ),
				var_export( $actual_requires_wp, true )
			) );
			$failed = true;
		}

		// Advisory only — "Tested up to" / "WC tested up to" live entirely
		// in the plugin's own readme/header (see live_tested_up_to()), so a
		// plugin bumping either never fails this check or needs a matching
		// plugins.json edit. This just flags when a plugin has fallen
		// BEHIND what the harness currently tests against, as a nudge for
		// a manual bump — never exits non-zero.
		$tested   = live_tested_up_to( $root, $plugin );
		$defaults = $manifest['defaults'];

		if ( null !== $tested['wp'] && version_compare( $tested['wp'], $defaults['wp_latest_tested'], '<' ) ) {
			echo "NOTE [{$plugin['slug']}]: readme 'Tested up to' ({$tested['wp']}) is behind the harness's wp_latest_tested ({$defaults['wp_latest_tested']}).\n";
		}

		if ( in_array( 'woocommerce', $plugin['dependencies'], true ) && null !== $tested['wc']
			&& version_compare( $tested['wc'], $defaults['wc_latest_tested'], '<' ) ) {
			echo "NOTE [{$plugin['slug']}]: 'WC tested up to' ({$tested['wc']}) is behind the harness's wc_latest_tested ({$defaults['wc_latest_tested']}).\n";
		}
	}

	if ( $failed ) {
		exit( 1 );
	}

	echo "OK: manifest requires_php/requires_wp match plugin headers.\n";
}

// ── Main ──────────────────────────────────────────────────────

$args = $argv;
array_shift( $args );
$command = array_shift( $args );

$manifest = manifest_load( $manifest_path );

switch ( $command ) {

	case 'dirs':
		print_scalar( array_column( $manifest['plugins'], 'dir' ) );
		break;

	case 'install-dirs':
		print_scalar( array_column( $manifest['plugins'], 'install_dir' ) );
		break;

	case 'slugs':
		print_scalar( array_column( $manifest['plugins'], 'slug' ) );
		break;

	case 'wporg-slugs':
		$slugs = array();
		foreach ( $manifest['plugins'] as $plugin ) {
			if ( ! empty( $plugin['wporg'] ) ) {
				$slugs[] = $plugin['slug'];
			}
		}
		print_scalar( $slugs );
		break;

	case 'field':
		$slug  = $args[0] ?? null;
		$field = $args[1] ?? null;
		if ( null === $slug || null === $field ) {
			fwrite( STDERR, "Usage: manifest.php field <slug> <dotted.field>\n" );
			exit( 1 );
		}
		$plugin = manifest_find( $manifest, $slug );
		if ( null === $plugin ) {
			fwrite( STDERR, "Unknown plugin slug: {$slug}\n" );
			exit( 1 );
		}
		// "wc.tested" / "tested_up_to" are never hand-maintained in
		// plugins.json (see live_tested_up_to()) — read live instead.
		if ( 'wc.tested' === $field ) {
			print_scalar( live_tested_up_to( $root, $plugin )['wc'] );
		} elseif ( 'tested_up_to' === $field ) {
			print_scalar( live_tested_up_to( $root, $plugin )['wp'] );
		} else {
			print_scalar( dotted_get( $plugin, $field ) );
		}
		break;

	case 'default':
		$field = $args[0] ?? null;
		if ( null === $field ) {
			fwrite( STDERR, "Usage: manifest.php default <dotted.field>\n" );
			exit( 1 );
		}
		print_scalar( dotted_get( $manifest['defaults'], $field ) );
		break;

	case 'get':
		$slug = $args[0] ?? null;
		if ( null === $slug ) {
			fwrite( STDERR, "Usage: manifest.php get <slug>\n" );
			exit( 1 );
		}
		$plugin = manifest_find( $manifest, $slug );
		if ( null === $plugin ) {
			fwrite( STDERR, "Unknown plugin slug: {$slug}\n" );
			exit( 1 );
		}
		echo json_encode( $plugin, JSON_PRETTY_PRINT ) . "\n";
		break;

	case 'text-domains':
		$slug = $args[0] ?? null;
		if ( null === $slug ) {
			fwrite( STDERR, "Usage: manifest.php text-domains <slug>\n" );
			exit( 1 );
		}
		$plugin = manifest_find( $manifest, $slug );
		if ( null === $plugin ) {
			fwrite( STDERR, "Unknown plugin slug: {$slug}\n" );
			exit( 1 );
		}
		print_scalar( $plugin['text_domains'] );
		break;

	case 'matrix':
		$selected = array_column( $manifest['plugins'], 'slug' );
		foreach ( $args as $arg ) {
			if ( 0 === strpos( $arg, '--plugins=' ) ) {
				$selected = explode( ',', substr( $arg, strlen( '--plugins=' ) ) );
			}
		}
		echo json_encode( build_matrix( $manifest, $selected ), JSON_PRETTY_PRINT ) . "\n";
		break;

	case 'nightly-matrix':
		$selected = array_column( $manifest['plugins'], 'slug' );
		foreach ( $args as $arg ) {
			if ( 0 === strpos( $arg, '--plugins=' ) ) {
				$selected = explode( ',', substr( $arg, strlen( '--plugins=' ) ) );
			}
		}
		echo json_encode( build_nightly_matrix( $manifest, $selected ), JSON_PRETTY_PRINT ) . "\n";
		break;

	case 'affected':
		$paths = $args;
		if ( array( '-' ) === $paths || in_array( '-', $paths, true ) ) {
			$paths = array_filter( array_map( 'trim', explode( "\n", stream_get_contents( STDIN ) ) ) );
		}
		$affected = array();
		foreach ( $paths as $path ) {
			foreach ( affected_slugs_for_path( $manifest, $path ) as $slug ) {
				$affected[ $slug ] = true;
			}
		}
		print_scalar( array_keys( $affected ) );
		break;

	case 'check-headers':
		cmd_check_headers( $manifest, $root, $args[0] ?? null );
		break;

	case 'container-plugin-paths':
		$paths = array();
		foreach ( $manifest['plugins'] as $plugin ) {
			$paths[] = '/var/www/html/wp-content/plugins/' . $plugin['install_dir'];
		}
		print_scalar( $paths );
		break;

	case 'lint-ignore':
		// Comma-separated phpcs --ignore patterns for every plugin's lint_exclude.
		$patterns = array();
		foreach ( $manifest['plugins'] as $plugin ) {
			foreach ( $plugin['lint_exclude'] as $exclude ) {
				$patterns[] = '*/' . $plugin['install_dir'] . '/' . trim( $exclude, '/' ) . '/*';
			}
		}
		echo implode( ',', $patterns ) . "\n";
		break;

	case 'compose':
		echo "# Generated by `make compose` from plugins.json. Do not edit by hand.\n";
		echo "services:\n";
		echo "  wordpress:\n";
		echo "    volumes:\n";
		foreach ( $manifest['plugins'] as $plugin ) {
			echo "      - ../{$plugin['dir']}:/var/www/html/wp-content/plugins/{$plugin['install_dir']}\n";
		}
		break;

	default:
		fwrite( STDERR, "Unknown command: {$command}\n" );
		fwrite( STDERR, "Run without arguments to see usage.\n" );
		exit( 1 );
}
