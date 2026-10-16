<?php
declare(strict_types=1);

namespace Dharma\Plugin\Task\DharmaSitemap\Extension;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class DharmaSitemapTask extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected const TASKS_MAP = [
        'plg_task_dharmasitemap_build' => [
            'langConstPrefix' => 'PLG_TASK_DHARMASITEMAP_BUILD',
            'method' => 'buildSitemap',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onTaskOptionsList' => 'advertiseRoutines', 'onExecuteTask' => 'standardRoutineHandler'];
    }

    protected function buildSitemap(ExecuteTaskEvent $event): int
    {
        try {
            $result = (new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class)))->build();
            $this->logTask('Dharma Sitemap: опубликована сборка #' . $result['build_id'] . ', URL: ' . $result['url_count'] . ', дублей: ' . $result['duplicate_count']);
            return TaskStatus::OK;
        } catch (\Throwable $error) {
            $this->logTask('Dharma Sitemap: сборка не опубликована: ' . $error->getMessage());
            return TaskStatus::KNOCKOUT;
        }
    }
}
