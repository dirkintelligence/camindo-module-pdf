<?php
/**
 * Befehlsdefinitionen des Moduls Pdf - (CMS:PDF_*).
 *
 * Aufbau wie Core/Modules/Template/commands.php (dort ist jeder Schlüssel erklärt).
 * Der Parser mischt diese Datei über TemplateParserTrait::loadCommands() ein, weil
 * install/settings.php['commands'] sie nennt; das Objekt 'pdf' in 'method' stellt
 * settings.php['services'] bereit (Page::$lazyServices -> CmsPdf).
 *
 * Alle Parameter sind optional. Der 'do'-Parameter ist der, der ohne Schlüssel
 * angegeben werden darf: (CMS:PDF_PAGE brief.pdf). Maße in mm, Schriftgrößen in Punkt,
 * Farben sind Farbfelder aus PDF_COLOR, '#rrggbb' oder 'c,m,y,k'.
 *
 * @see Camindo\Modules\Pdf\CmsPdf   Laufzeit
 * @see README.md                    Befehlsreferenz
 */

$param = fn(string $value, string $description, string $default = '') => [
    'mandatory' => false, 'subparam' => 0, 'default' => $default, 'value' => $value, 'description' => $description,
];
$color = fn(string $description) => $param('Farbe', $description . ' Farbfeld (PDF_COLOR), #rrggbb oder c,m,y,k.');

