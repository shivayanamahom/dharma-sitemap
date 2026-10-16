<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Installer\InstallerAdapter;

return new class () implements InstallerScriptInterface {
    public function install(InstallerAdapter $adapter): bool { return true; }
    public function update(InstallerAdapter $adapter): bool { return true; }
    public function uninstall(InstallerAdapter $adapter): bool
    {
        $path = rtrim((string) JPATH_CACHE, '/\\') . '/com_dharmasitemap';
        $this->removeTree($path);
        return true;
    }
    public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }
    public function postflight(string $type, InstallerAdapter $adapter): bool { return true; }
    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) { return; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
};
