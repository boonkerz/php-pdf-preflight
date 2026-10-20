#!/bin/sh
# Verifies that all version declarations match the release version.
# Usage: scripts/check-version.sh 0.1.0
set -eu
VERSION="$1"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STATUS=0
check() {
  if [ "$2" = "$3" ]; then echo "ok    $1: $2"; else echo "FAIL  $1: '$2', expected '$3'"; STATUS=1; fi
}
cargo=$(sed -n 's/^version = "\(.*\)"/\1/p' "$ROOT/extension/Cargo.toml" | head -1)
stub=$(sed -n "s/.*public const VERSION = '\(.*\)';/\1/p" "$ROOT/extension/pdf_preflight.stub.php")
compat=$(sed -n "s/.*public const EXTENSION_VERSION = '\(.*\)';/\1/p" "$ROOT/php/src/Analysis/NativePdfAnalyzer.php")
composer_req=$(sed -n 's/.*"ext-pdf_preflight": "\([0-9.]*\)\.\*".*/\1/p' "$ROOT/composer.json")
composer_platform=$(sed -n 's/.*"ext-pdf_preflight": "\([0-9.]*[0-9]\)".*/\1/p' "$ROOT/composer.json")
lock=$(awk '/^name = "pdf_preflight"/{getline; print}' "$ROOT/extension/Cargo.lock" | sed 's/version = "\(.*\)"/\1/')
check "extension/Cargo.toml" "$cargo" "$VERSION"
check "extension/Cargo.lock" "$lock" "$VERSION"
check "extension/pdf_preflight.stub.php" "$stub" "$VERSION"
check "NativePdfAnalyzer::EXTENSION_VERSION" "$compat" "$(echo "$VERSION" | cut -d. -f1,2)"
check "composer.json require ext-pdf_preflight" "$composer_req" "$(echo "$VERSION" | cut -d. -f1,2)"
check "composer.json config.platform ext-pdf_preflight" "$composer_platform" "$VERSION"
exit $STATUS
