<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;

\defined('_JEXEC') or die;

/** Резервный Joomla endpoint: отдаёт индекс или часть активной сборки из Joomla Cache. Общий код компонента и системного плагина. */
final class SitemapEndpoint
{
    /** @throws \RuntimeException 404 — нет такой части, 503 — карта ещё не собрана. */
    public static function serve(CMSApplicationInterface $app, Input $input, DatabaseInterface $db): void
    {
        $build = (new SitemapBuilder($db))->getActiveBuild();

        if (!$build) {
            throw new \RuntimeException('Sitemap has not been generated yet.', 503);
        }

        $base = Paths::buildDirectory((int) $build->id);
        $part = $input->getInt('part', 0);
        $path = $base . '/' . ($part < 1 ? 'sitemap-index.xml' : 'sitemap-' . $part . '.xml');
        $realBase = realpath($base);
        $realPath = realpath($path);

        if (!$realBase || !$realPath || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Sitemap part not found.', 404);
        }

        $app->setHeader('Content-Type', 'application/xml; charset=UTF-8', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'public, max-age=300', true);
        $app->sendHeaders();
        readfile($realPath);
        $app->close();
    }
}
