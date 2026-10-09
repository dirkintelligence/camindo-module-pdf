<?php
// Module Pdf - (CMS:PDF_*) commands for frontend templates, based on tc-lib-pdf.
// No backend (listed = false); the commands come from commands.php, their runtime
// is CmsPdf, which Page loads as $this->pdf (developer docs, chapter 06 (Template compiler)).
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
