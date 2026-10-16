<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\DharmaShop\Extension;

use Dharma\Component\DharmaSitemap\Administrator\Event\CollectSourcesEvent;
use Dharma\Plugin\DharmaSitemap\DharmaShop\Source\DharmaShopSource;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class DharmaShop extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;
    public static function getSubscribedEvents(): array { return ['onDharmaSitemapCollectSources' => 'registerSources']; }

    public function registerSources(CollectSourcesEvent $event): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = ComponentHelper::getParams('com_dharmasitemap');
        $sources = [];
        if ($params->get('include_dharmashop_products', 1)) {
            $sources[] = new DharmaShopSource($db, 'products');
        }
        if ($params->get('include_dharmashop_categories', 1)) {
            $sources[] = new DharmaShopSource($db, 'categories');
        }
        if ($params->get('include_dharmashop_brands', 1)) {
            $sources[] = new DharmaShopSource($db, 'brands');
        }
        if ($params->get('include_dharmashop_badges', 1)) {
            $sources[] = new DharmaShopSource($db, 'badges');
        }
        if ($params->get('include_dharmashop_landings', 1)) {
            $sources[] = new DharmaShopSource($db, 'landings');
        }
        $event->addResult($sources);
    }
}
