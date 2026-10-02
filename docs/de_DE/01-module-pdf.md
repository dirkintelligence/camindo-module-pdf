# Zusatzmodul PDF

Das Zusatzmodul ***PDF*** (`camindo/module-pdf`) erzeugt PDF-Dokumente direkt aus
Frontend-Templates – Rechnungen, Tickets, Datenblätter, Urkunden. Die Befehle
`(CMS:PDF_*)` bauen ein Dokument Seite für Seite auf, auf Wunsch auf einer PDF-Vorlage wie
einem Briefbogen, mit Textblöcken, HTML, Bildern, Linien, Rechtecken und Tabellen, in RGB
oder CMYK, und schicken es an den Browser. Grundlage ist
[tc-lib-pdf](https://github.com/tecnickcom/tc-lib-pdf), der Nachfolger von TCPDF.

```
(CMS:PDF_INIT colorspace=CMYK margins=25,20,30,20)
(CMS:PDF_COLOR hausfarbe rgb=#0066FF cmyk=100,60,0,0)
(CMS:PDF_META title="Rechnung 4711" author="Muster GmbH")
(CMS:PDF_PAGE briefbogen.pdf)
(CMS:PDF_FONT helvetica style=B size=16)
(CMS:PDF_TEXT "Rechnung 4711" y=50 color=hausfarbe)
(CMS:PDF_FONT helvetica style="" size=10)
(CMS:PDF_TEXT "Vielen Dank für Ihre Bestellung." align=J)
(CMS:PDF_TABLE 15,100,25,30 padding=1.5)
(CMS:PDF_ROW is_header=true)
(CMS:PDF_CELL "Pos") (CMS:PDF_CELL "Artikel") (CMS:PDF_CELL "Menge" align=C) (CMS:PDF_CELL "Preis" align=R)
(CMS:PDF_ROW)
(CMS:PDF_CELL "1") (CMS:PDF_CELL "Ein sehr langer Artikelname, der passen muss" fit=scale) (CMS:PDF_CELL "2" align=C) (CMS:PDF_CELL "19,98 €" align=R)
(CMS:PDF_TABLE_END)
(CMS:PDF_PRINT rechnung-4711.pdf)
```

Weitere Kapitel:

- [Befehle](./02-befehle.md) – alle Befehle `(CMS:PDF_*)` mit ihren Parametern

## Installation

```bash
composer require camindo/module-pdf
php Core/cron/camindo.php component install Pdf --activate
```

`composer require` legt das Modul nach `Modules/Pdf/` und tc-lib-pdf nach `vendor/`;
`component install` legt `var/modules/pdf/{fonts,layouts,profiles}` an und kopiert die
14 Standardschriften von PDF nach `var/modules/pdf/fonts/core/`. Voraussetzung: PHP 8.2
und die Erweiterung `zlib`.

## Verzeichnisse (`var/modules/pdf/`)

| Verzeichnis | Inhalt |
|---|---|
| `layouts/` | PDF-Vorlagen (z. B. Briefbögen). `(CMS:PDF_PAGE {datei}.pdf)` übernimmt eine Seite einer solchen Datei als Seitenhintergrund (importiert als Form-XObject, Vektorgrafik bleibt also Vektorgrafik). Nur Dateinamen, keine Pfade. |
| `fonts/` | Schriften im Format von tc-lib-pdf-font: `{name}.json` (+ `.z`, `.ctg.z` bei eingebetteten Schriften). Unterverzeichnisse werden mit durchsucht – der Installer legt die Standardschriften in `fonts/core/`. |
| `profiles/` | ICC-Farbprofile für `(CMS:PDF_INIT icc_profile=…)`, eingebettet als Output Intent. |

### Schriften

Nach der Installation sofort verfügbar: `helvetica`, `times`, `courier` (jeweils mit den
Schnitten B, I, BI), `symbol`, `zapfdingbats`. Das sind die Standardschriften von PDF –
nicht eingebettet, jeder Viewer bringt sie mit; Text ist auf die Zeichen von
Windows-1252 beschränkt.

***Eigene Schriften:*** Legen Sie eine TrueType- oder OpenType-Datei in
`var/modules/pdf/fonts/` und verwenden Sie sie. Das erste `(CMS:PDF_FONT {name})`, das zu
`{name}.ttf` oder `{name}.otf` passt (Dateiname ohne Endung, Groß-/Kleinschreibung egal),
wandelt die Datei daneben in das Format von tc-lib-pdf-font um. Der Name der
umgewandelten Schrift stammt aus der Schrift selbst (meist der Familienname in
Kleinbuchstaben, aus `Verdana.ttf` wird `verdana`); weicht er vom Dateinamen ab, steht
die Umwandlung samt dem zu verwendenden Namen in `var/log/{datum}_pdf.log`. Solche
Schriften werden in das PDF eingebettet (voller Unicode-Umfang). Für eine Umwandlung mit
anderen Optionen dient `vendor/tecnickcom/tc-lib-pdf-font/util/convert.php` mit
`--outpath=var/modules/pdf/fonts`.

## Konventionen

* ***Einheiten:*** Alle Positionen und Größen sind Millimeter, gemessen von der linken
  oberen Ecke der Seite; Schriftgrößen sind Punkt.
* ***Parameter*** sind optional, wenn nicht anders angegeben. Den ersten Parameter jedes
  Befehls darf man ohne seinen Namen angeben: `(CMS:PDF_PAGE briefbogen.pdf)` ist
  `(CMS:PDF_PAGE layout=briefbogen.pdf)`. Werte folgen der Template-Syntax (`"…"` erlaubt
  PHP-Interpolation, z. B. `text="Rechnung {$cms['nummer']}"`).
* ***Farben:*** Wo ein Befehl eine Farbe nimmt, geben Sie den Namen eines Farbfelds
  (`PDF_COLOR`), `#rrggbb` oder `c,m,y,k` (0–100) an. Der Farbraum des Dokuments
  (`PDF_INIT`) entscheidet, welcher Wert ins PDF geschrieben wird; ein fehlender wird
  umgerechnet.
* ***Schreibposition:*** Nach `PDF_PAGE` steht die Schreibposition am oberen Rand; jedes
  `PDF_TEXT`, `PDF_IMAGE` und jede Tabelle setzt sie unter ihre Unterkante. Befehle ohne
  `y=` schreiben dort, ohne `x=` am linken Rand. `PDF_HTML` verschiebt die
  Schreibposition nicht (tc-lib-pdf meldet die Höhe des Blocks nicht).
* ***Vierseitige Werte*** (`margins`, `padding`) wirken wie in CSS: ein Wert für alle
  Seiten, zwei für oben/unten und rechts/links, vier für oben, rechts, unten, links.

## Fehler

Eine fehlende Vorlage, eine unbekannte Schrift oder Farbe, `PDF_TEXT` ohne Seite, ein
`PDF_PRINT` in einer nicht abgeschlossenen Tabelle und Ausnahmen der Bibliothek werden
mit `Cms::error()` gesammelt – `(CMS:MESSAGE)` zeigt sie an – und in
`var/log/{datum}_pdf.log` protokolliert. Das Template läuft weiter; `PDF_PRINT` ohne
Dokument tut nichts, damit die Fehlermeldungen den Browser erreichen.

## So hängt sich das Modul in den Template-Compiler

`install/settings.php` nennt die Befehlsdefinitionen (`'commands' => 'commands.php'`,
gleicher Aufbau wie `Core/Modules/Template/commands.php`) und den Dienst, der sie ausführt
(`'services' => ['pdf' => CmsPdf::class]`). Der Template-Parser führt die Befehlsdateien
aller aktiven Module zusammen; `Page` lädt den Dienst über `LazyServicesTrait` erst beim
ersten Befehl `(CMS:PDF_*)` – Templates ohne PDF-Befehle berühren weder das Modul noch
tc-lib-pdf. Mehr dazu in der Entwicklerdokumentation von camindo CMS, Kapitel
„Template-Compiler“.

## Lizenz

LGPL-3.0-or-later (siehe `LICENSE`) – tc-lib-pdf steht unter der GNU LGPL v3, der das
Modul folgt. Die Metriken der Standardschriften in `fonts/core/` stammen aus den Adobe
Core 14 AFM-Dateien (siehe `fonts/core/LICENSE`). Der Core von camindo steht unter
AGPL-3.0-or-later; beide Lizenzen sind verträglich.
