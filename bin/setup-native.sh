#!/usr/bin/env bash
# Sets up a native (non-Docker) WordPress core + test library environment
# for a given WP version, driven by plugins.json: installs WooCommerce when
# any manifest plugin needs it, symlinks every plugin checkout into
# wp-content/plugins by its install_dir, and writes wp-tests-config.php.
#
# The test library is always checked out from the *same* develop.svn ref as
# core, so they can never drift apart (running one WP version's core against
# another's test suite is a common source of confusing failures). A
# "$TESTS_DIR/.wp-tests-ref" marker records which ref was used, so
# tests/bootstrap.php can detect a stale/mismatched test library later.
#
# Usage:
#   bin/setup-native.sh <wp-version> [options]
#
#   <wp-version> is a WP release like "6.9.1", "latest", "trunk", or
#   "nightly" (latest published beta/RC if one exists — develop.svn
#   tags/<rc> for core+tests, same as any other tagged version; otherwise
#   the WordPress.org nightly build zip for core + develop.svn trunk for
#   tests, since there's no SVN tag for an untagged trunk nightly).
#
# Options (env vars of the same name are also honored; flags win):
#   --core-dir=DIR         WP core destination      (default: /tmp/wp-native/core-<version>)
#   --tests-dir=DIR        WP test library dest.     (default: /tmp/wp-native/tests-lib-<version>)
#   --db-name=NAME         (default: wordpress_test)
#   --db-user=USER         (default: wordpress)
#   --db-pass=PASS         (default: wordpress)
#   --db-host=HOST         (default: localhost)
#   --plugins-root=DIR     Parent dir of plugin checkouts (default: harness repo root)
#   --skip-woocommerce     Don't install WooCommerce even if a plugin needs it
#   --wc-zip=PATH          Use a local WooCommerce zip instead of downloading it
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS_ROOT="$(dirname "$SCRIPT_DIR")"
MANIFEST="$HARNESS_ROOT/plugins.json"

WP_VERSION="${1:-}"
if [ -z "$WP_VERSION" ]; then
	echo "Usage: bin/setup-native.sh <wp-version> [options]" >&2
	exit 1
fi
shift || true

CORE_DIR="${WP_CORE_DIR:-/tmp/wp-native/core-$WP_VERSION}"
TESTS_DIR="${WP_TESTS_DIR:-/tmp/wp-native/tests-lib-$WP_VERSION}"
DB_NAME="${WP_TESTS_DB_NAME:-wordpress_test}"
DB_USER="${WP_TESTS_DB_USER:-wordpress}"
DB_PASS="${WP_TESTS_DB_PASS:-wordpress}"
DB_HOST="${WP_TESTS_DB_HOST:-localhost}"
PLUGINS_ROOT="$HARNESS_ROOT"
SKIP_WOOCOMMERCE=0
WC_ZIP_OVERRIDE="${WC_ZIP_PATH:-}"

for arg in "$@"; do
	case "$arg" in
		--core-dir=*) CORE_DIR="${arg#*=}" ;;
		--tests-dir=*) TESTS_DIR="${arg#*=}" ;;
		--db-name=*) DB_NAME="${arg#*=}" ;;
		--db-user=*) DB_USER="${arg#*=}" ;;
		--db-pass=*) DB_PASS="${arg#*=}" ;;
		--db-host=*) DB_HOST="${arg#*=}" ;;
		--plugins-root=*) PLUGINS_ROOT="${arg#*=}" ;;
		--skip-woocommerce) SKIP_WOOCOMMERCE=1 ;;
		--wc-zip=*) WC_ZIP_OVERRIDE="${arg#*=}" ;;
		*) echo "Unknown option: $arg" >&2; exit 1 ;;
	esac
done

if [ ! -f "$MANIFEST" ]; then
	echo "Manifest not found: $MANIFEST" >&2
	exit 1
fi

echo "==> WP version:   $WP_VERSION"
echo "==> Core dir:      $CORE_DIR"
echo "==> Tests dir:      $TESTS_DIR"

# ── Resolve "latest" / "trunk" / "nightly" to a concrete SVN ref ─
#
# Core and tests always resolve to the SAME ref here, so they can never
# drift apart. The one exception is "nightly" with no published beta/RC:
# there's no SVN tag for an in-progress trunk nightly build, so core comes
# from the nightly-builds zip and tests from develop.svn trunk — the
# NIGHTLY_CORE_FROM_ZIP sentinel below tracks that case.

NIGHTLY_CORE_FROM_ZIP=0

resolve_svn_ref() {
	case "$WP_VERSION" in
		trunk)
			echo "trunk"
			;;
		nightly)
			local rc
			rc="$(curl -s "https://api.wordpress.org/core/version-check/1.7/?channel=beta" \
				| php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["offers"][0]["version"] ?? "";')"
			if [ -n "$rc" ]; then
				echo "tags/$rc"
			else
				NIGHTLY_CORE_FROM_ZIP=1
				echo "trunk"
			fi
			;;
		latest)
			curl -s https://api.wordpress.org/core/version-check/1.7/ \
				| php -r '$d=json_decode(stream_get_contents(STDIN),true); echo "tags/" . $d["offers"][0]["version"];'
			;;
		*)
			echo "tags/$WP_VERSION"
			;;
	esac
}

SVN_REF="$(resolve_svn_ref)"
echo "==> SVN ref:       $SVN_REF"
if [ "$NIGHTLY_CORE_FROM_ZIP" -eq 1 ]; then
	echo "==> No published beta/RC — core from the nightly-builds zip, tests from trunk"
