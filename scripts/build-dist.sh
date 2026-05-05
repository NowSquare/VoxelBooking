#!/usr/bin/env bash
set -euo pipefail

# ═══════════════════════════════════════════════════════
#  VoxelBooking Build Script
#
#  Produces a distributable .zip file suitable for:
#  1. Marketplace distribution
#  2. Fresh installation (upload → extract → run installer)
#  3. In-app updates (Admin → Updates → upload zip)
#
#  How it works:
#  - Copies the working tree (not git HEAD) so uncommitted
#    release changes are always included
#  - Excludes runtime data, dev tooling, and user content
#    via an explicit exclude list
#  - Never mutates the local checkout (vendor/, node_modules/
#    are copied, not rebuilt in-place)
#
#  Usage:
#    ./scripts/build-dist.sh           # Uses version from VERSION file
#    ./scripts/build-dist.sh 1.2.0     # Override version
# ═══════════════════════════════════════════════════════

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"

# ── Read version ──
if [[ -n "${1:-}" ]]; then
  VERSION="$1"
  echo "$VERSION" > "$ROOT_DIR/VERSION"
  echo "📌 Version set to $VERSION"
else
  VERSION="$(cat "$ROOT_DIR/VERSION" 2>/dev/null || echo '1.0.0')"
fi

# Trim whitespace/newlines from version
VERSION="$(echo "$VERSION" | tr -d '[:space:]')"

SLUG="voxelbooking-v${VERSION}"
PKG_DIR="$DIST_DIR/$SLUG"
ZIP_FILE="$DIST_DIR/$SLUG.zip"

echo ""
echo "══════════════════════════════════════════"
echo "  VoxelBooking Build — v${VERSION}"
echo "══════════════════════════════════════════"
echo ""

# ── Step 1: Clean previous build ──
echo "🧹 Cleaning previous build..."
rm -rf "$PKG_DIR"
rm -f "$ZIP_FILE"
mkdir -p "$DIST_DIR"

# ── Step 2: Build production assets (CSS + JS) ──
echo "🎨 Building production assets..."
(cd "$ROOT_DIR" && npm run build)

# ── Step 3: Copy working tree into staging directory ──
# Uses rsync to copy the current working tree state (not git HEAD).
# This ensures uncommitted release changes are always included.
# The exclude list prevents runtime data, dev files, and user content
# from leaking into the package.
echo "📦 Copying working tree to staging..."
mkdir -p "$PKG_DIR"
rsync -a --delete \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='*.env' \
  --exclude='node_modules/' \
  --exclude='dist/' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='.idea/' \
  --exclude='.vscode/' \
  --exclude='*.swp' \
  --exclude='*.swo' \
  --exclude='storage/logs/*.log' \
  --exclude='storage/logs/*.log.*' \
  --exclude='storage/cache/*' \
  --exclude='storage/sessions/' \
  --exclude='storage/demo/' \
  --exclude='.demo' \
  --exclude='public/uploads/*' \
  --exclude='.phpunit.result.cache' \
  --exclude='.phpunit.cache/' \
  --exclude='coverage/' \
  --exclude='.ai/tmp/' \
  --exclude='.ai/screenshots/' \
  --exclude='.ai/reports/' \
  --exclude='.ai/**/*.png' \
  --exclude='.ai/**/*.jpg' \
  --exclude='.superpowers/' \
  --exclude='getMessage' \
  "$ROOT_DIR/" "$PKG_DIR/"

# Stamp the current version
echo "$VERSION" > "$PKG_DIR/VERSION"

# ── Step 4: Remove internal dev files that shouldn't ship ──
echo "🧹 Removing internal dev files..."
rm -rf "$PKG_DIR/scripts"              # Build + audit scripts
rm -rf "$PKG_DIR/.gitignore"           # Not needed in dist
rm -rf "$PKG_DIR/.gitattributes"       # Not needed in dist
rm -rf "$PKG_DIR/.github"             # GitHub config
rm -rf "$PKG_DIR/.ai"                 # AI docs (internal)
rm -rf "$PKG_DIR/.agent"              # Agent workflows (internal)
rm -rf "$PKG_DIR/.gemini"             # Gemini config
rm -f  "$PKG_DIR/CLAUDE.md"           # Claude instructions
rm -f  "$PKG_DIR/package-lock.json"   # npm lockfile
rm -rf "$PKG_DIR/tests"               # PHPUnit test suite
rm -f  "$PKG_DIR/phpunit.xml"         # Test config
rm -rf "$PKG_DIR/.phpunit.cache"      # Test cache
rm -f  "$PKG_DIR/seed.php"            # Dev seed script

# ── Step 5: Ensure directory structure for runtime dirs ──
echo "📁 Ensuring directory structure..."
for dir in \
  "storage/logs" \
  "storage/cache" \
  "public/uploads"; do
  mkdir -p "$PKG_DIR/$dir"
  touch "$PKG_DIR/$dir/.gitkeep"
