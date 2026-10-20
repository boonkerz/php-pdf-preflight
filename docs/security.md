# Sicherheit und Ressourcengrenzen

PDF-Dateien von Kunden sind **nicht vertrauenswürdige Eingaben**. Dieses
Dokument beschreibt, welche Schutzmaßnahmen die Extension innerhalb des
PHP-Prozesses durchsetzt und wo ein isolierter Worker nötig ist.

## Limits

Alle Limits lassen sich pro Aufruf setzen:
`PdfNative::open($path, ['max_stream_size' => 64 << 20, ...])`.
Unbekannte Optionsnamen führen zu einer Exception, damit ein Tippfehler kein
Limit stillschweigend abschaltet.

| Option | Default | Wirkung bei Überschreitung | Zuverlässig im Prozess? |
|---|---|---|---|
| `max_file_size` | 1 GiB | `FILE_TOO_LARGE` vor dem Einlesen (Dateigröße per `stat`) | ja |
| `max_objects` | 5 000 000 | `LIMIT_EXCEEDED` nach dem Öffnen | ja¹ |
| `max_pages` | 100 000 | `LIMIT_EXCEEDED` nach dem Öffnen | ja¹ |
| `max_stream_size` | 256 MiB | Content-/Form-Stream: Seite `complete = false` + Hinweis; Objekt-/XRef-Stream: `LIMIT_EXCEEDED` vor dem Parsen | ja, mit Ausnahme² |
| `max_total_decoded` | 2 GiB | Summe aller von der Analyse dekodierten Bytes; danach Seiten `complete = false` | ja |
| `max_nesting_depth` | 32 | Tiefe von Form-XObjects/Patterns/Appearance-Streams; tiefere Ebenen werden nicht analysiert, Seite `complete = false` | ja |
| `max_operations_per_page` | 20 000 000 | Abbruch der Seitenanalyse, `complete = false` | ja |
| `max_analysis_time_ms` | 60 000 | Zeitbudget für die Content-Analyse des ganzen Dokuments, `complete = false` | ja³ |
| `max_color_check_pixels` | 25 000 000 | größere Bilder werden nicht auf Farbe geprüft (Klassifikation `unknown`); `0` schaltet Pixelprüfungen ab | ja |
| `max_image_placements` | 100 000 | unterschiedliche Bildplatzierungen je Seite; danach `complete = false` | ja |

¹ Die XRef-Tabelle und der Seitenbaum werden vor der Prüfung aufgebaut. Ihr
Umfang ist durch die Dateigröße begrenzt (jeder Eintrag braucht Bytes in der
Datei).

² **Objekt-Streams verschlüsselter Dateien** können vor dem Entschlüsseln nicht
vermessen werden. Sie werden als `object_streams_unverified` im Dokument
gemeldet (`getDocumentInfo()['guard']`). Gleiches gilt für Objekt-Streams mit
anderen Filtern als FlateDecode. Ein Angreifer kann in diesem Fall den
Speicherverbrauch beim Öffnen in die Höhe treiben. Siehe „Isolierter Worker“.

³ Geprüft wird alle 4096 Operatoren. Das Öffnen selbst (XRef und Seitenbaum)
läuft linear zur Dateigröße und wird nicht zeitlich begrenzt.

## Weitere Schutzmaßnahmen

* **Begrenzte Dekodierung:** Alle Streams, die die Analyse liest
  (Content-Streams, Forms, Patterns, XMP, Schriftprogramm-Header), laufen
  durch den eigenen Decoder in `extension/src/decode.rs`. Er bricht ab, sobald
  das Limit erreicht ist, und dekomprimiert dabei nie mehr als das Limit plus
  64 KiB. Bilddaten werden nie dekodiert.
* **Zyklen:** Seitenbaum (`/Parent`-Kette mit Besuchsmenge und Tiefenlimit
  256), Form-XObjects und Patterns (Aufrufkette) werden auf Zyklen geprüft.
