<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Plugin\Task\DharmaSitemap\Extension\DharmaSitemapTask;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (): DharmaSitemapTask {
            $plugin = new DharmaSitemapTask((array) PluginHelper::getPlugin('task', 'dharmasitemap'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
