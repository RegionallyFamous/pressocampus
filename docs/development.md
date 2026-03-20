# Development

This guide covers setting up a local development environment, running the build system, and contributing to Pressocampus.

---

## Requirements

- PHP 8.3+
- Composer
- MySQL 8.0+ or MariaDB 10.5+
- A local WordPress install (wp-env, LocalWP, DDEV, or similar)
- Node.js (only needed for the release build step that compresses assets)
- WP-CLI

---

## Getting started

```bash
# Clone the repo
git clone https://github.com/RegionallyFamous/pressocampus.git
cd pressocampus

# Install PHP dependencies
composer install

# Verify the build tools are working
make check
```

---

## Project structure

```
pressocampus/
├── pressocampus.php          Main plugin file, constants, autoloader
├── uninstall.php             Cleanup on plugin deletion
├── includes/
│   ├── class-plugin.php      Central singleton, wires everything together
│   ├── class-installer.php   Activation, deactivation, DB migrations
│   ├── class-cpt.php         Custom post types and taxonomies
│   ├── class-soul.php        Soul and Index management
│   ├── class-mcp-endpoint.php  MCP JSON-RPC dispatcher + 6 tools
│   ├── class-auth.php        OAuth token validation
│   ├── class-oauth-server.php  OAuth 2.1 endpoints (league/oauth2-server)
│   ├── class-resource-index.php  DB index table, search, dedup
│   ├── class-cache.php       Object cache wrapper + rate limiting
│   ├── class-audit-log.php   Activity logging
│   ├── class-discovery.php   /.well-known endpoints
│   ├── class-settings.php    WordPress admin UI
│   ├── class-onboarding.php  First-run experience + admin notices
│   └── oauth/
│       ├── class-wp-client-repository.php
│       ├── class-wp-access-token-repository.php
│       ├── class-wp-auth-code-repository.php
│       ├── class-wp-refresh-token-repository.php
│       ├── class-wp-scope-repository.php
│       ├── class-psr7-bridge.php   WP → PSR-7 adapter
│       └── class-user-entity.php
├── bin/
│   ├── wp-cli.php            WP-CLI command definitions
│   ├── build.sh              Distribution zip builder
│   └── install-wp-tests.sh   PHPUnit WordPress test suite installer
├── tests/
│   ├── bootstrap.php         PHPUnit bootstrap
│   ├── class-testcase.php    Custom base class (bypasses WP_UnitTestCase for PHPUnit 12 compat)
│   └── MCPDispatcherTest.php Integration tests
├── languages/                Translation strings
├── .phpcs.xml                PHPCS configuration
├── phpstan.neon              PHPStan configuration
├── phpstan-baseline.neon     PHPStan baseline (pre-existing issues)
├── phpunit.xml               PHPUnit configuration
├── Makefile                  Development task runner
├── composer.json             Dependencies and scripts
└── .github/workflows/ci.yml  GitHub Actions CI
```

---

## Build system

### Makefile targets

| Target | Description |
|--------|-------------|
| `make install` | Install Composer dependencies |
| `make install-tests` | Set up WordPress test suite (requires DB config) |
| `make test` | Run PHPUnit tests |
| `make test-coverage` | Run tests with code coverage report |
| `make lint` | Check coding standards with PHPCS |
| `make lint-fix` | Auto-fix coding standards with PHPCBF |
| `make analyse` | Run PHPStan static analysis |
| `make check` | Full CI check: lint + analyse + test |
| `make build` | Build distributable plugin zip |
| `make clean` | Remove build artifacts |

### Composer scripts

The same tasks are available via Composer:

```bash
composer test          # PHPUnit
composer lint          # PHPCS
composer lint:fix      # PHPCBF
composer analyse       # PHPStan
composer check         # lint + analyse + test
composer build         # build zip
```

---

## Running tests

### Setup (first time only)

The test suite requires a dedicated MySQL database and a downloaded copy of WordPress core.

```bash
# Set up with default local MySQL (no password)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Set up with a password
bash bin/install-wp-tests.sh wordpress_test root 'mypassword' localhost latest

# Or use the Makefile target (set env vars first)
export DB_NAME=wordpress_test DB_USER=root DB_PASS='' DB_HOST=localhost
make install-tests
```

