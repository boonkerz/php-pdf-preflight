# Build, ABI-Kompatibilität und Deployment

## Voraussetzungen zum Bauen

| Werkzeug | Version | Zweck |
|---|---|---|
| Rust | ≥ 1.92 (stable) | Compiler (`rust-version` in `Cargo.toml`) |
| PHP-Entwicklungsdateien | 8.2 – 8.5 | Header und `php-config` (Paket `php-dev`, `php8.x-dev`; in den offiziellen Docker-Images enthalten) |
| clang / libclang | ≥ 9 | `bindgen` erzeugt daraus die Zend-Bindings |
| C-Compiler | gcc oder clang | kleiner C-Wrapper von ext-php-rs |

Auf dem **Produktionsserver** wird nur die fertige `pdf_preflight.so` benötigt.

```sh
cd extension
# php-config wird über PATH gefunden oder explizit gesetzt:
PHP_CONFIG=/usr/bin/php-config8.3 cargo build --release
cp target/release/libpdf_preflight.so /usr/lib/php/20230831/pdf_preflight.so
echo "extension=pdf_preflight.so" > /etc/php/8.3/mods-available/pdf_preflight.ini
```

Reproduzierbar in einem Container (identisch zur CI):

```sh
scripts/build-in-docker.sh mirror.gcr.io/library/php:8.3-cli-bookworm    # Debian, glibc, NTS
scripts/build-in-docker.sh mirror.gcr.io/library/php:8.3-zts-bookworm    # Debian, glibc, ZTS
scripts/build-in-docker.sh mirror.gcr.io/library/php:8.3-cli-alpine3.21  # Alpine, musl, NTS
```

Das Skript baut mit `--locked`, führt die PHP-Tests aus, prüft die dynamischen
Abhängigkeiten und legt das Ergebnis unter
`dist/pdf_preflight-php<version>-<nts|zts>-<arch>-<libc>.so` ab.

## PHP-ABI

Eine PHP-Extension ist binär nur kompatibel zu **genau der PHP-Minor-Version,
dem Thread-Safety-Modus und der C-Bibliothek**, für die sie gebaut wurde. PHP
prüft das beim Laden über `ZEND_MODULE_API_NO` und den Build-String
(`API20250925,NTS`) und verweigert inkompatible Extensions.

| PHP | Module API | Build-Varianten |
|---|---|---|
| 8.2 | 20220829 | NTS, ZTS |
| 8.3 | 20230831 | NTS, ZTS |
| 8.4 | 20240924 | NTS, ZTS |
| 8.5 | 20250925 | NTS, ZTS |

Eine Build-Variante ist damit festgelegt durch:
**PHP-Minor × NTS/ZTS × Architektur (x86_64, aarch64) × libc (glibc, musl)**.
Patch-Versionen (8.3.1 → 8.3.20) sind ABI-kompatibel. Debug-Builds von PHP
(`--enable-debug`) benötigen eigene Builds.

Ob NTS oder ZTS vorliegt: `php -i | grep "Thread Safety"`. PHP-FPM und CLI
der Distributionen sind fast immer NTS. FrankenPHP und das Apache-Modul mit
Worker-MPM nutzen ZTS.

### glibc-Version

Die `.so` setzt mindestens die glibc-Version des Build-Systems voraus.
Achtung: Die offiziellen `php:*`-Images **ohne** Suffix basieren inzwischen
auf Debian 13 trixie (glibc 2.41). Die Release-Builds laufen deshalb
ausdrücklich auf den `-bookworm`-Tags (glibc 2.36) und funktionieren damit
auf bookworm **und** trixie (Debian 12+, Ubuntu 23.04+, RHEL 10). musl-Builds
entstehen auf `-alpine3.21` und laufen auf Alpine ≥ 3.21. Für ältere Systeme
muss auf einem entsprechend alten System gebaut werden. Ein lokaler Build auf
Arch Linux benötigt z. B. GLIBC_2.34.

Die CI bezieht die Images über `mirror.gcr.io/library/php:…`, Googles Spiegel
der offiziellen Docker-Hub-Images, um das Pull-Limit von Docker Hub für
anonyme Zugriffe zu umgehen.

Veröffentlichung und Einbindung in Docker-Images: [release.md](release.md).

## Dynamische Abhängigkeiten

Geprüft mit `scripts/check-deps.sh` (nutzt `readelf -d` bzw. `ldd`). In der CI
schlägt der Build fehl, wenn etwas anderes als die C-Laufzeit gelinkt ist:

```
$ sh scripts/check-deps.sh target/release/libpdf_preflight.so
  ok      libgcc_s.so.1        # Stack-Unwinding (für das Abfangen von Panics)
  ok      libm.so.6            # Mathematikfunktionen (Teil der glibc; JPEG-Decoder)
  ok      libc.so.6
  ok      ld-linux-x86-64.so.2
```

Es gibt keine Abhängigkeiten zu zlib, OpenSSL, libclang, PDF-Bibliotheken oder
Java. Inflate, RC4/AES und alles Weitere ist in Rust einkompiliert. Die
PHP-Symbole (`zend_*`) werden beim Laden aus dem PHP-Binary aufgelöst.

## Größe

Release-Build (LTO, `codegen-units = 1`, Debug-Infos entfernt): ca. 1,8 MB.

## Lizenzen der einkompilierten Abhängigkeiten

Alle Laufzeitabhängigkeiten (Linux) stehen unter permissiven Lizenzen:
MIT, Apache-2.0, Zlib, 0BSD, Unlicense, Unicode-3.0 (jeweils allein oder als
Wahllizenz). Es gibt keine GPL- oder AGPL-Abhängigkeiten. Die CI prüft das mit
`cargo deny check licenses` (Konfiguration: `extension/deny.toml`).

```sh
cd extension
cargo tree -e normal --target x86_64-unknown-linux-gnu --format "{p} {l}"
```
