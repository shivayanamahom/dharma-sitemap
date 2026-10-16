<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Очистка старых сборок: строки адресов в БД, каталоги в Joomla Cache и версионные части в корне сайта.
 * Трогает только данные самого компонента; активная сборка и корневой `sitemap.xml` не удаляются никогда.
 */
final class Retention
{
    private const CHUNK = 5000;
    /**
     * Неудачная или незавершённая сборка старше часа — мусор: блокировка не даёт работать двум сборкам одновременно,
     * поэтому «зависшая» запись принадлежит упавшему процессу.
     */
    private const STALE_SECONDS = 3600;

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @param int $keep Сколько последних успешных сборок оставить (включая активную), не меньше 1.
     *
     * @return array{builds:int, urls:int, files:int}
     */
    public function run(int $keep, int $activeBuildId): array
    {
        $db = $this->db;
        $keep = max(1, $keep);
        $victims = [];

        $ready = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__dharmasitemap_builds'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('ready'))->where($db->quoteName('id') . ' <> ' . $activeBuildId)
            ->order($db->quoteName('id') . ' DESC'))->loadColumn();

        // Активная сборка занимает одно место из `keep`.
        foreach (\array_slice(array_map('intval', $ready), $keep - 1) as $id) {
            $victims[] = $id;
        }

        $stale = $db->setQuery($db->createQuery()->select($db->quoteName('id'))->from($db->quoteName('#__dharmasitemap_builds'))
            ->where($db->quoteName('status') . ' <> ' . $db->quote('ready'))->where($db->quoteName('id') . ' <> ' . $activeBuildId)
            ->where($db->quoteName('started_at') . ' < ' . $db->quote(gmdate('Y-m-d H:i:s', time() - self::STALE_SECONDS))))->loadColumn();

        foreach ($stale as $id) {
            $victims[] = (int) $id;
        }

        $result = ['builds' => 0, 'urls' => 0, 'files' => 0];

        foreach (array_unique($victims) as $id) {
            if ($id < 1 || $id === $activeBuildId) {
                continue;
            }

            $result['urls'] += $this->deleteUrls($id);
            $db->setQuery($db->createQuery()->delete($db->quoteName('#__dharmasitemap_builds'))->where($db->quoteName('id') . ' = ' . $id))->execute();
            $result['files'] += $this->removeBuildFiles($id);
            $result['builds']++;
        }

        return $result;
    }

    private function deleteUrls(int $buildId): int
    {
        $db = $this->db;
        $total = 0;

        do {
            $db->setQuery('DELETE FROM ' . $db->quoteName('#__dharmasitemap_urls') . ' WHERE build_id = ' . $buildId . ' LIMIT ' . self::CHUNK)->execute();
            $affected = (int) $db->getAffectedRows();
            $total += $affected;
        } while ($affected === self::CHUNK);

        return $total;
    }

    private function removeBuildFiles(int $buildId): int
    {
        $removed = 0;
        $directory = Paths::buildDirectory($buildId);

        if (is_dir($directory) && !is_link($directory)) {
            $removed += $this->removeTree($directory);
        }

        $root = rtrim(JPATH_ROOT, '/\\');

        foreach (glob($root . '/sitemap-' . $buildId . '-*.xml') ?: [] as $file) {
            $name = basename($file);

            // Только версионные части именно этой сборки, и только если это действительно наш urlset.
            if (Paths::buildFromVersionedPart($name) !== $buildId || is_link($file) || !is_file($file)) {
                continue;
            }

            $head = (string) file_get_contents($file, false, null, 0, 600);

            if (str_contains($head, '<urlset') && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function removeTree(string $path): int
    {
        $removed = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $removed++;
            }
        }

        @rmdir($path);

        return $removed;
    }
}
