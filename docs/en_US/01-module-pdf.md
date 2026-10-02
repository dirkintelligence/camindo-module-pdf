# Add-on module PDF

PDF documents from camindo CMS 9 frontend templates: `(CMS:PDF_*)` commands build a
document page by page - optionally on top of a PDF layout template - with text blocks,
HTML, images, lines, rectangles and tables, and send it to the browser. Based on
[tc-lib-pdf](https://github.com/tecnickcom/tc-lib-pdf), the successor of TCPDF.

```
(CMS:PDF_INIT colorspace=CMYK margins=25,20,30,20)
(CMS:PDF_COLOR brand rgb=#0066FF cmyk=100,60,0,0)
(CMS:PDF_META title="Invoice 4711" author="ACME Ltd.")
(CMS:PDF_PAGE letterhead.pdf)
(CMS:PDF_FONT helvetica style=B size=16)
(CMS:PDF_TEXT "Invoice 4711" y=50 color=brand)
(CMS:PDF_FONT helvetica style="" size=10)
(CMS:PDF_TEXT "Thank you for your order." align=J)
(CMS:PDF_TABLE 15,100,25,30 padding=1.5)
(CMS:PDF_ROW is_header=true)
(CMS:PDF_CELL "Pos") (CMS:PDF_CELL "Item") (CMS:PDF_CELL "Qty" align=C) (CMS:PDF_CELL "Price" align=R)
(CMS:PDF_ROW)
(CMS:PDF_CELL "1") (CMS:PDF_CELL "A very long item name that has to fit" fit=scale) (CMS:PDF_CELL "2" align=C) (CMS:PDF_CELL "19,98 €" align=R)
(CMS:PDF_TABLE_END)
(CMS:PDF_PRINT invoice-4711.pdf)
```

Further chapters:

- [Commands](./02-commands.md) – all `(CMS:PDF_*)` commands with their parameters

## Installation

```bash
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

`composer require` puts the module into `Modules/Pdf/` and tc-lib-pdf into `vendor/`;
`component install` creates `var/modules/pdf/{fonts,layouts,profiles}` and copies the 14 standard
PDF fonts to `var/modules/pdf/fonts/core/`. Requires PHP 8.2 and the `zlib` extension.

## Directories (`var/modules/pdf/`)

| Directory | Content |
|---|---|
| `layouts/` | PDF templates. `(CMS:PDF_PAGE {file}.pdf)` takes a page of such a file as page background (imported as form XObject, i.e. vector graphics stay vectors). Only file names, no paths. |
| `fonts/` | Fonts in the format of tc-lib-pdf-font: `{name}.json` (+ `.z`, `.ctg.z` for embedded fonts). Subdirectories are searched too - the installer puts the standard fonts into `fonts/core/`. |
| `profiles/` | ICC profiles for `(CMS:PDF_INIT icc_profile=…)`, embedded as output intent. |

### Fonts

Ready to use after installation: `helvetica`, `times`, `courier` (each with styles
B, I, BI), `symbol`, `zapfdingbats`. These are the standard PDF fonts - not embedded,
every viewer provides them; text is limited to Windows-1252 characters.

Own fonts: put a TrueType/OpenType file into `var/modules/pdf/fonts/` and use it. The
first `(CMS:PDF_FONT {name})` that matches `{name}.ttf` or `{name}.otf` (file name
without extension, case-insensitive) converts the file to the tc-lib-pdf-font format
next to it. The name of the converted font comes from the font itself (usually the
lower-cased family name, e.g. `Verdana.ttf` becomes `verdana`); if it differs from the
file name the conversion is logged in `var/log/{date}_pdf.log` with the name to use. Such
fonts are embedded in the PDF (full Unicode). To convert with other options, use
`vendor/tecnickcom/tc-lib-pdf-font/util/convert.php` with `--outpath=var/modules/pdf/fonts`.

## Conventions

* **Units**: all positions and sizes are millimetres from the top-left page corner;
  font sizes are points.
* **Parameters** are optional unless stated. The first parameter of each command may
  be given without its key: `(CMS:PDF_PAGE letterhead.pdf)` is
  `(CMS:PDF_PAGE layout=letterhead.pdf)`. Values follow the template syntax (`"…"`
  allows PHP interpolation, e.g. `text="Invoice {$cms['number']}"`).
* **Colors**: wherever a command takes a color, give the name of a color field
  (`PDF_COLOR`), `#rrggbb` or `c,m,y,k` (0-100). The document colorspace (`PDF_INIT`)
  decides which value is written to the PDF; a missing one is converted.
* **Cursor**: after `PDF_PAGE` the write position is the top margin; every `PDF_TEXT`,
  `PDF_IMAGE` and table moves it below its bottom edge. Commands without `y=` write
  there, without `x=` at the left margin. `PDF_HTML` does not move the cursor (tc-lib-pdf
  does not report the block height).
* **Four-side values** (`margins`, `padding`) work like CSS: one value for all sides,
  two for top/bottom and right/left, four for top, right, bottom, left.

## Errors

A missing layout, an unknown font or color, `PDF_TEXT` without a page, a `PDF_PRINT`
inside an unfinished table and exceptions of the library are collected with
`Cms::error()` - `(CMS:MESSAGE)` shows them - and logged to `var/log/{date}_pdf.log`.
The template continues; `PDF_PRINT` without a document does nothing, so the error
messages reach the browser.

## How the module hooks into the template compiler

`install/settings.php` names the command definitions (`'commands' => 'commands.php'`,
same structure as `Core/Modules/Template/commands.php`) and the service that runs
them (`'services' => ['pdf' => CmsPdf::class]`). The template parser merges the command
file of every active module; `Page` loads the service lazily via `LazyServicesTrait` on
the first `(CMS:PDF_*)` command, so templates without PDF commands never touch the
module or tc-lib-pdf. See the developer documentation of camindo CMS, chapter "Template compiler".

## License

LGPL-3.0-or-later (see `LICENSE`) - tc-lib-pdf is licensed under the GNU LGPL v3,
which this module follows. The standard font metrics in `fonts/core/` are derived from
the Adobe Core 14 AFM files (see `fonts/core/LICENSE`). The camindo core is licensed
under AGPL-3.0-or-later; both licenses are compatible.
