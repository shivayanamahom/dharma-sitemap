<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Controller;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class GenerateController extends BaseController
{
    public function build(): void
    {
        $app = Factory::getApplication();
        if (!Session::checkToken('post') || !$app->getIdentity()->authorise('core.manage', 'com_dharmasitemap')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $result = (new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class)))->build();
            $app->enqueueMessage(Text::sprintf('COM_DHARMASITEMAP_BUILD_SUCCESS', $result['url_count'], $result['duplicate_count']), 'message');
        } catch (\Throwable $error) {
            $app->enqueueMessage(Text::sprintf('COM_DHARMASITEMAP_BUILD_FAILED', $error->getMessage()), 'error');
        }

        $this->setRedirect('index.php?option=com_dharmasitemap');
    }
}
