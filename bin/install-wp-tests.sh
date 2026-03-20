#!/usr/bin/env bash
# Install the WordPress test library and a test database.
#
# Usage:
#   bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Examples:
#   bash bin/install-wp-tests.sh pressocampus_test root ''
#   bash bin/install-wp-tests.sh pressocampus_test root '' 127.0.0.1 latest
#   bash bin/install-wp-tests.sh pressocampus_test root '' localhost 6.7
#
# Environment variables (override positional args):
#   WP_TESTS_DIR     Where to install the test library (default: /tmp/wordpress-tests-lib)
#   WP_CORE_DIR      Where to install WordPress core (default: /tmp/wordpress)
#   WP_TESTS_BRANCH  SVN branch for the test library, independent of WP core version.
#                    Use "trunk" to get the latest test library (required for PHPUnit 10+).
#                    Defaults to the same value as <wp-version>.

set -euo pipefail

DB_NAME="${1:-pressocampus_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
# WP_TESTS_BRANCH allows the test library SVN path to be overridden independently
# of the WP core version being tested.  Set to "trunk" in CI when using PHPUnit 10+.
WP_TESTS_BRANCH="${WP_TESTS_BRANCH:-}"

TMPDIR="${TMPDIR:-/tmp}"

# ─── Helpers ──────────────────────────────────────────────────────────────────

print_usage() {
    echo "Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]"
    exit 1
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

download() {
    local url="$1"
    local dest="$2"

    if command_exists curl; then
        # --fail: exit non-zero on HTTP 4xx/5xx so a 404 page is never
        # silently saved as the archive (which would cause a confusing tar error).
        curl --silent --location --fail "$url" --output "$dest"
    elif command_exists wget; then
        wget --quiet "$url" --output-document="$dest"
    else
        echo "Error: curl or wget is required."
        exit 1
    fi
}

# ─── Resolve WP version ───────────────────────────────────────────────────────

if [[ "$WP_VERSION" == "latest" ]]; then
    local_version_file="$TMPDIR/wp-latest.json"
    download "https://api.wordpress.org/core/version-check/1.7/" "$local_version_file"
    WP_VERSION=$(php -r "echo json_decode(file_get_contents('$local_version_file'))->offers[0]->version;")
    echo "Resolved latest WordPress version: $WP_VERSION"
fi

# ─── Resolve test library SVN tag ─────────────────────────────────────────────
# WP_TESTS_BRANCH can override the SVN branch independently of WP core.
# "trunk" gets the bleeding-edge test library (supports PHPUnit 10+).
# The WP test library from stable tags (≤ 6.7) only supports PHPUnit ≤ 9.

_TESTS_BRANCH="${WP_TESTS_BRANCH:-$WP_VERSION}"

if [[ "$_TESTS_BRANCH" == "trunk" ]]; then
    WP_TESTS_TAG="trunk"
    echo "Using WP test library from trunk (PHPUnit 10+ compatible)"
else
    WP_TESTS_TAG="tags/$_TESTS_BRANCH"
fi

# ─── Download WordPress core ──────────────────────────────────────────────────

if [[ ! -d "$WP_CORE_DIR/wp-includes" ]]; then
    mkdir -p "$WP_CORE_DIR"

    WP_ARCHIVE="$TMPDIR/wordpress-${WP_VERSION}.tar.gz"
    if [[ ! -f "$WP_ARCHIVE" ]]; then
        echo "Downloading WordPress $WP_VERSION..."
        download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$WP_ARCHIVE"
    fi

    echo "Extracting WordPress to $WP_CORE_DIR..."
    tar --strip-components=1 -zxf "$WP_ARCHIVE" -C "$WP_CORE_DIR"
    rm -f "$WP_ARCHIVE"
else
    echo "WordPress core already at $WP_CORE_DIR — skipping download."
fi

# ─── Download WordPress test library ──────────────────────────────────────────

if [[ ! -d "$WP_TESTS_DIR/includes" ]]; then
    mkdir -p "$WP_TESTS_DIR"

    TESTS_ARCHIVE="$TMPDIR/wp-tests-${WP_VERSION}.tar.gz"

    echo "Downloading WordPress test library ($WP_TESTS_TAG)..."
    SVN_URL="https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/"

    if command_exists svn; then
        svn export --quiet "$SVN_URL" "$WP_TESTS_DIR/includes"
        svn export --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    else
        echo "Error: svn is required to download the WordPress test library."
        echo "Install with: brew install subversion (macOS) or apt install subversion (Ubuntu)"
        exit 1
    fi
else
    echo "WordPress test library already at $WP_TESTS_DIR — skipping download."
fi

# ─── Create wp-tests-config.php ───────────────────────────────────────────────

if [[ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]]; then
    echo "Creating wp-tests-config.php..."

    # Download the sample config from develop.svn
    if [[ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]]; then
        download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" \
            "$WP_TESTS_DIR/wp-tests-config-sample.php"
    fi

    cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

    # Use sed to inject DB credentials
    local_os=$(uname)
    if [[ "$local_os" == "Darwin" ]]; then
        SED="sed -i ''"
    else
        SED="sed -i"
    fi

    $SED "s|youremptytestdbnamehere|$DB_NAME|"    "$WP_TESTS_DIR/wp-tests-config.php"
    $SED "s|yourusernamehere|$DB_USER|"           "$WP_TESTS_DIR/wp-tests-config.php"
    $SED "s|yourpasswordhere|$DB_PASS|"           "$WP_TESTS_DIR/wp-tests-config.php"
    $SED "s|localhost|$DB_HOST|"                  "$WP_TESTS_DIR/wp-tests-config.php"
    # Modern WP test configs use dirname(__FILE__).'/src/' for ABSPATH; legacy configs use /path/to/wordpress/.
    $SED "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|"  "$WP_TESTS_DIR/wp-tests-config.php"
    $SED "s|/path/to/wordpress/|${WP_CORE_DIR}/|"              "$WP_TESTS_DIR/wp-tests-config.php"
fi

# ─── Create test database ─────────────────────────────────────────────────────

echo "Creating test database '$DB_NAME'..."
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true

echo ""
echo "✓ WordPress test suite installed."
echo ""
echo "  WP core:      $WP_CORE_DIR"
echo "  Test library: $WP_TESTS_DIR"
echo "  Test DB:      $DB_NAME @ $DB_HOST"
echo ""
echo "Run tests with: composer test"
echo "            or: make test"
