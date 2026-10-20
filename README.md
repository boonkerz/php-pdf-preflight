# PDF Preflight für PHP

Open-Source-Lösung zur Analyse und Prüfung von PDF-Druckdaten für
Web-to-Print-Systeme und Online-Druckereien. Langfristig soll sie
PDFlib pCOS ersetzen.

* **`extension/`**: native PHP-Extension (`pdf_preflight.so`) in Rust, gebaut
  mit [ext-php-rs](https://github.com/extphprs/ext-php-rs) und dem Parser
  [hayro-syntax](https://github.com/LaurenzV/hayro). Sie liefert die technische
  Analyse ohne Prüfregeln.
* **`php/`**: Composer-Library `PrintPreflight` mit Rule Engine,
  JSON-Prüfprofilen und strukturierten Ergebnissen. Dokumentation:
  [php/README.md](php/README.md).

Auf dem Produktionsserver werden **keine** externen Programme, keine Java-Runtime
und keine zusätzlichen Bibliotheken benötigt. Die `.so` linkt nur gegen
die C-Laufzeit (siehe [docs/build.md](docs/build.md)).

## Status

| Phase | Inhalt | Stand |
|---|---|---|
| 1 | Evaluation der Rust-PDF-Parser | ✅ [docs/evaluation.md](docs/evaluation.md) – Entscheidung für hayro-syntax |
| 2 | Native Extension: Öffnen/Schließen, Dokumentinfos, Seiten, Boxen, Schriften | ✅ implementiert und getestet |
| 3 | PHP-Library: DTOs, Rule Engine, Profile, Regeln | ✅ implementiert und getestet ([php/README.md](php/README.md)) |
| 4 | Farb- und Bildanalyse, effektive DPI, Sonderfarben | ✅ implementiert und getestet ([docs/colors.md](docs/colors.md)) |
| 5 | Integrationstests, Benchmarks, CI/CD, Release-Builds | 🟡 CI, Benchmarks und Release-Workflow vorhanden |

## Schnellstart

```sh
cd extension
cargo build --release                 # findet php-config über PATH (oder PHP_CONFIG=…)
php -d extension=$PWD/target/release/libpdf_preflight.so ../examples/inspect.php ../tests/fixtures/fonts.pdf
```

Installation: Die Datei als `pdf_preflight.so` in das `extension_dir` von PHP
kopieren (`php -i | grep extension_dir`) und aktivieren:

```ini
extension=pdf_preflight.so
```

Vorgebaute Binärdateien für PHP 8.2–8.5 (NTS/ZTS, x86_64/ARM64, glibc/musl)
erzeugt der Release-Workflow, als GitHub-Release-Assets und als
Docker-Images auf GHCR. Für ein Docker-Image genügt:

```dockerfile
# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine
RUN --mount=type=bind,from=ghcr.io/boonkerz/php-pdf-preflight:0.1.0-php8.4-alpine,source=/pdf_preflight.so,target=/tmp/pdf_preflight.so \
    apk add --no-cache libgcc \
 && cp /tmp/pdf_preflight.so "$(php-config --extension-dir)/" && docker-php-ext-enable pdf_preflight
```

```sh
composer require boonkerz/php-pdf-preflight
```

Varianten, Releases und Packagist: [docs/release.md](docs/release.md).

## Preflight mit Profil (Library)

```php
use PrintPreflight\Preflight;
use PrintPreflight\Profile;

$profile = Profile::fromJson(file_get_contents('examples/profiles/business-card.json'));
$result  = Preflight::fromProfile($profile)->checkFile('customer.pdf');

$result->isValid();          // bool
$result->getErrors();        // Violations mit code, page, expected, actual, message
echo $result->toJson();      // {"valid": false, "profile": "business-card", "errors": [...], "warnings": [...]}
```

Verfügbare Regeln: Dateityp, Seitenformat (DIN A0–A10, individuelle Maße,
Min/Max, Toleranz, Ausrichtung, wählbare Box), Seitenanzahl,
Pflicht-Boxen, Beschnittzugabe, Schrifteinbettung, Anzahl
Farb-/Schwarz-Weiß-Seiten, effektive Bildauflösung, verbotene Farbräume
und Sonderfarben sowie PDF-Eigenschaften (Verschlüsselung,
PDF/X-Kennzeichnung, Version). Eigene Regeln lassen sich registrieren, ohne
die Engine zu ändern.

### Farben und Bilder

Jede Seite wird als `color`, `grayscale` oder `unknown` klassifiziert.
Grundlage sind die Farbwerte, mit denen tatsächlich gemalt wird, nicht die
definierten Farbräume. Bilder werden bei Bedarf pixelgenau geprüft,
JPEGs eingeschlossen. Lässt sich etwas nicht sicher entscheiden, lautet das
Ergebnis `unknown` mit Begründung. Die effektive Auflösung berücksichtigt
Skalierung, Drehung und verschachtelte Forms. Dasselbe Bild in
unterschiedlichen Größen ergibt eigene Einträge. Alle Regeln:
[docs/colors.md](docs/colors.md).

## PHP-API der Extension

Seitennummern sind **immer 1-basiert**. Die Datei wird beim `open()` genau
einmal geparst. Alle weiteren Abfragen arbeiten auf demselben Objekt. Die
Content-Analyse einer Seite (Schriften, Farben, Bilder) wird erst beim ersten Zugriff berechnet
und dann zwischengespeichert. `analyze()` nutzt bereits berechnete Seiten.

```php
$pdf = PdfNative::open('/path/to/file.pdf');            // oder PdfNative::fromString($bytes)

$pdf->getPageCount();      // int
$pdf->getDocumentInfo();   // Version, Verschlüsselung, Metadaten, PDF/X-Kennzeichnung, Strukturprobleme
$pdf->getPageInfo(1);      // Größe in mm, Ausrichtung, Rotation, UserUnit, alle Boxen
$pdf->getPageBoxes(1);     // MediaBox, CropBox, BleedBox, TrimBox, ArtBox
$pdf->getFonts(1);         // Schriften der Seite inkl. verschachtelter Forms
$pdf->getColorSpaces(1);   // definierte und verwendete Farbräume, Sonderfarben, Klassifikation color/grayscale/unknown
$pdf->getImages(1);        // Bildplatzierungen mit Pixelmaßen, Farbraum, Kompression, effektiver DPI
$pdf->getPageAnalysis(1);  // Vollständigkeit der Content-Analyse, Hinweise
$pdf->analyze();           // alles zusammen, plus Schriftübersicht über das Dokument
$pdf->close();             // gibt alle nativen Ressourcen sofort frei
```

Eine IDE-Stub-Datei liegt unter [extension/pdf_preflight.stub.php](extension/pdf_preflight.stub.php).

### Optionen und Limits

```php
$pdf = PdfNative::open($path, [
    'password'                => '',          // Benutzerpasswort für verschlüsselte Dateien
    'max_file_size'           => 1 << 30,
    'max_objects'             => 5_000_000,
    'max_pages'               => 100_000,
    'max_stream_size'         => 256 << 20,   // je dekodiertem Stream
    'max_total_decoded'       => 2 << 30,     // je Dokument
    'max_nesting_depth'       => 32,          // Form-XObjects, Patterns, Annotationen
    'max_operations_per_page' => 20_000_000,
    'max_analysis_time_ms'    => 60_000,      // 0 = kein Zeitlimit
    'max_color_check_pixels'  => 25_000_000,  // größere Bilder werden nicht auf Farbe geprüft; 0 = nie
    'max_image_placements'    => 100_000,     // unterschiedliche Bildplatzierungen je Seite
]);
```

Welche Limits zuverlässig greifen und wann ein isolierter Worker nötig ist:
[docs/security.md](docs/security.md).

### Fehler

Alle Fehler werden als `PdfNativeException` (erbt von `Exception`) mit
maschinenlesbarem Code geworfen:

| Konstante | Code | Bedeutung |
|---|---|---|
| `IO` | 1 | Datei nicht lesbar |
| `FILE_TOO_LARGE` | 2 | `max_file_size` überschritten |
| `INVALID_PDF` | 3 | keine PDF-Datei oder nicht reparierbar beschädigt |
| `PASSWORD_REQUIRED` | 4 | Passwort fehlt oder ist falsch |
| `UNSUPPORTED_ENCRYPTION` | 5 | Verschlüsselungsverfahren nicht unterstützt |
| `LIMIT_EXCEEDED` | 6 | Ressourcenlimit beim Öffnen überschritten |
| `PAGE_OUT_OF_RANGE` | 7 | Seitennummer außerhalb von `1..getPageCount()` |
| `CLOSED` | 8 | Dokument wurde bereits geschlossen |
| `INVALID_ARGUMENT` | 9 | ungültige oder unbekannte Option |
| `INTERNAL` | 99 | interner Fehler (z. B. abgefangener Panic) |

Probleme, die die Analyse nicht abbrechen, stehen als `issues` mit stabilem
`code` im jeweiligen Ergebnis, z. B. `startxref_invalid`, `missing_media_box`,
`non_inheritable_box_on_pages_node`, `font_resource_missing`, `xobject_cycle`
oder `limit_exceeded`.

### Ergebnisformat (Auszüge)

**Seitenboxen.** Jede Box unterscheidet zwischen definierten und abgeleiteten
Werten:

```php
$pdf->getPageBoxes(1)['TrimBox'] === [
    'defined'           => false,        // true: in der Seite definiert oder geerbt
    'source'            => 'default',    // page | inherited | default | fallback
    'derived_from'      => 'CropBox',    // bei source=default
    'rect'              => [0, 0, 595.276, 841.89],   // pt, normalisiert
    'effective_rect'    => [0, 0, 595.276, 841.89],   // geschnitten mit der MediaBox
    'exceeds_media_box' => false,
    'width_pt' => 595.276, 'height_pt' => 841.89,
    'width_mm' => 210.0001, 'height_mm' => 297.0001,  // inkl. UserUnit
    'display_width_mm' => 210.0001, 'display_height_mm' => 297.0001, // nach /Rotate
];
```

Die Regeln der PDF-Spezifikation werden eingehalten. `MediaBox`, `CropBox` und
`Rotate` werden über den Seitenbaum vererbt. `BleedBox`, `TrimBox` und `ArtBox`
werden nicht vererbt und fallen auf die `CropBox` zurück. Eine `TrimBox` an
einem `Pages`-Knoten wird deshalb ignoriert und mit einem Hinweis gemeldet.

**Schriften.** Jede Schrift wird mit Einbettungsstatus und Fundort gemeldet:

```php
[
    'object' => '12 0 R', 'name' => 'Arial', 'base_font' => 'ABCDEF+Arial',
    'type' => 'TrueType', 'descendant_type' => null,
    'embedded' => true, 'embedding' => 'embedded',   // embedded | not_embedded | invalid
    'subset' => true, 'standard14' => false,
    'font_file' => 'FontFile2', 'font_file_subtype' => null, 'program_format' => 'truetype',
    'problems' => [],
    'used' => true,       // Text wurde mit dieser Schrift ausgegeben (nicht nur deklariert)
    'visible' => true,    // nicht nur Textrendermodus 3 (unsichtbar)
    'resource_names' => ['F1'],
    'contexts' => ['page', 'form'],   // page | form | pattern | annotation
]
```

Die Einbettungsprüfung beschränkt sich nicht darauf, ob ein `FontFile*`-Eintrag
vorhanden ist. Das referenzierte Schriftprogramm muss sich auflösen und
dekodieren lassen, darf nicht leer sein und muss die Signatur eines bekannten
Formats tragen. Durchsucht werden der Seiteninhalt, verschachtelte
Form-XObjects, Tiling-Patterns, Schriften aus `gs`-Operatoren (ExtGState) und
die Appearance-Streams **druckbarer** Annotationen.

**PDF/X.** `getDocumentInfo()['pdfx']` meldet nur die *Kennzeichnung*
(Info-Dictionary, XMP, OutputIntent). `validated` ist immer `false`. Eine
PDF/X-Validierung findet nicht statt.

## Tests

```sh
make test-rust   # 55 Rust-Tests ohne PHP; 5 weitere als „nicht implementiert“ markiert (ignored), 1 Benchmark-Helfer
make test-php    # baut die Extension und testet die PHP-API (46 Prüfungen)
make test-lib    # PHP-Library: 98 Unit- + 14 Integrationstests (vorher: composer install)
make lint        # rustfmt, clippy mit und ohne PHP-Bindings
make bench       # Analysezeit und Speicher je Datei (BENCH_FILES="a.pdf b.pdf" für eigene Dateien)
```

Hinweis: Builds ohne das Feature `php` (`--no-default-features`) erzeugen eine
`.so` ohne PHP-Modul. Das Makefile nutzt dafür ein eigenes Target-Verzeichnis
(`extension/target/core`), damit die Extension nicht überschrieben wird.

Die Testdateien in `tests/fixtures/` erzeugt `tests/fixtures/generate.py`
deterministisch, nur mit der Python-Standardbibliothek. Sie sind synthetisch und
lizenzfrei. Die CI prüft, dass sie reproduzierbar sind. Eingebettete
Schriftprogramme in den Fixtures sind *Stubs* mit gültiger Signatur, aber ohne
Glyphen.

Abgedeckt sind außerdem 17 Farbfälle (Graustufen in RGB/CMYK/ICC/Lab,
Sonderfarben, Passermarken, DeviceN, geerbte Farben in Forms, Muster,
Verläufe, unsichtbarer Text) und 13 Bildfälle (mehrfach platziert,
ungleichmäßig skaliert, gedreht, in Forms, außerhalb der Seite, Inline,
JPEG grau/farbig, Paletten, 1-Bit, CMYK). Dazu kommen ein- und mehrseitige Dokumente, Formate A3/A4/A5/quer,
Rotation (inkl. vererbt, negativ, ungültig), alle Seitenboxen (vererbt,
abgeleitet, ungültig, gespiegelt, überstehend, UserUnit), eingebettete,
nicht eingebettete, Subset-, CID-, Type3- und defekte Schriften, verschachtelte
Forms (Tiefe 5, Zyklus, Tiefe 40 über dem Limit, 1000-fache Wiederverwendung),
Objekt-Streams, RC4-Verschlüsselung (leeres und gesetztes Benutzerpasswort),
beschädigte Dateien, Dekompressionsbomben, 5000-seitige Dokumente und 600
zufällig beschädigte Varianten (Panic-Freiheit).

## Bekannte Einschränkungen (Stand Phase 4)

* Die **Farbprüfung von Bildern** unterstützt keine CMYK-JPEGs und kein
  JPEG 2000 (Ergebnis `unknown`). Verdeckte oder beschnittene Objekte zählen
  als gemalt. Details: [docs/colors.md](docs/colors.md#bekannte-einschränkungen).
* **Bilddateien** (JPEG/PNG/TIFF) werden erkannt, aber noch nicht analysiert.
  Seitenregeln melden für sie `document.not_analyzed`.
* Schriften, Farben und Bilder **innerhalb von Type3-Glyphen** werden nicht erfasst.
* **Optional Content (Ebenen)** wird nicht ausgewertet. Ausgeblendete Ebenen
  zählen als gedruckt.
* Schriften in **Soft-Mask-Gruppen** (`SMask` in ExtGState) werden nicht
  durchsucht.
* **Objekt-Streams verschlüsselter Dateien** können vor dem Parsen nicht auf
  Dekompressionsbomben geprüft werden (Meldung `object_streams_unverified`).
* Das **Einlesen sehr großer Dateien** kostet Zeit (600 MB ≈ 220 ms aus dem
  Page-Cache), weil die Datei vollständig in den Speicher gelesen wird.
  Memory-Mapping wäre eine mögliche Optimierung (mit dem Risiko eines
  `SIGBUS`, wenn die Datei während der Analyse verkürzt wird).
* Schriftprogramme werden nur anhand ihrer Signatur geprüft, nicht
  vollständig geparst.

## Bekannte Werkzeug-Hinweise

* PHPUnit benötigt die PHP-Erweiterung `ctype`. Fehlt sie lokal, lassen sich
  die Tests mit `scripts/php-test-in-docker.sh php:8.x-cli` im offiziellen
  Image ausführen.

## Lizenz

MIT OR Apache-2.0 (siehe `LICENSE-MIT`, `LICENSE-APACHE`). Alle
einkompilierten Abhängigkeiten stehen unter permissiven Lizenzen (siehe
[docs/build.md](docs/build.md#lizenzen-der-einkompilierten-abhängigkeiten)).
