<?php
/**
 * Laufzeit der (CMS:PDF_*)-Befehle - PDF-Dokumente aus Frontend-Templates.
 *
 * Ein Template baut ein PDF Seite für Seite auf und schickt es an den Browser:
 *
 *     (CMS:PDF_INIT colorspace=CMYK margins=20,20,30,20)   Dokumentvorgaben (optional)
 *     (CMS:PDF_COLOR blau rgb=#0066FF cmyk=100,60,0,0)     Farbfeld
 *     (CMS:PDF_PAGE brief.pdf)                             Seite auf der Layout-Vorlage
 *     (CMS:PDF_FONT helvetica style=B size=14)             Schrift für die folgenden Texte
 *     (CMS:PDF_TEXT "Hallo Welt" x=20 y=40 color=blau)     Textblock (mm, von links oben)
 *     (CMS:PDF_TABLE 40,80,30) (CMS:PDF_ROW) (CMS:PDF_CELL "…") … (CMS:PDF_TABLE_END)
 *     (CMS:PDF_META title="Angebot" author="…")            Dokumenteigenschaften
 *     (CMS:PDF_PRINT angebot.pdf)                          ausgeben - beendet den Request
 *
 * Diese Klasse wird von Page über LazyServicesTrait erst beim ersten (CMS:PDF_*)-Befehl
 * geladen (install/settings.php['services']); die Befehlsdefinitionen stehen in
 * commands.php, die Befehlsreferenz in README.md. Die Bibliothek tc-lib-pdf wird erst
 * mit der ersten Seite angefasst.
 *
 * VERZEICHNISSE (var/modules/pdf/)
 * --------------------
 *     layouts/    PDF-Vorlagen, deren Seiten PDF_PAGE als Hintergrund übernimmt
 *     fonts/      Schriften im Format von tc-lib-pdf-font ({name}.json [+ .z, .ctg.z]);
 *                 der Installer legt die 14 PDF-Standardschriften nach fonts/core/.
 *                 Eine TrueType-/OpenType-Datei {name}.ttf|.otf im Verzeichnis wird beim
 *                 ersten (CMS:PDF_FONT {name}) automatisch konvertiert - der Name der
 *                 konvertierten Schrift ergibt sich aus der Datei selbst (Log 'pdf').
 *     profiles/   ICC-Profile für PDF_INIT icc_profile= (Output Intent)
 *
 * KOORDINATEN UND FARBEN
 * --------------------
 * Alle Maße sind Millimeter, gemessen von der linken oberen Ecke; Schriftgrößen sind
 * Punkt. Farben sind benannte Farbfelder (PDF_COLOR) mit RGB- und/oder CMYK-Wert; der
 * Farbraum des Dokuments (PDF_INIT colorspace) entscheidet, welcher Wert ins PDF geht -
 * fehlt er, wird aus dem anderen umgerechnet. Wo ein Befehl eine Farbe erwartet, geht
 * statt des Namens auch '#rrggbb' oder 'c,m,y,k' (0-100).
 *
 * Ein "Cursor" (aktuelle Schreibposition) rückt nach jedem Textblock, Bild und jeder
 * Tabelle unter deren Unterkante und steht nach PDF_PAGE am oberen Rand: Befehle ohne
 * y= schreiben dort, ohne x= am linken Rand.
 *
 * AUSGABE
 * --------------------
 * Ab der ersten Seite wird die Template-Ausgabe gepuffert, damit der Whitespace
 * zwischen den Befehlen nicht vor dem PDF beim Browser landet. PDF_PRINT verwirft den
 * Puffer, sendet die Header und das Dokument und beendet den Request mit exit - wie
 * die Befehle mit php_suffix 'include_and_stop'. Was das Template VOR der ersten
 * Seite ausgibt, ist bereits unterwegs; PDF_PRINT meldet dann "headers already sent".
 *
 * FEHLER
 * --------------------
 * Fehler (fehlende Vorlage, unbekannte Schrift oder Farbe, Ausnahmen der Bibliothek)
 * gehen an Cms::error() - ein (CMS:MESSAGE) zeigt sie - und ins Log
 * var/log/{datum}_pdf.log. Das Template läuft weiter; ein PDF_PRINT ohne Dokument tut
 * nichts, die Meldungen erreichen so den Browser.
 *
 * @see commands.php                          Befehlsdefinitionen
 * @see README.md                             Befehlsreferenz
 * @see Camindo\Core\Page                     $lazyServices, Template-Kontext
 * @see Camindo\Core\Modules\Template\TemplateParserTrait::loadCommands()
 * @see https://github.com/tecnickcom/tc-lib-pdf
 */
namespace Camindo\Modules\Pdf;

use Camindo\Core\Cms;
use Camindo\Core\Config;
use Camindo\Core\System;
use Com\Tecnick\Color\Model as ColorModel;
use Com\Tecnick\Color\Model\Cmyk;
use Com\Tecnick\Color\Model\Rgb;
use Com\Tecnick\Pdf\Font\Import as FontImport;
use Com\Tecnick\Pdf\Tcpdf;

class CmsPdf {

    private const UNIT = 'mm';
    private const LOG = 'pdf';
    private const DEFAULT_LINE_WIDTH = 0.2;    // mm, Linien und Rahmen ohne Angabe

    private ?Tcpdf $pdf = null;
    private int $pages = 0;             // angelegte Seiten des laufenden Dokuments
    private array $sources = [];        // Layout-Datei => Source-Id in tc-lib-pdf
    private int $obLevel = 0;           // Pufferstand vor dem Dokument, siehe discardOutput()
    private array $lastPage = [];       // Parameter des letzten PDF_PAGE (Folgeseiten der Tabellen)
    private float $cursorY = 0;         // Schreibposition, siehe Klassenkommentar

