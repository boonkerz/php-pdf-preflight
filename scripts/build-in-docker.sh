#!/bin/sh
# Builds and tests the extension inside an official PHP Docker image.
# Usage: scripts/build-in-docker.sh <php-image>   e.g. php:8.2-cli
# Output: dist/pdf_preflight-<php-version>-<nts|zts>-<arch>-<libc>.so
set -eu
IMAGE="${1:-mirror.gcr.io/library/php:8.4-cli-bookworm}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
docker run --rm -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" -v "$ROOT:/src" -w /src "$IMAGE" sh -euc '
  if command -v apk >/dev/null; then
    apk add --no-cache curl gcc musl-dev clang-dev llvm-dev >/dev/null
  else
    apt-get update -qq && apt-get install -y -qq --no-install-recommends curl ca-certificates gcc libc6-dev clang libclang-dev >/dev/null
  fi
  curl -sSf https://sh.rustup.rs | sh -s -- -y -q --profile minimal >/dev/null
  . "$HOME/.cargo/env"
  export CARGO_TARGET_DIR=/tmp/target
  cd extension
  # musl targets default to static CRT, which cdylibs do not support
  RUSTFLAGS="-C target-feature=-crt-static" cargo build --release --locked
  cd ..
  SO=/tmp/target/release/libpdf_preflight.so
  php -n -d extension=$SO extension/tests/php/extension_test.php
  sh scripts/check-deps.sh $SO
  VER=$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")
  TS=$(php -r "echo PHP_ZTS ? \"zts\" : \"nts\";")
  LIBC=$(if ldd --version 2>&1 | grep -qi musl; then echo musl; else echo glibc; fi)
  mkdir -p dist
  cp $SO "dist/pdf_preflight-php$VER-$TS-$(uname -m)-$LIBC.so"
  chown -R "$HOST_UID:$HOST_GID" dist
  ls -la dist
'
