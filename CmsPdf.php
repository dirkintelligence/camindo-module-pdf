<?php
/**
 * Runtime of the (CMS:PDF_*) commands - PDF documents from frontend templates.
 *
 * A template builds a PDF page by page and sends it to the browser:
 *
 *     (CMS:PDF_INIT colorspace=CMYK margins=20,20,30,20)   document defaults (optional)
 *     (CMS:PDF_COLOR blau rgb=#0066FF cmyk=100,60,0,0)     color field
 *     (CMS:PDF_PAGE brief.pdf)                             page on the layout template
 *     (CMS:PDF_FONT helvetica style=B size=14)             font for the following texts
 *     (CMS:PDF_TEXT "Hallo Welt" x=20 y=40 color=blau)     text block (mm, from top left)
 *     (CMS:PDF_TABLE 40,80,30) (CMS:PDF_ROW) (CMS:PDF_CELL "…") … (CMS:PDF_TABLE_END)
 *     (CMS:PDF_META title="Angebot" author="…")            document properties
 *     (CMS:PDF_PRINT angebot.pdf)                          output - ends the request
 *
 * This class is loaded by Page via LazyServicesTrait only at the first (CMS:PDF_*) command
 * (install/settings.php['services']); the command definitions are in
 * commands.php, the command reference in README.md. The tc-lib-pdf library is only
 * touched with the first page.
 *
 * DIRECTORIES (var/modules/pdf/)
 * ------------------------------
 *     layouts/    PDF templates whose pages PDF_PAGE uses as background
 *     fonts/      fonts in the format of tc-lib-pdf-font ({name}.json [+ .z, .ctg.z]);
 *                 the installer places the 14 standard PDF fonts in fonts/core/.
 *                 A TrueType/OpenType file {name}.ttf|.otf in the directory is converted
 *                 automatically at the first (CMS:PDF_FONT {name}) - the name of the
 *                 converted font comes from the file itself (log 'pdf').
 *     profiles/   ICC profiles for PDF_INIT icc_profile= (output intent)
 *
 * COORDINATES AND COLORS
 * ----------------------
 * All dimensions are millimeters, measured from the top left corner; font sizes are
 * points. Colors are named color fields (PDF_COLOR) with an RGB and/or CMYK value; the
 * color space of the document (PDF_INIT colorspace) decides which value goes into the PDF -
 * if it is missing, it is converted from the other one. Where a command expects a color,
 * '#rrggbb' or 'c,m,y,k' (0-100) may be used instead of the name.
 *
 * A "cursor" (current writing position) moves below the bottom edge after every text block, image and
 * table, and is at the top margin after PDF_PAGE: commands without
 * y= write there, without x= at the left margin.
 *
 * OUTPUT
 * ------
 * From the first page on, the template output is buffered, so that the whitespace
 * between the commands does not reach the browser before the PDF. PDF_PRINT discards the
 * buffer, sends the headers and the document and ends the request with exit - as
 * the commands with php_suffix 'include_and_stop' do. Anything the template outputs BEFORE the first
 * page is already on its way; PDF_PRINT then reports "headers already sent".
 *
 * ERRORS
 * ------
 * Errors (missing template, unknown font or color, library exceptions)
 * go to Cms::error() - a (CMS:MESSAGE) shows them - and into the log
 * var/log/{date}_pdf.log. The template continues; a PDF_PRINT without a document does
 * nothing, so the messages reach the browser.
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
    private const DEFAULT_LINE_WIDTH = 0.2;    // mm, lines and borders without a value

    private ?Tcpdf $pdf = null;
    private int $pages = 0;             // pages created in the current document
    private array $sources = [];        // layout file => source id in tc-lib-pdf
    private int $obLevel = 0;           // buffer level before the document, see discardOutput()
    private array $lastPage = [];       // parameters of the last PDF_PAGE (following pages of tables)
    private float $cursorY = 0;         // writing position, see class comment

    // Document defaults (PDF_INIT)
    private string $colorspace = 'RGB';
    private string $iccFile = '';
    private string $iccName = '';
    private string $format = 'A4';
    private string $orientation = 'P';
    private array $margins = ['T' => 20.0, 'R' => 20.0, 'B' => 20.0, 'L' => 20.0];
    private array $meta = [];
    private array $pageMargins = ['T' => 20.0, 'R' => 20.0, 'B' => 20.0, 'L' => 20.0]; // of the current page

    // Current font - applies to all following texts, also across pages
    private string $fontName = 'helvetica';
    private string $fontStyle = '';
    private float $fontSize = 12;
    private array $fontAlias = [];      // requested name => name of the converted font

    // Color fields (PDF_COLOR) and set colors (color specification, see colorModel())
    private array $palette = [];        // name => ['rgb' => ?Rgb, 'cmyk' => ?Cmyk]
    private ?string $textColor = null;
    private ?string $fillColor = null;
    private ?string $lineColor = null;

    // Current table (PDF_TABLE … PDF_TABLE_END), see cmsPdfTable()
    private ?array $table = null;

    public function __construct(
        private Cms $cms,
        private Config $config,
        private System $system,
    )
    {
    }

    // Document
    // ====================

    // CMS:PDF_INIT - defaults for the document, before the first page
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

    // CMS:PDF_META - document properties
    public function cmsPdfMeta(array $p): void
    {
        foreach (['author', 'title', 'subject', 'keywords', 'creator'] as $key) {
            if (isset($p[$key])) $this->meta[$key] = (string)$p[$key];
        }
    }

    // CMS:PDF_PAGE - new page, optionally on a page of a layout template
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

    // CMS:PDF_PRINT - send the document to the browser and end the request
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
            // download: empty/false = inline, true = download as 'filename',
            // otherwise download under the given name
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

        // Discard the template output so far (whitespace between the commands)
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

    // Font and colors
    // ====================

    // CMS:PDF_FONT - font for the following texts
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
        // tc-lib-pdf: B bold, I italic, U underlined, D strikethrough, O overlined
        $style = isset($p['style']) ? preg_replace('/[^BIUDO]/', '', strtoupper((string)$p['style'])) : $this->fontStyle;
        $size  = isset($p['size']) && (float)$p['size'] > 0 ? (float)$p['size'] : $this->fontSize;

        // Without a page only remember it - PDF_PAGE applies the font. Otherwise apply first,
        // then adopt it: an unknown font keeps the previous one in effect.
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

    // CMS:PDF_COLOR - color field with RGB and/or CMYK value
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

    // CMS:PDF_TEXT_COLOR / PDF_FILL_COLOR / PDF_LINE_COLOR - color for the following commands
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

    // Content
    // ====================

    // CMS:PDF_TEXT - text block with line wrapping within the width
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

    // CMS:PDF_HTML - HTML block (wrapping and page breaks by tc-lib-pdf)
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
            // tc-lib-pdf does not report the height of the block - the cursor stays put
        }
        catch (\Throwable $e) {
            $this->error('CMS:PDF_HTML: ' . $e->getMessage());
        }
    }

    // CMS:PDF_IMAGE - image from the media directory, optionally linked
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
                $width = $px['width'] * 25.4 / 72;   // pixels as points (72 dpi)
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

    // CMS:PDF_LINE - line
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

    // CMS:PDF_RECT - rectangle, filled and/or outlined
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

    // Tables
    // ====================
    //
    // PDF_TABLE collects, PDF_ROW/PDF_CELL fill, PDF_TABLE_END draws (renderTable()).
    // Each cell remembers the font that was set at its PDF_CELL - so
    // the header row gets its bold font with a PDF_FONT before it. Rows are never
    // split: if a row no longer fits on the page, it starts on the
    // next one (following page as the last PDF_PAGE or next_layout/next_page), and the
    // header rows (is_header) are repeated there.

    // CMS:PDF_TABLE - start a table
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

    // CMS:PDF_ROW - new row
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

    // CMS:PDF_CELL - cell of the current row
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

    // CMS:PDF_TABLE_END - draw the table
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
     * Draw the table: measure row heights, break pages if needed, output cells.
     */
    private function renderTable(array $table): void
    {
        $pdf = $this->pdf;
        $y = $table['y'];
        $headers = [];       // header rows already drawn, repeated on following pages
        $rowsOnPage = 0;

        foreach ($table['rows'] as $row) {
            $layout = $this->layoutRow($table, $row);
            if ($rowsOnPage > 0 && $y + $layout['height'] > $this->pageHeight() - $this->pageMargins['B']) {
                // following page, then repeat the header rows
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
        // the font of the last cell is still in the content - set the configured font again
        $this->applyFont($this->fontName, $this->fontStyle, $this->fontSize);
    }

    /**
     * Measure a row: per cell the fitted font (fit) and the height; the
     * result is the row plus 'height' and per cell 'font_fit' / 'inner_width'.
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
                // empty cell: one line height of the font
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
     * Fit the font of a cell according to 'fit': [name, style, size, spacing, stretching].
     * The goal is one line within the inner width; whatever does not fit even then wraps.
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
                // character spacing in points, at most 15 % of the font size tighter - if
                // that is not enough, the spacing stays normal and the text wraps legibly
                $result[3] = $this->fitSpacing($width, $inner, $size, $chars);
                break;
            case 'scale':
                // font size and spacing share the reduction
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
     * Character spacing (points, negative) that brings $width mm to $inner mm - at most
     * 15 % of the font size tighter; 0 if that is not enough.
     */
    private function fitSpacing(float $width, float $inner, float $size, int $chars): float
    {
        $needed = $this->pdf->toPoints($inner - $width) / $chars;
        return $needed >= -0.15 * $size ? $needed : 0.0;
    }

    /**
     * Draw a measured row at $y; returns the bottom edge.
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

    // Helper functions: document and page
    // ====================

    /**
     * The current document - created at the first call. From here on the
     * template output is buffered (see class comment).
     */
    private function document(): Tcpdf
    {
        if ($this->pdf === null) {
            $fontsDir = $this->varDir() . '/fonts';
            // tc-lib-pdf-font looks up its definitions under K_PATH_FONTS (and its
            // subdirectories) - a constant, so once per request
            if (!defined('K_PATH_FONTS')) {
                define('K_PATH_FONTS', $fontsDir);
            }
            // readable directories for tc-lib-pdf (fonts, templates, profiles, media)
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
                // identifier also as /OutputCondition and /Info - otherwise "sRGB" would be there
                $this->pdf->setOutputIntent($this->iccName, $this->iccFile, $this->iccName, 'http://www.color.org', $this->iccName);
            }
            $this->pages = 0;
            $this->sources = [];
        }
        return $this->pdf;
    }

    /**
     * Create a page (PDF_PAGE and following pages of tables): on a layout page
     * or empty, with the margins from PDF_INIT or margins=.
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
        // restore the font on the new page
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

    // Helper functions: font and text
    // ====================

    /**
     * Push the font onto the font stack (without output); an unknown font is looked up
     * once as TTF/OTF in the fonts directory and converted.
     *
     * @return array  font data from tc-lib-pdf ('out' = operator for the page content)
     * @throws \RuntimeException  font not present
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
     * Set the font and write the operator into the page.
     */
    private function applyFont(string $name, string $style, float $size): void
    {
        $font = $this->insertFont($name, $style, $size);
        $this->pdf->page->addContent($font['out']);
    }

    /**
     * Text block with wrapping as page content (without border/fill - the callers
     * draw those themselves); getLastCellBBox() then returns the occupied area.
     */
    private function textCell(string $text, float $x, float $y, float $width, float $height, string $align, string $valign): string
    {
        return $this->pdf->getTextCell(
            txt: $text, posx: $x, posy: $y, width: $width, height: $height,
            valign: $valign, halign: $align, cell: Tcpdf::ZEROCELL, drawcell: false,
        );
    }

    /**
     * Convert {name}.ttf|.otf from the fonts directory to tc-lib-pdf-font
     * (output {fontname}.json + .z + .ctg.z in the same directory).
     *
     * @return string|null  name of the converted font, null without a source file
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

        // encoding_id 10: full Unicode table (also characters above U+FFFF)
        $import = new FontImport($source, $dir . '/', 'TrueTypeUnicode', '', 32, 3, 10);
        $converted = $import->getFontName();
        $this->system->log(self::LOG, 'font "' . basename($source) . '" converted - use (CMS:PDF_FONT ' . $converted . ')');
        return $converted;
    }

    // Helper functions: colors
    // ====================

    /**
     * Check a color specification (color field, '#rrggbb' or 'c,m,y,k'); null on error or
     * empty specification (= default color).
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
     * Color operator for the page content in the color space of the document. Without a color, black.
     *
     * @param bool $stroke  true: line color (K/RG), false: fill/text color (k/rg)
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
     * Color model in the color space of the document: color field (PDF_COLOR), '#rrggbb' or
     * 'c,m,y,k' (0-100). If the matching value is missing in the color field, it is converted.
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

    // Helper functions: parameters and paths
    // ====================

    /**
     * Coordinate from the parameter or default (margin/cursor) - '' counts as missing.
     */
    private function coord(mixed $value, float $default): float
    {
        return $value === null || trim((string)$value) === '' ? $default : (float)$value;
    }

    /**
     * Four page values in CSS style: "a" all, "a,b" top/bottom and right/left,
     * "a,b,c,d" top, right, bottom, left. Empty or invalid: default.
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
     * File from a subdirectory of var/modules/pdf - only a file name with an
     * allowed extension, no paths.
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
     * Image file below the media directory: absolute path (CMS:MEDIA
     * subfield=file), web path (subfield=path, '/media/…' with a ',name' suffix) or
     * relative path. null if the file is missing or lies outside.
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
            // ',name' before the extension is decoration of the URL (Cms::mediaUriName())
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
     * var/modules/pdf - fonts, layouts, profiles. Lowercase, like
     * var/extensions/{name} (Backend::extensionVarDir()).
     */
    private function varDir(): string
    {
        return (string)$this->config->get('paths.var_dir') . '/modules/pdf';
    }

    /**
     * File name for Content-Disposition: file name only, extension .pdf, without characters
     * that disturb the header.
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
     * Discard all output buffers opened since document().
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