    // Dokumentvorgaben (PDF_INIT)
    private string $colorspace = 'RGB';
    private string $iccFile = '';
    private string $iccName = '';
    private string $format = 'A4';
    private string $orientation = 'P';
    private array $margins = ['T' => 20.0, 'R' => 20.0, 'B' => 20.0, 'L' => 20.0];
    private array $meta = [];
    private array $pageMargins = ['T' => 20.0, 'R' => 20.0, 'B' => 20.0, 'L' => 20.0]; // der aktuellen Seite

    // Aktuelle Schrift - gilt für alle folgenden Texte, auch über Seiten hinweg
    private string $fontName = 'helvetica';
    private string $fontStyle = '';
    private float $fontSize = 12;
    private array $fontAlias = [];      // angeforderter Name => Name der konvertierten Schrift

    // Farbfelder (PDF_COLOR) und eingestellte Farben (Farbangabe, siehe colorModel())
    private array $palette = [];        // name => ['rgb' => ?Rgb, 'cmyk' => ?Cmyk]
    private ?string $textColor = null;
    private ?string $fillColor = null;
    private ?string $lineColor = null;

    // Laufende Tabelle (PDF_TABLE … PDF_TABLE_END), siehe cmsPdfTable()
    private ?array $table = null;

    public function __construct(
        private Cms $cms,
        private Config $config,
        private System $system,
    )
    {
    }

    // Dokument
    // ====================

    // CMS:PDF_INIT - Vorgaben für das Dokument, vor der ersten Seite
    public function cmsPdfInit(array $p): void
    {
        if ($this->pdf !== null) {
            $this->error('CMS:PDF_INIT: must come before the first CMS:PDF_PAGE.');
            return;
        }
        $colorspace = strtoupper(trim((string)($p['colorspace'] ?? 'RGB')));
        if (!in_array($colorspace, ['RGB', 'CMYK'], true)) {
            $this->error('CMS:PDF_INIT: colorspace must be RGB or CMYK.');
            return;
        }
        $this->colorspace = $colorspace;

        $icc = trim((string)($p['icc_profile'] ?? ''));
        if ($icc !== '') {
            $file = $this->varFile('profiles', $icc, ['icc', 'icm']);
            if ($file === null) {
                $this->error('CMS:PDF_INIT: icc_profile "' . $icc . '" not found in ' . $this->varDir() . '/profiles.');
                return;
            }
            $this->iccFile = $file;
            $this->iccName = trim((string)($p['output_condition'] ?? '')) ?: pathinfo($icc, PATHINFO_FILENAME);
        }
        if (isset($p['format']))      $this->format = trim((string)$p['format']) ?: 'A4';
        if (isset($p['orientation'])) $this->orientation = $this->orientation((string)$p['orientation']);
        if (isset($p['margins']))     $this->margins = $this->sides((string)$p['margins'], $this->margins);
    }

    // CMS:PDF_META - Dokumenteigenschaften
    public function cmsPdfMeta(array $p): void
    {
        foreach (['author', 'title', 'subject', 'keywords', 'creator'] as $key) {
            if (isset($p[$key])) $this->meta[$key] = (string)$p[$key];
        }
    }

    // CMS:PDF_PAGE - neue Seite, wahlweise auf einer Seite einer Layout-Vorlage
    public function cmsPdfPage(array $p): void
    {
        if ($this->table !== null) {
            $this->error('CMS:PDF_PAGE: table not finished - CMS:PDF_TABLE_END missing.');
            return;
        }
        try {
            $this->addPage($p);
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_PAGE: ' . $e->getMessage());
        }
    }

    // CMS:PDF_PRINT - Dokument an den Browser senden und den Request beenden
    public function cmsPdfPrint(array $p): void
    {
        if ($this->pdf === null || $this->pages < 1) {
            $this->error('CMS:PDF_PRINT: no document - use CMS:PDF_PAGE first.');
            return;
        }
        if ($this->table !== null) {
            $this->error('CMS:PDF_PRINT: table not finished - CMS:PDF_TABLE_END missing.');
            return;
        }
        try {
            $filename = $this->outputFilename((string)($p['filename'] ?? ''));
            // download: leer/false = inline, true = Download unter 'filename',
            // sonst Download unter dem angegebenen Namen
            $download = trim((string)($p['download'] ?? ''));
            $attachment = false;
            if ($download !== '' && !in_array(strtolower($download), ['0', 'false', 'no', 'off'], true)) {
                $attachment = true;
                if (!in_array(strtolower($download), ['1', 'true', 'yes', 'on'], true)) {
                    $filename = $this->outputFilename($download);
                }
            }
            $this->pdf->setCreator($this->meta['creator'] ?? 'camindo CMS');
            if (!empty($this->meta['author']))   $this->pdf->setAuthor($this->meta['author']);
            if (!empty($this->meta['title']))    $this->pdf->setTitle($this->meta['title']);
            if (!empty($this->meta['subject']))  $this->pdf->setSubject($this->meta['subject']);
            if (!empty($this->meta['keywords'])) $this->pdf->setKeywords($this->meta['keywords']);
            $raw = $this->pdf->getOutPDFString();
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_PRINT: ' . $e->getMessage());
            return;
        }

        // Bisherige Template-Ausgabe (Whitespace zwischen den Befehlen) verwerfen
        $this->discardOutput();
        if (headers_sent($file, $line)) {
            $this->error("CMS:PDF_PRINT: headers already sent ($file:$line) - no output before CMS:PDF_PAGE allowed.");
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($raw));
        header('Cache-Control: private, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex');
        echo $raw;
        exit;
    }

    // Schrift und Farben
    // ====================

