<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Component\DharmaSitemap\Administrator\Extension\DharmaSitemapComponent;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('Dharma\\Component\\DharmaSitemap'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('Dharma\\Component\\DharmaSitemap'));
        $container->set(ComponentInterface::class, static function (Container $c): DharmaSitemapComponent {
            $component = new DharmaSitemapComponent($c->get(ComponentDispatcherFactoryInterface::class));
            $component->setMVCFactory($c->get(MVCFactoryInterface::class));

            return $component;
        });
    }
};
