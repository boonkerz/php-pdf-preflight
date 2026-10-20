# Veröffentlichung und Einbindung

Das Repository `github.com/boonkerz/php-pdf-preflight` enthält zwei Produkte
mit **einer gemeinsamen Versionsnummer**:

| Produkt | Kanal | Name |
|---|---|---|
| PHP-Library | Packagist | `boonkerz/php-pdf-preflight` |
| Native Extension | GitHub-Release-Assets | `pdf_preflight-php<x.y>-<nts\|zts>-<arch>-<glibc\|musl>.so` + `SHA256SUMS` |
| Native Extension | GitHub Container Registry | `ghcr.io/boonkerz/php-pdf-preflight:<version>-php<x.y>[-zts\|-alpine]` |

Library und Extension werden zusammen versioniert. Die Library akzeptiert
Extensions mit derselben `major.minor`-Version (bei 0.x ist jede
Minor-Version potenziell inkompatibel) und wirft sonst eine
`IncompatibleExtensionException`.

## Einmalige Einrichtung

1. **GitHub-Repository** `boonkerz/php-pdf-preflight` anlegen (öffentlich) und
   den Code pushen.
2. **Packagist:** Auf <https://packagist.org> mit dem GitHub-Konto anmelden,
   *Submit* → `https://github.com/boonkerz/php-pdf-preflight`. Danach in den
   Packagist-Einstellungen die GitHub-Integration aktivieren, damit neue
   Tags automatisch erscheinen.
3. **GHCR:** Die Images werden über das Label `org.opencontainers.image.source`
   mit dem Repository verknüpft und übernehmen dessen Sichtbarkeit
   (öffentlich). Builds können sie ohne Login ziehen.
4. Die Workflows benötigen keine Secrets: Für Releases und GHCR reicht das
   automatische `GITHUB_TOKEN`.

## Ein Release erstellen

```sh
# 1. Version an allen Stellen anheben (Beispiel 0.2.0)
#    extension/Cargo.toml          version = "0.2.0"
#    extension/pdf_preflight.stub.php   public const VERSION = '0.2.0';
#    php/src/Analysis/NativePdfAnalyzer.php  EXTENSION_VERSION = '0.2'
#    composer.json                 "ext-pdf_preflight": "0.2.*" (require)
#                                  "ext-pdf_preflight": "0.2.0" (config.platform)
cd extension && cargo update -p pdf_preflight && cd ..   # Cargo.lock nachziehen
scripts/check-version.sh 0.2.0

# 2. Committen, taggen, pushen
git commit -am "Release 0.2.0"
git tag v0.2.0
git push origin main v0.2.0
```

Der Workflow `release.yml` prüft die Versionen, baut alle 24 Varianten
(PHP 8.2–8.5 × NTS/ZTS/Alpine × x86_64/ARM64), testet jede Variante im
offiziellen PHP-Image (Extension- und Library-Tests) und prüft die
dynamischen Abhängigkeiten. Danach veröffentlicht er:

* das GitHub-Release mit allen `.so` und `SHA256SUMS`,
* 12 Multi-Arch-Images auf GHCR, jeweils als `<version>-…` und
  `<major.minor>-…`.

Packagist übernimmt den Tag automatisch.

## Welche Variante passt?

| Basis-Image | Tag-Suffix | Gebaut auf | Läuft auf |
|---|---|---|---|
| `php:8.x-fpm`, `php:8.x-cli`, `php:8.x-apache` (Debian) | `-php8.x` | Debian bookworm (glibc 2.36) | bookworm und trixie (alle aktuellen Debian-Tags) |
| `php:8.x-zts` (z. B. FrankenPHP) | `-php8.x-zts` | Debian bookworm | bookworm und trixie |
| `php:8.x-fpm-alpine`, `php:8.x-cli-alpine` | `-php8.x-alpine` | Alpine 3.21 (musl) | Alpine ≥ 3.21 |

FPM, CLI und Apache-Images derselben PHP-Version teilen sich die ABI (NTS)
und damit denselben Build. Die Architektur (amd64/arm64) wählt Docker
automatisch.

## Einbindung in eure Docker-Images

### Debian (`php:8.4-fpm`)

