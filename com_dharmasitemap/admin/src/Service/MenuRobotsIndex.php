<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Значения robots, заданные в параметрах пунктов меню, по ключу `option:view:id`. Если у страницы нет своего robots, Joomla и Dharma Shop
 * берут его у пункта меню, поэтому источники сущностей спрашивают этот индекс перед настройками по умолчанию.
 */
final class MenuRobotsIndex
{
    /** @var array<string, string>|null */
    private static ?array $index = null;

    public static function lookup(DatabaseInterface $db, string $option, string $view, int $id): ?string
    {
        self::$index ??= self::load($db);

        return self::$index[$option . ':' . $view . ':' . $id] ?? null;
    }

    public static function reset(): void
    {
        self::$index = null;
    }

    /** @return array<string, string> */
    private static function load(DatabaseInterface $db): array
    {
        $query = $db->createQuery()->select($db->quoteName(['link', 'params']))->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('params') . ' LIKE ' . $db->quote('%"robots"%'))
            ->order($db->quoteName('id') . ' ASC');
        $index = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $robots = RobotsPolicy::fromParams($row->params);

            if ($robots === null) {
                continue;
            }

            parse_str((string) parse_url((string) $row->link, PHP_URL_QUERY), $vars);
            $key = ($vars['option'] ?? '') . ':' . ($vars['view'] ?? '') . ':' . (int) ($vars['id'] ?? 0);
            // Если на одну страницу ведёт несколько пунктов, действует первый по номеру.
            $index[$key] ??= $robots;
        }

        return $index;
    }
}
