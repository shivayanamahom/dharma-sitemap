<?php
declare(strict_types=1);

namespace Dharma\Plugin\Console\DharmaSitemap\Extension;

use Dharma\Plugin\Console\DharmaSitemap\CliCommand\BuildSitemapCommand;
use Joomla\Application\ApplicationEvents;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class DharmaSitemapConsolePlugin extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ApplicationEvents::BEFORE_EXECUTE => 'registerCommands'];
    }

    public function registerCommands(): void
    {
        $app = Factory::getApplication();
        if ($app instanceof \Joomla\Console\Application) {
            $app->addCommand(new BuildSitemapCommand());
        }
    }
}
