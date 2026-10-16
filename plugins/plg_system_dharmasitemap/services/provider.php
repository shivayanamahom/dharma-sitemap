<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Plugin\System\DharmaSitemap\Extension\DharmaSitemap;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        \JLoader::registerNamespace('Dharma\\Plugin\\System\\DharmaSitemap', dirname(__DIR__) . '/src');
        $container->set(PluginInterface::class, static function (): DharmaSitemap {
            $plugin = new DharmaSitemap((array) PluginHelper::getPlugin('system', 'dharmasitemap'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
