#!/usr/bin/env bash
#
# Build a distributable zip of the plugin for the WordPress.org repository.
#
# Copies runtime files (excluding everything listed in .distignore) into a
# single top-level plugin directory and zips it up as
# dist/client-side-media-everywhere.zip.

set -euo pipefail

PLUGIN_SLUG="client-side-media-everywhere"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${DIST_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${DIST_DIR}/${PLUGIN_SLUG}.zip"

rm -rf "${BUILD_DIR}" "${ZIP_FILE}"
mkdir -p "${BUILD_DIR}"

rsync -a --exclude-from="${ROOT_DIR}/.distignore" "${ROOT_DIR}/" "${BUILD_DIR}/"

( cd "${DIST_DIR}" && zip -rq "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}" )

rm -rf "${BUILD_DIR}"

echo "Built ${ZIP_FILE}"
