# PrintPreflight – PHP-Library

Konfigurierbare Rule Engine zur Prüfung von PDF-Druckdaten. Sie baut auf der
nativen Extension `pdf_preflight` auf (siehe [../README.md](../README.md)).
Die Library ist framework-unabhängig und nutzt strikte Typisierung. Sie
läuft ab PHP 8.2.

```sh
composer require boonkerz/php-pdf-preflight
```

Die Library setzt die Extension in derselben `major.minor`-Version voraus
(`ext-pdf_preflight: 0.1.*`). Sie muss vor `composer install` installiert
sein. Installation (Docker-Images, Release-Assets) und Build-Stages ohne
Extension: [docs/release.md](../docs/release.md).

## Architektur

```
Datei ──► Analyzer ──► DocumentAnalysis ──► Preflight(Regeln) ──► PreflightResult
          (Extension)   (DTOs, JSON)          (Profil / Code)       (JSON, Meldungen)
```

* **Analyse und Prüfung sind getrennt.** Regeln sehen nur die
  parserunabhängigen DTOs aus `PrintPreflight\Analysis`, niemals die
  Extension. Eine Analyse lässt sich als JSON speichern
  (`$analysis->toJson()`) und später ohne erneutes Parsen gegen andere
  Profile prüfen (`DocumentAnalysis::fromJson()`).
* **Regeln und Profile** greifen nicht auf die Extension zu. Nur das
  Analysieren von PDF-Dateien ruft sie auf.
* **Drei Ergebnisse statt zwei:** Eine Regel kann bestanden sein,
  fehlschlagen oder *unbestimmt* sein. „Unbestimmt“ bedeutet, dass die Analyse
  für eine Entscheidung nicht ausreicht, etwa bei unbekannter
  Farbklassifikation oder wenn die Content-Analyse eine Seite wegen eines
  Limits nicht vollständig auswerten konnte. Standardmäßig wird das als
  Fehler gemeldet, damit nichts ungeprüft durchrutscht. Das Verhalten ist pro
  Regel einstellbar (`on_undetermined: error | warning | ignore`).

## Verwendung im Code

```php
use PrintPreflight\Analyzer;
use PrintPreflight\Preflight;
use PrintPreflight\Rules\PageSizeRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\FontsEmbeddedRule;

$document = Analyzer::open('customer.pdf');   // wirft AnalysisException bei defekten/verschlüsselten Dateien

$preflight = new Preflight([
    new PageSizeRule(width: 85, height: 55, tolerance: 0.5, box: 'TrimBox'),
    new PageCountRule(min: 1, max: 2),
    new FontsEmbeddedRule(),
], 'business-card');

$result = $preflight->check($document);

if (!$result->isValid()) {
    foreach ($result->getErrors() as $error) {
        echo $error->getMessage(), "\n";
    }
}
echo $result->toJson(JSON_PRETTY_PRINT);
```

`Preflight::checkFile($path)` analysiert und prüft in einem Schritt. Dateien,
die sich nicht analysieren lassen, liefern dann ein ungültiges Ergebnis mit
einem `document.*`-Fehler statt einer Exception: `document.invalid`,
`document.password_required`, `document.too_large`, `document.limit_exceeded`
oder `document.unreadable`.

## Prüfprofile (JSON)

Ein Profil enthält ID, Name, Beschreibung, Version, Sprache der Meldungen und
die Regelkonfigurationen. Es lässt sich in einer Datenbank speichern und über
eine Shop-Oberfläche bearbeiten. Ein vollständiges Beispiel liegt unter
[examples/profiles/business-card.json](../examples/profiles/business-card.json).

```json
{
    "schema": 1,
    "id": "business-card",
    "name": "Visitenkarte 85 × 55 mm",
    "description": "…",
    "version": 3,
    "locale": "de",
    "rules": [
        {
            "type": "page_size",
            "id": "format",
            "enabled": true,
            "severity": "error",
            "on_undetermined": "error",
            "params": { "width": 85, "height": 55, "tolerance": 0.5, "box": "TrimBox" },
            "messages": { "page_size.mismatch": "Seite {page}: Bitte 85 × 55 mm anlegen." }
        }
    ]
}
```