* **Exponentielle Verschachtelung:** Form-XObjects werden bei jedem Aufruf
  interpretiert, weil Auflösung und geerbte Farben vom Aufrufer abhängen.
  Eine Form, die 1000-mal eine Form zeichnet, die wiederum 1000-mal eine Form
  zeichnet, stößt deshalb an `max_operations_per_page` bzw. das Zeitbudget.
  Die Seite gilt dann als unvollständig (Klassifikation `unknown`).
  Gefärbte Kachelmuster werden je Musterraum nur einmal ausgeführt.
* **Bilddekodierung:** Pixel werden nur für die Farbprüfung dekodiert,
  begrenzt durch `max_color_check_pixels`, `max_stream_size` und
  `max_total_decoded`. Der JPEG-Decoder läuft zusätzlich in einem eigenen
  `catch_unwind`. Ein Fehler darin ergibt `unknown` statt eines Abbruchs.
* **Panics:** Jede PHP-Methode läuft in `catch_unwind` und wandelt einen Panic
  in eine `PdfNativeException` mit Code `INTERNAL` um. Zusätzlich fängt
  ext-php-rs Panics an jeder Grenze ab. Das Release-Profil nutzt
  `panic = "unwind"` (Voraussetzung dafür). Der Mutationstest
  (`mutated_inputs_do_not_panic`) prüft 1200 beschädigte Varianten, darunter Dateien mit JPEGs und allen Farbraumtypen.
* **Speicherfreigabe:** `close()` gibt Dateipuffer, Parser-Strukturen und alle
  Analyse-Caches sofort frei. Ohne `close()` geschieht das, wenn das Objekt
  von PHP zerstört wird. Der PHP-Test prüft 2000 Open/Analyze-Zyklen auf
  Speicherwachstum.
* **Keine Dateisystem- oder Netzwerkzugriffe** außer dem Lesen der angegebenen
  Datei. Externe Streams (`/F`) werden ignoriert.

## Was im PHP-Prozess nicht zuverlässig durchsetzbar ist

| Anforderung | Grund | Lösung |
|---|---|---|
| Harte Obergrenze für den Gesamtspeicher | Speicher, den Rust allokiert, läuft nicht über den Zend-Allocator und zählt daher **nicht** zu `memory_limit`. Ein Allokationsfehler in Rust beendet den Prozess. | Worker-Prozess mit `ulimit -v` / `RLIMIT_AS` oder cgroup-Memory-Limit (z. B. eigener PHP-FPM-Pool oder Queue-Worker in einem Container mit Speicherlimit). |
| Zeitlimit für das Öffnen | `max_execution_time` unterbricht keinen nativen Code. | Worker mit Timeout (z. B. `timeout 30 php worker.php`, Queue-Job-Timeout). |
| Schutz vor Bugs in nativem Code (Speicherfehler) | Die Extension enthält kein eigenes `unsafe`. Die Abhängigkeiten nutzen `unsafe` aber intern (ext-php-rs für die Zend-API, smallvec, memchr). | Prozessisolation; Uploads nicht im Web-Request, sondern asynchron prüfen. |
| Bomben in verschlüsselten Objekt-Streams | siehe ² | Worker mit Speicherlimit oder verschlüsselte Dateien ablehnen, wenn `guard.object_streams_unverified > 0`. |

### Empfohlenes Betriebsmodell

Für Web-to-Print mit vielen Uploads:

1. Upload speichern, Job in eine Queue legen.
2. Ein Worker (`php worker.php`) prüft die Datei mit Speicherlimit
   (`systemd`-Unit mit `MemoryMax=`, Container-Limit oder `prlimit --as=`) und
   Zeitlimit.
3. Das Ergebnis (JSON) wird gespeichert, der Kunde bekommt es asynchron.

Wenn die Prüfung synchron im Web-Request laufen muss, sollten zumindest
`max_file_size`, `max_stream_size` und `max_total_decoded` passend zum
verfügbaren Speicher gesetzt werden.
