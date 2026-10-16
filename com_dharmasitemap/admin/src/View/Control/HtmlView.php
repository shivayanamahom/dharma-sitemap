<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\View\Control;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class HtmlView extends BaseHtmlView
{
    public ?object $activeBuild = null;

    public function display($tpl = null): void
    {
        $this->activeBuild = (new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class)))->getActiveBuild();
        parent::display($tpl);
    }
}
