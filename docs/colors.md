# Farb- und Bildanalyse

Dieses Dokument beschreibt, wie die Extension Farben und Bilder auswertet und
wann eine Seite als **farbig**, **Schwarz-Weiß** oder **unbekannt** gilt.

## Drei Ebenen

`PdfNative::getColorSpaces($page)` trennt drei Dinge:

| Feld | Bedeutung |
|---|---|
| `declared` | Farbräume, die in den Ressourcen der ausgeführten Content-Streams definiert sind, auch wenn sie nie verwendet werden |
| `used` | Farbräume, mit denen ein Zeichenoperator tatsächlich etwas gemalt hat, mit `targets` (`fill`, `stroke`, `text`, `image`, `image_mask`, `shading`) und `neutral` (`true`/`false`/`null`) |
| `classification` | drucktechnische Einordnung der Seite: `color`, `grayscale` oder `unknown` |

`spot_colors` enthält nur Sonderfarben, die mit einem Tonwert > 0 gemalt
wurden. Die bloß definierten stehen in `declared_spot_colors`.

Die Klassifikation wird **nie** aus den definierten Farbräumen abgeleitet,
sondern nur aus den Farbwerten, mit denen tatsächlich gemalt wird.

## Was ausgewertet wird

Der Content-Stream-Interpreter verfolgt Transformationsmatrix (CTM),
Füll- und Konturfarbe, Farbraum, Schrift und Textrendermodus, inklusive
`q`/`Q`. Er wertet aus:

* Flächen und Konturen (`f`, `F`, `f*`, `B`, `B*`, `b`, `b*`, `S`, `s`)
* Text mit dem Rendermodus: 0/2/4/6 füllen, 1/2/5/6 konturieren, 3/7 malen nichts
* Bilder (`Do`) und Inline-Bilder (`BI … ID … EI`); Bildmasken (`ImageMask`) malen mit der aktuellen Füllfarbe
* Verläufe (`sh` und Shading-Patterns)
* Gefärbte Kachelmuster (deren Inhalt) und ungefärbte Kachelmuster (Farbe aus dem zugrunde liegenden Farbraum; Farboperatoren im Muster werden gemäß Spezifikation ignoriert)
* Verschachtelte Form-XObjects. Sie erben die Farben, die Schrift und die CTM des Aufrufers: Eine Form ohne eigene Farbangabe malt in der Farbe der Seite.
* Erscheinungsbilder druckbarer Annotationen

## Wann ist ein Farbwert „grau“?

| Farbraum | grau, wenn … | sonst |
|---|---|---|
| DeviceGray, CalGray, ICCBased mit N=1 | immer | – |
| DeviceRGB, CalRGB, ICCBased mit N=3 | R = G = B (Toleranz 1/255) | farbig |
| DeviceCMYK, ICCBased mit N=4 | C = M = Y = 0 (nur Schwarz) | farbig. **Tiefschwarz** (C=M=Y>0) braucht die Farbplatten und zählt als farbig. |
| Lab | a = b = 0 (Toleranz 0,5) | farbig |
| Separation `/Black`, `/All` (Passermarken) | Tonwert > 0 | – |
| Separation `/None` oder Tonwert 0 | malt nichts | – |
| Separation mit anderem Namen (Sonderfarbe, auch `Cyan` usw.) | – | farbig |
| DeviceN | nur Farbmittel `Black`/`All`/`None` mit Tonwert > 0 | farbig |
| Indexed | der referenzierte Paletteneintrag ist grau | farbig |

Verläufe: Exponentielle Funktionen (Typ 2) und daraus zusammengesetzte
Funktionen (Typ 3) werden an ihren Endpunkten ausgewertet. Die Interpolation
zweier grauer Farben bleibt grau. Abgetastete Funktionen (Typ 0) und
PostScript-Funktionen (Typ 4) in RGB/CMYK/Lab ergeben `unknown`.

## Bilder

