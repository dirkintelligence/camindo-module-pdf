<?php
/**
 * Command definitions of the Pdf module - (CMS:PDF_*).
 *
 * Structure as in Core/Modules/Template/commands.php (every key is explained there).
 * The parser merges this file in via TemplateParserTrait::loadCommands(), because
 * install/settings.php['commands'] names it; the object 'pdf' in 'method' is provided by
 * settings.php['services'] (Page::$lazyServices -> CmsPdf).
 *
 * All parameters are optional. The 'do' parameter is the one that may be given without
 * a key: (CMS:PDF_PAGE brief.pdf). Dimensions in mm, font sizes in points,
 * colors are color fields from PDF_COLOR, '#rrggbb' or 'c,m,y,k'.
 *
 * Texts are snippets of this module (backend/lang/{lang}.ini): note #pdf.cmd_{command},
 * description #pdf.cmd_{command}__{parameter}, value #pdf.cmdval_{hint} or the shared core
 * vocabulary #template.cmdval_{hint}. Syntax hints ('P / L', 'mm', '#rrggbb' - no snippet,
 * see Template::commandText()) and real default values stay literal.
 *
 * @see Camindo\Modules\Pdf\CmsPdf   Laufzeit
 * @see README.md                    Befehlsreferenz
 */

$param = fn(string $value, string $description, string $default = '') => [
    'mandatory' => false, 'subparam' => 0, 'default' => $default, 'value' => $value, 'description' => $description,
];
$color = fn(string $description) => $param('#pdf.cmdval_color', $description);

