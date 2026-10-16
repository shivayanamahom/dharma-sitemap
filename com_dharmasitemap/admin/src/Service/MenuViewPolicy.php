<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

\defined('_JEXEC') or die;

/**
 * Какие пункты меню попадают в карту.
 *
 * Страницы делятся на три группы:
 * - личные и служебные (корзина, оформление заказа, избранное, заказы, вход и регистрация, поиск): в карте им не место,
 *   они закрыты от индексации и зависят от посетителя;
 * - страницы сущностей (материал, товар, категория, бренд, бейдж, посадочная): их адреса отдают источники сущностей с проверкой
 *   публикации, доступа и noindex, поэтому пункт меню не дублирует их;
 * - остальное (главная, списки категорий, брендов, бейджей, посадочных, избранные материалы и т. п.): берётся из меню.
 */
final class MenuViewPolicy
{
    public const INCLUDE = 'include';
    public const PRIVATE = 'private';
    public const COVERED = 'covered';
    public const SKIP = 'skip';

    /** Компоненты, страницы которых не публичный контент. */
    private const PRIVATE_COMPONENTS = ['com_users', 'com_config', 'com_login', 'com_privacy', 'com_finder', 'com_search', 'com_ajax', 'com_admin', 'com_mailto'];

    /** Личные и служебные виды компонентов с публичной частью. */
    private const PRIVATE_VIEWS = [
        'com_dharmashop' => ['cart', 'checkout', 'checkoutcomplete', 'favorites', 'order', 'orders', 'payment', 'search'],
        'com_content' => ['form'],
        'com_contact' => ['form'],
    ];

    /** Виды, адреса которых отдают источники сущностей. */
    private const COVERED_VIEWS = [
        'com_content' => ['article', 'category'],
        'com_contact' => ['contact', 'category'],
        'com_newsfeeds' => ['newsfeed', 'category'],
        'com_dharmashop' => ['product', 'category', 'brand', 'badge'],
        'com_dharmashop_landing' => ['landing'],
    ];

    /** Виды Dharma Shop, у которых корень (id 0 или 1) — страница со списком всего каталога. */
    private const ROOT_LISTS = ['category', 'brand', 'badge'];

    /**
     * @return array{verdict: string, option: string, view: string, id: int}
     */
    public static function classify(string $link, bool $home = false): array
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $vars);
        $option = strtolower((string) ($vars['option'] ?? ''));
        $view = strtolower((string) ($vars['view'] ?? ''));
        $id = (int) ($vars['id'] ?? 0);
        $result = ['verdict' => self::INCLUDE, 'option' => $option, 'view' => $view, 'id' => $id];

        if ($option === '') {
            $result['verdict'] = self::SKIP;

            return $result;
        }

        if (\in_array($option, self::PRIVATE_COMPONENTS, true) || \in_array($view, self::PRIVATE_VIEWS[$option] ?? [], true)) {
            $result['verdict'] = self::PRIVATE;

            return $result;
        }

        // Главная — это адрес `/`, а не адрес сущности, на которую она ссылается.
        if (!$home && \in_array($view, self::COVERED_VIEWS[$option] ?? [], true)) {
            $result['verdict'] = self::COVERED;
        }

        // Корень (id 0 или 1) у категорий, брендов и бейджей Dharma Shop — это список всего каталога, а не сущность: источники сущностей
        // отдают только записи с id больше 1, поэтому страница корня берётся из меню.
        if ($result['verdict'] === self::COVERED && $option === 'com_dharmashop' && \in_array($view, self::ROOT_LISTS, true) && $id <= 1) {
            $result['verdict'] = self::INCLUDE;
        }

        return $result;
    }
}
