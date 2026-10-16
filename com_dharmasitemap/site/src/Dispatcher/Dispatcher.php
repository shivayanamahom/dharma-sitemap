<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Site\Dispatcher;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapEndpoint;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class Dispatcher extends ComponentDispatcher
{
    public function dispatch()
    {
        if ($this->input->getCmd('view') !== 'sitemap' || $this->input->getCmd('format') !== 'xml') {
            throw new \RuntimeException('Not Found', 404);
        }

        SitemapEndpoint::serve($this->app, $this->input, Factory::getContainer()->get(DatabaseInterface::class));
    }
}