```php
$profile = Profile::fromJson($json);       // InvalidProfileException mit Pfad, z. B. "rules[2].params"
$profile->createRules();                   // validiert alle Regelparameter (z. B. vor dem Speichern)
$result = Preflight::fromProfile($profile)->checkFile('customer.pdf');
```

| Schlüssel | Bedeutung |
|---|---|
| `type` | Regeltyp (siehe unten) |
| `id` | optional; eindeutige ID im Ergebnis, nötig bei mehreren Regeln desselben Typs |
| `enabled` | `false` deaktiviert die Regel |
| `severity` | `error` (Default) oder `warning`; Warnungen machen das Ergebnis nicht ungültig |
| `on_undetermined` | `error` (Default), `warning` oder `ignore` |
| `params` | Parameter der Regel in snake_case |
| `messages` | eigene Meldungstexte je Code mit Platzhaltern wie `{page}` |

Unbekannte Schlüssel und Parameter werden abgelehnt. So kann ein Tippfehler
keine Prüfung stillschweigend abschalten.

## Regeln

Alle Seitenregeln akzeptieren `pages`: `null`/`"all"` (Default), `"first"`,
`"last"`, `"odd"`, `"even"`, eine Seitenzahl, eine Liste wie `[1, "3-5"]` oder
einen String wie `"1,3-5,last"`. Seitennummern beginnen bei 1.

### `file_type` – `FileTypeRule`

| Parameter | Default | |
|---|---|---|
| `allowed` | `["pdf"]` | erlaubte Typen: `pdf`, `jpeg`, `png`, `tiff` |
| `allow_images` | `false` | Kurzform für alle Bildtypen |

Der Typ wird am Dateiinhalt erkannt, nicht an der Endung. Die Regel ist
*blockierend*: Schlägt sie fehl, werden die weiteren Regeln übersprungen
(`skipped`). Bilddateien werden derzeit nur erkannt. Eine Bildanalyse lässt
sich über `FileAnalyzerInterface` ergänzen. Ohne sie melden Seitenregeln bei
Bildern `document.not_analyzed` (unbestimmt).

Codes: `file_type.not_allowed`

Die Seiten-DTOs enthalten neben Boxen und Schriften auch `colors`
(`ColorReport`: Klassifikation, verwendete und definierte Farbräume,
Sonderfarben, Begründungen) und `images` (`ImagePlacement`: Pixelmaße,
Farbraum, Kompression, effektive DPI, platzierte Größe, `onPage`).

### `page_size` – `PageSizeRule`

| Parameter | Default | |
|---|---|---|
| `width`, `height` | – | Sollmaß in mm (auch einzeln: nur Breite oder nur Höhe) |
| `format` | – | `"A4"` oder Liste `["A4", "A5"]` (DIN A0–A10; weitere über `PageFormat::register()`) |
| `tolerance` | `0.5` | erlaubte Abweichung in mm |
| `box` | `"TrimBox"` | `MediaBox`, `CropBox`, `BleedBox`, `TrimBox`, `ArtBox` |
| `orientation` | `null` | `portrait` oder `landscape` |
| `allow_swapped` | `true` | Breite und Höhe dürfen vertauscht sein (Ausrichtung prüft `orientation`) |
| `min_width`, `max_width`, `min_height`, `max_height` | – | Grenzmaße in mm |
| `require_defined_box` | `false` | Box muss definiert sein, nicht nur abgeleitet |
| `pages` | alle | |

Gemessen wird die angezeigte Größe, also nach `/Rotate` und mit `/UserUnit`.
Fehlt die TrimBox, ist sie nach PDF-Regeln gleich der CropBox. Mit
`require_defined_box` wird das als Fehler gemeldet.

Codes: `page_size.mismatch`, `page_size.orientation`, `page_size.too_small`,
`page_size.too_large`, `page_size.box_missing`

