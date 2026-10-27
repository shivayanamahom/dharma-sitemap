<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\Core\Source;

use Dharma\Component\DharmaSitemap\Administrator\Service\MenuRobotsIndex;
use Dharma\Component\DharmaSitemap\Administrator\Service\PublicAccess;
use Dharma\Component\DharmaSitemap\Administrator\Service\RobotsPolicy;
use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

final class CoreSource implements SitemapSourceInterface
{
    public function __construct(private readonly DatabaseInterface $db, private readonly string $id)
    {
    }

    public function getId(): string
    {
        return 'joomla.' . $this->id;
    }

    public function getTitle(): string
    {
        return match ($this->id) {
            'content' => 'Материалы Joomla',
            'content_categories' => 'Категории материалов Joomla',
            'contacts' => 'Контакты Joomla',
            'contact_categories' => 'Категории контактов Joomla',
            'newsfeeds' => 'Ленты новостей Joomla',
            'newsfeed_categories' => 'Категории лент Joomla',
            'tags' => 'Метки Joomla',
            default => $this->id,
        };
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function isAvailable(): bool
    {
        $table = match ($this->id) {
            'content', 'content_categories' => '#__content',
            'contacts', 'contact_categories' => '#__contact_details',
            'newsfeeds', 'newsfeed_categories' => '#__newsfeeds',
            'tags' => '#__tags',
            default => '',
        };

        try {
            return $table !== '' && $this->db->getTableColumns($this->db->replacePrefix($table), false) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetchBatch(?int $afterId, int $limit): array
    {
        $query = match ($this->id) {
            'content' => $this->contentQuery(),
            'content_categories' => $this->categoryQuery('com_content', '#__content', 'a.state = 1', 'a.access', 'a.publish_up', 'a.publish_down'),
            'contacts' => $this->entityQuery('#__contact_details', 'com_contact', 'contact', 'x.published = 1'),
            'contact_categories' => $this->categoryQuery('com_contact', '#__contact_details', 'a.published = 1', 'a.access', 'a.publish_up', 'a.publish_down'),
            'newsfeeds' => $this->entityQuery('#__newsfeeds', 'com_newsfeeds', 'newsfeed', 'x.published = 1'),
            'newsfeed_categories' => $this->categoryQuery('com_newsfeeds', '#__newsfeeds', 'a.published = 1', 'a.access', 'a.publish_up', 'a.publish_down'),
            'tags' => $this->tagQuery(),
            default => throw new \RuntimeException('Неизвестный источник Joomla.'),
        };
        $afterIdValue = $afterId ?? 0;
        $query->where('x.id > :afterId')->bind(':afterId', $afterIdValue, ParameterType::INTEGER)->order('x.id ASC');
        $rows = $this->db->setQuery($query, 0, $limit)->loadObjectList();
        $global = (string) Factory::getApplication()->get('robots', '');
        $entries = [];

        foreach ($rows as $row) {
            $route = $this->route($row->route, (int) $row->id, (int) ($row->catid ?? 0), $row->language ?? '*');

            if (!$route) {
                continue;
            }

            $entries[] = [
                'url' => $route,
                'title' => $row->title ?? null,
                'description' => ($row->metadesc ?? '') !== '' ? $row->metadesc : ($row->introtext ?? null),
                'section' => $row->section_title ?? $this->defaultSection(),
                'lastmod' => $row->modified ?: null,
                'identity' => $this->getId() . ':' . $row->id . ':' . ($row->language ?? '*'),
                // Запись → пункт меню этой страницы → общие настройки Joomla: тот же порядок, что при показе страницы.
                'robots' => RobotsPolicy::effective(
                    RobotsPolicy::fromMetadata($row->metadata ?? null),
                    MenuRobotsIndex::lookup($this->db, $this->optionFor($row->route), $row->route, (int) $row->id),
                    $global
                ),
            ];
        }

        return ['entries' => $entries, 'next_cursor' => \count($rows) === $limit ? (int) end($rows)->id : null];
    }

    private function contentQuery(): object
    {
        $db = $this->db;
        $levels = PublicAccess::list();

        return $db->createQuery()->select(['x.id', 'x.catid', 'x.alias', 'x.modified', 'x.language', 'x.metadata', 'x.title', 'x.metadesc', 'x.introtext', 'c.title AS section_title', $db->quote('article') . ' AS route'])
            ->from($db->quoteName('#__content', 'x'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = x.catid AND c.extension = ' . $db->quote('com_content'))
            ->where('x.state = 1')->where('x.access IN (' . $levels . ')')->where('c.published = 1')->where('c.access IN (' . $levels . ')')
            ->where($this->openBranch('c', 'com_content'))
            ->where('(x.publish_up IS NULL OR x.publish_up <= UTC_TIMESTAMP())')
            ->where('(x.publish_down IS NULL OR x.publish_down = ' . $db->quote($db->getNullDate()) . ' OR x.publish_down > UTC_TIMESTAMP())');
    }

    /**
     * Категории расширения. Пустые (без опубликованных записей в самой категории и во вложенных) пропускаются, если это не отключено
     * в настройках: страница пустого раздела — тонкий контент.
     */
    private function categoryQuery(string $extension, string $table, string $itemFilter, string $accessColumn, string $upColumn, string $downColumn): object
    {
        $db = $this->db;
        $levels = PublicAccess::list();
        $query = $db->createQuery()->select(['x.id', '0 AS catid', 'x.alias', 'x.modified_time AS modified', $db->quote('*') . ' AS language', 'x.metadata', 'x.title', 'x.metadesc', 'x.description AS introtext', $db->quote('category') . ' AS route'])
            ->from($db->quoteName('#__categories', 'x'))->where('x.extension = ' . $db->quote($extension))
            ->where('x.id > 1')->where('x.published = 1')->where('x.access IN (' . $levels . ')')
            ->where($this->openBranch('x', $extension, true));

        if ((int) ComponentHelper::getParams('com_dharmasitemap')->get('skip_empty_categories', 1) === 1) {
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName($table, 'a') . ' INNER JOIN ' . $db->quoteName('#__categories', 'd') . ' ON d.id = a.catid'
                . ' WHERE d.extension = x.extension AND d.lft >= x.lft AND d.rgt <= x.rgt AND ' . $itemFilter . ' AND ' . $accessColumn . ' IN (' . $levels . ')'
                . ' AND (' . $upColumn . ' IS NULL OR ' . $upColumn . ' <= UTC_TIMESTAMP())'
                . ' AND (' . $downColumn . ' IS NULL OR ' . $downColumn . ' = ' . $db->quote($db->getNullDate()) . ' OR ' . $downColumn . ' > UTC_TIMESTAMP()))');
        }

        return $query;
    }

    private function entityQuery(string $table, string $extension, string $view, string $filter): object
    {
        $db = $this->db;
        $levels = PublicAccess::list();

        return $db->createQuery()->select(['x.id', 'x.catid', 'x.alias', 'x.modified', 'x.language', 'x.metadata', 'x.name AS title', 'x.metadesc', $db->quote($view) . ' AS route'])
            ->from($db->quoteName($table, 'x'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = x.catid AND c.extension = ' . $db->quote($extension))
            ->where($filter)->where('x.access IN (' . $levels . ')')->where('c.published = 1')->where('c.access IN (' . $levels . ')')
            ->where($this->openBranch('c', $extension))
            ->where('(x.publish_up IS NULL OR x.publish_up <= UTC_TIMESTAMP())')
            ->where('(x.publish_down IS NULL OR x.publish_down = ' . $db->quote($db->getNullDate()) . ' OR x.publish_down > UTC_TIMESTAMP())');
    }

    private function tagQuery(): object
    {
        $db = $this->db;
        $levels = PublicAccess::list();

        return $db->createQuery()->select(['x.id', '0 AS catid', 'x.alias', 'x.modified_time AS modified', $db->quote('*') . ' AS language', 'x.metadata', 'x.title', 'x.metadesc', $db->quote('tag') . ' AS route'])
            ->from($db->quoteName('#__tags', 'x'))->where('x.published = 1')->where('x.access IN (' . $levels . ')')->where('x.id > 1')
            ->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__contentitem_tag_map', 'm') . ' INNER JOIN ' . $db->quoteName('#__content', 'c') . ' ON c.id = m.content_item_id AND m.type_alias = ' . $db->quote('com_content.article')
                . ' WHERE m.tag_id = x.id AND c.state = 1 AND c.access IN (' . $levels . '))');
    }

    /** Раздел в llms.txt для записей без своей категории: категории — «Разделы сайта», остальное — по источнику. */
    private function defaultSection(): string
    {
        return match ($this->id) {
            'content_categories', 'contact_categories', 'newsfeed_categories' => 'Разделы сайта',
            'contacts' => 'Контакты',
            'newsfeeds' => 'Ленты новостей',
            'tags' => 'Метки',
            default => 'Страницы',
        };
    }

    /**
     * Ни одна категория выше не закрыта (не опубликована или не публична): страница внутри закрытой ветки недоступна.
     *
     * @param bool $strict true — только предки (для самой категории), false — предки и сама категория
     */
    private function openBranch(string $alias, string $extension, bool $strict = false): string
    {
        $db = $this->db;
        $compare = $strict ? 'a.lft < ' . $alias . '.lft AND a.rgt > ' . $alias . '.rgt' : 'a.lft <= ' . $alias . '.lft AND a.rgt >= ' . $alias . '.rgt';

        return 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = ' . $db->quote($extension)
            . ' AND ' . $compare . ' AND (a.published <> 1 OR a.access NOT IN (' . PublicAccess::list() . ')))';
    }

    /** Компонент страницы: у категорий вид один (`category`), поэтому смотрим ещё и на источник. */
    private function optionFor(string $view): string
    {
        return match (true) {
            $this->id === 'contact_categories', $view === 'contact' => 'com_contact',
            $this->id === 'newsfeed_categories', $view === 'newsfeed' => 'com_newsfeeds',
            $view === 'tag' => 'com_tags',
            default => 'com_content',
        };
    }

    private function route(string $view, int $id, int $category, string $language): ?string
    {
        // Для категории `catid` не нужен: у вида `category` ключ — сам `id` (иначе роутер дописывает `?catid=…`).
        $query = 'index.php?option=' . $this->optionFor($view) . '&view=' . $view . '&id=' . $id;

        if ($category > 0) {
            $query .= '&catid=' . $category;
        }

        if ($language !== '*' && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language)) {
            $query .= '&lang=' . rawurlencode($language);
        }

        try {
            return Route::link('site', $query, false, Route::TLS_FORCE, true);
        } catch (\Throwable) {
            return null;
        }
    }
}
