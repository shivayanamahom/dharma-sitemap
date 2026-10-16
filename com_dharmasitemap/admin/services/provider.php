<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Dharma\Component\DharmaSitemap\Administrator\Extension\DharmaSitemapComponent;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ComponentDispatcherFactory('Dharma\\Component\\DharmaSitemap'));
        $container->set(ComponentInterface::class, static fn (Container $c): DharmaSitemapComponent => new DharmaSitemapComponent($c->get(ComponentDispatcherFactoryInterface::class)));
    }
};