return [

    // Document
    // --------------------

    'pdf_init' => [
        'method' => 'pdf.cmsPdfInit',
        'note' => '#pdf.cmd_pdf_init',
        'do' => 'colorspace',
        'oneof' => '',
        'params' => [
            'colorspace'  => $param('RGB / CMYK', '#pdf.cmd_pdf_init__colorspace', 'RGB'),
            'icc_profile' => $param('#pdf.cmdval_file_name', '#pdf.cmd_pdf_init__icc_profile'),
            'output_condition' => $param('#template.cmdval_text', '#pdf.cmd_pdf_init__output_condition'),
            'format'      => $param('A4 / A5 / LETTER / ...', '#pdf.cmd_pdf_init__format', 'A4'),
            'orientation' => $param('P / L', '#pdf.cmd_pdf_init__orientation', 'P'),
            'margins'     => $param('#pdf.cmdval_margins', '#pdf.cmd_pdf_init__margins', '20,20,20,20'),
        ],
        'include' => []
    ],

    'pdf_meta' => [
        'method' => 'pdf.cmsPdfMeta',
        'note' => '#pdf.cmd_pdf_meta',
        'do' => 'title',
        'oneof' => '',
        'params' => [
            'title'    => $param('#template.cmdval_text', '#pdf.cmd_pdf_meta__title'),
            'author'   => $param('#template.cmdval_text', '#pdf.cmd_pdf_meta__author'),
            'subject'  => $param('#template.cmdval_text', '#pdf.cmd_pdf_meta__subject'),
            'keywords' => $param('#template.cmdval_text', '#pdf.cmd_pdf_meta__keywords'),
            'creator'  => $param('#template.cmdval_text', '#pdf.cmd_pdf_meta__creator', 'camindo CMS'),
        ],
        'include' => []
    ],

    'pdf_page' => [
        'method' => 'pdf.cmsPdfPage',
        'note' => '#pdf.cmd_pdf_page',
        'do' => 'layout',
        'oneof' => '',
        'params' => [
            'layout'      => $param('#pdf.cmdval_file_name', '#pdf.cmd_pdf_page__layout'),
            'page'        => $param('#template.cmdval_number', '#pdf.cmd_pdf_page__page', '1'),
            'format'      => $param('A4 / A5 / LETTER / ...', '#pdf.cmd_pdf_page__format'),
            'orientation' => $param('P / L', '#pdf.cmd_pdf_page__orientation'),
            'margins'     => $param('#pdf.cmdval_margins', '#pdf.cmd_pdf_page__margins'),
        ],
        'include' => []
    ],

    'pdf_print' => [
        'method' => 'pdf.cmsPdfPrint',
        'note' => '#pdf.cmd_pdf_print',
        'do' => 'filename',
        'oneof' => '',
        'params' => [
            'filename' => $param('#pdf.cmdval_file_name', '#pdf.cmd_pdf_print__filename', 'document.pdf'),
            'download' => $param('#pdf.cmdval_download', '#pdf.cmd_pdf_print__download', 'false'),
        ],
        'include' => []
    ],

    // Font and colors
    // --------------------

    'pdf_font' => [
        'method' => 'pdf.cmsPdfFont',
        'note' => '#pdf.cmd_pdf_font',
        'do' => 'font',
        'oneof' => '',
        'params' => [
            'font'  => $param('#pdf.cmdval_font_name', '#pdf.cmd_pdf_font__font', 'helvetica'),
            'style' => $param('#pdf.cmdval_font_style', '#pdf.cmd_pdf_font__style'),
            'size'  => $param('#pdf.cmdval_points', '#pdf.cmd_pdf_font__size', '12'),
        ],
        'include' => []
    ],

    'pdf_color' => [
        'method' => 'pdf.cmsPdfColor',
        'note' => '#pdf.cmd_pdf_color',
        'do' => 'name',
        'oneof' => '',
        'params' => [
            'name' => $param('#pdf.cmdval_name', '#pdf.cmd_pdf_color__name'),
            'rgb'  => $param('#rrggbb', '#pdf.cmd_pdf_color__rgb'),
            'cmyk' => $param('c,m,y,k', '#pdf.cmd_pdf_color__cmyk'),
        ],
        'include' => []
    ],

    'pdf_text_color' => [
        'method' => 'pdf.cmsPdfTextColor',
        'note' => '#pdf.cmd_pdf_text_color',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('#pdf.cmd_pdf_text_color__name')],
        'include' => []
    ],

    'pdf_fill_color' => [
        'method' => 'pdf.cmsPdfFillColor',
        'note' => '#pdf.cmd_pdf_fill_color',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('#pdf.cmd_pdf_fill_color__name')],
        'include' => []
    ],

    'pdf_line_color' => [
        'method' => 'pdf.cmsPdfLineColor',
        'note' => '#pdf.cmd_pdf_line_color',
        'do' => 'name',
        'oneof' => '',
        'params' => ['name' => $color('#pdf.cmd_pdf_line_color__name')],
        'include' => []
    ],

    // Content
    // --------------------

    'pdf_text' => [
        'method' => 'pdf.cmsPdfText',
        'note' => '#pdf.cmd_pdf_text',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text'       => $param('#template.cmdval_text', '#pdf.cmd_pdf_text__text'),
            'x'          => $param('mm', '#pdf.cmd_pdf_text__x'),
            'y'          => $param('mm', '#pdf.cmd_pdf_text__y'),
            'width'      => $param('mm', '#pdf.cmd_pdf_text__width', '0'),
            'align'      => $param('L / C / R / J', '#pdf.cmd_pdf_text__align', 'L'),
            'size'       => $param('#pdf.cmdval_points', '#pdf.cmd_pdf_text__size'),
            'color'      => $color('#pdf.cmd_pdf_text__color'),
            'background' => $color('#pdf.cmd_pdf_text__background'),
        ],
        'include' => []
    ],

    'pdf_html' => [
        'method' => 'pdf.cmsPdfHtml',
        'note' => '#pdf.cmd_pdf_html',
        'do' => 'html',
        'oneof' => '',
        'params' => [
            'html'  => $param('#template.cmdval_html_code', '#pdf.cmd_pdf_html__html'),
            'x'     => $param('mm', '#pdf.cmd_pdf_html__x'),
            'y'     => $param('mm', '#pdf.cmd_pdf_html__y'),
            'width' => $param('mm', '#pdf.cmd_pdf_html__width', '0'),
        ],
        'include' => []
    ],

    'pdf_image' => [
        'method' => 'pdf.cmsPdfImage',
        'note' => '#pdf.cmd_pdf_image',
        'do' => 'file',
        'oneof' => '',
        'params' => [
            'file'   => $param('#pdf.cmdval_path', '#pdf.cmd_pdf_image__file'),
            'x'      => $param('mm', '#pdf.cmd_pdf_image__x'),
            'y'      => $param('mm', '#pdf.cmd_pdf_image__y'),
            'width'  => $param('mm', '#pdf.cmd_pdf_image__width', '0'),
            'height' => $param('mm', '#pdf.cmd_pdf_image__height', '0'),
            'link'   => $param('URL', '#pdf.cmd_pdf_image__link'),
        ],
        'include' => []
    ],

    'pdf_line' => [
        'method' => 'pdf.cmsPdfLine',
        'note' => '#pdf.cmd_pdf_line',
        'oneof' => '',
        'params' => [
            'x1'    => $param('mm', '#pdf.cmd_pdf_line__x1', '0'),
            'y1'    => $param('mm', '#pdf.cmd_pdf_line__y1', '0'),
            'x2'    => $param('mm', '#pdf.cmd_pdf_line__x2', '0'),
            'y2'    => $param('mm', '#pdf.cmd_pdf_line__y2', '0'),
            'width' => $param('mm', '#pdf.cmd_pdf_line__width', '0.2'),
            'color' => $color('#pdf.cmd_pdf_line__color'),
        ],
        'include' => []
    ],

    'pdf_rect' => [
        'method' => 'pdf.cmsPdfRect',
        'note' => '#pdf.cmd_pdf_rect',
        'oneof' => '',
        'params' => [
            'x'          => $param('mm', '#pdf.cmd_pdf_rect__x', '0'),
            'y'          => $param('mm', '#pdf.cmd_pdf_rect__y', '0'),
            'width'      => $param('mm', '#pdf.cmd_pdf_rect__width', '0'),
            'height'     => $param('mm', '#pdf.cmd_pdf_rect__height', '0'),
            'style'      => $param('D / F / DF', '#pdf.cmd_pdf_rect__style', 'D'),
            'color'      => $color('#pdf.cmd_pdf_rect__color'),
            'border'     => $color('#pdf.cmd_pdf_rect__border'),
            'line_width' => $param('mm', '#pdf.cmd_pdf_rect__line_width', '0.2'),
        ],
        'include' => []
    ],

    // Tables
    // --------------------

    'pdf_table' => [
        'method' => 'pdf.cmsPdfTable',
        'note' => '#pdf.cmd_pdf_table',
        'do' => 'cols',
        'oneof' => '',
        'params' => [
            'cols'         => $param('#pdf.cmdval_column_widths', '#pdf.cmd_pdf_table__cols'),
            'x'            => $param('mm', '#pdf.cmd_pdf_table__x'),
            'y'            => $param('mm', '#pdf.cmd_pdf_table__y'),
            'padding'      => $param('#pdf.cmdval_margins', '#pdf.cmd_pdf_table__padding', '1'),
            'border_width' => $param('mm', '#pdf.cmd_pdf_table__border_width', '0.2'),
            'next_layout'  => $param('#pdf.cmdval_file_name', '#pdf.cmd_pdf_table__next_layout'),
            'next_page'    => $param('#template.cmdval_number', '#pdf.cmd_pdf_table__next_page'),
        ],
        'include' => []
    ],

    'pdf_row' => [
        'method' => 'pdf.cmsPdfRow',
        'note' => '#pdf.cmd_pdf_row',
        'oneof' => '',
        'params' => [
            'is_header'     => $param('true / false', '#pdf.cmd_pdf_row__is_header', 'false'),
            'keep_together' => $param('true / false', '#pdf.cmd_pdf_row__keep_together', 'true'),
        ],
        'include' => []
    ],

    'pdf_cell' => [
        'method' => 'pdf.cmsPdfCell',
        'note' => '#pdf.cmd_pdf_cell',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text'         => $param('#template.cmdval_text', '#pdf.cmd_pdf_cell__text'),
            'col'          => $param('#template.cmdval_number', '#pdf.cmd_pdf_cell__col'),
            'align'        => $param('L / C / R / J', '#pdf.cmd_pdf_cell__align', 'L'),
            'valign'       => $param('T / C / B', '#pdf.cmd_pdf_cell__valign', 'T'),
            'fit'          => $param('none / scale / fontsize / spacing / force', '#pdf.cmd_pdf_cell__fit', 'none'),
            'min_size'     => $param('#pdf.cmdval_points', '#pdf.cmd_pdf_cell__min_size', '6'),
            'border'       => $param('LTRB / 1 / 0', '#pdf.cmd_pdf_cell__border', '1'),
            'border_width' => $param('mm', '#pdf.cmd_pdf_cell__border_width'),
            'border_color' => $color('#pdf.cmd_pdf_cell__border_color'),
            'padding'      => $param('#pdf.cmdval_margins', '#pdf.cmd_pdf_cell__padding'),
            'color'        => $color('#pdf.cmd_pdf_cell__color'),
            'background'   => $color('#pdf.cmd_pdf_cell__background'),
        ],
        'include' => []
    ],

    'pdf_table_end' => [
        'method' => 'pdf.cmsPdfTableEnd',
        'note' => '#pdf.cmd_pdf_table_end',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],
];
