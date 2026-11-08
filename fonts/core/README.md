# Core fonts for tc-lib-pdf-font

Metrics of the 14 standard PDF fonts (Helvetica, Times, Courier, Symbol, ZapfDingbats),
converted from the Adobe Core 14 AFM files (mirror:
https://github.com/tecnickcom/tc-font-mirror) with `util/convert.php` of
https://github.com/tecnickcom/tc-lib-pdf-font (`--type=Core --encoding=cp1252`,
Symbol: `symbol`, ZapfDingbats: none).

The files hold metrics only - character widths, bounding boxes and the font descriptor,
no glyph outlines and no font program. These fonts are not embedded in the PDF: every
viewer provides them. Adobe permits use, copying and distribution of the AFM data; the
conditions and the copyright notices are in LICENSE, each file additionally carries the
notice of its own font in `_copyright` (members starting with `_` are ignored by
tc-lib-pdf-font).

The installer copies this directory to `var/modules/pdf/fonts/core/`.
