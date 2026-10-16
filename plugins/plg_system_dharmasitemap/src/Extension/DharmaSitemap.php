<?php
declare(strict_types=1);

namespace Dharma\Plugin\System\DharmaSitemap\Extension;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapEndpoint;
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

        SitemapEndpoint::serve($app, $input, Factory::getContainer()->get(DatabaseInterface::class));
    }
}
