#!/usr/bin/env bash
#
# Build the production distribution of SAC Database Inspector.
#
# Produces build/sac-database-inspector/ (the sanitized plugin directory)
# and build/sac-database-inspector.zip (the installable artifact), honoring
# .distignore so development files never ship. This is the same exclusion
# file the WordPress.org deploy action applies.
#
# Usage: bin/build-dist.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="sac-database-inspector"
BUILD_DIR="${REPO_ROOT}/build"
DIST_DIR="${BUILD_DIR}/${SLUG}"

rm -rf "${DIST_DIR}" "${BUILD_DIR}/${SLUG}.zip"
mkdir -p "${DIST_DIR}"

rsync -a --delete --exclude-from="${REPO_ROOT}/.distignore" "${REPO_ROOT}/" "${DIST_DIR}/"

( cd "${BUILD_DIR}" && zip -rq "${SLUG}.zip" "${SLUG}" )

echo "Built ${BUILD_DIR}/${SLUG}.zip containing:"
( cd "${BUILD_DIR}" && unzip -l "${SLUG}.zip" )