fi

# ── WP core ────────────────────────────────────────────────────

if [ ! -f "$CORE_DIR/wp-load.php" ]; then
	mkdir -p "$CORE_DIR"
	if [ "$NIGHTLY_CORE_FROM_ZIP" -eq 1 ]; then
		echo "==> Downloading WP nightly build core..."
		NIGHTLY_ZIP="$(mktemp /tmp/wp-nightly.XXXXXX.zip)"
		curl -sL -o "$NIGHTLY_ZIP" "https://wordpress.org/nightly-builds/wordpress-latest.zip"
		EXTRACT_DIR="$(mktemp -d /tmp/wp-nightly-extract.XXXXXX)"
		unzip -q -o "$NIGHTLY_ZIP" -d "$EXTRACT_DIR"
		cp -a "$EXTRACT_DIR/wordpress/." "$CORE_DIR/"
		rm -rf "$EXTRACT_DIR" "$NIGHTLY_ZIP"
	else
		echo "==> Exporting WP core ($SVN_REF/src)..."
		svn export --quiet --force "https://develop.svn.wordpress.org/${SVN_REF}/src/" "$CORE_DIR"
	fi
else
	echo "==> WP core already present, skipping download."
fi

# ── WP test library (same ref as core, except the nightly-zip case above
#    which always pairs with trunk tests) ──────────────────────
TESTS_SVN_REF="$SVN_REF"

if [ ! -f "$TESTS_DIR/includes/functions.php" ]; then
	mkdir -p "$TESTS_DIR"
	echo "==> Exporting WP test library ($TESTS_SVN_REF/tests/phpunit)..."
	svn export --quiet --force "https://develop.svn.wordpress.org/${TESTS_SVN_REF}/tests/phpunit/includes/" "$TESTS_DIR/includes"
	svn export --quiet --force "https://develop.svn.wordpress.org/${TESTS_SVN_REF}/tests/phpunit/data/" "$TESTS_DIR/data"
else
	echo "==> WP test library already present, skipping download."
fi

# Strip the "tags/" prefix so the marker's format matches what
# tests/bootstrap.php writes/expects ("6.9.1" or "trunk", never "tags/6.9.1").
echo "${TESTS_SVN_REF#tags/}" > "$TESTS_DIR/.wp-tests-ref"

# ── wp-tests-config.php ────────────────────────────────────────

CONFIG_FILE="$TESTS_DIR/wp-tests-config.php"
if [ ! -f "$CONFIG_FILE" ]; then
	echo "==> Writing $CONFIG_FILE"
	cat > "$CONFIG_FILE" <<PHP
<?php
define( 'ABSPATH', '${CORE_DIR}/' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP
else
	echo "==> $CONFIG_FILE already exists, leaving it alone."
fi

mkdir -p "$CORE_DIR/wp-content/plugins"

# ── WooCommerce (only if some plugin needs it) ─────────────────

NEEDS_WC="$(jq -r '[.plugins[] | select(.dependencies[]? == "woocommerce")] | length > 0' "$MANIFEST")"

if [ "$NEEDS_WC" = "true" ] && [ "$SKIP_WOOCOMMERCE" -eq 0 ]; then
	WC_DIR="$CORE_DIR/wp-content/plugins/woocommerce"
	if [ ! -f "$WC_DIR/woocommerce.php" ]; then
		if [ -n "$WC_ZIP_OVERRIDE" ] && [ -f "$WC_ZIP_OVERRIDE" ]; then
			echo "==> Installing WooCommerce from $WC_ZIP_OVERRIDE..."
			rm -rf "$WC_DIR"
			unzip -q -o "$WC_ZIP_OVERRIDE" -d "$CORE_DIR/wp-content/plugins/"
		else
			echo "==> Installing WooCommerce..."
			WC_ZIP="$(mktemp /tmp/woocommerce.XXXXXX.zip)"
			curl -sL -o "$WC_ZIP" "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"
			rm -rf "$WC_DIR"
			unzip -q -o "$WC_ZIP" -d "$CORE_DIR/wp-content/plugins/"
			rm -f "$WC_ZIP"
		fi
	else
		echo "==> WooCommerce already installed, skipping."
	fi
fi

# ── Symlink plugins by install_dir ─────────────────────────────

jq -r '.plugins[] | "\(.dir)\t\(.install_dir)"' "$MANIFEST" | while IFS=$'\t' read -r dir install_dir; do
	src="$PLUGINS_ROOT/$dir"
	dest="$CORE_DIR/wp-content/plugins/$install_dir"

	if [ ! -d "$src" ]; then
		echo "==> Skipping $dir (not checked out at $src)"
		continue
	fi

	if [ -L "$dest" ] && [ "$(readlink -f "$dest")" = "$(readlink -f "$src")" ]; then
		continue
	fi

	rm -rf "$dest"
	ln -s "$src" "$dest"
	echo "==> Linked $install_dir -> $src"
done

echo "==> Done. Export these before running tests:"
echo "    export WP_TESTS_DIR=$TESTS_DIR"
echo "    export WP_CORE_DIR=$CORE_DIR"
echo "    export WP_TESTS_DB_HOST=$DB_HOST WP_TESTS_DB_NAME=$DB_NAME WP_TESTS_DB_USER=$DB_USER WP_TESTS_DB_PASS=$DB_PASS"
