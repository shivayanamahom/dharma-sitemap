<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Joomla\CMS\Factory;

\defined('_JEXEC') or die;

/** Каталоги и имена файлов карты. Всё, что компонент пишет или удаляет, лежит только в этих местах. */
final class Paths
{
    /** Подмена каталога кэша для тестов без запущенного приложения Joomla. */
    public static ?string $cacheBase = null;

    /** Каталог компонента в Joomla Cache. */
    public static function cacheRoot(): string
    {
        $base = self::$cacheBase ?? (string) Factory::getApplication()->get('cache_path', JPATH_CACHE);

        return rtrim($base, '/\\') . '/com_dharmasitemap';
    }

    public static function buildDirectory(int $buildId): string
    {
        return self::cacheRoot() . '/build-' . $buildId;
    }

    /** Имя статической части карты в корне сайта при нескольких частях. */
    public static function versionedPart(int $buildId, int $part): string
    {
        return 'sitemap-' . $buildId . '-' . $part . '.xml';
    }

    /** Номер сборки из имени версионной части (`sitemap-12-3.xml` → 12) или null для любого другого файла. */
    public static function buildFromVersionedPart(string $filename): ?int
    {
        return preg_match('/^sitemap-(\d+)-\d+\.xml$/', $filename, $match) === 1 ? (int) $match[1] : null;
    }
}
