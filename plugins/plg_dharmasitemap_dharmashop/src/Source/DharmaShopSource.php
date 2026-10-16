<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\DharmaShop\Source;

use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

final class DharmaShopSource implements SitemapSourceInterface
{
    public function __construct(private readonly DatabaseInterface $db, private readonly string $kind)
    {
    }

    public function getId(): string { return 'dharmashop.' . $this->kind; }
    public function getTitle(): string { return $this->kind === 'products' ? 'Dharma Shop: товары' : 'Dharma Shop: категории'; }

    public function isAvailable(): bool
    {
        $table = $this->kind === 'products' ? '#__dharmashop_products' : '#__categories';
        try {
            return $this->db->getTableColumns($this->db->replacePrefix($table), false) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetchBatch(?int $afterId, int $limit): array
    {
        $db = $this->db;
        $afterIdValue = $afterId ?? 0;
        if ($this->kind === 'products') {
            $query = $db->createQuery()->select(['p.id', 'p.catid', 'p.modified', 'p.alias'])
                ->from($db->quoteName('#__dharmashop_products', 'p'))
                ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = p.catid AND c.extension = ' . $db->quote('com_dharmashop'))
                ->where('p.state = 1')->where('p.id > :afterId')->where('c.published = 1')->where('c.access = 1')
                ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = ' . $db->quote('com_dharmashop') . ' AND a.lft <= c.lft AND a.rgt >= c.rgt AND (a.published <> 1 OR a.access <> 1))')
                ->order('p.id ASC')->bind(':afterId', $afterIdValue, ParameterType::INTEGER);
        } else {
            $query = $db->createQuery()->select(['c.id', 'c.alias', 'c.modified_time AS modified', 'c.language'])
                ->from($db->quoteName('#__categories', 'c'))->where('c.extension = ' . $db->quote('com_dharmashop'))
                ->where('c.id > 1')->where('c.id > :afterId')->where('c.published = 1')->where('c.access = 1')
                ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = ' . $db->quote('com_dharmashop') . ' AND a.lft < c.lft AND a.rgt > c.rgt AND (a.published <> 1 OR a.access <> 1))')
                ->order('c.id ASC')->bind(':afterId', $afterIdValue, ParameterType::INTEGER);
        }

        $rows = $db->setQuery($query, 0, $limit)->loadObjectList();
        $entries = [];
        foreach ($rows as $row) {
            $view = $this->kind === 'products' ? 'product&id=' . (int) $row->id . '&catid=' . (int) $row->catid : 'category&id=' . (int) $row->id;
            $url = 'index.php?option=com_dharmashop&view=' . $view;
            if (!empty($row->language) && $row->language !== '*') {
                $url .= '&lang=' . rawurlencode($row->language);
            }
            try {
                $absolute = Route::link('site', $url, false, Route::TLS_FORCE, true);
            } catch (\Throwable) {
                continue;
            }
            $entries[] = ['url' => $absolute, 'lastmod' => $row->modified ?: null, 'identity' => $this->getId() . ':' . $row->id . ':' . ($row->language ?? '*')];
        }

        return ['entries' => $entries, 'next_cursor' => count($rows) === $limit ? (int) end($rows)->id : null];
    }
}
