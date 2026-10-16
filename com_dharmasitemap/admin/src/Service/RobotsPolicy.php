<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

\defined('_JEXEC') or die;

/** Разбор значений robots: что считать запретом индексации и как выбрать итоговое значение. */
final class RobotsPolicy
{
    /** `noindex` или `none` (равно `noindex, nofollow`) в любом регистре и порядке директив. */
    public static function isNoindex(?string $robots): bool
    {
        if ($robots === null || trim($robots) === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', strtolower($robots)) ?: [] as $directive) {
            if ($directive === 'noindex' || $directive === 'none') {
                return true;
            }
        }

        return false;
    }

    /** Первое непустое значение: запись → пункт меню → настройки по умолчанию. */
    public static function effective(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** Значение `robots` из JSON-поля `metadata` записи Joomla (пусто, если ключа нет или JSON повреждён). */
    public static function fromMetadata(?string $metadata): ?string
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        $data = json_decode($metadata, true);

        return \is_array($data) && isset($data['robots']) && \is_string($data['robots']) && trim($data['robots']) !== '' ? trim($data['robots']) : null;
    }

    /** Значение `robots` из JSON параметров пункта меню или записи. */
    public static function fromParams(?string $params): ?string
    {
        return self::fromMetadata($params);
    }
}
