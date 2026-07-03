#!/bin/bash
# Script: build.sh
# Purpose: Automated build system for Mandalo Shipping WordPress plugin
# Author: Claude Code / danacosta
# Date: 2025-12-10
# Usage: ./build.sh

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_NAME="mandalo-shipping-for-woocommerce"
PLUGIN_FILE="mandalo-shipping.php"
BACKUP_DIR="${SCRIPT_DIR}/backup/builds"
BUILD_OUTPUT_DIR="${SCRIPT_DIR}"
LOG_FILE="${SCRIPT_DIR}/build.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

log_color() {
    local color=$1
    shift
    echo -e "${color}$*${NC}" | tee -a "$LOG_FILE"
}

# Cleanup trap
trap cleanup EXIT
cleanup() {
    if [ -d "${SCRIPT_DIR}/temp_build" ]; then
        rm -rf "${SCRIPT_DIR}/temp_build"
        log "Cleanup: Removed temporary build directory"
    fi
}

# Main build function
main() {
    log_color "$BLUE" "════════════════════════════════════════════════════════════════"
    log_color "$BLUE" "  Mandalo Shipping - Build Automation System"
    log_color "$BLUE" "════════════════════════════════════════════════════════════════"
    echo ""

    # Step 1: Extract version from plugin file
    log_color "$GREEN" "📋 Step 1: Extracting plugin version..."
    if [ ! -f "${SCRIPT_DIR}/${PLUGIN_FILE}" ]; then
        log_color "$RED" "❌ ERROR: Plugin file not found: ${PLUGIN_FILE}"
        exit 1
    fi

    VERSION=$(grep -m 1 "^ \* Version:" "${SCRIPT_DIR}/${PLUGIN_FILE}" | awk '{print $3}')
    if [ -z "$VERSION" ]; then
        log_color "$RED" "❌ ERROR: Could not extract version from ${PLUGIN_FILE}"
        exit 1
    fi

    log_color "$GREEN" "   ✓ Current version: ${VERSION}"
    echo ""

    # Step 2: Check for existing ZIP
    log_color "$GREEN" "🔍 Step 2: Checking for existing builds..."
    ZIP_FILENAME="${PROJECT_NAME}-v${VERSION}.zip"
    ZIP_PATH="${BUILD_OUTPUT_DIR}/${ZIP_FILENAME}"

    MOVED_OLD_ZIP=false
    if [ -f "$ZIP_PATH" ]; then
        log_color "$YELLOW" "   ⚠ Existing build found: ${ZIP_FILENAME}"

        # Create backup directory if it doesn't exist
        mkdir -p "$BACKUP_DIR"

        # Move old ZIP to backup with timestamp
        TIMESTAMP=$(date +%Y%m%d_%H%M%S)
        BACKUP_PATH="${BACKUP_DIR}/${PROJECT_NAME}-v${VERSION}_${TIMESTAMP}.zip"

        mv "$ZIP_PATH" "$BACKUP_PATH"
        MOVED_OLD_ZIP=true
        log_color "$YELLOW" "   ✓ Moved to: backup/builds/$(basename "$BACKUP_PATH")"
    else
        log "   ✓ No existing build found"
    fi
    echo ""

    # Step 3: Create temporary build directory
    log_color "$GREEN" "📦 Step 3: Preparing build..."
    TEMP_BUILD="${SCRIPT_DIR}/temp_build/${PROJECT_NAME}"
    mkdir -p "$TEMP_BUILD"

    # Files/directories to exclude from ZIP
    EXCLUDE_PATTERNS=(
        ".git"
        ".gitignore"
        ".DS_Store"
        "node_modules"
        "*.log"
        "backup"
        "temp_build"
        "build.sh"
        "*.zip"
        "*.md~"
        ".env"
        ".vscode"
        "phpunit.xml"
        "composer.lock"
        "package-lock.json"
        "tests"
        "docs/PROJECT_SUMMARY.md"
        "*BEFORE_AFTER*"
        "*CHANGELOG*"
        "*CHANGES_*"
        "*CODE_CHANGES*"
        "*CRITICAL_FIX*"
        "*DEPLOY.md"
        "*DEPLOYMENT*.md"
        "*FIXES_*"
        "*IMPLEMENTATION_SUMMARY*"
        "*MIGRATION_*"
        "*QUICKSTART*"
        "*RELEASE_NOTES*"
        "*SUMMARY_*"
        "*TESTING_*"
    )

    # Build rsync exclude arguments
    EXCLUDE_ARGS=()
    for pattern in "${EXCLUDE_PATTERNS[@]}"; do
        EXCLUDE_ARGS+=("--exclude=$pattern")
    done

    # Copy files to temp directory
    rsync -av "${EXCLUDE_ARGS[@]}" \
        --exclude="temp_build/" \
        "${SCRIPT_DIR}/" \
        "$TEMP_BUILD/" > /dev/null 2>&1

    log "   ✓ Files copied to temporary build directory"
    echo ""

    # Step 4: Create ZIP file
    log_color "$GREEN" "🗜️  Step 4: Creating ZIP archive..."
    cd "${SCRIPT_DIR}/temp_build"

    if command -v zip &> /dev/null; then
        zip -r -q "${ZIP_PATH}" "$PROJECT_NAME"
    else
        log_color "$RED" "❌ ERROR: 'zip' command not found"
        exit 1
    fi

    log "   ✓ ZIP created: ${ZIP_FILENAME}"
    echo ""

    # Step 5: Calculate ZIP size
    if [ -f "$ZIP_PATH" ]; then
        ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
        ZIP_SIZE_BYTES=$(stat -f%z "$ZIP_PATH" 2>/dev/null || stat -c%s "$ZIP_PATH" 2>/dev/null)
    else
        log_color "$RED" "❌ ERROR: ZIP file was not created"
        exit 1
    fi

    # Step 6: Verify ZIP structure and count files
    log_color "$GREEN" "🔍 Step 6: Verifying ZIP structure..."

    # Get the root folder name from ZIP
    ROOT_FOLDER=$(unzip -l "$ZIP_PATH" | head -5 | grep -o "${PROJECT_NAME}[^/]*/" | head -1 | sed 's#/##')

    if [ "$ROOT_FOLDER" = "$PROJECT_NAME" ]; then
        log_color "$GREEN" "   ✓ ZIP structure correct: ${PROJECT_NAME}/ (no version in folder name)"
    else
        log_color "$RED" "   ✗ WARNING: ZIP contains folder '${ROOT_FOLDER}/' instead of '${PROJECT_NAME}/'"
        log_color "$YELLOW" "   This may cause WordPress upgrade issues!"
    fi

    FILE_COUNT=$(unzip -l "$ZIP_PATH" | tail -1 | awk '{print $2}')
    echo ""

    # Step 7: Display summary
    echo ""
    log_color "$BLUE" "════════════════════════════════════════════════════════════════"
    log_color "$GREEN" "✅ BUILD COMPLETED SUCCESSFULLY"
    log_color "$BLUE" "════════════════════════════════════════════════════════════════"
    echo ""
    log_color "$YELLOW" "📦 BUILD SUMMARY:"
    echo "   Plugin Name:     ${PROJECT_NAME}"
    echo "   Version:         ${VERSION}"
    echo "   ZIP File:        ${ZIP_FILENAME}"
    echo "   Folder in ZIP:   ${ROOT_FOLDER}/"
    echo "   ZIP Size:        ${ZIP_SIZE} (${ZIP_SIZE_BYTES} bytes)"
    echo "   Files Included:  ${FILE_COUNT}"
    echo ""
    echo "   📍 LOCATION:"
    echo "      • ${BUILD_OUTPUT_DIR}/${ZIP_FILENAME}"
    echo ""

    if [ "$MOVED_OLD_ZIP" = true ]; then
        log_color "$YELLOW" "📁 BACKUP INFO:"
        echo "   • Old build backed up to:"
        echo "     → backup/builds/$(basename "$BACKUP_PATH")"
        echo ""
    fi

    log_color "$BLUE" "════════════════════════════════════════════════════════════════"
    log_color "$GREEN" "🚀 NEXT STEPS:"
    echo ""
    echo "   1. Upload to mandalo.mx via WordPress admin"
    echo "   2. Go to: Plugins → Add New → Upload Plugin"
    echo "   3. Select the ZIP file: ${ZIP_FILENAME}"
    echo "   4. Activate and test the plugin"
    echo ""
    log_color "$BLUE" "════════════════════════════════════════════════════════════════"

    log "Build process completed successfully"
}

# Run if not sourced
if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
    main "$@"
fi
