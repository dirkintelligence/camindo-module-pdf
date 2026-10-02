# camindo/module-pdf

PDF documents from camindo CMS 9 frontend templates: `(CMS:PDF_*)` commands build a
document page by page - optionally on top of a PDF layout template such as a letterhead -
with text blocks, HTML, images, lines, rectangles and tables, in RGB or CMYK, and send it
to the browser. Based on [tc-lib-pdf](https://github.com/tecnickcom/tc-lib-pdf), the
successor of TCPDF.

```bash
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

Requires PHP 8.2 and the `zlib` extension.

## Manual

Once installed, the manual also appears in the backend (main menu → Manuals).

- English: [docs/en_US/01-module-pdf.md](docs/en_US/01-module-pdf.md)
- Deutsch: [docs/de_DE/01-module-pdf.md](docs/de_DE/01-module-pdf.md)

## License

LGPL-3.0-or-later (see `LICENSE`) - tc-lib-pdf is licensed under the GNU LGPL v3, which
this module follows. The standard font metrics in `fonts/core/` are derived from the
Adobe Core 14 AFM files (see `fonts/core/LICENSE`). The camindo core is licensed under
AGPL-3.0-or-later; both licenses are compatible.