### `page_count` – `PageCountRule`

Parameter: `exact`, `min`, `max`, `multiple_of` (z. B. 4 für Broschüren).
Codes: `page_count.exact`, `page_count.too_few`, `page_count.too_many`,
`page_count.multiple_of`

### `box_required` – `BoxRequiredRule`

Parameter: `boxes` (Default `["TrimBox"]`), `pages`. Eine Box gilt als
vorhanden, wenn sie auf der Seite definiert oder (bei Media- und CropBox)
geerbt ist. Code: `box.missing`

### `bleed_margin` – `BleedMarginRule`

| Parameter | Default | |
|---|---|---|
| `min_bleed` | `3.0` | Mindestbeschnitt in mm auf **jeder** Seite |
| `tolerance` | `0.01` | |
| `trim_box`, `bleed_box` | `TrimBox`, `BleedBox` | |
| `require_defined_boxes` | `true` | bei `false` werden abgeleitete Boxen gemessen |

Geprüft wird nur die **geometrische** Beschnittzugabe, also der Abstand der
Boxen. Ob tatsächlich Bildinhalt bis in den Beschnitt reicht, prüft diese
Regel nicht. Codes: `bleed.insufficient` (mit `actual.bleed_mm` je Seite),
`bleed.box_missing`

### `fonts_embedded` – `FontsEmbeddedRule`

| Parameter | Default | |
|---|---|---|
| `ignore_invisible_text` | `true` | Schriften, die nur für unsichtbaren Text (Rendermodus 3) genutzt werden, ignorieren |
| `allow_standard14` | `false` | nicht eingebettete Standard-14-Schriften akzeptieren |
| `pages` | alle | |

Geprüft werden nur Schriften, mit denen tatsächlich Text ausgegeben wird,
auch in verschachtelten Forms, Patterns und druckbaren Annotationen. Bloß
deklarierte Schriften zählen nicht. Für eine reine Warnung genügt
`"severity": "warning"`. Codes: `fonts.not_embedded`,
`fonts.invalid_embedding`, `fonts.missing_resource`,
`fonts.analysis_incomplete` (unbestimmt)

### `color_pages` – `ColorPageCountRule`

Parameter: `min_color`, `max_color`, `min_grayscale`, `max_grayscale`.

Grundlage ist die Klassifikation der Extension (`color`, `grayscale`,
`unknown`; Regeln in [docs/colors.md](../docs/colors.md)). Ist auch nur eine
Seite `unknown`, meldet die Regel `color_pages.undetermined` mit den
betroffenen Seiten, statt falsch zu zählen.

### `image_resolution` – `ImageResolutionRule`

| Parameter | Default | |
|---|---|---|
| `min_dpi` | `300` | Mindestauflösung für Farb- und Graustufenbilder |
| `max_dpi` | – | Höchstauflösung (überdimensionierte Bilder) |
| `min_dpi_bitonal` | = `min_dpi` | Mindestauflösung für 1-Bit-Bilder und Bildmasken (Strich, z. B. 1200) |
| `only_on_page` | `true` | Platzierungen außerhalb der CropBox ignorieren |
| `pages` | alle | |

Geprüft wird jede unterschiedliche Platzierung, also auch dasselbe Bild in
mehreren Größen. Maßgeblich ist der kleinere Wert von horizontaler und
vertikaler effektiver Auflösung. Codes: `image_resolution.too_low`,
`image_resolution.too_high`, `image_resolution.analysis_incomplete`
(unbestimmt)

### `color_spaces` – `ColorSpaceRule`

| Parameter | Default | |
|---|---|---|
| `forbidden` | `[]` | verbotene Kategorien: `gray`, `rgb`, `cmyk`, `lab`, `spot`, `pattern`, `unknown` |
| `targets` | alle | nur bestimmte Verwendungen prüfen: `fill`, `stroke`, `text`, `image`, `image_mask`, `shading` |
| `allowed_spot_colors` | `null` (alle) | erlaubte Sonderfarbnamen |
| `max_spot_colors` | – | maximale Anzahl verschiedener Sonderfarben im Dokument |
| `pages` | alle | |

