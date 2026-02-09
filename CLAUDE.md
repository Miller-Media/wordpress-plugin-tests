# WordPress Plugins - Testing Infrastructure

## Architecture

This repository contains **6 WordPress plugins** (each in its own subdirectory/git repo) plus a **shared testing infrastructure** at the top level.

```
WordPress Plugins/
├── docker/                    # Docker environment (WordPress + MySQL)
├── tests/                     # All tests live here (outside plugin dirs)
│   ├── bootstrap.php          # Loads WP test suite + plugins + mocks
│   ├── phpunit.xml            # PHPUnit config with unit/integration suites
│   ├── _helpers/              # Base test case classes
│   ├── _mocks/                # API mock interceptors + JSON fixtures
│   ├── unit/                  # Unit tests per plugin
│   └── integration/           # Live API tests (skipped without creds)
├── phpcs.xml                  # PHPCS config for all plugins
├── Makefile                   # Command runner
└── [6 plugin directories]     # Each is its own git repo
```

## Quick Start

```bash
make start          # Boot Docker (WordPress at http://localhost:8080)
make test           # Run all tests
make test-unit      # Unit tests only
make lint           # PHPCS lint all plugins
make shell          # Shell into the WordPress container
```

## Plugins

| Plugin | Directory | Purpose |
|--------|-----------|---------|
| IP Blacklist for Cloudflare | `cloudflare-ip-blacklist/` | Blacklists IPs via Cloudflare firewall rules when banned usernames are used |
| Edit Usernames | `edit-usernames/` | Allows admins to change WordPress usernames |
| Expire User Passwords | `expire-passwords/` | Forces password resets after configurable number of days |
| Protect the Children | `protect-the-children/` | Password protects child pages when parent is protected |
| Send Emails with Mandrill | `send-emails-with-mandrill/` | Replaces wp_mail() with Mandrill API |
| Wooflare | `wooflare/` | Clears Cloudflare cache on WooCommerce events |

## Testing Strategy

### Unit Tests (`tests/unit/`)
- Run without external dependencies
- API calls intercepted by mocks in `tests/_mocks/`
- Use `PluginTestCase` base class
- Safe to run anytime: `make test-unit`

### Integration Tests (`tests/integration/`)
- Use live APIs (Cloudflare, Mandrill)
- Auto-skip when credentials missing
- Use `IntegrationTestCase` base class
- Set credentials in `tests/.env` (copy from `.env.example`)

### API Mocking
Mocks use WordPress's `pre_http_request` filter to intercept HTTP before it leaves. No external mock server needed.

- `CloudflareAPIMock` - Intercepts `api.cloudflare.com` requests
- `MandrillAPIMock` - Intercepts `mandrillapp.com` requests

Both support:
- Request logging for assertions (`::get_request_log()`, `::get_last_request()`)
- Custom response overrides (`::set_response($pattern, $data)`)
- Fixture-based default responses (`tests/_mocks/fixtures/`)

## Running Tests for a Single Plugin

```bash
make test-plugin PLUGIN=cloudflare-ip-blacklist
make test-plugin PLUGIN=edit-usernames
make test-plugin PLUGIN=expire-passwords
make test-plugin PLUGIN=protect-the-children
make test-plugin PLUGIN=send-emails-with-mandrill
make test-plugin PLUGIN=wooflare
```

## Linting

```bash
make lint                           # All plugins
make lint-fix                       # Auto-fix
make lint-plugin PLUGIN=wooflare    # Single plugin
```

PHPCS is configured with WordPress Coding Standards + PHPCompatibilityWP for PHP 8.1+.

## Adding Tests

1. Create test file in `tests/unit/<plugin-name>/YourTest.php`
2. Extend `PluginTestCase` (unit) or `IntegrationTestCase` (live API)
3. Name the file with `Test.php` suffix
4. Run `make test` to verify

## Key Design Decisions

- **Tests live outside plugin directories** so released plugins stay clean
- **Docker provides isolated environment** - no local PHP/MySQL needed
- **Separate test database** (`db-test` service) prevents test data from polluting dev site
- **WooCommerce auto-installed** in Docker for Wooflare compatibility
- **PHP 8.1** target runtime (matches production)

## Releasing a Plugin

Each plugin is its own git repo and is published to WordPress.org via SVN. Follow every step below when releasing a new version.

### 1. Version Bump

Each plugin stores its version in **3 places** — update all three:

| Location | Example |
|----------|---------|
| Main plugin file header (`Version:`) | `* Version: 1.0.4` |
| Main plugin file constant | `define('CFIP_PLUGIN_VERSION', '1.0.4');` |
| `readme.txt` (`Stable tag:`) | `Stable tag: 1.0.4` |

Version constants per plugin:

| Plugin | Main File | Constant |
|--------|-----------|----------|
| cloudflare-ip-blacklist | `ip-blacklist-for-cloudflare.php` | `CFIP_PLUGIN_VERSION` |
| edit-usernames | `edit-usernames.php` | `EUN_PLUGIN_VERSION` |
| expire-passwords | `expire-user-passwords.php` | `EXPIRE_USER_PASSWORDS_VERSION` |
| protect-the-children | `protect-the-children.php` | `PTC_PLUGIN_VERSION` |
| send-emails-with-mandrill | `wpmandrill.php` | `SEWM_VERSION` |
| wooflare | `wooflare.php` | `WOOCF_PLUGIN_VERSION` |

### 2. Changelog

Add a new entry at the top of the `== Changelog ==` section in `readme.txt`:

```
= 1.0.4 =
* Description of change
```

### 3. Test

```bash
make test                              # All plugins
make test-plugin PLUGIN=plugin-name    # Single plugin
make lint-plugin PLUGIN=plugin-name    # Lint
```

All tests must pass before committing.

### 4. Git Commit, Tag, Push

```bash
cd plugin-directory/
git add <changed files>
git commit -m "Description of changes"
git push origin master
git tag X.Y.Z
git push origin X.Y.Z
```

### 5. SVN Deploy

SVN working copies live in `svn-deploy/<svn-slug>/` (checked out from trunk).

```bash
# Copy changed files from git repo to SVN trunk
cp plugin-directory/changed-file.php svn-deploy/svn-slug/changed-file.php

# Commit trunk
cd svn-deploy/svn-slug/
svn commit -m "X.Y.Z: Summary of changes"

# Create tag (server-side copy)
svn copy https://plugins.svn.wordpress.org/svn-slug/trunk \
         https://plugins.svn.wordpress.org/svn-slug/tags/X.Y.Z \
         -m "Tag X.Y.Z"
```

SVN slugs (differ from directory names for some plugins):

| Directory | SVN Slug |
|-----------|----------|
| `cloudflare-ip-blacklist` | `ip-blacklist-cloudflare` |
| `edit-usernames` | `edit-usernames` |
| `expire-passwords` | `expire-user-passwords` |
| `protect-the-children` | `protect-the-children` |
| `send-emails-with-mandrill` | `send-emails-with-mandrill` |
| `wooflare` | `wooflare` |

### 6. GitHub Release

```bash
cd plugin-directory/
gh release create X.Y.Z --title "X.Y.Z" --notes "* Change 1
* Change 2"
```

### Quick Checklist

- [ ] Version bumped in 3 places (header, constant, readme stable tag)
- [ ] Changelog added to readme.txt
- [ ] `make test` passes
- [ ] Git commit + push
- [ ] Git tag + push
- [ ] SVN trunk committed
- [ ] SVN tag created
- [ ] GitHub release created