    // CMS:PDF_FONT - Schrift für die folgenden Texte
    public function cmsPdfFont(array $p): void
    {
        $name = strtolower(trim((string)($p['font'] ?? '')));
        if ($name === '') {
            $name = $this->fontName;
        }
        elseif (!preg_match('/^[a-z0-9_\-]+$/', $name)) {
            $this->error('CMS:PDF_FONT: invalid font name "' . $name . '".');
            return;
        }
        // tc-lib-pdf: B fett, I kursiv, U unterstrichen, D durchgestrichen, O überstrichen
        $style = isset($p['style']) ? preg_replace('/[^BIUDO]/', '', strtoupper((string)$p['style'])) : $this->fontStyle;
        $size  = isset($p['size']) && (float)$p['size'] > 0 ? (float)$p['size'] : $this->fontSize;

        // Ohne Seite nur merken - PDF_PAGE wendet die Schrift an. Sonst erst anwenden,
        // dann übernehmen: eine unbekannte Schrift lässt die bisherige in Kraft.
        if ($this->pages > 0) {
            try {
                $this->applyFont($name, $style, $size);
            }
            catch (\Throwable $e) {
                $this->error('CMS:PDF_FONT: ' . $e->getMessage());
                return;
            }
        }
        $this->fontName  = $name;
        $this->fontStyle = $style;
        $this->fontSize  = $size;
    }