done

# Ensure uploads .htaccess is present
if [[ -f "$ROOT_DIR/public/uploads/.htaccess" ]]; then
  cp "$ROOT_DIR/public/uploads/.htaccess" "$PKG_DIR/public/uploads/.htaccess"
fi


# ── Step 6: Verify critical files ──
echo "✅ Verifying package..."
MISSING=()
[[ -f "$PKG_DIR/VERSION" ]]                              || MISSING+=("VERSION")
[[ -f "$PKG_DIR/public/index.php" ]]                     || MISSING+=("public/index.php")
[[ -f "$PKG_DIR/public/.htaccess" ]]                     || MISSING+=("public/.htaccess")
[[ -f "$PKG_DIR/public/assets/css/admin-css.css" ]]      || MISSING+=("public/assets/css/admin-css.css")
[[ -f "$PKG_DIR/public/assets/css/booking-css.css" ]]    || MISSING+=("public/assets/css/booking-css.css")
[[ -f "$PKG_DIR/public/assets/js/admin.js" ]]            || MISSING+=("public/assets/js/admin.js")
[[ -f "$PKG_DIR/public/assets/js/booking.js" ]]          || MISSING+=("public/assets/js/booking.js")
[[ -d "$PKG_DIR/vendor" ]]                               || MISSING+=("vendor/")
[[ -d "$PKG_DIR/app" ]]                                  || MISSING+=("app/")
[[ -d "$PKG_DIR/templates" ]]                            || MISSING+=("templates/")
[[ -d "$PKG_DIR/lang" ]]                                 || MISSING+=("lang/")
[[ -d "$PKG_DIR/config" ]]                               || MISSING+=("config/")
[[ -f "$PKG_DIR/app/Engine/Version.php" ]]               || MISSING+=("app/Engine/Version.php")
[[ -f "$PKG_DIR/app/Controllers/Admin/UpdateController.php" ]] || MISSING+=("app/Controllers/Admin/UpdateController.php")
[[ -f "$PKG_DIR/templates/admin/updates.php" ]]          || MISSING+=("templates/admin/updates.php")

if [[ ${#MISSING[@]} -gt 0 ]]; then
  echo "❌ ERROR: Missing critical files in package:"
  printf "   - %s\n" "${MISSING[@]}"
  exit 1
fi

# Count files for sanity check
FILE_COUNT=$(find "$PKG_DIR" -type f | wc -l | tr -d ' ')
echo "   📄 ${FILE_COUNT} files total"

# ── Step 7: Verify NO user/dev data leaked into the package ──
LEAKED=()
[[ ! -f "$PKG_DIR/.env" ]]                || LEAKED+=(".env")
[[ ! -d "$PKG_DIR/.ai" ]]                 || LEAKED+=(".ai/")
[[ ! -d "$PKG_DIR/.agent" ]]              || LEAKED+=(".agent/")
[[ ! -d "$PKG_DIR/tests" ]]               || LEAKED+=("tests/")
[[ ! -f "$PKG_DIR/phpunit.xml" ]]         || LEAKED+=("phpunit.xml")
[[ ! -d "$PKG_DIR/scripts" ]]             || LEAKED+=("scripts/")
[[ ! -d "$PKG_DIR/node_modules" ]]        || LEAKED+=("node_modules/")

# Check no log files leaked
if find "$PKG_DIR/storage/logs" -name "*.log" -type f 2>/dev/null | grep -q .; then
  LEAKED+=("storage/logs/*.log")
fi

if [[ ${#LEAKED[@]} -gt 0 ]]; then
  echo "⚠️  WARNING: Dev/user data found in package:"
  printf "   - %s\n" "${LEAKED[@]}"
  echo "   These files should not be in the distributable."
  # Don't exit — just warn
fi

# ── Step 8: Create the zip ──
echo "🗜️  Creating zip archive..."
(
  cd "$DIST_DIR"
  zip -rq "$ZIP_FILE" "$SLUG/" -x "*.DS_Store" "*__MACOSX*"
)

# ── Step 9: Cleanup staging directory ──
rm -rf "$PKG_DIR"

# ── Done ──
ZIP_SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo ""
echo "══════════════════════════════════════════"
echo "  ✅ Build complete!"
echo ""
echo "  📦 $ZIP_FILE"
echo "  📏 Size: $ZIP_SIZE"
echo "  🏷️  Version: $VERSION"
echo ""
echo "  This zip can be used for:"
echo "  • Marketplace distribution"
echo "  • Fresh installation (extract → point webroot to public/)"
echo "  • In-app update (Admin → Updates → upload)"
echo "══════════════════════════════════════════"
echo ""
