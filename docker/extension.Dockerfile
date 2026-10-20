# Minimal image that only carries the compiled extension, so that
# application images can copy it (see docs/release.md):
#
#   RUN --mount=type=bind,from=ghcr.io/boonkerz/php-pdf-preflight:0.1.0-php8.4-alpine,source=/pdf_preflight.so,target=/tmp/pdf_preflight.so \
#       apk add --no-cache libgcc \
#    && cp /tmp/pdf_preflight.so "$(php-config --extension-dir)/" && docker-php-ext-enable pdf_preflight
#
# (libgcc only on Alpine; Debian images already contain libgcc_s.)
#
# The build context contains <arch>/pdf_preflight.so for amd64 and arm64;
# buildx selects the file for each target platform (no emulation needed).
FROM scratch
ARG TARGETARCH
COPY ${TARGETARCH}/pdf_preflight.so /pdf_preflight.so
COPY LICENSE-MIT LICENSE-APACHE /licenses/
