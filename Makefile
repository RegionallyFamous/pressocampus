# Pressocampus — build commands
# Requires: PHP 8.3+, Composer

.DEFAULT_GOAL := help
.PHONY: help install install-tests test test-coverage lint lint-fix analyse check build clean

# ─── Paths ────────────────────────────────────────────────────────────────────

PLUGIN_SLUG  = pressocampus
BUILD_DIR    = build
DIST_DIR     = $(BUILD_DIR)/$(PLUGIN_SLUG)
PHPCS        = vendor/bin/phpcs
PHPCBF       = vendor/bin/phpcbf
PHPSTAN      = vendor/bin/phpstan
PHPUNIT      = vendor/bin/phpunit

# ─── Help ─────────────────────────────────────────────────────────────────────

help: ## Show this help
	@echo ""
	@echo "  Pressocampus build commands"
	@echo ""
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""

# ─── Dependencies ─────────────────────────────────────────────────────────────

install: ## Install Composer dependencies (prod + dev)
	composer install

install-prod: ## Install Composer production dependencies only
	composer install --no-dev --optimize-autoloader

install-tests: ## Install the WordPress test suite (requires DB credentials)
	@echo "Usage: DB_NAME=wp_test DB_USER=root DB_PASS='' bash bin/install-wp-tests.sh"
	@bash bin/install-wp-tests.sh $(DB_NAME) $(DB_USER) '$(DB_PASS)' $(DB_HOST)

# ─── Testing ──────────────────────────────────────────────────────────────────

test: ## Run PHPUnit tests
	$(PHPUNIT) --testdox

test-coverage: ## Run tests with HTML coverage report (requires Xdebug or PCOV)
	XDEBUG_MODE=coverage $(PHPUNIT) --coverage-html build/coverage --coverage-text

test-filter: ## Run a single test: make test-filter FILTER=test_remember_creates_memory
	$(PHPUNIT) --filter $(FILTER) --testdox

# ─── Linting ──────────────────────────────────────────────────────────────────

lint: ## Check coding standards (PHPCS + WordPress rules)
	$(PHPCS)

lint-fix: ## Auto-fix coding standards issues (PHPCBF)
	$(PHPCBF); true

lint-diff: ## Lint only files changed since last commit
	git diff --name-only --diff-filter=ACM HEAD | grep '\.php$$' | xargs $(PHPCS)

# ─── Static analysis ──────────────────────────────────────────────────────────

analyse: ## Run PHPStan static analysis (level 6)
	$(PHPSTAN) analyse --memory-limit=512M

analyse-baseline: ## Generate PHPStan baseline (suppresses existing errors)
	$(PHPSTAN) analyse --generate-baseline phpstan-baseline.neon --memory-limit=512M

# ─── Full CI check ────────────────────────────────────────────────────────────

check: lint analyse test ## Run lint + analyse + test (full CI suite locally)

# ─── Build ────────────────────────────────────────────────────────────────────

build: ## Build distributable plugin zip (build/pressocampus.zip)
	bash bin/build.sh

build-info: ## Show what will be included in the build
	@echo "Plugin files that will be included:"
	@rsync --dry-run --archive --exclude-from=.buildignore . $(DIST_DIR)/ | grep -v "/$"

# ─── Utilities ────────────────────────────────────────────────────────────────

clean: ## Remove build artifacts
	rm -rf $(BUILD_DIR)
	rm -f phpstan-baseline.neon

version: ## Show current plugin version
	@grep "Version:" pressocampus.php | awk '{print $$3}'

# ─── Git hooks ────────────────────────────────────────────────────────────────

hooks: ## Install Git pre-commit hook (runs lint on staged PHP files)
	@echo '#!/bin/sh\nSTAGED=$(git diff --cached --name-only --diff-filter=ACM | grep "\.php$$")\nif [ -n "$$STAGED" ]; then\n    echo "$$STAGED" | xargs vendor/bin/phpcs\nfi' > .git/hooks/pre-commit
	@chmod +x .git/hooks/pre-commit
	@echo "Pre-commit hook installed."