return [

    // Dokument
    // --------------------

    'pdf_init' => [
        'method' => 'pdf.cmsPdfInit',
        'note' => 'Vorgaben für das Dokument: Farbraum, ICC-Profil, Seitenformat und Ränder. Vor der ersten CMS:PDF_PAGE.',
        'do' => 'colorspace',
        'oneof' => '',
        'params' => [
            'colorspace'  => $param('RGB / CMYK', 'Farbraum, in dem Farben ins PDF geschrieben werden.', 'RGB'),
            'icc_profile' => $param('Dateiname', 'ICC-Profil aus var/modules/pdf/profiles/, wird als Output Intent eingebettet.'),
            'output_condition' => $param('Text', 'Bezeichner der Druckbedingung für den Output Intent (z. B. FOGRA39). Ohne Angabe der Dateiname des Profils.'),
            'format'      => $param('A4 / A5 / LETTER / ...', 'Seitenformat leerer Seiten (Namen wie in tc-lib-pdf).', 'A4'),
            'orientation' => $param('P / L', 'Hoch- (P) oder Querformat (L) leerer Seiten.', 'P'),
            'margins'     => $param('oben,rechts,unten,links', 'Seitenränder in mm (ein Wert: alle; zwei: oben/unten, rechts/links). Linker Rand = x-Vorgabe, rechter Rand = Breitenvorgabe, unterer Rand = Umbruchgrenze der Tabellen.', '20,20,20,20'),
        ],
        'include' => []
    ],

    'pdf_meta' => [
        'method' => 'pdf.cmsPdfMeta',
        'note' => 'Dokumenteigenschaften (werden bei CMS:PDF_PRINT geschrieben).',
        'do' => 'title',
        'oneof' => '',
        'params' => [
            'title'    => $param('Text', 'Titel.'),
            'author'   => $param('Text', 'Autor.'),
            'subject'  => $param('Text', 'Thema.'),
            'keywords' => $param('Text', 'Stichwörter.'),
            'creator'  => $param('Text', 'Erzeugende Anwendung.', 'camindo CMS'),
        ],
        'include' => []
    ],

    'pdf_page' => [
        'method' => 'pdf.cmsPdfPage',
        'note' => 'Legt eine neue Seite an - leer oder auf einer Seite einer Layout-Vorlage. Der erste Aufruf beginnt das Dokument.',
        'do' => 'layout',
        'oneof' => '',
        'params' => [
            'layout'      => $param('Dateiname', 'PDF-Vorlage aus var/modules/pdf/layouts/, deren Seite als Hintergrund übernommen wird. Ohne Angabe eine leere Seite.'),
            'page'        => $param('Zahl', 'Seite der Vorlage (ab 1), die übernommen wird.', '1'),
            'format'      => $param('A4 / A5 / LETTER / ...', 'Seitenformat einer leeren Seite; sonst die Vorgabe aus PDF_INIT.'),
            'orientation' => $param('P / L', 'Ausrichtung einer leeren Seite; sonst die Vorgabe aus PDF_INIT.'),
            'margins'     => $param('oben,rechts,unten,links', 'Ränder nur dieser Seite; sonst die Vorgabe aus PDF_INIT.'),
        ],
        'include' => []
    ],

    'pdf_print' => [
        'method' => 'pdf.cmsPdfPrint',
        'note' => 'Sendet das Dokument an den Browser und beendet den Request. Vor CMS:PDF_PAGE darf das Template nichts ausgegeben haben.',
        'do' => 'filename',
        'oneof' => '',
        'params' => [
            'filename' => $param('Dateiname', 'Dateiname, den der Browser anzeigt bzw. beim Speichern vorschlägt.', 'document.pdf'),
            'download' => $param('false / true / Dateiname', 'Leer oder false: im Browser anzeigen. true: als Download unter filename. Ein Dateiname: als Download unter diesem Namen.', 'false'),
        ],
        'include' => []
    ],

    // Schrift und Farben
    // --------------------

    'pdf_font' => [
        'method' => 'pdf.cmsPdfFont',
        'note' => 'Stellt die Schrift für die folgenden Texte und Zellen ein. Gilt über Seiten hinweg.',
        'do' => 'font',
        'oneof' => '',
        'params' => [
            'font'  => $param('Schriftname', 'Name einer Schrift aus var/modules/pdf/fonts/ ({name}.json; mitgeliefert: helvetica, times, courier, symbol, zapfdingbats). Eine Datei {name}.ttf/.otf wird beim ersten Aufruf konvertiert.', 'helvetica'),
            'style' => $param('B / I / U / D / O, kombinierbar', 'Schriftschnitt: B fett, I kursiv, U unterstrichen, D durchgestrichen, O überstrichen. Leer = regulär.'),
            'size'  => $param('Punkt', 'Schriftgröße in Punkt.', '12'),
        ],
        'include' => []
    ],

    'pdf_color' => [
        'method' => 'pdf.cmsPdfColor',
        'note' => 'Definiert ein Farbfeld mit RGB- und/oder CMYK-Wert. Der Farbraum des Dokuments (PDF_INIT) wählt den Wert; ein fehlender wird umgerechnet.',
        'do' => 'name',
        'oneof' => '',
        'params' => [
            'name' => $param('Name', 'Name des Farbfelds (Buchstaben, Ziffern, _ und -).'),
            'rgb'  => $param('#rrggbb', 'RGB-Wert als Hex-Farbe, z. B. #0066FF.'),
            'cmyk' => $param('c,m,y,k', 'CMYK-Wert in Prozent, z. B. 0,100,100,0.'),
        ],
        'include' => []
    ],

    'pdf_text_color' => [
        'method' => 'pdf.cmsPdfTextColor',
        'note' => 'Textfarbe für die folgenden CMS:PDF_TEXT und Zellen. Ohne Angabe Schwarz.',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('Textfarbe.')],
        'include' => []
    ],

    'pdf_fill_color' => [
        'method' => 'pdf.cmsPdfFillColor',
        'note' => 'Füllfarbe für die folgenden CMS:PDF_RECT. Ohne Angabe Schwarz.',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('Füllfarbe.')],
        'include' => []
    ],

    'pdf_line_color' => [
        'method' => 'pdf.cmsPdfLineColor',
        'note' => 'Linienfarbe für die folgenden CMS:PDF_LINE, Rahmen von CMS:PDF_RECT und Zellenrahmen. Ohne Angabe Schwarz.',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('Linienfarbe.')],
        'include' => []
    ],

    // Inhalte
    // --------------------

    'pdf_text' => [
        'method' => 'pdf.cmsPdfText',
        'note' => 'Schreibt einen Textblock mit Zeilenumbruch innerhalb der Breite. Der Cursor rückt unter den Block.',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text'       => $param('Text', 'Der Text (UTF-8; Zeilenumbrüche werden umgebrochen). Leer = keine Ausgabe.'),
            'x'          => $param('mm', 'Abstand vom linken Seitenrand; ohne Angabe der linke Rand.'),
            'y'          => $param('mm', 'Abstand vom oberen Seitenrand (Oberkante des Blocks); ohne Angabe der Cursor.'),
            'width'      => $param('mm', 'Breite des Blocks; 0 = bis zum rechten Rand.', '0'),
            'align'      => $param('L / C / R / J', 'Ausrichtung: links, zentriert, rechts, Blocksatz.', 'L'),
            'size'       => $param('Punkt', 'Schriftgröße nur für diesen Block; sonst die von CMS:PDF_FONT.'),
            'color'      => $color('Textfarbe nur für diesen Block; sonst CMS:PDF_TEXT_COLOR.'),
            'background' => $color('Hintergrundfarbe des Blocks.'),
        ],
        'include' => []
    ],

    'pdf_html' => [
        'method' => 'pdf.cmsPdfHtml',
        'note' => 'Schreibt einen HTML-Block (Umbruch und Seitenumbruch durch tc-lib-pdf). Der Cursor bleibt stehen - die Höhe des Blocks ist nicht bekannt.',
        'do' => 'html',
        'oneof' => '',
        'params' => [
            'html'  => $param('HTML', 'Der HTML-Code (Textauszeichnung, Listen, Tabellen, Inline-CSS).'),
            'x'     => $param('mm', 'Abstand vom linken Seitenrand; ohne Angabe der linke Rand.'),
            'y'     => $param('mm', 'Abstand vom oberen Seitenrand; ohne Angabe der Cursor.'),
            'width' => $param('mm', 'Breite des Blocks; 0 = bis zum rechten Rand.', '0'),
        ],
        'include' => []
    ],

    'pdf_image' => [
        'method' => 'pdf.cmsPdfImage',
        'note' => 'Platziert ein Bild (JPEG, PNG, GIF, ...) aus dem Medienverzeichnis. Der Cursor rückt unter das Bild.',
        'do' => 'file',
        'oneof' => '',
        'params' => [
            'file'   => $param('Pfad', 'Bilddatei: absoluter Pfad aus (CMS:MEDIA subfield=file), Webpfad aus subfield=path oder Pfad relativ zu public/media. Nur Dateien unterhalb des Medienverzeichnisses.'),
            'x'      => $param('mm', 'Abstand vom linken Seitenrand; ohne Angabe der linke Rand.'),
            'y'      => $param('mm', 'Abstand vom oberen Seitenrand; ohne Angabe der Cursor.'),
            'width'  => $param('mm', 'Breite; 0 = aus der Höhe (bzw. aus den Pixeln bei 72 dpi, wenn beide fehlen).', '0'),
            'height' => $param('mm', 'Höhe; 0 = proportional zur Breite.', '0'),
            'link'   => $param('URL', 'Verweis, der beim Klick auf das Bild geöffnet wird.'),
        ],
        'include' => []
    ],

    'pdf_line' => [
        'method' => 'pdf.cmsPdfLine',
        'note' => 'Zeichnet eine Linie.',
        'oneof' => '',
        'params' => [
            'x1'    => $param('mm', 'Startpunkt, Abstand von links.', '0'),
            'y1'    => $param('mm', 'Startpunkt, Abstand von oben.', '0'),
            'x2'    => $param('mm', 'Endpunkt, Abstand von links.', '0'),
            'y2'    => $param('mm', 'Endpunkt, Abstand von oben.', '0'),
            'width' => $param('mm', 'Liniendicke.', '0.2'),
            'color' => $color('Linienfarbe; sonst CMS:PDF_LINE_COLOR.'),
        ],
        'include' => []
    ],

    'pdf_rect' => [
        'method' => 'pdf.cmsPdfRect',
        'note' => 'Zeichnet ein Rechteck, umrandet und/oder gefüllt.',
        'oneof' => '',
        'params' => [
            'x'          => $param('mm', 'Linke Kante.', '0'),
            'y'          => $param('mm', 'Obere Kante.', '0'),
            'width'      => $param('mm', 'Breite.', '0'),
            'height'     => $param('mm', 'Höhe.', '0'),
            'style'      => $param('D / F / DF', 'D Rahmen, F Füllung, DF beides.', 'D'),
            'color'      => $color('Füllfarbe; sonst CMS:PDF_FILL_COLOR.'),
            'border'     => $color('Rahmenfarbe; sonst CMS:PDF_LINE_COLOR.'),
            'line_width' => $param('mm', 'Rahmendicke.', '0.2'),
        ],
        'include' => []
    ],

    // Tabellen
    // --------------------

    'pdf_table' => [
        'method' => 'pdf.cmsPdfTable',
        'note' => 'Beginnt eine Tabelle; Zeilen und Zellen folgen mit CMS:PDF_ROW / CMS:PDF_CELL, gezeichnet wird bei CMS:PDF_TABLE_END. Zeilen werden nie geteilt: was nicht mehr auf die Seite passt, beginnt auf einer Folgeseite, Kopfzeilen werden dort wiederholt.',
        'do' => 'cols',
        'oneof' => '',
        'params' => [
            'cols'         => $param('b1,b2,...', 'Spaltenbreiten in mm, kommasepariert.'),
            'x'            => $param('mm', 'Linke Kante; ohne Angabe der linke Rand.'),
            'y'            => $param('mm', 'Obere Kante; ohne Angabe der Cursor.'),
            'padding'      => $param('oben,rechts,unten,links', 'Innenabstand aller Zellen in mm (ein Wert: alle; zwei: oben/unten, rechts/links).', '1'),
            'border_width' => $param('mm', 'Rahmendicke der Zellen.', '0.2'),
            'next_layout'  => $param('Dateiname', 'Layout-Vorlage der Folgeseiten bei Seitenumbruch; sonst wie die letzte CMS:PDF_PAGE (leer = leere Seite).'),
            'next_page'    => $param('Zahl', 'Seite der Vorlage für Folgeseiten.'),
        ],
        'include' => []
    ],

    'pdf_row' => [
        'method' => 'pdf.cmsPdfRow',
        'note' => 'Beginnt eine Tabellenzeile.',
        'oneof' => '',
        'params' => [
            'is_header'     => $param('true / false', 'Kopfzeile: wird nach einem Seitenumbruch auf der Folgeseite wiederholt.', 'false'),
            'keep_together' => $param('true / false', 'Zeile nicht über Seiten teilen. Derzeit werden Zeilen grundsätzlich nicht geteilt; der Parameter ist für spätere Versionen reserviert.', 'true'),
        ],
        'include' => []
    ],

    'pdf_cell' => [
        'method' => 'pdf.cmsPdfCell',
        'note' => 'Zelle der aktuellen Zeile. Schrift ist die zu diesem Zeitpunkt eingestellte (CMS:PDF_FONT).',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text'         => $param('Text', 'Zelltext; bricht innerhalb der Spalte um, soweit fit= ihn nicht einpasst.'),
            'col'          => $param('Zahl', 'Spaltenindex ab 0; ohne Angabe die nächste Spalte.'),
            'align'        => $param('L / C / R / J', 'Horizontale Ausrichtung.', 'L'),
            'valign'       => $param('T / C / B', 'Vertikale Ausrichtung in der Zeile.', 'T'),
            'fit'          => $param('none / scale / fontsize / spacing / force', 'Text auf eine Zeile in der Spaltenbreite einpassen: fontsize verkleinert die Schrift (bis min_size), spacing verringert den Zeichenabstand, scale beides, force staucht horizontal. Was auch so nicht passt, bricht um.', 'none'),
            'min_size'     => $param('Punkt', 'Kleinste Schriftgröße beim Einpassen.', '6'),
            'border'       => $param('LTRB / 1 / 0', 'Rahmenseiten: L links, T oben, R rechts, B unten; 1 alle, 0 keine.', '1'),
            'border_width' => $param('mm', 'Rahmendicke; sonst die der Tabelle.'),
            'border_color' => $color('Rahmenfarbe; sonst CMS:PDF_LINE_COLOR.'),
            'padding'      => $param('oben,rechts,unten,links', 'Innenabstand dieser Zelle; sonst der der Tabelle.'),
            'color'        => $color('Textfarbe; sonst CMS:PDF_TEXT_COLOR.'),
            'background'   => $color('Hintergrundfarbe der Zelle.'),
        ],
        'include' => []
    ],

    'pdf_table_end' => [
        'method' => 'pdf.cmsPdfTableEnd',
        'note' => 'Zeichnet die Tabelle. Der Cursor rückt unter die Tabelle.',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],
];
