<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Joomla\CMS\Access\Access;

\defined('_JEXEC') or die;

/** Уровни доступа, доступные гостю: страница попадает в карту, только если открыта всем посетителям. */
final class PublicAccess
{
    /** @var list<int>|null */
    private static ?array $levels = null;

    /** @return list<int> */
    public static function levels(): array
    {
        if (self::$levels === null) {
            $levels = array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels(0))));
            // Уровень Public по умолчанию имеет id 1; если у гостя нет уровней, оставляем его, чтобы запрос не стал пустым.
            self::$levels = $levels ?: [1];
        }

        return self::$levels;
    }

    /** Список уровней для `IN (...)`. */
    public static function list(): string
    {
        return implode(',', self::levels());
    }

    public static function reset(): void
    {
        self::$levels = null;
    }
}
