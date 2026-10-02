# PDF commands

All positions and sizes in millimetres from the top-left page corner, font sizes in
points; the first parameter of each command may be given without its key (see
[Conventions](./01-module-pdf.md#conventions)).

## Document

### `(CMS:PDF_INIT [colorspace=] icc_profile= output_condition= format= orientation= margins=)`

Document defaults; must come before the first `PDF_PAGE`.

| Parameter | Default | Meaning |
|---|---|---|
| `colorspace` | `RGB` | `RGB` or `CMYK` - the colorspace colors are written in |
| `icc_profile` | - | file in `var/modules/pdf/profiles/`, embedded as output intent (`/DestOutputProfile`) |
| `output_condition` | file name | `/OutputConditionIdentifier` of the output intent, e.g. `FOGRA39` |
| `format` | `A4` | page size of empty pages: `A4`, `A5`, `LETTER`, … (tc-lib-pdf names) |
| `orientation` | `P` | `P` portrait or `L` landscape of empty pages |
| `margins` | `20` | page margins: left = default `x`, right = default width limit, top = cursor start, bottom = table page-break limit |

### `(CMS:PDF_META [title=] author= subject= keywords= creator=)`

Document properties, written by `PDF_PRINT`.

### `(CMS:PDF_PAGE [layout=]file.pdf page= format= orientation= margins=)`

Adds a page. The first call starts the document (and buffers all further template output,
see PDF_PRINT). The cursor is set to the top margin.

| Parameter | Default | Meaning |
|---|---|---|
| `layout` | - | file in `var/modules/pdf/layouts/` whose page becomes the background; the new page gets the size of that page |
| `page` | `1` | page of the layout file to use |
| `format`, `orientation` | from `PDF_INIT` | size of an empty page (without `layout`) |
| `margins` | from `PDF_INIT` | margins of this page only |

### `(CMS:PDF_PRINT [filename=]file.pdf download=)`

Sends the document to the browser and **ends the request** (`exit`, like the
`CMS:USER_*` success templates). Everything the template printed since the first
`PDF_PAGE` (usually just the line breaks between the commands) is discarded. Anything
printed *before* the first `PDF_PAGE` has already been sent to the browser by then;
PDF_PRINT then reports "headers already sent" - so a PDF template starts with
`PDF_INIT`/`PDF_PAGE`.

| Parameter | Default | Meaning |
|---|---|---|
| `filename` | `document.pdf` | file name shown by the browser / suggested for saving |
| `download` | `false` | empty or `false`: show inline. `true`: download as `filename`. A file name: download under that name |

## Font and colors

### `(CMS:PDF_FONT [font=]name style= size=)`

Sets the font for the following text blocks and table cells; stays in effect across
pages. Parameters that are left out keep their current value (initially `helvetica`,
regular, 12 pt). An unknown font is reported and the previous font stays in effect.

| Parameter | Default | Meaning |
|---|---|---|
| `font` | `helvetica` | font name, see Fonts |
| `style` | regular | `B` bold, `I` italic, `U` underline, `D` line-through, `O` overline - combinable (`BI`) |
| `size` | `12` | size in points |

### `(CMS:PDF_COLOR [name=]name rgb=#rrggbb cmyk=c,m,y,k)`

Defines a color field with an RGB and/or CMYK value (at least one). Name: letters,
digits, `_`, `-`.

### `(CMS:PDF_TEXT_COLOR [name=]color)` / `(CMS:PDF_FILL_COLOR …)` / `(CMS:PDF_LINE_COLOR …)`

Current text color (text blocks, cells), fill color (`PDF_RECT`) and line color
(`PDF_LINE`, borders of `PDF_RECT` and cells). Without a value: black. Commands with
their own `color=`/`border=` parameters override these for that call.

## Content

### `(CMS:PDF_TEXT [text=]text x= y= width= align= size= color= background=)`

Writes a text block that wraps within the width. Needs a page.

| Parameter | Default | Meaning |
|---|---|---|
| `text` | - | the text (UTF-8; empty = nothing) |
| `x` | left margin | distance from the left page edge |
| `y` | cursor | distance from the top page edge (top of the block) |
| `width` | `0` | block width; `0` = up to the right margin |
| `align` | `L` | `L` left, `C` center, `R` right, `J` justify |
| `size` | font size | size in points for this block only |
| `color` | `PDF_TEXT_COLOR` | text color |
| `background` | - | background color of the block |

### `(CMS:PDF_HTML [html=]html x= y= width=)`

Writes an HTML block (text markup, lists, tables, inline CSS; rendered by tc-lib-pdf,
which also breaks pages). `x`, `y`, `width` as in `PDF_TEXT`. Does not move the cursor.

### `(CMS:PDF_IMAGE [file=]file x= y= width= height= link=)`

Places an image (JPEG, PNG, GIF, …) from the media directory.

| Parameter | Default | Meaning |
|---|---|---|
| `file` | - | absolute path from `(CMS:MEDIA … subfield=file)`, web path from `subfield=path` (`/media/…`) or a path relative to `public/media`. Only files below the media directory |
| `x`, `y` | margin / cursor | top-left corner |
| `width` | `0` | `0` = from the height; when both are 0, the pixel size at 72 dpi |
| `height` | `0` | `0` = proportional to the width |
| `link` | - | URL opened when the image is clicked |

### `(CMS:PDF_LINE x1= y1= x2= y2= width= color=)`

Draws a line. `width` = line width in mm (default 0.2), `color` = line color (default
`PDF_LINE_COLOR`).

### `(CMS:PDF_RECT x= y= width= height= style= color= border= line_width=)`

Draws a rectangle. `style`: `D` outline (default), `F` fill, `DF` both. `color` = fill
color (default `PDF_FILL_COLOR`), `border` = outline color (default `PDF_LINE_COLOR`),
`line_width` in mm (default 0.2).

## Tables

Tables are collected between `PDF_TABLE` and `PDF_TABLE_END` and drawn at the end.
Each cell uses the font that is current at its `PDF_CELL` - a `PDF_FONT` before the
header row gives it a bold font. Rows are never split: a row that no longer fits above
the bottom margin starts on a continuation page (same parameters as the last
`PDF_PAGE`, or `next_layout`/`next_page`), where the header rows (`is_header`) are
repeated. Columns without a `PDF_CELL` stay empty (no border, no background).

### `(CMS:PDF_TABLE [cols=]w1,w2,… x= y= padding= border_width= next_layout= next_page=)`

| Parameter | Default | Meaning |
|---|---|---|
| `cols` | - | column widths in mm, comma separated (required) |
| `x`, `y` | margin / cursor | top-left corner |
| `padding` | `1` | cell padding (four-side value) |
| `border_width` | `0.2` | border width of the cells |
| `next_layout`, `next_page` | last `PDF_PAGE` | layout file and page for continuation pages; `next_layout=""` gives empty pages |

### `(CMS:PDF_ROW is_header= keep_together=)`

Starts a row. `is_header=true` marks a row that is repeated on continuation pages.
`keep_together` is accepted for forward compatibility - rows are currently never
split, so it is always in effect.

### `(CMS:PDF_CELL [text=]text col= align= valign= fit= min_size= border= border_width= border_color= padding= color= background=)`

| Parameter | Default | Meaning |
|---|---|---|
| `text` | - | cell text; wraps within the column unless `fit` makes it fit |
| `col` | next column | column index starting at 0 |
| `align` | `L` | `L`, `C`, `R`, `J` |
| `valign` | `T` | `T` top, `C` center, `B` bottom within the row |
| `fit` | `none` | fit the text on one line: `fontsize` shrinks the font (down to `min_size`), `spacing` tightens the character spacing (up to 15 % of the size), `scale` both proportionally, `force` compresses horizontally. What still does not fit wraps |
| `min_size` | `6` | smallest font size when fitting |
| `border` | `1` | sides: `L`, `T`, `R`, `B` combinable; `1` all, `0` none |
| `border_width` | table | border width in mm |
| `border_color` | `PDF_LINE_COLOR` | border color |
| `padding` | table | cell padding (four-side value) |
| `color` | `PDF_TEXT_COLOR` | text color |
| `background` | - | background color |

### `(CMS:PDF_TABLE_END)`

Draws the table and moves the cursor below it.
