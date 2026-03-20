#!/usr/bin/env bash
# Build a distributable plugin zip for WordPress.org or direct install.
#
# Output: build/pressocampus.zip
#
# Usage: bash bin/build.sh [version]
#   version — override the version string in the zip name (default: reads from plugin header)

set -euo pipefail

PLUGIN_SLUG="pressocampus"
BUILD_DIR="build"
DIST_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
IGNORE_FILE=".buildignore"

# ─── Resolve version ──────────────────────────────────────────────────────────

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    VERSION=$(grep -m1 "^ \* Version:" pressocampus.php | awk '{print $3}')
fi
if [[ -z "$VERSION" ]]; then
    VERSION="dev"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

echo "Building Pressocampus v${VERSION}..."

# ─── Clean previous build ─────────────────────────────────────────────────────

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# ─── Create .buildignore if it doesn't exist ──────────────────────────────────

if [[ ! -f "$IGNORE_FILE" ]]; then
    cat > "$IGNORE_FILE" << 'EOF'
.git/
.github/
.gitignore
.buildignore
.phpcs.xml
bin/
build/
node_modules/
tests/
phpunit.xml
phpstan.neon
phpstan-baseline.neon
Makefile
composer.json
composer.lock
vendor/bin/
vendor/dealerdirect/
vendor/composer/
vendor/squizlabs/
vendor/phpstan/
vendor/szepeviktor/
vendor/php-stubs/
EOF
fi

# ─── Install production-only Composer dependencies ────────────────────────────

echo "Installing production Composer dependencies..."
composer install --no-dev --optimize-autoloader --quiet

# ─── Copy plugin files ────────────────────────────────────────────────────────

echo "Copying files to $DIST_DIR/..."

rsync \
    --archive \
    --exclude-from="$IGNORE_FILE" \
    --exclude=".git" \
    --exclude="build/" \
    ./ "$DIST_DIR/"

# ─── Create zip ───────────────────────────────────────────────────────────────

echo "Creating $ZIP_PATH..."
rm -f "$ZIP_PATH"

(cd "$BUILD_DIR" && zip -r "../$ZIP_PATH" "$PLUGIN_SLUG" -x "*.DS_Store" -x "__MACOSX/*")

# ─── Re-install dev dependencies (so local dev env is restored) ───────────────

echo "Restoring dev dependencies..."
composer install --quiet

# ─── Summary ──────────────────────────────────────────────────────────────────

ZIP_SIZE=$(du -sh "$ZIP_PATH" | cut -f1)
FILE_COUNT=$(unzip -l "$ZIP_PATH" | tail -1 | awk '{print $2}')

echo ""
echo "✓ Build complete."
echo ""
echo "  File:    $ZIP_PATH"
echo "  Size:    $ZIP_SIZE"
echo "  Files:   $FILE_COUNT"
echo ""
echo "To install: upload $ZIP_NAME to WordPress → Plugins → Add New → Upload Plugin"
