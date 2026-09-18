# Core fonts for tc-lib-pdf-font

Metrics of the 14 standard PDF fonts (Helvetica, Times, Courier, Symbol, ZapfDingbats),
converted from the Adobe Core14 AFM files (https://github.com/tecnickcom/tc-font-mirror)
with `util/convert.php` of https://github.com/tecnickcom/tc-lib-pdf-font
(`--type=Core --encoding=cp1252`, Symbol: `symbol`, ZapfDingbats: none).

These fonts are not embedded in the PDF - every viewer provides them. The files are
subject to the conditions stated in LICENSE. The installer copies this directory to
`var/modules/pdf/fonts/core/`.