    // CMS:PDF_COLOR - Farbfeld mit RGB- und/oder CMYK-Wert
    public function cmsPdfColor(array $p): void
    {
        $name = strtolower(trim((string)($p['name'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $name)) {
            $this->error('CMS:PDF_COLOR: invalid color name "' . $name . '".');
            return;
        }
        $rgb  = $this->parseRgb((string)($p['rgb'] ?? ''));
        $cmyk = $this->parseCmyk((string)($p['cmyk'] ?? ''));
        if ($rgb === null && $cmyk === null) {
            $this->error('CMS:PDF_COLOR "' . $name . '": rgb=#rrggbb and/or cmyk=c,m,y,k (0-100) required.');
            return;
        }
        $this->palette[$name] = ['rgb' => $rgb, 'cmyk' => $cmyk];
    }

    // CMS:PDF_TEXT_COLOR / PDF_FILL_COLOR / PDF_LINE_COLOR - Farbe für die folgenden Befehle
    public function cmsPdfTextColor(array $p): void
    {
        $this->textColor = $this->checkedColor((string)($p['name'] ?? ''), 'CMS:PDF_TEXT_COLOR');
    }

    public function cmsPdfFillColor(array $p): void
    {
        $this->fillColor = $this->checkedColor((string)($p['name'] ?? ''), 'CMS:PDF_FILL_COLOR');
    }

    public function cmsPdfLineColor(array $p): void
    {
        $this->lineColor = $this->checkedColor((string)($p['name'] ?? ''), 'CMS:PDF_LINE_COLOR');
    }

    // Inhalte
    // ====================

    // CMS:PDF_TEXT - Textblock mit Umbruch innerhalb der Breite
    public function cmsPdfText(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_TEXT')) return;
        $text = (string)($p['text'] ?? '');
        if ($text === '') return;
        try {
            $pdf = $this->pdf;
            $x = $this->coord($p['x'] ?? null, $this->pageMargins['L']);
            $y = $this->coord($p['y'] ?? null, $this->cursorY);
            $width = (float)($p['width'] ?? 0);
            if ($width <= 0) $width = $this->pageWidth() - $this->pageMargins['R'] - $x;
            $align = $this->align((string)($p['align'] ?? 'L'));
            $size = isset($p['size']) && (float)$p['size'] > 0 ? (float)$p['size'] : $this->fontSize;

            $sizeChanged = $size !== $this->fontSize;
            if ($sizeChanged) $this->applyFont($this->fontName, $this->fontStyle, $size);
            $content = $this->textCell($text, $x, $y, $width, 0, $align, 'T');
            $box = $pdf->getLastCellBBox();

            $out = '';
            if (!empty($p['background'])) {
                $out .= $this->colorOp((string)$p['background'], false)
                    . $pdf->graph->getBasicRect($box['x'], $box['y'], $box['w'], $box['h'], 'f');
            }
            $out .= $this->colorOp(isset($p['color']) ? (string)$p['color'] : $this->textColor, false) . $content;
            $pdf->page->addContent($out);
            if ($sizeChanged) $this->applyFont($this->fontName, $this->fontStyle, $this->fontSize);
            $this->cursorY = $box['y'] + $box['h'];
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_TEXT: ' . $e->getMessage());
        }
    }

    // CMS:PDF_HTML - HTML-Block (Umbruch, Seitenumbruch durch tc-lib-pdf)
    public function cmsPdfHtml(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_HTML')) return;
        $html = (string)($p['html'] ?? '');
        if (trim($html) === '') return;
        try {
            $x = $this->coord($p['x'] ?? null, $this->pageMargins['L']);
            $y = $this->coord($p['y'] ?? null, $this->cursorY);
            $width = (float)($p['width'] ?? 0);
            if ($width <= 0) $width = $this->pageWidth() - $this->pageMargins['R'] - $x;
            $this->pdf->page->addContent($this->colorOp($this->textColor, false));
            $this->pdf->addHTMLCell($html, $x, $y, $width);
            // tc-lib-pdf verrät die Höhe des Blocks nicht - der Cursor bleibt stehen
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_HTML: ' . $e->getMessage());
        }
    }

    // CMS:PDF_IMAGE - Bild aus dem Medienverzeichnis, wahlweise verlinkt
    public function cmsPdfImage(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_IMAGE')) return;
        $file = $this->mediaFile((string)($p['file'] ?? ''));
        if ($file === null) {
            $this->error('CMS:PDF_IMAGE: file "' . (string)($p['file'] ?? '') . '" not found below ' . $this->mediaDir() . '.');
            return;
        }
        try {
            $pdf = $this->pdf;
            $iid = $pdf->image->add($file);
            $px = $pdf->image->getImageDimensionsByKey($pdf->image->getKey($file));
            $ratio = $px['width'] > 0 ? $px['height'] / $px['width'] : 1;
            $width  = (float)($p['width'] ?? 0);
            $height = (float)($p['height'] ?? 0);
            if ($width <= 0 && $height <= 0) {
                $width = $px['width'] * 25.4 / 72;   // Pixel als Punkt (72 dpi)
            }
            if ($width <= 0)  $width  = $ratio > 0 ? $height / $ratio : $height;
            if ($height <= 0) $height = $width * $ratio;
            $x = $this->coord($p['x'] ?? null, $this->pageMargins['L']);
            $y = $this->coord($p['y'] ?? null, $this->cursorY);
            $pdf->page->addContent($pdf->image->getSetImage($iid, $x, $y, $width, $height, $this->pageHeight()));
            $link = trim((string)($p['link'] ?? ''));
            if ($link !== '') {
                $pdf->page->addAnnotRef($pdf->setLink($x, $y, $width, $height, $link));
            }
            $this->cursorY = $y + $height;
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_IMAGE: ' . $e->getMessage());
        }
    }

    // CMS:PDF_LINE - Linie
    public function cmsPdfLine(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_LINE')) return;
        try {
            $this->pdf->page->addContent(
                $this->colorOp(isset($p['color']) ? (string)$p['color'] : $this->lineColor, true)
                . $this->pdf->graph->getLine(
                    (float)($p['x1'] ?? 0), (float)($p['y1'] ?? 0), (float)($p['x2'] ?? 0), (float)($p['y2'] ?? 0),
                    ['lineWidth' => $this->lineWidth($p['width'] ?? null)]
                )
            );
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_LINE: ' . $e->getMessage());
        }
    }

    // CMS:PDF_RECT - Rechteck, gefüllt und/oder umrandet
    public function cmsPdfRect(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_RECT')) return;
        try {
            $style = preg_replace('/[^DF]/', '', strtoupper((string)($p['style'] ?? 'D'))) ?: 'D';
            $mode = str_contains($style, 'F') ? (str_contains($style, 'D') ? 'DF' : 'F') : 'D';
            $this->pdf->page->addContent(
                $this->colorOp(isset($p['color']) ? (string)$p['color'] : $this->fillColor, false)
                . $this->colorOp(isset($p['border']) ? (string)$p['border'] : $this->lineColor, true)
                . $this->pdf->graph->getRect(
                    (float)($p['x'] ?? 0), (float)($p['y'] ?? 0), (float)($p['width'] ?? 0), (float)($p['height'] ?? 0),
                    $mode, ['all' => ['lineWidth' => $this->lineWidth($p['line_width'] ?? null)]]
                )
            );
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_RECT: ' . $e->getMessage());
        }
    }

    // Tabellen
    // ====================
    //
    // PDF_TABLE sammelt, PDF_ROW/PDF_CELL füllen, PDF_TABLE_END zeichnet (renderTable()).
    // Jede Zelle merkt sich die Schrift, die bei ihrem PDF_CELL eingestellt war - so
    // bekommt die Kopfzeile ihre fette Schrift mit einem PDF_FONT davor. Zeilen werden
    // nie geteilt: passt eine Zeile nicht mehr auf die Seite, beginnt sie auf der
    // nächsten (Folgeseite wie das letzte PDF_PAGE bzw. next_layout/next_page), und die
    // Kopfzeilen (is_header) werden dort wiederholt.

    // CMS:PDF_TABLE - Tabelle beginnen
    public function cmsPdfTable(array $p): void
    {
        if (!$this->requirePage('CMS:PDF_TABLE')) return;
        if ($this->table !== null) {
            $this->error('CMS:PDF_TABLE: previous table not finished - CMS:PDF_TABLE_END missing.');
            return;
        }
        $cols = [];
        foreach (explode(',', trim((string)($p['cols'] ?? ''), " \t{}")) as $col) {
            if (trim($col) === '') continue;
            $cols[] = (float)trim($col);
        }
        if ($cols === [] || min($cols) <= 0) {
            $this->error('CMS:PDF_TABLE: cols= must be a comma list of column widths in mm.');
            return;
        }
        $next = [];
        if (isset($p['next_layout'])) $next['layout'] = (string)$p['next_layout'];
        if (isset($p['next_page']))   $next['page']   = (string)$p['next_page'];
        $this->table = [
            'cols'         => $cols,
            'x'            => $this->coord($p['x'] ?? null, $this->pageMargins['L']),
            'y'            => $this->coord($p['y'] ?? null, $this->cursorY),
            'padding'      => $this->sides((string)($p['padding'] ?? ''), ['T' => 1.0, 'R' => 1.0, 'B' => 1.0, 'L' => 1.0]),
            'border_width' => $this->lineWidth($p['border_width'] ?? null),
            'next'         => $next,
            'rows'         => [],
        ];
    }

    // CMS:PDF_ROW - neue Zeile
    public function cmsPdfRow(array $p): void
    {
        if ($this->table === null) {
            $this->error('CMS:PDF_ROW: no table - use CMS:PDF_TABLE first.');
            return;
        }
        $this->table['rows'][] = [
            'header'        => $this->bool($p['is_header'] ?? false),
            'keep_together' => $this->bool($p['keep_together'] ?? true),
            'cells'         => [],
            'next_col'      => 0,
        ];
    }

    // CMS:PDF_CELL - Zelle der aktuellen Zeile
    public function cmsPdfCell(array $p): void
    {
        if ($this->table === null || $this->table['rows'] === []) {
            $this->error('CMS:PDF_CELL: no row - use CMS:PDF_TABLE and CMS:PDF_ROW first.');
            return;
        }
        $rowIdx = array_key_last($this->table['rows']);
        $row = &$this->table['rows'][$rowIdx];
        $col = isset($p['col']) && $p['col'] !== '' ? (int)$p['col'] : $row['next_col'];
        if ($col < 0 || $col >= count($this->table['cols'])) {
            $this->error("CMS:PDF_CELL: column $col does not exist (" . count($this->table['cols']) . ' columns).');
            return;
        }
        $fit = strtolower(trim((string)($p['fit'] ?? 'none')));
        if (!in_array($fit, ['none', 'scale', 'fontsize', 'spacing', 'force'], true)) {
            $this->error('CMS:PDF_CELL: fit must be none, scale, fontsize, spacing or force.');
            return;
        }
        $border = strtoupper(trim((string)($p['border'] ?? '1')));
        if ($border === '1') $border = 'LTRB';
        elseif ($border === '0') $border = '';
        else $border = preg_replace('/[^LTRB]/', '', $border);

        $row['cells'][$col] = [
            'text'         => (string)($p['text'] ?? ''),
            'align'        => $this->align((string)($p['align'] ?? 'L')),
            'valign'       => $this->valign((string)($p['valign'] ?? 'T')),
            'fit'          => $fit,
            'min_size'     => max(1.0, (float)($p['min_size'] ?? 6)),
            'border'       => $border,
            'border_width' => isset($p['border_width']) ? $this->lineWidth($p['border_width']) : $this->table['border_width'],
            'padding'      => isset($p['padding']) ? $this->sides((string)$p['padding'], $this->table['padding']) : $this->table['padding'],
            'color'        => isset($p['color']) ? (string)$p['color'] : $this->textColor,
            'background'   => isset($p['background']) ? (string)$p['background'] : null,
            'border_color' => isset($p['border_color']) ? (string)$p['border_color'] : $this->lineColor,
            'font'         => [$this->fontName, $this->fontStyle, $this->fontSize],
        ];
        $row['next_col'] = $col + 1;
        unset($row);
    }

    // CMS:PDF_TABLE_END - Tabelle zeichnen
    public function cmsPdfTableEnd(array $p): void
    {
        if ($this->table === null) {
            $this->error('CMS:PDF_TABLE_END: no table - use CMS:PDF_TABLE first.');
            return;
        }
        $table = $this->table;
        $this->table = null;
        try {
            $this->renderTable($table);
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_TABLE_END: ' . $e->getMessage());
        }
    }

    /**
     * Tabelle zeichnen: Zeilenhöhen messen, bei Bedarf umbrechen, Zellen ausgeben.
     */
    private function renderTable(array $table): void
    {
        $pdf = $this->pdf;
        $y = $table['y'];
        $headers = [];       // bereits gezeichnete Kopfzeilen, auf Folgeseiten wiederholt
        $rowsOnPage = 0;

        foreach ($table['rows'] as $row) {
            $layout = $this->layoutRow($table, $row);
            if ($rowsOnPage > 0 && $y + $layout['height'] > $this->pageHeight() - $this->pageMargins['B']) {
                // Folgeseite, dann Kopfzeilen wiederholen
                $this->addPage($table['next'] + $this->lastPage);
                $y = $this->cursorY;
                $rowsOnPage = 0;
                if (!$row['header']) {
                    foreach ($headers as $header) {
                        $y = $this->drawRow($table, $header, $y);
                        $rowsOnPage++;
                    }
                }
            }
            $y = $this->drawRow($table, $layout, $y);
            $rowsOnPage++;
            if ($row['header']) $headers[] = $layout;
        }
        $this->cursorY = $y;
        // Schrift der letzten Zelle steht noch im Inhalt - eingestellte Schrift wieder setzen
        $this->applyFont($this->fontName, $this->fontStyle, $this->fontSize);
    }

    /**
     * Zeile vermessen: je Zelle die eingepasste Schrift (fit) und die Höhe; das
     * Ergebnis ist die Zeile plus 'height' und je Zelle 'font_fit' / 'inner_width'.
     */
    private function layoutRow(array $table, array $row): array
    {
        $pdf = $this->pdf;
        $height = 0.0;
        foreach ($row['cells'] as $col => &$cell) {
            $pad = $cell['padding'];
            $inner = max(1.0, $table['cols'][$col] - $pad['L'] - $pad['R']);
            $cell['inner_width'] = $inner;
            $cell['font_fit'] = $this->fitFont($cell, $inner);
            $cellHeight = $pad['T'] + $pad['B'];
            if ($cell['text'] !== '') {
                $this->insertFont(...$cell['font_fit']);
                $this->textCell($cell['text'], 0, 0, $inner, 0, $cell['align'], 'T');
                $cellHeight += $pdf->getLastCellBBox()['h'];
                $pdf->font->popLastFont();
            }
            else {
                // leere Zelle: eine Zeilenhöhe der Schrift
                $this->insertFont(...$cell['font_fit']);
                $cellHeight += $pdf->toUnit($pdf->font->getCurrentFont()['height']);
                $pdf->font->popLastFont();
            }
            $height = max($height, $cellHeight);
        }
        unset($cell);
        $row['height'] = $height;
        return $row;
    }

    /**
     * Schrift einer Zelle nach 'fit' einpassen: [name, style, size, spacing, stretching].
     * Ziel ist eine Zeile in der Innenbreite; was auch damit nicht passt, bricht um.
     */
    private function fitFont(array $cell, float $inner): array
    {
        [$name, $style, $size] = $cell['font'];
        $result = [$name, $style, $size, 0.0, 1.0];
        if ($cell['fit'] === 'none' || $cell['text'] === '') return $result;

        $pdf = $this->pdf;
        $this->insertFont($name, $style, $size);
        $ords = $pdf->uniconv->strToOrdArr($cell['text']);
        $width = $pdf->toUnit($pdf->font->getOrdArrWidth($ords));
        $pdf->font->popLastFont();
        if ($width <= $inner || $width <= 0) return $result;

        $chars = max(1, count($ords) - 1);
        $ratio = $inner / $width;
        $min = min($cell['min_size'], $size);
        switch ($cell['fit']) {
            case 'fontsize':
                $result[2] = max($min, $size * $ratio);
                break;
            case 'spacing':
                // Zeichenabstand in Punkt, höchstens 15 % der Schriftgröße enger - reicht
                // das nicht, bleibt der Abstand normal und der Text bricht lesbar um
                $result[3] = $this->fitSpacing($width, $inner, $size, $chars);
                break;
            case 'scale':
                // Schriftgröße und Abstand teilen sich die Verkleinerung
                $newSize = max($min, $size * sqrt($ratio));
                $newWidth = $width * $newSize / $size;
                $result[2] = $newSize;
                if ($newWidth > $inner) {
                    $result[3] = $this->fitSpacing($newWidth, $inner, $newSize, $chars);
                }
                break;
            case 'force':
                $result[4] = max(0.3, $ratio);
                break;
        }
        return $result;
    }

    /**
     * Zeichenabstand (Punkt, negativ), der $width mm auf $inner mm bringt - höchstens
     * 15 % der Schriftgröße enger; 0, wenn das nicht reicht.
     */
    private function fitSpacing(float $width, float $inner, float $size, int $chars): float
    {
        $needed = $this->pdf->toPoints($inner - $width) / $chars;
        return $needed >= -0.15 * $size ? $needed : 0.0;
    }

    /**
     * Eine vermessene Zeile bei $y zeichnen; liefert die Unterkante.
     */
    private function drawRow(array $table, array $row, float $y): float
    {
        $pdf = $this->pdf;
        $x = $table['x'];
        $h = $row['height'];
        foreach ($table['cols'] as $col => $w) {
            $cell = $row['cells'][$col] ?? null;
            if ($cell !== null) {
                $out = '';
                if ($cell['background'] !== null) {
                    $out .= $this->colorOp($cell['background'], false) . $pdf->graph->getBasicRect($x, $y, $w, $h, 'f');
                }
                if ($cell['text'] !== '') {
                    $pad = $cell['padding'];
                    $font = $this->insertFont(...$cell['font_fit']);
                    $out .= $font['out'] . $this->colorOp($cell['color'], false)
                        . $this->textCell($cell['text'], $x + $pad['L'], $y + $pad['T'], $cell['inner_width'],
                            max(0.0, $h - $pad['T'] - $pad['B']), $cell['align'], $cell['valign']);
                    $pdf->font->popLastFont();
                }
                if ($cell['border'] !== '') {
                    $out .= $this->colorOp($cell['border_color'], true);
                    $style = ['lineWidth' => $cell['border_width']];
                    if (str_contains($cell['border'], 'T')) $out .= $pdf->graph->getLine($x, $y, $x + $w, $y, $style);
                    if (str_contains($cell['border'], 'B')) $out .= $pdf->graph->getLine($x, $y + $h, $x + $w, $y + $h, $style);
                    if (str_contains($cell['border'], 'L')) $out .= $pdf->graph->getLine($x, $y, $x, $y + $h, $style);
                    if (str_contains($cell['border'], 'R')) $out .= $pdf->graph->getLine($x + $w, $y, $x + $w, $y + $h, $style);
                }
                $pdf->page->addContent($out);
            }
            $x += $w;
        }
        return $y + $h;
    }

    // Hilfsfunktionen: Dokument und Seite
    // ====================

    /**
     * Das laufende Dokument - beim ersten Aufruf angelegt. Ab hier wird die
     * Template-Ausgabe gepuffert (siehe Klassenkommentar).
     */
    private function document(): Tcpdf
    {
        if ($this->pdf === null) {
            $fontsDir = $this->varDir() . '/fonts';
            // tc-lib-pdf-font sucht seine Definitionen unter K_PATH_FONTS (und dessen
            // Unterverzeichnissen) - eine Konstante, also einmal je Request
            if (!defined('K_PATH_FONTS')) {
                define('K_PATH_FONTS', $fontsDir);
            }
            // Lesbare Verzeichnisse für tc-lib-pdf (Schriften, Vorlagen, Profile, Medien)
            $vendor = dirname((new \ReflectionClass(Tcpdf::class))->getFileName(), 3);
            $paths = array_values(array_filter(array_unique([
                sys_get_temp_dir(), realpath(sys_get_temp_dir()) ?: '', $fontsDir, $this->varDir(),
                $this->mediaDir(), realpath($this->mediaDir()) ?: '', $vendor,
            ])));
            $this->obLevel = ob_get_level();
            ob_start();
            $this->pdf = new Tcpdf(self::UNIT, true, false, true, '', null, [
                'allowedPaths'       => $paths,
                'markupAllowedPaths' => array_values(array_filter(array_unique(
                    [$this->mediaDir(), realpath($this->mediaDir()) ?: '', $fontsDir]
                ))),
            ]);
            if ($this->iccFile !== '') {
                // Bezeichner auch als /OutputCondition und /Info - sonst stünde dort "sRGB"
                $this->pdf->setOutputIntent($this->iccName, $this->iccFile, $this->iccName, 'http://www.color.org', $this->iccName);
            }
            $this->pages = 0;
            $this->sources = [];
        }
        return $this->pdf;
    }

    /**
     * Seite anlegen (PDF_PAGE und Folgeseiten der Tabellen): auf einer Layout-Seite
     * oder leer, mit den Rändern aus PDF_INIT bzw. margins=.
     */
    private function addPage(array $p): void
    {
        $pdf = $this->document();
        $margins = isset($p['margins']) ? $this->sides((string)$p['margins'], $this->margins) : $this->margins;
        $marginData = [
            'PL' => $margins['L'], 'PR' => $margins['R'],
            'PT' => $margins['T'], 'HB' => $margins['T'], 'CT' => $margins['T'],
            'CB' => $margins['B'], 'FT' => $margins['B'], 'PB' => $margins['B'],
        ];
        $layout = trim((string)($p['layout'] ?? ''));
        if ($layout !== '') {
            $file = $this->varFile('layouts', $layout, ['pdf']);
            if ($file === null) {
                throw new \RuntimeException('layout "' . $layout . '" not found in ' . $this->varDir() . '/layouts.');
            }
            $this->sources[$file] ??= $pdf->setImportSourceFile($file);
            $tpl = $pdf->importPage($this->sources[$file], max(1, (int)($p['page'] ?? 1)));
            $w = $pdf->toUnit($tpl->getWidth());
            $h = $pdf->toUnit($tpl->getHeight());
            $pdf->addPage(['format' => '', 'width' => $w, 'height' => $h, 'orientation' => $w > $h ? 'L' : 'P', 'margin' => $marginData]);
            $pdf->useImportedPage($tpl, 0.0, 0.0, $w, $h, ['keepAspectRatio' => false]);
        }
        else {
            $pdf->addPage([
                'format'      => trim((string)($p['format'] ?? $this->format)) ?: $this->format,
                'orientation' => isset($p['orientation']) ? $this->orientation((string)$p['orientation']) : $this->orientation,
                'margin'      => $marginData,
            ]);
        }
        $this->pages++;
        $this->pageMargins = $margins;
        $this->cursorY = $margins['T'];
        $this->lastPage = $p;
        // Schrift auf der neuen Seite wiederherstellen
        $this->applyFont($this->fontName, $this->fontStyle, $this->fontSize);
    }

    private function requirePage(string $command): bool
    {
        if ($this->pdf === null || $this->pages < 1) {
            $this->error($command . ': no page - use CMS:PDF_PAGE first.');
            return false;
        }
        return true;
    }

    private function pageWidth(): float
    {
        return (float)$this->pdf->page->getPage()['width'];
    }

    private function pageHeight(): float
    {
        return (float)$this->pdf->page->getPage()['height'];
    }

    // Hilfsfunktionen: Schrift und Text
    // ====================

    /**
     * Schrift auf den Font-Stapel legen (ohne Ausgabe); eine unbekannte Schrift wird
     * einmal als TTF/OTF im Fonts-Verzeichnis gesucht und konvertiert.
     *
     * @return array  Font-Daten von tc-lib-pdf ('out' = Operator für den Seiteninhalt)
     * @throws \RuntimeException  Schrift nicht vorhanden
     */
    private function insertFont(string $name, string $style, float $size, float $spacing = 0.0, float $stretching = 1.0): array
    {
        $pdf = $this->document();
        $key = $this->fontAlias[$name] ?? $name;
        try {
            return $pdf->font->insert($pdf->pon, $key, $style, $size, $spacing, $stretching);
        }
        catch (\Com\Tecnick\Pdf\Font\Exception $e) {
            $converted = $this->convertFont($name);
            if ($converted === null) {
                throw new \RuntimeException('font "' . $name . '" not found in ' . $this->varDir() . '/fonts'
                    . ' (' . $e->getMessage() . ')');
            }
            $this->fontAlias[$name] = $converted;
            return $pdf->font->insert($pdf->pon, $converted, $style, $size, $spacing, $stretching);
        }
    }

    /**
     * Schrift einstellen und den Operator in die Seite schreiben.
     */
    private function applyFont(string $name, string $style, float $size): void
    {
        $font = $this->insertFont($name, $style, $size);
        $this->pdf->page->addContent($font['out']);
    }

    /**
     * Textblock mit Umbruch als Seiteninhalt (ohne Rahmen/Füllung - die zeichnen die
     * Aufrufer selbst); getLastCellBBox() liefert danach die belegte Fläche.
     */
    private function textCell(string $text, float $x, float $y, float $width, float $height, string $align, string $valign): string
    {
        return $this->pdf->getTextCell(
            txt: $text, posx: $x, posy: $y, width: $width, height: $height,
            valign: $valign, halign: $align, cell: Tcpdf::ZEROCELL, drawcell: false,
        );
    }

    /**
     * {name}.ttf|.otf aus dem Fonts-Verzeichnis nach tc-lib-pdf-font konvertieren
     * (Ausgabe {fontname}.json + .z + .ctg.z im selben Verzeichnis).
     *
     * @return string|null  Name der konvertierten Schrift, null ohne Quelldatei
     */
    private function convertFont(string $name): ?string
    {
        $dir = $this->varDir() . '/fonts';
        $source = null;
        foreach (scandir($dir) ?: [] as $entry) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (($ext === 'ttf' || $ext === 'otf') && strtolower(pathinfo($entry, PATHINFO_FILENAME)) === $name) {
                $source = "$dir/$entry";
                break;
            }
        }
        if ($source === null) return null;

        // encoding_id 10: vollständige Unicode-Tabelle (auch Zeichen über U+FFFF)
        $import = new FontImport($source, $dir . '/', 'TrueTypeUnicode', '', 32, 3, 10);
        $converted = $import->getFontName();
        $this->system->log(self::LOG, 'font "' . basename($source) . '" converted - use (CMS:PDF_FONT ' . $converted . ')');
        return $converted;
    }

    // Hilfsfunktionen: Farben
    // ====================

    /**
     * Farbangabe prüfen (Farbfeld, '#rrggbb' oder 'c,m,y,k'); null bei Fehler oder
     * leerer Angabe (= Standardfarbe).
     */
    private function checkedColor(string $value, string $command): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if ($this->colorModel($value) === null) {
            $this->error($command . ': unknown color "' . $value . '".');
            return null;
        }
        return $value;
    }