```dockerfile
# syntax=docker/dockerfile:1
ARG PDF_PREFLIGHT_VERSION=0.1.0
FROM ghcr.io/boonkerz/php-pdf-preflight:${PDF_PREFLIGHT_VERSION}-php8.4 AS pdf-preflight

FROM php:8.4-fpm
RUN --mount=type=bind,from=pdf-preflight,source=/pdf_preflight.so,target=/tmp/pdf_preflight.so \
    cp /tmp/pdf_preflight.so "$(php-config --extension-dir)/" \
 && docker-php-ext-enable pdf_preflight
```

### Alpine (`php:8.4-fpm-alpine`)

```dockerfile
# syntax=docker/dockerfile:1
ARG PDF_PREFLIGHT_VERSION=0.1.0
FROM ghcr.io/boonkerz/php-pdf-preflight:${PDF_PREFLIGHT_VERSION}-php8.4-alpine AS pdf-preflight

FROM php:8.4-fpm-alpine
RUN --mount=type=bind,from=pdf-preflight,source=/pdf_preflight.so,target=/tmp/pdf_preflight.so \
    apk add --no-cache libgcc \
 && cp /tmp/pdf_preflight.so "$(php-config --extension-dir)/" \
 && docker-php-ext-enable pdf_preflight
```

Die Version wird als globales `ARG` vor dem ersten `FROM` deklariert und
über einen Stage-Alias eingebunden, denn `--mount=…,from=` erlaubt keine
Variablen. Getestet mit `php:8.4-fpm` (Debian 13) und `php:8.4-fpm-alpine`
(Alpine 3.24) gegen die veröffentlichten GHCR-Images.

`libgcc` (ca. 100 KB) stellt `libgcc_s.so.1` bereit. Die Extension braucht
die Bibliothek, um Rust-Panics abzufangen, statt den PHP-Prozess zu beenden.
Unter Debian ist sie immer vorhanden. Im Alpine-Image fehlt sie zumindest
auf ARM64, und ohne sie lädt die Extension nicht
(`Error loading shared library libgcc_s.so.1`).

`RUN --mount` benötigt BuildKit (Standard seit Docker 23). Ohne BuildKit
funktioniert der klassische Weg über `COPY`:

```dockerfile
FROM ghcr.io/boonkerz/php-pdf-preflight:0.1.0-php8.4-alpine AS pdf-preflight
FROM php:8.4-fpm-alpine
COPY --from=pdf-preflight /pdf_preflight.so /tmp/pdf_preflight.so
RUN apk add --no-cache libgcc \
 && mv /tmp/pdf_preflight.so "$(php-config --extension-dir)/" && docker-php-ext-enable pdf_preflight
```

### Ohne Docker (Release-Asset)

```sh
V=0.1.0; F=pdf_preflight-php8.4-nts-x86_64-glibc.so
curl -fsSLO "https://github.com/boonkerz/php-pdf-preflight/releases/download/v$V/$F"
curl -fsSL "https://github.com/boonkerz/php-pdf-preflight/releases/download/v$V/SHA256SUMS" | grep " $F\$" | sha256sum -c -
install -m 0755 "$F" "$(php-config --extension-dir)/pdf_preflight.so"
echo "extension=pdf_preflight.so" > "$(php --ini | sed -n 's/^Scan for additional .ini files in: //p')/pdf_preflight.ini"
```

### Selbst bauen (z. B. für andere glibc-Versionen)

```sh
scripts/build-in-docker.sh php:8.4-fpm-bookworm   # Ergebnis unter dist/
```

## Composer in der Anwendung

```sh
composer require boonkerz/php-pdf-preflight
```

Die Library verlangt die Extension mit derselben `major.minor`-Version
(`"ext-pdf_preflight": "0.1.*"`). Die Extension meldet ihre Version an PHP
(`phpversion('pdf_preflight')`). `composer install` schlägt deshalb fehl, wenn
sie fehlt oder eine inkompatible Version installiert ist. Die Extension muss
also vor `composer install` im Image sein.

Wird `composer install` in einem eigenen Build-Stage ohne Extension
ausgeführt (z. B. im `composer`-Image), dort die Prüfung abschalten:

```sh
composer install --ignore-platform-req=ext-pdf_preflight
```

oder in der `composer.json` der Anwendung die Plattform festlegen:

```json
"config": {
    "platform": {
        "ext-pdf_preflight": "0.1.0"
    }
}
```

Die Laufzeitprüfung der Library (`IncompatibleExtensionException`) greift
in beiden Fällen weiterhin.

Prüfen im Container:

```sh
php -m | grep pdf_preflight
php -r 'var_dump(phpversion("pdf_preflight"));'
```
