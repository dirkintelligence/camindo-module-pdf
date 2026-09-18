<?php
/**
 * Installer des Moduls Pdf: legt var/modules/pdf/{fonts,layouts,profiles} an und kopiert die
 * mitgelieferten Standardschriften (fonts/core) dorthin. Keine Datenbank.
 *
 * Deinstallieren lässt die Verzeichnisse stehen - dort liegen projekteigene Vorlagen
 * und Schriften; mit --purge werden sie entfernt.
 */
namespace Camindo\Modules\Pdf\Install;

use Camindo\Core\Install\ComponentInstallerInterface;
use Camindo\Core\Install\InstallContext;

class Installer implements ComponentInstallerInterface {

    private const VAR_DIR = 'modules/pdf';

    public function install(InstallContext $ctx): void
    {
        $varDir = (string)$ctx->config->get('paths.var_dir');
        foreach (['fonts', 'layouts', 'profiles'] as $sub) {
            if (!$ctx->system->createDir($varDir, self::VAR_DIR . '/' . $sub)) {
                throw new \RuntimeException("cannot create $varDir/" . self::VAR_DIR . "/$sub");
            }
        }
        $copied = $this->copyCoreFonts($ctx, $varDir);
        $ctx->log("Pdf: $copied core font files copied to var/" . self::VAR_DIR . '/fonts/core');
    }

    public function migrate(string $from, string $to, InstallContext $ctx): void
    {
        // Schriften nachziehen, falls ein Update welche mitbringt
        $this->copyCoreFonts($ctx, (string)$ctx->config->get('paths.var_dir'));
    }

    public function activate(InstallContext $ctx): void {}

    public function deactivate(InstallContext $ctx): void {}

    public function uninstall(InstallContext $ctx, bool $purge): void
    {
        if (!$purge) return;
        $dir = (string)$ctx->config->get('paths.var_dir') . '/' . self::VAR_DIR;
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
        $ctx->log("Pdf: $dir removed");
    }

    /**
     * fonts/core des Moduls nach var/modules/pdf/fonts/core - vorhandene Dateien werden
     * überschrieben (das sind die mitgelieferten, keine projekteigenen).
     */
    private function copyCoreFonts(InstallContext $ctx, string $varDir): int
    {
        $source = $ctx->path . '/fonts/core';
        if (!is_dir($source)) return 0;
        $relDir = self::VAR_DIR . '/fonts/core';
        if (!$ctx->system->createDir($varDir, $relDir)) {
            throw new \RuntimeException("cannot create $varDir/$relDir");
        }
        $n = 0;
        foreach (scandir($source) ?: [] as $file) {
            if ($file[0] === '.' || !is_file("$source/$file")) continue;
            $content = file_get_contents("$source/$file");
            if ($content === false || !$ctx->system->filePutContents($varDir, "$relDir/$file", $content)) {
                throw new \RuntimeException("cannot write $varDir/$relDir/$file");
            }
            $n++;
        }
        return $n;
    }
}
