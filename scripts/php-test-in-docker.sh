#!/bin/sh
# Runs the PHP library tests inside an official PHP image. If a matching
# extension build exists in dist/ (see build-in-docker.sh), it is loaded and
# skipped tests count as failures, so an extension that cannot be loaded
# fails the run. The container is prepared exactly as docs/release.md tells
# users to (Alpine: libgcc).
# Usage: scripts/php-test-in-docker.sh [php-image] [phpunit args...]
set -eu
IMAGE="${1:-mirror.gcr.io/library/php:8.4-cli-bookworm}"
[ $# -gt 0 ] && shift
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
docker run --rm -v "$ROOT:/src:ro" -w /src "$IMAGE" sh -euc '
  if command -v apk >/dev/null; then apk add --no-cache libgcc >/dev/null; fi
  VER=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")
  TS=$(php -r "echo PHP_ZTS ? \"zts\" : \"nts\";")
  LIBC=$(if ldd --version 2>&1 | grep -qi musl; then echo musl; else echo glibc; fi)
  SO="/src/dist/pdf_preflight-php$VER-$TS-$(uname -m)-$LIBC.so"
  CACHE="--cache-directory /tmp/phpunit-cache"
  if [ -f "$SO" ]; then
    echo "Using extension $SO"
    php -d extension="$SO" -r "exit(extension_loaded(\"pdf_preflight\") ? 0 : 1);" || { echo "extension cannot be loaded"; exit 1; }
    exec php -d extension="$SO" vendor/bin/phpunit $CACHE --fail-on-skipped "$@"
  fi
  echo "No extension build for PHP $VER-$TS found in dist/ – integration tests will be skipped"
  exec php vendor/bin/phpunit $CACHE "$@"
' sh "$@"