    /**
     * Farboperator für den Seiteninhalt im Farbraum des Dokuments. Ohne Farbe Schwarz.
     *
     * @param bool $stroke  true: Linienfarbe (K/RG), false: Füll-/Textfarbe (k/rg)
     */
    private function colorOp(?string $value, bool $stroke): string
    {
        $model = null;
        if ($value !== null && trim($value) !== '') {
            $model = $this->colorModel($value);
            if ($model === null) {
                $this->error('CMS:PDF: unknown color "' . $value . '" - black used.');
            }
        }
        if ($model === null) {
            $model = $this->colorspace === 'CMYK'
                ? new Cmyk(['cyan' => 0, 'magenta' => 0, 'yellow' => 0, 'key' => 1])
                : new Rgb(['red' => 0, 'green' => 0, 'blue' => 0]);
        }
        return $model->getPdfColor($stroke);
    }

    /**
     * Farbmodell im Farbraum des Dokuments: Farbfeld (PDF_COLOR), '#rrggbb' oder
     * 'c,m,y,k' (0-100). Fehlt der passende Wert im Farbfeld, wird umgerechnet.
     */
    private function colorModel(string $value): ?ColorModel
    {
        $value = trim($value);
        $entry = $this->palette[strtolower($value)] ?? null;
        if ($entry === null) {
            $rgb = $this->parseRgb($value);
            $cmyk = $rgb === null ? $this->parseCmyk($value) : null;
            if ($rgb === null && $cmyk === null) return null;
            $entry = ['rgb' => $rgb, 'cmyk' => $cmyk];
        }
        if ($this->colorspace === 'CMYK') {
            return $entry['cmyk'] ?? new Cmyk($entry['rgb']->toCmykArray());
        }
        return $entry['rgb'] ?? new Rgb($entry['cmyk']->toRgbArray());
    }

