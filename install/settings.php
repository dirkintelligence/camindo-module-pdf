<?php
// Modul Pdf - (CMS:PDF_*)-Befehle für Frontend-Templates auf Basis von tc-lib-pdf.
// Kein Backend (listed = false); die Befehle kommen aus commands.php, ihre Laufzeit
// ist CmsPdf, die Page als $this->pdf nachlädt (docs/developers/06-templates.md).
return [
    'version'     => '1.0.0',
    'type'        => 'pdf',
    'label'       => '#pdf.module_label',
    'description' => '#pdf.module_description',
    'icon'        => 'newsmode',
    'listed'      => false,
    'access'      => [],
    'requires'    => ['core' => '^9.0', 'php' => ['zlib']],
    'commands'    => 'commands.php',
    'services'    => ['pdf' => 'Camindo\\Modules\\Pdf\\CmsPdf'],
];
