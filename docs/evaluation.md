# Phase 1 – Technische Evaluation der Rust-PDF-Parser

Stand: 19.10.2026. Alle Versionsangaben stammen aus der crates.io-API bzw. aus
dem Quellcode der jeweiligen Version; die Messwerte wurden mit dem
Vergleichsprogramm unter „Methodik“ auf derselben Maschine erhoben.

## Kandidaten

| Crate | Version | Lizenz | Letztes Release | Downloads (90 Tage) | Bemerkung |
|---|---|---|---|---|---|
| **hayro-syntax** | 0.8.0 | Apache-2.0 OR MIT | 04.10.2026 | 3,5 Mio. | Low-Level-Parser des hayro-Renderers, zero-copy, lazy |
| **lopdf** | 0.45.0 | MIT | 08.09.2026 | 12,3 Mio. | Verbreitetster Rust-PDF-Crate, lädt eager |
| **pdf** (pdf-rs) | 0.10.0 | MIT | 02.03.2026 | 73 Tsd. | Typisiertes Objektmodell |
| pdf_oxide | 0.3.78 | MIT OR Apache-2.0 | 08.09.2026 | 0,9 Mio. | Fokus auf Textextraktion, Pre-1.0-API |
| oxidize-pdf | 5.5.0 | MIT | 19.10.2026 | 109 Tsd. | Fokus auf Erzeugung/Bearbeitung |
| pdfium-render | 0.9.4 | MIT OR Apache-2.0 | 06.09.2026 | 1,6 Mio. | **ausgeschlossen**: benötigt die native PDFium-Bibliothek zur Laufzeit |
| mupdf | 0.8.0 | **AGPL-3.0** | 22.06.2026 | 0,25 Mio. | **ausgeschlossen**: AGPL, native MuPDF-Bibliothek |

pdf_oxide und oxidize-pdf wurden nicht vertieft geprüft. Sie sind auf
Textextraktion bzw. PDF-Erzeugung ausgerichtet und bieten für die
Strukturanalyse keinen Vorteil gegenüber den drei Hauptkandidaten.

## Funktionsvergleich (aus dem Quellcode der genannten Versionen)

| Kriterium | hayro-syntax 0.8 | lopdf 0.45 | pdf 0.10 |
|---|---|---|---|
| PDF 1.0 – 2.0 | ja (`PdfVersion::Pdf20`) | ja | ja |
| Objekt-/XRef-Streams | ja | ja | ja |
| Reparatur defekter XRef-Tabellen | ja (Brute-Force-Neuaufbau, Seitenbaum-Fallback) | ja (Rebuild) | eingeschränkt |
| Verschlüsselung | RC4, AES-128, AES-256 (Passwort optional) | RC4, AES-128/256 | RC4/AES |
| Content-Stream-Operatoren | typisierter Iterator (`TypedIter`) inkl. Inline-Bilder | `Content::decode` (nom) | typisiert |
| Vererbte Seitenattribute | MediaBox/CropBox/Rotate/Resources | manuell | ja |
| Speichermodell | zero-copy über einem `Arc<[u8]>`, Objekte lazy | alle Objekte werden beim Laden geparst und kopiert | Cache über Objekte |
| Dekompressionslimit | **nein** (Filter dekodieren unbegrenzt) | ja (`LoadOptions::max_decompressed_size`, `get_page_content_with_limit`) | nein |
| Verschachtelungstiefe beim Objektparsing | begrenzt (`MAX_OBJECT_NESTING_DEPTH`) | begrenzt (`MAX_NESTING_DEPTH = 100`) | – |
| Abhängigkeiten (ohne optionale Features) | smallvec, rustc-hash | nom, flate2, aes, cbc, ecb, md-5, sha2, rand, getrandom, rayon (Default), … | viele |
| `unsafe` | nur via Feature `unsafe` (Performance) | ja | ja |

Schriften, Farbräume und Bilder werden von **keiner** der Bibliotheken
druckspezifisch ausgewertet. Diese Analyse muss in jedem Fall selbst
implementiert werden; entscheidend ist der Zugriff auf Objekte, Ressourcen und
Content-Streams.

## Messungen

### Robustheit (Mutationstest)

Eine von Ghostscript erzeugte zweiseitige PDF-Datei (mit Objekt-Streams) wurde
6000-mal zufällig verändert (Bytes ersetzen, abschneiden, Bereiche löschen,
PDF-Syntaxzeichen einfügen). Jede Variante wurde geöffnet, die Seiten wurden
ermittelt und alle Content-Streams geparst; Panics wurden mit `catch_unwind`
gezählt.

| Parser | erfolgreich geöffnet | Fehler | Panics |
|---|---|---|---|
| hayro-syntax | **3848 (64 %)** | 2152 | 0 |
| lopdf | 644 (11 %) | 5356 | 0 |
| pdf-rs | 0 | 6000 | 0 |

pdf-rs scheitert bereits an der **unveränderten** Ghostscript-Datei
(`Primitive not allowed` bei einer indirekten `/Length`). Damit ist pdf-rs für
reale Kundendaten ungeeignet.