### Running

```bash
# Run all tests
make test

# Run with verbose output
vendor/bin/phpunit --testdox

# Run a specific test
vendor/bin/phpunit --filter test_remember_creates_memory

# Run with coverage (requires Xdebug or PCOV)
make test-coverage
```

### Test coverage

Tests live in `tests/MCPDispatcherTest.php` and cover:
- MCP `initialize` handshake
- All 6 MCP tools (success and error cases)
- Rate limiting behavior
- Soul protection (can't be forgotten)
- ETag conflict detection
- Per-user data isolation

---

## Code quality

### PHPCS (coding standards)

Pressocampus follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).

```bash
# Check
make lint

# Auto-fix
make lint-fix
```

Configuration is in `.phpcs.xml`. Notable exceptions from the default WordPress rules:
- **Yoda conditions** — disabled (PHP 8.3 strict types make this unnecessary)
- **Short ternary** — allowed (PHP 8.3+)
- **Multiple classes per file** — allowed in `class-psr7-bridge.php` and OAuth repositories (by design)

### PHPStan (static analysis)

```bash
make analyse
```

Running at level 6 against all plugin PHP files. A `phpstan-baseline.neon` captures pre-existing issues in the initial build — all new code must be clean.

To regenerate the baseline after fixing issues:

```bash
vendor/bin/phpstan analyse --generate-baseline
```

### CI pipeline

Every push and pull request runs the full pipeline via GitHub Actions (`.github/workflows/ci.yml`):

1. **Lint** — PHPCS on PHP 8.3
2. **Analyse** — PHPStan on PHP 8.3
3. **Test** — PHPUnit matrix:
   - PHP 8.3 + WordPress latest
   - PHP 8.4 + WordPress latest
   - PHP 8.3 + WordPress 6.7
4. **Build** — creates distributable zip on merge to `main`

---

## Building a release

```bash
make build
```

This runs `bin/build.sh` which:
1. Runs `composer install --no-dev --optimize-autoloader`
2. Copies only production files (per `.buildignore`)
3. Creates `build/pressocampus-{version}.zip`

The version is read from the `Version:` header in `pressocampus.php`.

---

## Contributing

### Reporting bugs

Open a [GitHub Issue](https://github.com/RegionallyFamous/pressocampus/issues) with:
- WordPress version
- PHP version
- Steps to reproduce
- Expected vs actual behavior
- Relevant error messages or logs

### Pull requests

1. Fork the repo
2. Create a branch: `git checkout -b feature/my-feature`
3. Make your changes
4. Ensure `make check` passes
5. Open a pull request against `main`

### Commit style

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add TTL controls to remember tool
fix: prevent duplicate soul creation on concurrent initialize
docs: add troubleshooting section for CORS errors
refactor: extract rate limiter into its own class
test: add coverage for ETag conflict scenario
```

### Adding new MCP tools

1. Add the tool definition to the `tools_list()` method in `class-mcp-endpoint.php`
2. Add the dispatch case in `dispatch_tool()`
3. Implement the handler method following the `tool_remember()` pattern
4. Write tests in `tests/test-mcp-dispatcher.php`
5. Document the tool in `docs/mcp-tools-reference.md`

### Database migrations

Schema changes go in `class-installer.php` in `run_migrations()`. Use `dbDelta()` for table changes. Increment `DB_VERSION` in `pressocampus.php`.

---

## Local development tips

### Viewing MCP traffic

The full request/response cycle is logged to the PHP error log when `WP_DEBUG` is enabled. Set in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Resetting plugin state

```bash
# Deactivate and reactivate (runs migrations fresh)
wp plugin deactivate pressocampus && wp plugin activate pressocampus

# Flush rewrite rules if endpoints return 404
wp rewrite flush
```

### Testing OAuth without a real AI client

Use curl to simulate the MCP initialize call:

```bash
# First, get a token (requires completing OAuth flow)
TOKEN="your-access-token"

# Initialize
curl -X POST https://localhost/brain \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"test","version":"1.0"},"capabilities":{}},"id":1}'
```