    private function parseRgb(string $value): ?Rgb
    {
        $value = trim($value, " \t{}");
        if ($value === '') return null;
        if (preg_match('/^#?([0-9a-f]{6})$/i', $value, $m)) {
            [$r, $g, $b] = sscanf($m[1], '%2x%2x%2x');
            return new Rgb(['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255]);
        }
        return null;
    }

    private function parseCmyk(string $value): ?Cmyk
    {
        $value = trim($value, " \t{}");
        if (!preg_match('/^\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*$/', $value, $m)) {
            return null;
        }
        $c = fn(string $v) => min(100, (int)$v) / 100;
        return new Cmyk(['cyan' => $c($m[1]), 'magenta' => $c($m[2]), 'yellow' => $c($m[3]), 'key' => $c($m[4])]);
    }

    // Hilfsfunktionen: Parameter und Pfade
    // ====================

    /**
     * Koordinate aus dem Parameter oder Vorgabe (Rand/Cursor) - '' zählt wie fehlend.
     */
    private function coord(mixed $value, float $default): float
    {
        return $value === null || trim((string)$value) === '' ? $default : (float)$value;
    }

    /**
     * Vier Seitenwerte nach CSS-Art: "a" alle, "a,b" oben/unten und rechts/links,
     * "a,b,c,d" oben, rechts, unten, links. Leer oder ungültig: Vorgabe.
     */
    private function sides(string $value, array $default): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', trim($value, " \t{}"))), fn($v) => $v !== ''));
        $n = array_map('floatval', $parts);
        return match (count($n)) {
            1 => ['T' => $n[0], 'R' => $n[0], 'B' => $n[0], 'L' => $n[0]],
            2 => ['T' => $n[0], 'R' => $n[1], 'B' => $n[0], 'L' => $n[1]],
            4 => ['T' => $n[0], 'R' => $n[1], 'B' => $n[2], 'L' => $n[3]],
            default => $default,
        };
    }

    private function orientation(string $value): string
    {
        return strtoupper(substr(trim($value), 0, 1)) === 'L' ? 'L' : 'P';
    }

    private function align(string $value): string
    {
        $a = strtoupper(substr(trim($value), 0, 1));
        return in_array($a, ['L', 'C', 'R', 'J'], true) ? $a : 'L';
    }

    private function valign(string $value): string
    {
        $a = strtoupper(substr(trim($value), 0, 1));
        return in_array($a, ['T', 'C', 'B'], true) ? $a : 'T';
    }

    private function lineWidth(mixed $value): float
    {
        return $value !== null && trim((string)$value) !== '' && (float)$value >= 0 ? (float)$value : self::DEFAULT_LINE_WIDTH;
    }

    private function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Datei aus einem Unterverzeichnis von var/modules/pdf - nur ein Dateiname mit
     * erlaubter Endung, keine Pfade.
     */
    private function varFile(string $sub, string $name, array $extensions): ?string
    {
        $base = basename($name);
        if ($base !== $name || !in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), $extensions, true)) {
            return null;
        }
        $file = $this->varDir() . "/$sub/$base";
        return is_file($file) ? $file : null;
    }

    /**
     * Bilddatei unterhalb des Medienverzeichnisses: absoluter Pfad (CMS:MEDIA
     * subfield=file), Webpfad (subfield=path, '/media/…' mit ',name'-Zusatz) oder
     * relativer Pfad. null, wenn die Datei fehlt oder außerhalb liegt.
     */
    private function mediaFile(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0")) return null;
        $mediaDir = rtrim($this->mediaDir(), '/');
        $mediaUri = rtrim((string)$this->config->get('paths.media'), '/');
        if (str_starts_with($value, $mediaDir . '/')) {
            $path = $value;
        }
        elseif ($mediaUri !== '' && str_starts_with($value, $mediaUri . '/')) {
            // ',name' vor der Endung ist Dekoration der URL (Cms::mediaUriName())
            $path = $mediaDir . preg_replace('/,[^\/,]*(\.[a-z0-9]+)$/i', '$1', substr($value, strlen($mediaUri)));
        }
        else {
            $path = $mediaDir . '/' . ltrim($value, '/');
        }
        $real = realpath($path);
        $realDir = realpath($mediaDir);
        if ($real === false || $realDir === false || !is_file($real) || !str_starts_with($real, $realDir . '/')) {
            return null;
        }
        return $real;
    }

    private function mediaDir(): string
    {
        return (string)$this->config->get('paths.media_dir');
    }

    /**
     * var/modules/pdf - Fonts, Layouts, Profile. Kleingeschrieben wie
     * var/extensions/{name} (Backend::extensionVarDir()).
     */
    private function varDir(): string
    {
        return (string)$this->config->get('paths.var_dir') . '/modules/pdf';
    }

    /**
     * Dateiname für Content-Disposition: nur Dateiname, Endung .pdf, ohne Zeichen,
     * die den Header stören.
     */
    private function outputFilename(string $filename): string
    {
        $name = basename(trim($filename));
        $name = preg_replace('/[^\w.\-]+/u', '_', $name) ?? '';
        $name = trim($name, '._');
        if ($name === '') $name = 'document.pdf';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') $name .= '.pdf';
        return $name;
    }

    /**
     * Alle seit document() geöffneten Ausgabepuffer verwerfen.
     */
    private function discardOutput(): void
    {
        while (ob_get_level() > $this->obLevel) {
            ob_end_clean();
        }
    }

    private function error(string $msg): void
    {
        $this->cms->error($msg);
        $this->system->log(self::LOG, $msg);
    }
}
