<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Dharma\Component\DharmaSitemap\Administrator\Event\CollectSourcesEvent;
use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

final class SitemapBuilder
{
    private const BATCH_SIZE = 500;
    private int $partSize = 45000;
    /** @var list<string> */
    private array $excludedUrlPatterns = [];
    /** @var list<string> */
    private array $excludedEntities = [];

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function build(): array
    {
        $params = ComponentHelper::getParams('com_dharmasitemap');
        $this->partSize = max(1000, min(50000, (int) $params->get('part_size', 45000)));
        $this->excludedUrlPatterns = $this->readList((string) $params->get('exclude_url_patterns', ''));
        $this->excludedEntities = $this->readList((string) $params->get('exclude_entities', ''));
        $this->ensureTables();
        $lockToken = $this->acquireLock();
        $db = $this->db;
        $db->setQuery($db->createQuery()->insert($db->quoteName('#__dharmasitemap_builds'))
            ->columns($db->quoteName(['status', 'started_at', 'created_by']))
            ->values(implode(', ', [$db->quote('building'), $db->quote(gmdate('Y-m-d H:i:s')), (int) Factory::getApplication()->getIdentity()->id])))->execute();
        $buildId = (int) $db->insertid();

        $transactionStarted = false;
        try {
            PluginHelper::importPlugin('dharmasitemap');
            $event = new CollectSourcesEvent('onDharmaSitemapCollectSources', []);
            Factory::getApplication()->getDispatcher()->dispatch('onDharmaSitemapCollectSources', $event);
            $sources = [];
            foreach ($event->getArgument('result', []) as $group) {
                foreach ($group as $source) {
                    if ($source instanceof SitemapSourceInterface) {
                        $sources[] = $source;
                    }
                }
            }
            usort($sources, static fn (SitemapSourceInterface $a, SitemapSourceInterface $b): int => strcmp($a->getId(), $b->getId()));
            if (!$sources) {
                throw new \RuntimeException('Не зарегистрирован ни один источник URL.');
            }

            $total = 0;
            $duplicates = 0;
            $sourceReport = [];
            foreach ($sources as $source) {
                if (!$source->isAvailable()) {
                    $sourceReport[] = ['source' => $source->getTitle(), 'count' => 0, 'status' => 'unavailable'];
                    continue;
                }

                $cursor = null;
                $count = 0;
                do {
                    $batch = $source->fetchBatch($cursor, self::BATCH_SIZE);
                    foreach ($batch['entries'] as $entry) {
                        $url = UrlNormalizer::normalize(
                            $entry['url'],
                            (string) $params->get('sitemap_base_url', ''),
                            (string) $params->get('tracking_parameters', '')
                        );
                        if (!$url || !$this->isLocalUrl($url) || $this->isExcluded($entry['identity'], $url)) {
                            continue;
                        }
                        if ($this->insertUrl($buildId, $source->getId(), $entry['identity'], $url, $entry['lastmod'])) {
                            $count++;
                        } else {
                            $duplicates++;
                        }
                    }
                    $cursor = $batch['next_cursor'];
                } while ($cursor !== null);

                $total += $count;
                $sourceReport[] = ['source' => $source->getTitle(), 'count' => $count, 'status' => 'ok'];
            }

            if ($total < 1) {
                throw new \RuntimeException('Источники не вернули публичных URL. Проверьте меню и включённые расширения.');
            }

            $output = $this->writeSitemaps($buildId);
            $db->transactionStart();
            $transactionStarted = true;
            $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_state'))
                ->set($db->quoteName('active_build_id') . ' = ' . $buildId)->where($db->quoteName('id') . ' = 1'))->execute();
            $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_builds'))
                ->set($db->quoteName('status') . ' = ' . $db->quote('ready'))
                ->set($db->quoteName('finished_at') . ' = ' . $db->quote(gmdate('Y-m-d H:i:s')))
                ->set($db->quoteName('url_count') . ' = ' . $total)
                ->set($db->quoteName('duplicate_count') . ' = ' . $duplicates)
                ->set($db->quoteName('report') . ' = ' . $db->quote(json_encode($sourceReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
                ->where($db->quoteName('id') . ' = ' . $buildId))->execute();
            $db->transactionCommit();
            $transactionStarted = false;

            return ['build_id' => $buildId, 'url_count' => $total, 'duplicate_count' => $duplicates, 'parts' => $output, 'sources' => $sourceReport];
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                try {
                    $db->transactionRollback();
                } catch (\Throwable) {
                    // Preserve the build error if the driver cannot roll back.
                }
            }
            $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_builds'))
                ->set($db->quoteName('status') . ' = ' . $db->quote('failed'))
                ->set($db->quoteName('finished_at') . ' = ' . $db->quote(gmdate('Y-m-d H:i:s')))
                ->set($db->quoteName('error') . ' = ' . $db->quote(mb_substr($error->getMessage(), 0, 1000)))
                ->where($db->quoteName('id') . ' = ' . $buildId))->execute();
            throw $error;
        } finally {
            $this->releaseLock($lockToken);
        }
    }

    public function getActiveBuild(): ?object
    {
        $db = $this->db;
        try {
            $query = $db->createQuery()->select('b.*')->from($db->quoteName('#__dharmasitemap_state', 's'))
                ->join('INNER', $db->quoteName('#__dharmasitemap_builds', 'b') . ' ON b.id = s.active_build_id')
                ->where('s.id = 1')->where("b.status = 'ready'");

            return $db->setQuery($query)->loadObject() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureTables(): void
    {
        $db = $this->db;
        foreach (['#__dharmasitemap_state', '#__dharmasitemap_builds', '#__dharmasitemap_urls'] as $table) {
            if (!$db->getTableColumns($db->replacePrefix($table), false)) {
                throw new \RuntimeException('Не установлена схема Dharma Sitemap. Переустановите компонент.');
            }
        }
    }

    private function acquireLock(): string
    {
        $token = \Joomla\CMS\Factory::getApplication()->getSession()->getId();
        $token = substr(hash('sha256', $token . ':' . microtime(true) . ':' . random_bytes(8)), 0, 36);
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName('#__dharmasitemap_state') . ' SET lock_token = ' . $db->quote($token)
            . ', lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = 1 AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())')->execute();
        if ($db->getAffectedRows() !== 1) {
            throw new \RuntimeException('Сборка уже выполняется. Дождитесь её завершения и повторите запуск.');
        }
        return $token;
    }

    private function releaseLock(string $token): void
    {
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName('#__dharmasitemap_state') . ' SET lock_token = NULL, lock_until = NULL WHERE id = 1 AND lock_token = ' . $db->quote($token))->execute();
    }

    private function insertUrl(int $buildId, string $source, string $identity, string $url, ?string $lastmod): bool
    {
        $db = $this->db;
        $urlHash = hash('sha256', $url);
        $existing = $db->createQuery()->select('1')->from($db->quoteName('#__dharmasitemap_urls'))
            ->where($db->quoteName('build_id') . ' = ' . $buildId)
            ->where('(' . $db->quoteName('url_hash') . ' = ' . $db->quote($urlHash)
                . ' OR ' . $db->quoteName('identity_key') . ' = ' . $db->quote($identity) . ')');
        if ($db->setQuery($existing, 0, 1)->loadResult()) {
            return false;
        }

        $query = $db->createQuery()->insert($db->quoteName('#__dharmasitemap_urls'))
            ->columns($db->quoteName(['build_id', 'url_hash', 'url', 'source_id', 'identity_key', 'lastmod']))
            ->values(implode(', ', [
                $buildId,
                $db->quote($urlHash),
                $db->quote($url),
                $db->quote($source),
                $db->quote($identity),
                $lastmod ? $db->quote(gmdate('Y-m-d H:i:s', strtotime($lastmod))) : 'NULL',
            ]));
        $db->setQuery($query)->execute();

        return true;
    }

    private function isLocalUrl(string $url): bool
    {
        $siteHost = parse_url(\Joomla\CMS\Uri\Uri::root(), PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $siteHost && $urlHost && strcasecmp((string) $siteHost, (string) $urlHost) === 0;
    }

    /** @return list<string> */
    private function readList(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), static fn (string $item): bool => $item !== '' && !str_starts_with($item, '#')));
    }

    private function isExcluded(string $identity, string $url): bool
    {
        $identityParts = explode(':', $identity, 3);
        $entityKey = count($identityParts) > 1 ? $identityParts[0] . ':' . $identityParts[1] : $identity;
        if (in_array($entityKey, $this->excludedEntities, true)) {
            return true;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->excludedUrlPatterns as $pattern) {
            $pattern = str_starts_with($pattern, '/') ? $pattern : '/' . $pattern;
            $regex = '~^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '~')) . '$~u';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function writeSitemaps(int $buildId): int
    {
        $directory = rtrim((string) Factory::getApplication()->get('cache_path', JPATH_CACHE), '/\\') . '/com_dharmasitemap/build-' . $buildId;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог карты сайта в Joomla Cache.');
        }
        $db = $this->db;
        $count = (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__dharmasitemap_urls'))->where('build_id = ' . $buildId))->loadResult();
        $parts = (int) ceil($count / $this->partSize);
        $siteBase = (string) ComponentHelper::getParams('com_dharmasitemap')->get('sitemap_base_url', '');
        $base = rtrim($siteBase !== '' ? $siteBase : \Joomla\CMS\Uri\Uri::root(), '/');
        for ($part = 0; $part < $parts; $part++) {
            $name = 'sitemap-' . ($part + 1) . '.xml';
            $path = $directory . '/' . $name;
            $handle = fopen($path . '.tmp', 'wb');
            if (!$handle) {
                throw new \RuntimeException('Не удалось записать файл карты сайта.');
            }
            fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
            $query = $db->createQuery()->select($db->quoteName(['url', 'lastmod']))->from($db->quoteName('#__dharmasitemap_urls'))
                ->where('build_id = ' . $buildId)->order($db->quoteName('id') . ' ASC')->setLimit($this->partSize, $part * $this->partSize);
            foreach ($db->setQuery($query)->loadObjectList() as $row) {
                $loc = htmlspecialchars((string) $row->url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                fwrite($handle, "<url><loc>{$loc}</loc>");
                if ($row->lastmod) {
                    fwrite($handle, '<lastmod>' . gmdate('Y-m-d', strtotime($row->lastmod)) . '</lastmod>');
                }
                fwrite($handle, "</url>\n");
            }
            fwrite($handle, "</urlset>\n");
            fclose($handle);
            if (!rename($path . '.tmp', $path)) {
                throw new \RuntimeException('Не удалось активировать часть карты сайта.');
            }
        }

        $indexPath = $directory . '/sitemap-index.xml';
        $handle = fopen($indexPath . '.tmp', 'wb');
        fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
        for ($part = 1; $part <= $parts; $part++) {
            $loc = htmlspecialchars($base . '/index.php?option=com_dharmasitemap&view=sitemap&format=xml&part=' . $part . '&build=' . $buildId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            fwrite($handle, "<sitemap><loc>{$loc}</loc></sitemap>\n");
        }
        fwrite($handle, "</sitemapindex>\n");
        fclose($handle);
        rename($indexPath . '.tmp', $indexPath);

        $this->publishStaticFiles($buildId, $parts, $base, $directory);

        return $parts;
    }

    private function publishStaticFiles(int $buildId, int $parts, string $base, string $cacheDirectory): void
    {
        $root = rtrim(JPATH_ROOT, '/\\');
        $indexPath = $root . '/sitemap.xml';
        $marker = '<!-- Generated by Dharma Sitemap -->';
        if (is_link($indexPath)) {
            throw new \RuntimeException('Файл sitemap.xml является символической ссылкой; безопасная публикация остановлена.');
        }
        if (is_file($indexPath)) {
            $existingHeader = file_get_contents($indexPath, false, null, 0, 512);
            if ($existingHeader === false || !str_contains($existingHeader, $marker)) {
                throw new \RuntimeException('В корне уже есть чужой sitemap.xml. Компонент не будет его перезаписывать.');
            }
        }

        $locations = [];
        for ($part = 1; $part <= $parts; $part++) {
            $filename = 'sitemap-' . $buildId . '-' . $part . '.xml';
            $target = $root . '/' . $filename;
            if (is_link($target) || file_exists($target)) {
                throw new \RuntimeException('Целевой файл карты уже существует; безопасная публикация остановлена: ' . $filename);
            }
            $source = $cacheDirectory . '/sitemap-' . $part . '.xml';
            $temporary = tempnam($root, '.dharmasitemap-part-');
            if ($temporary === false || !copy($source, $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
                throw new \RuntimeException('Не удалось опубликовать статическую часть карты: ' . $filename);
            }
            $locations[] = rtrim($base, '/') . '/' . $filename;
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $marker . "\n";
        $xml .= "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($locations as $location) {
            $xml .= '<sitemap><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";

        $temporaryIndex = tempnam($root, '.dharmasitemap-index-');
        if ($temporaryIndex === false || file_put_contents($temporaryIndex, $xml, LOCK_EX) !== strlen($xml)
            || !chmod($temporaryIndex, 0644) || !rename($temporaryIndex, $indexPath)) {
            if (is_string($temporaryIndex) && is_file($temporaryIndex)) {
                unlink($temporaryIndex);
            }
            throw new \RuntimeException('Не удалось атомарно опубликовать статический файл sitemap.xml.');
        }
    }
}
