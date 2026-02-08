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
