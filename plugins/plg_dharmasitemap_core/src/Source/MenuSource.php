<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\Core\Source;

use Dharma\Component\DharmaSitemap\Administrator\Service\MenuViewPolicy;
use Dharma\Component\DharmaSitemap\Administrator\Service\PublicAccess;
use Dharma\Component\DharmaSitemap\Administrator\Service\RobotsPolicy;
use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

/**
 * Страницы из пунктов меню, которых нет у источников сущностей: главная, списки категорий, брендов, бейджей и посадочных,
 * избранные материалы и т. п. Личные страницы (корзина, оформление, избранное, заказы, вход) пропускаются.
 */
final class MenuSource implements SitemapSourceInterface
{
    /** Компоненты с собственным значением robots по умолчанию в настройках магазина. */
    private const SHOP_COMPONENTS = ['com_dharmashop', 'com_dharmashop_landing'];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function getId(): string
    {
        return 'joomla.menu';
    }

    public function getTitle(): string
    {
        return 'Страницы из меню';
    }

    /** После источников сущностей: при совпадении адреса остаётся запись сущности с её проверками. */
    public function getPriority(): int
    {
        return 90;
    }

    public function isAvailable(): bool
    {
        try {
            return $this->db->getTableColumns($this->db->replacePrefix('#__menu'), false) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetchBatch(?int $afterId, int $limit): array
    {
        $db = $this->db;
        $levels = PublicAccess::list();
        $afterIdValue = $afterId ?? 0;
        $nullDate = $db->quote($db->getNullDate());
        $query = $db->createQuery()->select(['m.id', 'm.link', 'm.language', 'm.params', 'm.home'])->from($db->quoteName('#__menu', 'm'))
            ->where('m.client_id = 0')->where('m.published = 1')->where('m.type = ' . $db->quote('component'))->where('m.access IN (' . $levels . ')')
            ->where('m.link LIKE ' . $db->quote('index.php?option=%'))
            ->where('(m.publish_up IS NULL OR m.publish_up <= UTC_TIMESTAMP())')
            ->where('(m.publish_down IS NULL OR m.publish_down = ' . $nullDate . ' OR m.publish_down > UTC_TIMESTAMP())')
            // Пункт под закрытым родителем недоступен посетителю.
            ->where('NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__menu', 'a') . ' WHERE a.client_id = 0 AND a.id > 1 AND a.lft < m.lft AND a.rgt > m.rgt'
                . ' AND (a.published <> 1 OR a.access NOT IN (' . $levels . ')))')
            ->where('m.id > :afterId')->bind(':afterId', $afterIdValue, ParameterType::INTEGER)->order('m.id ASC');
        $rows = $db->setQuery($query, 0, $limit)->loadObjectList();
        $global = (string) Factory::getApplication()->get('robots', '');
        $shop = trim((string) ComponentHelper::getParams('com_dharmashop')->get('seo_robots', '')) ?: null;
        $entries = [];

        foreach ($rows as $row) {
            $page = MenuViewPolicy::classify((string) $row->link, (int) $row->home === 1);

            if ($page['verdict'] !== MenuViewPolicy::INCLUDE) {
                continue;
            }

            $url = 'index.php?Itemid=' . (int) $row->id;
            $language = (string) $row->language;

            if ($language !== '' && $language !== '*' && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language)) {
                $url .= '&lang=' . rawurlencode($language);
            }

            try {
                $absolute = Route::link('site', $url, false, Route::TLS_FORCE, true);
            } catch (\Throwable) {
                continue;
            }

            $entries[] = [
                'url' => $absolute,
                'lastmod' => null,
                'identity' => $this->getId() . ':' . $row->id . ':' . ($language !== '' ? $language : '*'),
                'robots' => RobotsPolicy::effective(RobotsPolicy::fromParams($row->params), \in_array($page['option'], self::SHOP_COMPONENTS, true) ? $shop : null, $global),
            ];
        }

        return ['entries' => $entries, 'next_cursor' => \count($rows) === $limit ? (int) end($rows)->id : null];
    }
}