Ausgewertet werden nur Farbräume, mit denen **tatsächlich gemalt** wird.
Definierte, aber ungenutzte Farbräume lösen keinen Fehler aus. Codes:
`color_spaces.forbidden`, `color_spaces.spot_not_allowed`,
`color_spaces.too_many_spot_colors`, `color_spaces.analysis_incomplete`

### `pdf_properties` – `PdfPropertiesRule`

Parameter: `allow_encrypted` (Default `true`), `require_pdfx` (z. B.
`["PDF/X-1a", "PDF/X-4"]`, Präfixvergleich), `max_version` (z. B. `"1.7"`).
`require_pdfx` prüft nur die **Kennzeichnung**, keine PDF/X-Konformität.
Codes: `pdf_properties.encrypted`, `pdf_properties.pdfx_not_declared`,
`pdf_properties.version_too_high`

## Ergebnisformat

```json
{
    "valid": false,
    "profile": "business-card",
    "errors": [
        {
            "rule": "page_size",
            "code": "page_size.mismatch",
            "severity": "error",
            "page": 1,
            "expected": { "sizes": [{ "width": 85.0, "height": 55.0 }], "tolerance": 0.5, "swapped_allowed": true },
            "actual": { "width": 90.0, "height": 55.0, "box": "TrimBox" },
            "message": "Seite 1: Das Format 90 × 55 mm (TrimBox) entspricht nicht dem geforderten Format 85 × 55 mm.",
            "undetermined": false
        }
    ],
    "warnings": [],
    "rules": [
        { "rule": "page_size", "type": "page_size", "status": "failed" },
        { "rule": "page_count", "type": "page_count", "status": "passed" }
    ]
}
```

`code`, `expected` und `actual` sind für Maschinen gedacht. `message` ist der
Kundentext in der Sprache des Profils (`de` oder `en`). Ihn liefert der
Meldungskatalog (`Results\Messages`) oder die Vorlage aus dem Profil.

## Eigene Regeln

```php
final class MaxRotationRule extends AbstractRule
{
    public function __construct(private readonly int $max = 0, ?RuleOptions $options = null)
    {
        parent::__construct($options);
    }

    public static function type(): string { return 'max_rotation'; }

    protected function check(DocumentAnalysis $document, Findings $findings): void
    {
        foreach ($document->getPages() as $page) {
            if ($page->rotation > $this->max) {
                $findings->fail('max_rotation.exceeded', $page->number, ['max' => $this->max], ['rotation' => $page->rotation]);
            }
        }
    }
}

$registry = (new RuleRegistry())->register(MaxRotationRule::class);
$preflight = Preflight::fromProfile($profile, $registry);
```

Die Profilparameter (`max`) werden automatisch auf die benannten
Konstruktorparameter abgebildet. Für den Meldungstext genügt ein Eintrag in
`messages` des Profils oder ein eigener Katalog
(`new MessageFormatter('de', ['de' => ['max_rotation.exceeded' => '…']])`).
Wer `AbstractRule` nicht nutzen möchte, implementiert direkt `RuleInterface`
(`validate(DocumentAnalysis): RuleResult`).

## Tests

```sh
# im Wurzelverzeichnis des Repositories
composer install
vendor/bin/phpunit                                   # ohne Extension: Integrationstests werden übersprungen
php -d extension=/pfad/pdf_preflight.so vendor/bin/phpunit
vendor/bin/phpstan analyse                           # Level 8
scripts/php-test-in-docker.sh mirror.gcr.io/library/php:8.4-cli-bookworm   # mit passendem Build aus dist/
```

98 Unit-Tests laufen ohne Extension. Sie decken alle Regeln ab, dazu
Profilvalidierung, Meldungen, das Ergebnisformat und das Mapping
aufgezeichneter Extension-Ausgaben (`tests/Unit/data`). 14
Integrationstests prüfen Extension und Library zusammen an den Fixtures.
