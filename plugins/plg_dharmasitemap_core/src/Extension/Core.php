<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\Core\Extension;

use Dharma\Component\DharmaSitemap\Administrator\Event\CollectSourcesEvent;
use Dharma\Plugin\DharmaSitemap\Core\Source\CoreSource;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\SubscriberInterface;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class Core extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaSitemapCollectSources' => 'registerSources'];
    }

    public function registerSources(CollectSourcesEvent $event): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = ComponentHelper::getParams('com_dharmasitemap');
        $sources = [];
        if ($params->get('include_content', 1)) {
            $sources[] = new CoreSource($db, 'content');
            $sources[] = new CoreSource($db, 'content_categories');
        }
        if ($params->get('include_contacts', 1)) {
            $sources[] = new CoreSource($db, 'contacts');
            $sources[] = new CoreSource($db, 'contact_categories');
        }
        if ($params->get('include_newsfeeds', 1)) {
            $sources[] = new CoreSource($db, 'newsfeeds');
            $sources[] = new CoreSource($db, 'newsfeed_categories');
        }
        if ($params->get('include_tags', 1)) {
            $sources[] = new CoreSource($db, 'tags');
        }
        $event->addResult($sources);
    }
}
