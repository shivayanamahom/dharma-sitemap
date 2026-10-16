<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\Core\Source;

use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
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
            'content' => 'Материалы Joomla', 'contacts' => 'Контакты Joomla', 'newsfeeds' => 'Ленты новостей Joomla', 'tags' => 'Метки Joomla', default => $this->id,
        };
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
            'content_categories' => $this->categoryQuery('com_content'),
            'contacts' => $this->entityQuery('#__contact_details', 'com_contact', 'contact', 'id, catid, alias, modified, language', 'state = 1'),
            'contact_categories' => $this->categoryQuery('com_contact'),
            'newsfeeds' => $this->entityQuery('#__newsfeeds', 'com_newsfeeds', 'newsfeed', 'id, catid, alias, modified, language', 'x.published = 1'),
            'newsfeed_categories' => $this->categoryQuery('com_newsfeeds'),
            'tags' => $this->tagQuery(),
            default => throw new \RuntimeException('Неизвестный источник Joomla.'),
        };
        $afterIdValue = $afterId ?? 0;
        $query->where('x.id > :afterId')->bind(':afterId', $afterIdValue, ParameterType::INTEGER)->order('x.id ASC');
        $rows = $this->db->setQuery($query, 0, $limit)->loadObjectList();
        $entries = [];
        foreach ($rows as $row) {
            $route = $this->route($row->route, $row->id, $row->catid ?? null, $row->language ?? '*');
            if (!$route) {
                continue;
            }
            $entries[] = ['url' => $route, 'lastmod' => $row->modified ?: null, 'identity' => $this->getId() . ':' . $row->id . ':' . ($row->language ?? '*')];
        }

        return ['entries' => $entries, 'next_cursor' => count($rows) === $limit ? (int) end($rows)->id : null];
    }

    private function contentQuery(): object
    {
        $db = $this->db;
        return $db->createQuery()->select(['x.id', 'x.catid', 'x.alias', 'x.modified', 'x.language', $db->quote('article') . ' AS route'])
            ->from($db->quoteName('#__content', 'x'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = x.catid AND c.extension = ' . $db->quote('com_content'))
            ->where('x.state = 1')->where('x.access = 1')->where('c.published = 1')->where('c.access = 1')
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = c.extension AND a.lft <= c.lft AND a.rgt >= c.rgt AND (a.published <> 1 OR a.access <> 1))')
            ->where('(x.publish_up IS NULL OR x.publish_up <= UTC_TIMESTAMP())')->where('(x.publish_down IS NULL OR x.publish_down = ' . $db->quote($db->getNullDate()) . ' OR x.publish_down > UTC_TIMESTAMP())');
    }

    private function categoryQuery(string $extension): object
    {
        $db = $this->db;
        return $db->createQuery()->select(['x.id', 'x.parent_id AS catid', 'x.alias', 'x.modified_time AS modified', $db->quote('*') . ' AS language', $db->quote('category') . ' AS route'])
            ->from($db->quoteName('#__categories', 'x'))->where('x.extension = ' . $db->quote($extension))
            ->where('x.id > 1')->where('x.published = 1')->where('x.access = 1')
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'p') . ' WHERE p.extension = x.extension AND p.lft < x.lft AND p.rgt > x.rgt AND (p.published <> 1 OR p.access <> 1))');
    }

    private function entityQuery(string $table, string $extension, string $view, string $columns, string $filter): object
    {
        $db = $this->db;
        $select = array_map(static fn (string $column): string => 'x.' . trim($column), explode(',', $columns));
        $select[] = $db->quote($view) . ' AS route';
        return $db->createQuery()->select($select)->from($db->quoteName($table, 'x'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = x.catid AND c.extension = ' . $db->quote($extension))
            ->where($filter)->where('x.access = 1')->where('c.published = 1')->where('c.access = 1')
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = c.extension AND a.lft <= c.lft AND a.rgt >= c.rgt AND (a.published <> 1 OR a.access <> 1))');
    }

    private function tagQuery(): object
    {
        $db = $this->db;
        return $db->createQuery()->select(['x.id', 'x.id AS catid', 'x.alias', 'x.modified_time AS modified', $db->quote('*') . ' AS language', $db->quote('tag') . ' AS route'])
            ->from($db->quoteName('#__tags', 'x'))->where('x.published = 1')->where('x.access = 1')->where('x.id > 1')
            ->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__contentitem_tag_map', 'm') . ' INNER JOIN ' . $db->quoteName('#__content', 'c') . ' ON c.id = m.content_item_id AND m.type_alias = ' . $db->quote('com_content.article') . ' WHERE m.tag_id = x.id AND c.state = 1 AND c.access = 1)');
    }

    private function route(string $view, int $id, ?int $category, string $language): ?string
    {
        $query = 'index.php?option=' . match ($view) {
            'article', 'category' => 'com_content', 'contact' => 'com_contact', 'newsfeed' => 'com_newsfeeds', 'tag' => 'com_tags', default => '',
        } . '&view=' . $view . '&id=' . $id;
        if ($category) {
            $query .= '&catid=' . $category;
        }
        if ($language !== '*' && preg_match('/^[a-z]{2}-[A-Z]{2}$/', $language)) {
            $query .= '&lang=' . rawurlencode($language);
        }

        try {
            return Route::link('site', $query, false, Route::TLS_FORCE, true);
        } catch (\Throwable) {
            return null;
        }
    }
}
