<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Plugin\DharmaSitemap\Core\Extension\Core;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (): Core {
            $plugin = new Core((array) PluginHelper::getPlugin('dharmasitemap', 'core'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