### Große Datei (600 MB, 200 Seiten mit unkomprimiert-zufälligen Bildern)

| Parser | Öffnen + Content aller Seiten parsen | Spitzen-RSS |
|---|---|---|
| hayro-syntax | **6,5 ms** | **590 MB** (≈ Dateigröße) |
| lopdf (ohne rayon) | 298 ms | 1178 MB (≈ 2 × Dateigröße) |

lopdf parst beim Laden jedes Objekt und kopiert alle Stream-Daten,
einschließlich aller Bilddaten. hayro-syntax indiziert nur die Querverweise und
den Seitenbaum. Alles andere wird erst bei Zugriff geparst.

## Entscheidung: hayro-syntax

Begründung:

1. **Robustheit bei Kundendaten:** Die 6-mal höhere Wiederherstellungsquote
   bei beschädigten Dateien ist für Web-to-Print entscheidend, denn Kunden
   laden auch „kaputte“ PDFs hoch, die Acrobat noch öffnet.
2. **Speicher und Geschwindigkeit:** Durch Lazy Parsing und zero-copy entstehen
   beim Öffnen weder Kopien der Bilddaten noch Analysekosten für nicht
   benötigte Objekte. Das passt direkt zu den Anforderungen „einmal laden“ und
   „lazy“.
3. **Content-Stream-API:** Der typisierte Operator-Iterator inkl. Inline-Bildern
   ist eine gute Grundlage für Schrift-, Farb- und Bildanalyse.
4. **Lizenz und Abhängigkeiten:** Apache-2.0 OR MIT, nur zwei kleine
   Laufzeitabhängigkeiten (smallvec, rustc-hash), keine nativen Bibliotheken.
5. **Wartung:** Aktive Entwicklung (Release 0.8.0 am 04.10.2026). Getestet
   wird gegen die Korpora von pdf.js und PDFBox.

### Bekannte Einschränkungen von hayro-syntax und wie wir damit umgehen

| Einschränkung | Maßnahme in diesem Projekt |
|---|---|
| Stream-Filter dekodieren ohne Größenlimit | Alle Streams, die die Analyse benötigt, werden mit einem **eigenen**, begrenzten Decoder dekodiert (`decode.rs`: Flate mit Streaming-Inflate, LZW, ASCIIHex, ASCII85, RunLength, PNG/TIFF-Prädiktoren). |
| Objekt- und XRef-Streams werden intern unbegrenzt dekodiert | **Vorab-Scan** (`guard.rs`): Vor dem Parsen werden alle `/ObjStm`- und `/XRef`-Streams in den Rohdaten gesucht, und ihre dekomprimierte Größe wird mit konstantem Speicher gemessen. Grenze: siehe [security.md](security.md). |
| Fehlende MediaBox wird stillschweigend durch A4 ersetzt | Eigene Auswertung des Seitenbaums über `/Parent` (`pages.rs`), Ausgabe von `source: "fallback"` plus Hinweis `missing_media_box`. |
| Trailer und Info-Dictionary nicht öffentlich | Eigenes Lesen des Trailers ab `startxref` (klassisch oder XRef-Stream) mit dem öffentlichen `Reader` von hayro-syntax. |
| Reparatur der XRef-Tabelle wird nicht gemeldet | Eigene Strukturprüfung (Header, `%%EOF`, Gültigkeit von `startxref`). |
| `Dict::obj_id()` liefert bei direkten Dictionaries die ID des umgebenden Objekts | Objekt-IDs werden über `get_raw` (`MaybeRef`) ermittelt. |
| API vor 1.0, Breaking Changes möglich | Version in `Cargo.toml` gepinnt (`0.8.0`); `Cargo.lock` eingecheckt; Integrationstests decken die genutzte API ab. |

### Verworfene Alternative lopdf

lopdf wäre die naheliegende Wahl, denn es bringt fertige Dekompressionslimits
mit. Dagegen sprechen der doppelte Speicherbedarf (alle Streams werden kopiert),
die rund 45-mal langsamere Öffnungszeit bei großen Dateien und die deutlich
schlechtere Wiederherstellung beschädigter Dateien. Der Mehraufwand für eigene
Limits bei hayro-syntax ist überschaubar (ca. 500 Zeilen in `decode.rs` und
`guard.rs`).

## Methodik

Das Vergleichsprogramm (Rust, `catch_unwind` je Aufruf) hat jeden Parser mit
denselben Eingaben ausgeführt:

* lopdf: `Document::load_mem_with_options` mit `max_decompressed_size = 64 MiB`,
  `get_pages`, `get_page_content_with_limit`, `Content::decode`,
  `get_page_fonts`; `default-features = false` (ohne rayon).
* hayro-syntax: `Pdf::new`, `pages()`, `media_box()`, `typed_operations()`;
  Features `std` (ohne `unsafe`, ohne `images`).
* pdf-rs: `FileOptions::cached().load`, `pages()`, `contents.operations()`.

Den Speicher haben wir als `VmHWM` aus `/proc/self/status` gemessen, jeweils
in einem eigenen Prozess pro Parser.
