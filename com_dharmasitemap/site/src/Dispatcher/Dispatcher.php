<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Site\Dispatcher;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class Dispatcher extends ComponentDispatcher
{
    public function dispatch()
    {
        if ($this->input->getCmd('view') !== 'sitemap' || $this->input->getCmd('format') !== 'xml') {
            throw new \RuntimeException('Not Found', 404);
        }

        $builder = new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class));
        $build = $builder->getActiveBuild();
        if (!$build) {
            throw new \RuntimeException('Sitemap has not been generated yet.', 503);
        }

        $base = rtrim((string) Factory::getApplication()->get('cache_path', JPATH_CACHE), '/\\') . '/com_dharmasitemap/build-' . (int) $build->id;
        $part = $this->input->getInt('part', 0);
        $file = $part < 1 ? 'sitemap-index.xml' : 'sitemap-' . $part . '.xml';
        $path = $base . '/' . $file;
        if (!is_file($path) || !str_starts_with(realpath($path) ?: '', realpath($base) . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Sitemap part not found.', 404);
        }

        $this->app->setHeader('Content-Type', 'application/xml; charset=UTF-8', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('Cache-Control', 'public, max-age=300', true);
        $this->app->sendHeaders();
        readfile($path);
        $this->app->close();
    }
}
