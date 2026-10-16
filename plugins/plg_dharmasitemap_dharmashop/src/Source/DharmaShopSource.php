<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaSitemap\DharmaShop\Source;

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

/**
 * Публичные страницы Dharma Shop по видам: товары, категории, бренды, бейджи («метки») и посадочные страницы.
 *
 * Адреса строит штатный маршрутизатор магазина, как для canonical самих страниц. Корзина, оформление, избранное, заказы и поиск
 * сюда не входят: они личные и закрыты от индексации. Списки (корень каталога, бренды, бейджи, посадочные) берутся из пунктов меню.
 */
final class DharmaShopSource implements SitemapSourceInterface
{
    private const EXTENSIONS = ['categories' => 'com_dharmashop', 'brands' => 'com_dharmashop.brand', 'badges' => 'com_dharmashop.badge'];
    private const VIEWS = ['categories' => 'category', 'brands' => 'brand', 'badges' => 'badge'];

    public function __construct(private readonly DatabaseInterface $db, private readonly string $kind)
    {
    }

    public function getId(): string
    {
        return 'dharmashop.' . $this->kind;
    }

    public function getTitle(): string
    {
        return match ($this->kind) {
            'products' => 'Dharma Shop: товары',
            'categories' => 'Dharma Shop: категории',
            'brands' => 'Dharma Shop: бренды',
            'badges' => 'Dharma Shop: бейджи',
            'landings' => 'Dharma Shop: посадочные страницы',
            default => $this->kind,
        };
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function isAvailable(): bool
    {
        $table = match ($this->kind) {
            'products' => '#__dharmashop_products',
            'landings' => '#__dharmashop_landing_pages',
            default => '#__dharmashop_product_categories',
        };

        if ($this->kind === 'landings' && !ComponentHelper::isEnabled('com_dharmashop_landing')) {
            return false;
        }

        try {
            return $this->db->getTableColumns($this->db->replacePrefix($table), false) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetchBatch(?int $afterId, int $limit): array
    {
        $query = match ($this->kind) {
            'products' => $this->productQuery(),
            'landings' => $this->landingQuery(),
            default => $this->taxonomyQuery(),
        };
        $afterIdValue = $afterId ?? 0;
        $query->where('x.id > :afterId')->bind(':afterId', $afterIdValue, ParameterType::INTEGER)->order('x.id ASC');
        $rows = $this->db->setQuery($query, 0, $limit)->loadObjectList();
        // Настройка магазина «Robots»: пусто — «как в общих настройках Joomla» (значение по умолчанию с версии магазина, где это введено).
        $shopRobots = trim((string) ComponentHelper::getParams('com_dharmashop')->get('seo_robots', '')) ?: null;
        $global = (string) Factory::getApplication()->get('robots', '');
        [$option, $view] = match ($this->kind) {
            'products' => ['com_dharmashop', 'product'],
            'landings' => ['com_dharmashop_landing', 'landing'],
            default => ['com_dharmashop', self::VIEWS[$this->kind]],
        };
        $entries = [];

        // Страница-корень брендов и бейджей (`view=brand&id=1`): общий список. Без пункта меню её адрес — `/component/dharmashop/badge`,
        // с пунктом меню роутер вернёт адрес пункта (дубль с источником меню отсекается). Нужна, только если есть что показывать.
        if ($afterId === null && $rows && \in_array($this->kind, ['brands', 'badges'], true)) {
            $root = $this->route('index.php?option=com_dharmashop&view=' . $view . '&id=1');

            if ($root !== null) {
                $entries[] = [
                    'url' => $root,
                    'lastmod' => null,
                    'identity' => $this->getId() . ':root:*',
                    'robots' => RobotsPolicy::effective(MenuRobotsIndex::lookup($this->db, 'com_dharmashop', $this->kind, 0), $shopRobots, $global),
                ];
            }
        }

        foreach ($rows as $row) {
            $url = 'index.php?option=' . $option . '&view=' . $view . '&id=' . (int) $row->id;

            if ($this->kind === 'products') {
                $url .= '&catid=' . (int) $row->catid;
            }

            if (!empty($row->language) && $row->language !== '*' && preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', (string) $row->language)) {
                $url .= '&lang=' . rawurlencode((string) $row->language);
            }

            $absolute = $this->route($url);

            if ($absolute === null) {
                continue;
            }

            // Свой robots записи → robots пункта меню этой страницы → настройка магазина → общая настройка Joomla (как в PageSeo магазина).
            $own = $this->kind === 'landings' ? trim((string) ($row->robots ?? '')) : RobotsPolicy::fromMetadata($row->metadata ?? null);
            $entries[] = [
                'url' => $absolute,
                'lastmod' => $row->modified ?: null,
                'identity' => $this->getId() . ':' . $row->id . ':' . ($row->language ?? '*'),
                'robots' => RobotsPolicy::effective($own, MenuRobotsIndex::lookup($this->db, $option, $view, (int) $row->id), $shopRobots, $global),
            ];
        }

        return ['entries' => $entries, 'next_cursor' => \count($rows) === $limit ? (int) end($rows)->id : null];
    }

    private function route(string $url): ?string
    {
        try {
            return Route::link('site', $url, false, Route::TLS_FORCE, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Опубликованные товары в открытых категориях. При настройке магазина «главный вариант» (seo_variant_canonical = main) у остальных
     * вариантов группы canonical указывает на первый вариант, поэтому в карту попадает только он.
     */
    private function productQuery(): object
    {
        $db = $this->db;
        $levels = PublicAccess::list();
        $query = $db->createQuery()->select(['x.id', 'x.catid', 'x.modified', 'x.metadata', $db->quote('*') . ' AS language'])
            ->from($db->quoteName('#__dharmashop_products', 'x'))
            ->join('INNER', $db->quoteName('#__categories', 'c') . ' ON c.id = x.catid AND c.extension = ' . $db->quote('com_dharmashop'))
            ->where('x.state = 1')->where('c.published = 1')->where('c.access IN (' . $levels . ')')
            ->where($this->openBranch('c', 'com_dharmashop', false));

        if ((string) ComponentHelper::getParams('com_dharmashop')->get('seo_variant_canonical', 'self') === 'main') {
            $query->where('(x.group_id IS NULL OR x.group_id = 0'
                . ' OR NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__dharmashop_product_groups', 'g') . ' WHERE g.id = x.group_id AND g.state = 1)'
                . ' OR x.id = (SELECT MIN(m.id) FROM ' . $db->quoteName('#__dharmashop_products', 'm')
                . ' INNER JOIN ' . $db->quoteName('#__categories', 'mc') . ' ON mc.id = m.catid AND mc.extension = ' . $db->quote('com_dharmashop')
                . ' WHERE m.group_id = x.group_id AND m.state = 1 AND mc.published = 1 AND mc.access IN (' . $levels . ') AND ' . $this->openBranch('mc', 'com_dharmashop', false) . '))');
        }

        return $query;
    }

    /** Категории, бренды и бейджи с товарами (пустые страницы — тонкий контент, если не отключено в настройках). */
    private function taxonomyQuery(): object
    {
        $db = $this->db;
        $extension = self::EXTENSIONS[$this->kind] ?? 'com_dharmashop';
        $query = $db->createQuery()->select(['x.id', 'x.modified_time AS modified', 'x.language', 'x.metadata'])
            ->from($db->quoteName('#__categories', 'x'))->where('x.extension = ' . $db->quote($extension))
            ->where('x.id > 1')->where('x.published = 1')->where('x.access IN (' . PublicAccess::list() . ')')
            ->where($this->openBranch('x', $extension, true));

        if ((int) ComponentHelper::getParams('com_dharmasitemap')->get('skip_empty_categories', 1) === 1) {
            $query->where('EXISTS (SELECT 1 FROM ' . $db->quoteName('#__dharmashop_product_categories', 'pc') . ' WHERE pc.catid = x.id AND pc.state = 1)');
        }

        return $query;
    }

    /** Опубликованные посадочные страницы с рабочими условиями и открытой категорией-источником. */
    private function landingQuery(): object
    {
        $db = $this->db;

        return $db->createQuery()->select(['x.id', 'COALESCE(x.modified, x.created) AS modified', 'x.robots', $db->quote('*') . ' AS language'])
            ->from($db->quoteName('#__dharmashop_landing_pages', 'x'))
            ->where('x.state = 1')->where('x.broken = 0')->where('x.access IN (' . PublicAccess::list() . ')')
            ->where('(x.catid < 2 OR EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'c') . ' WHERE c.id = x.catid AND c.extension = ' . $db->quote('com_dharmashop')
                . ' AND c.published = 1 AND ' . $this->openBranch('c', 'com_dharmashop', false) . '))');
    }

    /** Ни одна категория ветки не закрыта (не опубликована или не публична). `$strict` — проверять только предков. */
    private function openBranch(string $alias, string $extension, bool $strict): string
    {
        $db = $this->db;
        $compare = $strict ? 'a.lft < ' . $alias . '.lft AND a.rgt > ' . $alias . '.rgt' : 'a.lft <= ' . $alias . '.lft AND a.rgt >= ' . $alias . '.rgt';

        return 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__categories', 'a') . ' WHERE a.extension = ' . $db->quote($extension)
            . ' AND ' . $compare . ' AND (a.published <> 1 OR a.access NOT IN (' . PublicAccess::list() . ')))';
    }
}