Zunächst entscheiden die Metadaten: Graustufen-Farbräume, 1-Komponenten-JPEGs,
JBIG2/CCITT (immer Graustufen), Sonderfarben und Paletten mit
ausschließlich grauen Einträgen.

Reicht das nicht, werden die **Pixel geprüft**:

* Flate, LZW, RunLength, ASCII-Filter oder unkomprimiert, mit 1, 2, 4, 8 oder
  16 Bit und unter Berücksichtigung von `/Decode`. Bei Paletten werden die
  tatsächlich verwendeten Indizes geprüft. Ein roter Paletteneintrag, der nie
  vorkommt, macht ein Bild also nicht farbig.
* JPEG (YCbCr/RGB) wird mit `zune-jpeg` dekodiert. Ein Pixel gilt als
  neutral, wenn R, G und B höchstens 3 Stufen voneinander abweichen.
* Die Prüfung bricht beim ersten farbigen Pixel ab. Flate-komprimierte
  Bilder (auch mit PNG-Prädiktoren) werden dafür zeilenweise gestreamt, statt
  sie vollständig zu entpacken. Bei 200 farbigen 1000×1000-Bildern sinkt die
  Analysezeit dadurch von 1,46 s auf 14 ms.
* Ist die Seite bereits durch anderen Inhalt farbig, entfällt die Prüfung.
* Grenzen: `max_color_check_pixels` (Default 25 Mio. Pixel) und die
  Dekodierlimits. Größere Bilder ergeben `unknown` mit Begründung.

Nicht unterstützt (Ergebnis `unknown`): CMYK-JPEGs (die Adobe-Inversion ist
nicht zuverlässig erkennbar), JPEG 2000 und Bilder in Lab mit JPEG-Kompression.

## Klassifikation der Seite

* `color`, sobald irgendetwas farbig gemalt wurde. Das gilt auch, wenn die
  Analyse unvollständig ist, denn Farbe ist dann bereits nachgewiesen.
* `unknown`, wenn etwas nicht entscheidbar war oder die Analyse der Seite
  unvollständig ist (Limit, defekter Stream). `reasons` nennt den Grund.
* `grayscale` sonst, auch für leere Seiten (`painted: false`).

## Effektive Bildauflösung

Für jede Platzierung wird aus der aktuellen Transformationsmatrix die
platzierte Größe des Bildes berechnet. Das Einheitsquadrat des Bildraums wird
auf ein Parallelogramm abgebildet, dessen Kantenlängen (in Punkt, mal
`/UserUnit`) die physische Größe ergeben:

```
dpi_x = Pixelbreite / (|(a, b)| · UserUnit / 72)
dpi_y = Pixelhöhe   / (|(c, d)| · UserUnit / 72)
```

Damit sind Skalierung, Drehung, Scherung, Form-Matrizen über beliebig viele
Ebenen, Pattern-Matrizen und die Platzierung von Annotationen berücksichtigt.
`effective_dpi` ist der kleinere der beiden Werte.

Dasselbe Bild in verschiedenen Größen ergibt **eigene Einträge** je
Auflösung. Identische Platzierungen werden zusammengefasst (`placements`
zählt sie). `on_page` ist `false`, wenn die Platzierung vollständig außerhalb
der CropBox liegt.

## Bekannte Einschränkungen

* Ob ein Objekt durch Beschneidungspfade, die BBox einer Form oder andere
  Objekte verdeckt ist, wird nicht ausgewertet. Ein verdecktes farbiges
  Objekt macht die Seite farbig.
* Optionale Inhalte (Ebenen) werden nicht ausgewertet.
* Farben in Type3-Glyphen mit eigener Farbe (`d0`), in Soft-Masks und in
  Transparenzgruppen-Farbräumen werden nicht berücksichtigt.
* ICC-Profile werden nicht interpretiert. Bei ICCBased zählt nur die Anzahl
  der Komponenten.
* Gesamtfarbauftrag (TAC) und Überdrucken erfordern ein Rendering und sind
  nicht Teil der Analyse.
