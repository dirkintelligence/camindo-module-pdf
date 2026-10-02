# PDF-Befehle

Alle Positionen und Größen in Millimetern von der linken oberen Ecke der Seite,
Schriftgrößen in Punkt; den ersten Parameter jedes Befehls darf man ohne seinen Namen
angeben (siehe [Konventionen](./01-module-pdf.md#konventionen)).

## Dokument

### `(CMS:PDF_INIT [colorspace=] icc_profile= output_condition= format= orientation= margins=)`

Vorgaben des Dokuments; muss vor dem ersten `PDF_PAGE` stehen.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `colorspace` | `RGB` | `RGB` oder `CMYK` – der Farbraum, in dem Farben geschrieben werden |
| `icc_profile` | – | Datei in `var/modules/pdf/profiles/`, eingebettet als Output Intent (`/DestOutputProfile`) |
| `output_condition` | Dateiname | `/OutputConditionIdentifier` des Output Intent, z. B. `FOGRA39` |
| `format` | `A4` | Seitengröße leerer Seiten: `A4`, `A5`, `LETTER`, … (Namen von tc-lib-pdf) |
| `orientation` | `P` | `P` Hochformat oder `L` Querformat leerer Seiten |
| `margins` | `20` | Seitenränder: links = Vorgabe für `x`, rechts = Grenze der Breite, oben = Start der Schreibposition, unten = Grenze für den Seitenumbruch von Tabellen |

### `(CMS:PDF_META [title=] author= subject= keywords= creator=)`

Dokumenteigenschaften, geschrieben von `PDF_PRINT`.

### `(CMS:PDF_PAGE [layout=]datei.pdf page= format= orientation= margins=)`

Fügt eine Seite hinzu. Der erste Aufruf beginnt das Dokument (und puffert alle weitere
Ausgabe des Templates, siehe `PDF_PRINT`). Die Schreibposition steht danach am oberen
Rand.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `layout` | – | Datei in `var/modules/pdf/layouts/`, deren Seite zum Hintergrund wird; die neue Seite bekommt deren Größe |
| `page` | `1` | verwendete Seite der Vorlagendatei |
| `format`, `orientation` | aus `PDF_INIT` | Größe einer leeren Seite (ohne `layout`) |
| `margins` | aus `PDF_INIT` | Ränder nur dieser Seite |

### `(CMS:PDF_PRINT [filename=]datei.pdf download=)`

Schickt das Dokument an den Browser und ***beendet den Aufruf*** (`exit`, wie die
Erfolgs-Templates von `CMS:USER_*`). Was das Template seit dem ersten `PDF_PAGE`
ausgegeben hat (meist nur die Zeilenumbrüche zwischen den Befehlen), wird verworfen. Was
*vor* dem ersten `PDF_PAGE` ausgegeben wurde, ist dann schon beim Browser; `PDF_PRINT`
meldet in diesem Fall „headers already sent“ – ein PDF-Template beginnt deshalb mit
`PDF_INIT`/`PDF_PAGE`.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `filename` | `document.pdf` | Dateiname, den der Browser anzeigt bzw. zum Speichern vorschlägt |
| `download` | `false` | leer oder `false`: im Browser anzeigen. `true`: als `filename` herunterladen. Ein Dateiname: unter diesem Namen herunterladen |

## Schrift und Farben

### `(CMS:PDF_FONT [font=]name style= size=)`

Setzt die Schrift für die folgenden Textblöcke und Tabellenzellen; gilt über
Seitenwechsel hinweg. Weggelassene Parameter behalten ihren aktuellen Wert (anfangs
`helvetica`, normal, 12 pt). Eine unbekannte Schrift wird gemeldet, die bisherige bleibt.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `font` | `helvetica` | Name der Schrift, siehe [Schriften](./01-module-pdf.md#schriften) |
| `style` | normal | `B` fett, `I` kursiv, `U` unterstrichen, `D` durchgestrichen, `O` überstrichen – kombinierbar (`BI`) |
| `size` | `12` | Größe in Punkt |

### `(CMS:PDF_COLOR [name=]name rgb=#rrggbb cmyk=c,m,y,k)`

Legt ein Farbfeld mit RGB- und/oder CMYK-Wert an (mindestens einer). Name: Buchstaben,
Ziffern, `_`, `-`.

### `(CMS:PDF_TEXT_COLOR [name=]farbe)` / `(CMS:PDF_FILL_COLOR …)` / `(CMS:PDF_LINE_COLOR …)`

Aktuelle Textfarbe (Textblöcke, Zellen), Füllfarbe (`PDF_RECT`) und Linienfarbe
(`PDF_LINE`, Rahmen von `PDF_RECT` und Zellen). Ohne Wert: Schwarz. Befehle mit eigenen
Parametern `color=`/`border=` überschreiben sie für diesen Aufruf.

## Inhalt

### `(CMS:PDF_TEXT [text=]text x= y= width= align= size= color= background=)`

Schreibt einen Textblock, der innerhalb der Breite umbricht. Braucht eine Seite.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `text` | – | der Text (UTF-8; leer = nichts) |
| `x` | linker Rand | Abstand vom linken Seitenrand |
| `y` | Schreibposition | Abstand vom oberen Seitenrand (Oberkante des Blocks) |
| `width` | `0` | Breite des Blocks; `0` = bis zum rechten Rand |
| `align` | `L` | `L` links, `C` zentriert, `R` rechts, `J` Blocksatz |
| `size` | Schriftgröße | Größe in Punkt nur für diesen Block |
| `color` | `PDF_TEXT_COLOR` | Textfarbe |
| `background` | – | Hintergrundfarbe des Blocks |

### `(CMS:PDF_HTML [html=]html x= y= width=)`

Schreibt einen HTML-Block (Textauszeichnung, Listen, Tabellen, Inline-CSS; gerendert von
tc-lib-pdf, das dabei auch Seiten umbricht). `x`, `y`, `width` wie bei `PDF_TEXT`.
Verschiebt die Schreibposition nicht.

### `(CMS:PDF_IMAGE [file=]datei x= y= width= height= link=)`

Platziert ein Bild (JPEG, PNG, GIF, …) aus dem Medienverzeichnis.

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `file` | – | absoluter Pfad aus `(CMS:MEDIA … subfield=file)`, Web-Pfad aus `subfield=path` (`/media/…`) oder ein Pfad relativ zu `public/media`. Nur Dateien unterhalb des Medienverzeichnisses |
| `x`, `y` | Rand / Schreibposition | linke obere Ecke |
| `width` | `0` | `0` = aus der Höhe; sind beide 0, die Pixelgröße bei 72 dpi |
| `height` | `0` | `0` = proportional zur Breite |
| `link` | – | Adresse, die ein Klick auf das Bild öffnet |

### `(CMS:PDF_LINE x1= y1= x2= y2= width= color=)`

Zeichnet eine Linie. `width` = Linienstärke in mm (Vorgabe 0,2), `color` = Linienfarbe
(Vorgabe `PDF_LINE_COLOR`).

### `(CMS:PDF_RECT x= y= width= height= style= color= border= line_width=)`

Zeichnet ein Rechteck. `style`: `D` Umriss (Vorgabe), `F` Füllung, `DF` beides. `color` =
Füllfarbe (Vorgabe `PDF_FILL_COLOR`), `border` = Farbe des Umrisses (Vorgabe
`PDF_LINE_COLOR`), `line_width` in mm (Vorgabe 0,2).

## Tabellen

Tabellen werden zwischen `PDF_TABLE` und `PDF_TABLE_END` gesammelt und am Ende
gezeichnet. Jede Zelle verwendet die Schrift, die bei ihrem `PDF_CELL` gilt – ein
`PDF_FONT` vor der Kopfzeile setzt sie fett. Zeilen werden nie geteilt: Eine Zeile, die
nicht mehr über den unteren Rand passt, beginnt auf einer Folgeseite (gleiche Parameter
wie das letzte `PDF_PAGE` oder `next_layout`/`next_page`), auf der die Kopfzeilen
(`is_header`) wiederholt werden. Spalten ohne `PDF_CELL` bleiben leer (kein Rahmen, kein
Hintergrund).

### `(CMS:PDF_TABLE [cols=]b1,b2,… x= y= padding= border_width= next_layout= next_page=)`

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `cols` | – | Spaltenbreiten in mm, durch Komma getrennt (Pflicht) |
| `x`, `y` | Rand / Schreibposition | linke obere Ecke |
| `padding` | `1` | Innenabstand der Zellen (vierseitiger Wert) |
| `border_width` | `0.2` | Rahmenstärke der Zellen |
| `next_layout`, `next_page` | letztes `PDF_PAGE` | Vorlagendatei und Seite für Folgeseiten; `next_layout=""` ergibt leere Seiten |

### `(CMS:PDF_ROW is_header= keep_together=)`

Beginnt eine Zeile. `is_header=true` kennzeichnet eine Zeile, die auf Folgeseiten
wiederholt wird. `keep_together` wird für künftige Versionen schon angenommen – Zeilen
werden derzeit nie geteilt, es wirkt also immer.

### `(CMS:PDF_CELL [text=]text col= align= valign= fit= min_size= border= border_width= border_color= padding= color= background=)`

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `text` | – | Text der Zelle; bricht innerhalb der Spalte um, sofern `fit` ihn nicht passend macht |
| `col` | nächste Spalte | Spaltennummer ab 0 |
| `align` | `L` | `L`, `C`, `R`, `J` |
| `valign` | `T` | `T` oben, `C` mittig, `B` unten in der Zeile |
| `fit` | `none` | Text in eine Zeile einpassen: `fontsize` verkleinert die Schrift (bis `min_size`), `spacing` verringert den Zeichenabstand (bis 15 % der Größe), `scale` beides anteilig, `force` staucht horizontal. Was dann noch nicht passt, bricht um |
| `min_size` | `6` | kleinste Schriftgröße beim Einpassen |
| `border` | `1` | Seiten: `L`, `T`, `R`, `B` kombinierbar; `1` alle, `0` keine |
| `border_width` | Tabelle | Rahmenstärke in mm |
| `border_color` | `PDF_LINE_COLOR` | Rahmenfarbe |
| `padding` | Tabelle | Innenabstand (vierseitiger Wert) |
| `color` | `PDF_TEXT_COLOR` | Textfarbe |
| `background` | – | Hintergrundfarbe |

### `(CMS:PDF_TABLE_END)`

Zeichnet die Tabelle und setzt die Schreibposition darunter.
