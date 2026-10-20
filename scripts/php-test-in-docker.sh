#!/bin/sh
# Runs the PHP library tests inside an official PHP image. If a matching
# extension build exists in dist/ (see build-in-docker.sh), it is loaded so
# that the integration tests run too.
# Usage: scripts/php-test-in-docker.sh [php-image] [phpunit args...]
set -eu
IMAGE="${1:-mirror.gcr.io/library/php:8.4-cli-bookworm}"
[ $# -gt 0 ] && shift
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
docker run --rm --user "$(id -u):$(id -g)" -v "$ROOT:/src" -w /src "$IMAGE" sh -euc '
  VER=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")
  TS=$(php -r "echo PHP_ZTS ? \"zts\" : \"nts\";")
  LIBC=$(if ldd --version 2>&1 | grep -qi musl; then echo musl; else echo glibc; fi)
  SO="/src/dist/pdf_preflight-php$VER-$TS-$(uname -m)-$LIBC.so"
  if [ -f "$SO" ]; then
    echo "Using extension $SO"
    exec php -d extension="$SO" vendor/bin/phpunit "$@"
  fi
  echo "No extension build for PHP $VER-$TS found in dist/ – integration tests will be skipped"
  exec php vendor/bin/phpunit "$@"
' sh "$@"
