<?php
declare(strict_types=1);

namespace Dharma\Plugin\System\DharmaSitemap\Extension;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class DharmaSitemap extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterRoute' => 'serveSitemap'];
    }

    public function serveSitemap(): void
    {
        $app = Factory::getApplication();
        if (!$app->isClient('site')) {
            return;
        }
        $input = $app->getInput();
        if ($input->getCmd('option') !== 'com_dharmasitemap' || $input->getCmd('view') !== 'sitemap') {
            return;
        }
        if ($input->getCmd('format') !== 'xml') {
            throw new \RuntimeException('Not Found', 404);
        }

        $builder = new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class));
        $build = $builder->getActiveBuild();
        if (!$build) {
            throw new \RuntimeException('Sitemap has not been generated yet.', 503);
        }
        $base = rtrim((string) $app->get('cache_path', JPATH_CACHE), '/\\') . '/com_dharmasitemap/build-' . (int) $build->id;
        $part = $input->getInt('part', 0);
        $file = $part < 1 ? 'sitemap-index.xml' : 'sitemap-' . $part . '.xml';
        $path = $base . '/' . $file;
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
