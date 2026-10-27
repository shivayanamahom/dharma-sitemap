<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

use Dharma\Component\DharmaSitemap\Administrator\Event\CollectSourcesEvent;
use Dharma\Component\DharmaSitemap\Administrator\Source\SitemapSourceInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class SitemapBuilder
{
    /** Порция, которую источник отдаёт за один вызов. */
    private const BATCH_SIZE = 500;
    /** Порция строк при записи XML (ключевая выборка по id, без OFFSET). */
    private const WRITE_BATCH = 2000;
    /** Запас до лимита протокола в 50 МБ несжатого XML. */
    private const PART_BYTES = 47 * 1024 * 1024;
    private const LOCK_MINUTES = 30;
    private const REASONS = ['noindex', 'pattern', 'nonlocal', 'invalid', 'duplicate'];

    private int $partSize = 45000;
    private Exclusions $exclusions;

    public function __construct(private readonly DatabaseInterface $db)
    {
        $this->exclusions = new Exclusions();
    }

    public function build(): array
    {
        $params = ComponentHelper::getParams('com_dharmasitemap');
        $this->partSize = max(1000, min(50000, (int) $params->get('part_size', 45000)));
        $this->exclusions = Exclusions::fromSettings((string) $params->get('exclude_url_patterns', ''), (string) $params->get('exclude_entities', ''));
        $respectNoindex = (int) $params->get('respect_noindex', 1) === 1;
        $keepBuilds = (int) $params->get('keep_builds', 3);
        $baseUrl = (string) $params->get('sitemap_base_url', '');
        $tracking = (string) $params->get('tracking_parameters', '');
        $stripPath = $this->consolePrefix($baseUrl);

        // В долгоживущем процессе (отложенный cron Scheduler) кэши прошлой сборки устарели.
        PublicAccess::reset();
        MenuRobotsIndex::reset();
        $this->ensureTables();
        $lockToken = $this->acquireLock();
        $db = $this->db;
        $buildId = 0;
        $transactionStarted = false;

        try {
            // Сборка большого каталога идёт дольше обычного лимита времени PHP и не должна обрываться закрытой вкладкой.
            if (\function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            @ignore_user_abort(true);
            PluginHelper::importPlugin('dharmasitemap');

            $buildId = $this->createBuild();
            $sources = $this->collectSources();
            $hosts = $this->localHosts($baseUrl);
            $total = 0;
            $duplicates = 0;
            $excludedTotals = array_fill_keys(self::REASONS, 0);
            $sourceReport = [];

            foreach ($sources as $source) {
                if (!$source->isAvailable()) {
                    $sourceReport[] = ['id' => $source->getId(), 'source' => $source->getTitle(), 'count' => 0, 'status' => 'unavailable', 'excluded' => []];
                    continue;
                }

                $excluded = array_fill_keys(self::REASONS, 0);
                $count = 0;
                $cursor = null;

                try {
                    do {
                        $batch = $source->fetchBatch($cursor, self::BATCH_SIZE);
                        $rows = [];

                        foreach ($batch['entries'] as $entry) {
                            $reason = $this->reject($entry, $hosts, $respectNoindex);
                            $url = $reason === null ? UrlNormalizer::normalize($entry['url'], $baseUrl, $tracking, $stripPath) : null;

                            if ($reason === null && $url === null) {
                                $reason = 'invalid';
                            }

                            if ($reason === null && $this->exclusions->matches($entry['identity'], $url)) {
                                $reason = 'pattern';
                            }

                            if ($reason !== null) {
                                $excluded[$reason]++;
                                continue;
                            }

                            $rows[] = [$url, $source->getId(), $entry['identity'], $entry['lastmod'] ?? null, $entry['title'] ?? null, $entry['description'] ?? null, $entry['section'] ?? null];
                        }

                        $inserted = $this->insertUrls($buildId, $rows);
                        $count += $inserted;
                        $excluded['duplicate'] += \count($rows) - $inserted;
                        $this->touchLock($lockToken);
                        $cursor = $batch['next_cursor'];
                    } while ($cursor !== null);
                } catch (\Throwable $error) {
                    throw new \RuntimeException('Источник «' . $source->getTitle() . '»: ' . $error->getMessage(), 0, $error);
                }

                $total += $count;
                $duplicates += $excluded['duplicate'];

                foreach ($excluded as $reason => $number) {
                    $excludedTotals[$reason] += $number;
                }

                $sourceReport[] = ['id' => $source->getId(), 'source' => $source->getTitle(), 'count' => $count, 'status' => 'ok', 'excluded' => array_filter($excluded)];
            }

            if ($total < 1) {
                throw new \RuntimeException('Источники не вернули публичных URL для индексации'
                    . ($excludedTotals['noindex'] > 0 ? ' (' . $excludedTotals['noindex'] . ' страниц закрыты noindex: проверьте robots в общих настройках Joomla и в настройках страниц)' : '')
                    . '. Проверьте меню и включённые расширения.');
            }

            $base = $this->siteBase($baseUrl);
            $parts = $this->writeParts($buildId, $base);
            $this->publishStaticFiles($buildId, $parts, $base, Paths::buildDirectory($buildId));
            $llms = $this->publishLlms($buildId, $params);
            $report = ['sources' => $sourceReport, 'excluded' => array_filter($excludedTotals), 'llms' => $llms];

            $db->transactionStart();
            $transactionStarted = true;
            $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_state'))
                ->set($db->quoteName('active_build_id') . ' = ' . $buildId)->where($db->quoteName('id') . ' = 1'))->execute();
            $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_builds'))
                ->set($db->quoteName('status') . ' = ' . $db->quote('ready'))
                ->set($db->quoteName('finished_at') . ' = ' . $db->quote(gmdate('Y-m-d H:i:s')))
                ->set($db->quoteName('url_count') . ' = ' . $total)
                ->set($db->quoteName('duplicate_count') . ' = ' . $duplicates)
                ->set($db->quoteName('report') . ' = ' . $db->quote(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))
                ->where($db->quoteName('id') . ' = ' . $buildId))->execute();
            $db->transactionCommit();
            $transactionStarted = false;

            // Очистка не должна ронять уже опубликованную сборку.
            $cleanup = ['builds' => 0, 'urls' => 0, 'files' => 0];

            try {
                $cleanup = (new Retention($db))->run($keepBuilds, $buildId);
            } catch (\Throwable) {
                // Следующая сборка повторит очистку.
            }

            return [
                'build_id' => $buildId, 'url_count' => $total, 'duplicate_count' => $duplicates, 'parts' => $parts,
                'sources' => $sourceReport, 'excluded' => $report['excluded'], 'llms' => $llms, 'cleanup' => $cleanup,
            ];
        } catch (\Throwable $error) {
            if ($transactionStarted) {
                try {
                    $db->transactionRollback();
                } catch (\Throwable) {
                    // Preserve the build error if the driver cannot roll back.
                }
            }

            if ($buildId > 0) {
                try {
                    $db->setQuery($db->createQuery()->update($db->quoteName('#__dharmasitemap_builds'))
                        ->set($db->quoteName('status') . ' = ' . $db->quote('failed'))
                        ->set($db->quoteName('finished_at') . ' = ' . $db->quote(gmdate('Y-m-d H:i:s')))
                        ->set($db->quoteName('error') . ' = ' . $db->quote(mb_substr($error->getMessage(), 0, 1000)))
                        ->where($db->quoteName('id') . ' = ' . $buildId))->execute();
                } catch (\Throwable) {
                    // The original error is more useful than a failure to record it.
                }
            }

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

    /**
     * Отчёт сборки в едином виде. Сборки версии до 0.3 хранили только список источников.
     *
     * @return array{sources: list<array<string, mixed>>, excluded: array<string, int>, llms: array<string, mixed>}
     */
    public static function readReport(?object $build): array
    {
        $data = $build && !empty($build->report) ? json_decode((string) $build->report, true) : null;

        if (!\is_array($data)) {
            return ['sources' => [], 'excluded' => [], 'llms' => []];
        }

        if (isset($data['sources'])) {
            return ['sources' => (array) $data['sources'], 'excluded' => (array) ($data['excluded'] ?? []), 'llms' => (array) ($data['llms'] ?? [])];
        }

        return ['sources' => array_values($data), 'excluded' => [], 'llms' => []];
    }

    /** @return list<SitemapSourceInterface> */
    private function collectSources(): array
    {
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

        usort($sources, static fn (SitemapSourceInterface $a, SitemapSourceInterface $b): int => [$a->getPriority(), $a->getId()] <=> [$b->getPriority(), $b->getId()]);

        if (!$sources) {
            throw new \RuntimeException('Не зарегистрирован ни один источник URL.');
        }

        return $sources;
    }

    private function createBuild(): int
    {
        $db = $this->db;
        $identity = Factory::getApplication()->getIdentity();
        $db->setQuery($db->createQuery()->insert($db->quoteName('#__dharmasitemap_builds'))
            ->columns($db->quoteName(['status', 'started_at', 'created_by']))
            ->values(implode(', ', [$db->quote('building'), $db->quote(gmdate('Y-m-d H:i:s')), (int) ($identity->id ?? 0)])))->execute();

        return (int) $db->insertid();
    }

    /** Причина, по которой запись не попадает в карту до нормализации, или null. */
    private function reject(array $entry, array $hosts, bool $respectNoindex): ?string
    {
        $host = strtolower((string) parse_url($entry['url'], PHP_URL_HOST));

        if ($host !== '' && $hosts && !\in_array($host, $hosts, true)) {
            return 'nonlocal';
        }

        if ($respectNoindex && RobotsPolicy::isNoindex($entry['robots'] ?? null)) {
            return 'noindex';
        }

        return null;
    }

    /** @return list<string> Хосты сайта: основной адрес Joomla, live_site и канонический адрес из настроек. */
    private function localHosts(string $baseUrl): array
    {
        $hosts = [];

        foreach ([Uri::root(), (string) Factory::getApplication()->get('live_site', ''), $baseUrl] as $address) {
            $host = strtolower((string) parse_url($address, PHP_URL_HOST));

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function ensureTables(): void
    {
        $db = $this->db;
        foreach (['#__dharmasitemap_state', '#__dharmasitemap_builds', '#__dharmasitemap_urls'] as $table) {
            if (!$db->getTableColumns($db->replacePrefix($table), false)) {
                throw new \RuntimeException('Не установлена схема Dharma Sitemap. Переустановите компонент.');
            }
        }

        // Версии до 0.4 хранили в таблице адресов только URL и дату; названия и разделы нужны для llms.txt.
        $columns = $db->getTableColumns($db->replacePrefix('#__dharmasitemap_urls'), false);

        foreach (['title' => 'varchar(255) NULL', 'description' => 'varchar(500) NULL', 'section' => 'varchar(190) NULL'] as $name => $definition) {
            if (!isset($columns[$name])) {
                $db->setQuery('ALTER TABLE ' . $db->quoteName('#__dharmasitemap_urls') . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $definition)->execute();
            }
        }
    }

    /**
     * Корень пути, который надо убрать из адресов. В консоли Joomla строит ссылки с технической подстановкой вместо домена
     * и каталога сайта, поэтому сборка из командной строки без канонического адреса невозможна.
     */
    private function consolePrefix(string $baseUrl): string
    {
        if (!Factory::getApplication() instanceof \Joomla\CMS\Application\ConsoleApplication) {
            return '';
        }

        if ($baseUrl === '') {
            throw new \RuntimeException('Сборка из командной строки требует «Канонический адрес сайта» в настройках карты: консоль не знает домен сайта.');
        }

        return rtrim((string) parse_url(Uri::root(), PHP_URL_PATH), '/');
    }

    private function acquireLock(): string
    {
        $token = substr(hash('sha256', microtime(true) . ':' . random_bytes(16)), 0, 36);
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName('#__dharmasitemap_state') . ' SET lock_token = ' . $db->quote($token)
            . ', lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE id = 1 AND (lock_until IS NULL OR lock_until < UTC_TIMESTAMP())')->execute();
        if ($db->getAffectedRows() !== 1) {
            throw new \RuntimeException('Сборка уже выполняется. Дождитесь её завершения и повторите запуск.');
        }

        return $token;
    }

    /** Продлевает блокировку, пока сборка жива: упавший процесс освобождает её сам по истечении срока. */
    private function touchLock(string $token): void
    {
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName('#__dharmasitemap_state') . ' SET lock_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE id = 1 AND lock_token = ' . $db->quote($token))->execute();
    }

    private function releaseLock(string $token): void
    {
        $db = $this->db;
        $db->setQuery('UPDATE ' . $db->quoteName('#__dharmasitemap_state') . ' SET lock_token = NULL, lock_until = NULL WHERE id = 1 AND lock_token = ' . $db->quote($token))->execute();
    }

    /**
     * Добавляет адреса одной вставкой. Повтор URL или сущности отсекают уникальные индексы (INSERT IGNORE), поэтому на каждый адрес
     * не нужен отдельный SELECT. Возвращает, сколько строк реально добавлено.
     *
     * @param list<array{0:string,1:string,2:string,3:?string,4:?string,5:?string,6:?string}> $rows url, источник, identity, lastmod, название, описание, раздел
     */
    private function insertUrls(int $buildId, array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $db = $this->db;
        $values = [];

        foreach ($rows as [$url, $source, $identity, $lastmod, $title, $description, $section]) {
            // identity_key — varchar(190): длинный ключ заменяем отпечатком, иначе IGNORE молча обрежет его и склеит разные сущности.
            $identity = strlen($identity) > 190 ? 'h:' . hash('sha256', $identity) : $identity;
            $stamp = self::lastmod($lastmod);
            $values[] = '(' . implode(', ', [
                $buildId, $db->quote(hash('sha256', $url)), $db->quote($url), $db->quote($source), $db->quote($identity),
                $stamp !== null ? $db->quote($stamp) : 'NULL',
                ...array_map(static fn (?string $text): string => $text !== null ? $db->quote($text) : 'NULL', [self::text($title, 255), self::text($description, 500), self::text($section, 190)]),
            ]) . ')';
        }

        $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__dharmasitemap_urls') . ' (build_id, url_hash, url, source_id, identity_key, lastmod, title, description, section) VALUES ' . implode(', ', $values))->execute();

        return (int) $db->getAffectedRows();
    }

    /** Короткий текст для llms.txt: без тегов и переносов, не длиннее лимита столбца; пустое значение — NULL. */
    private static function text(?string $value, int $length): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = mb_substr($text, 0, $length);

        return $text === '' ? null : $text;
    }

    /** Дата изменения в UTC или null: пустые, нулевые и заведомо неверные даты (раньше 1990 года) не публикуются. */
    public static function lastmod(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }

        $time = strtotime($value . (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) ? '' : ' UTC'));

        return $time !== false && $time >= 631152000 ? gmdate('Y-m-d H:i:s', $time) : null;
    }

    /** Корневой адрес для ссылок индекса: канонический из настроек или основной адрес Joomla, без завершающего `/`. */
    private function siteBase(string $baseUrl): string
    {
        return rtrim($baseUrl !== '' ? $baseUrl : Uri::root(), '/');
    }

    /** Пишет XML-части и индекс резервного endpoint в Joomla Cache и возвращает число частей. */
    private function writeParts(int $buildId, string $base): int
    {
        $directory = Paths::buildDirectory($buildId);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог карты сайта в Joomla Cache.');
        }

        $db = $this->db;
        $header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        $parts = 0;
        $handle = null;
        $path = '';
        $inPart = 0;
        $bytes = 0;
        $last = 0;

        $close = function () use (&$handle, &$path): void {
            if ($handle === null) {
                return;
            }

            fwrite($handle, "</urlset>\n");
            fclose($handle);
            $handle = null;

            if (!rename($path . '.tmp', $path)) {
                throw new \RuntimeException('Не удалось активировать часть карты сайта.');
            }
        };

        try {
            while (true) {
                $rows = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'url', 'lastmod']))->from($db->quoteName('#__dharmasitemap_urls'))
                    ->where('build_id = ' . $buildId)->where('id > ' . $last)->order($db->quoteName('id') . ' ASC'), 0, self::WRITE_BATCH)->loadObjectList();

                if (!$rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $last = (int) $row->id;
                    $loc = htmlspecialchars((string) $row->url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $line = '<url><loc>' . $loc . '</loc>' . ($row->lastmod ? '<lastmod>' . gmdate('Y-m-d', strtotime($row->lastmod . ' UTC')) . '</lastmod>' : '') . "</url>\n";

                    if ($handle === null || $inPart >= $this->partSize || $bytes + strlen($line) > self::PART_BYTES) {
                        $close();
                        $parts++;
                        $path = $directory . '/sitemap-' . $parts . '.xml';
                        $handle = fopen($path . '.tmp', 'wb');

                        if (!$handle) {
                            throw new \RuntimeException('Не удалось записать файл карты сайта.');
                        }

                        fwrite($handle, $header);
                        $inPart = 0;
                        $bytes = strlen($header);
                    }

                    fwrite($handle, $line);
                    $inPart++;
                    $bytes += strlen($line);
                }
            }

            $close();
        } catch (\Throwable $error) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            throw $error;
        }

        $this->writeCacheIndex($directory, $parts, $base, $buildId);

        return $parts;
    }

    /** Индекс резервного Joomla endpoint (используется, пока физический sitemap.xml недоступен). */
    private function writeCacheIndex(string $directory, int $parts, string $base, int $buildId): void
    {
        $indexPath = $directory . '/sitemap-index.xml';
        $handle = fopen($indexPath . '.tmp', 'wb');

        if (!$handle) {
            throw new \RuntimeException('Не удалось записать индекс карты сайта.');
        }

        fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
        $stamp = gmdate('Y-m-d\TH:i:s\Z');

        for ($part = 1; $part <= $parts; $part++) {
            $loc = htmlspecialchars($base . '/index.php?option=com_dharmasitemap&view=sitemap&format=xml&part=' . $part . '&build=' . $buildId, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            fwrite($handle, '<sitemap><loc>' . $loc . '</loc><lastmod>' . $stamp . "</lastmod></sitemap>\n");
        }

        fwrite($handle, "</sitemapindex>\n");
        fclose($handle);
        rename($indexPath . '.tmp', $indexPath);
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

        // Одна часть — обычный urlset прямо в /sitemap.xml; индекс нужен, только когда файлов несколько.
        if ($parts <= 1) {
            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $marker . "\n";
            $partContents = file_get_contents($cacheDirectory . '/sitemap-1.xml');

            if ($partContents === false) {
                throw new \RuntimeException('Не удалось прочитать собранную карту сайта.');
            }

            $xml .= preg_replace('/^<\?xml[^>]*\?>\s*/', '', $partContents, 1) ?? $partContents;
            $this->replaceAtomically($root, $indexPath, $xml);

            return;
        }

        $locations = [];

        for ($part = 1; $part <= $parts; $part++) {
            $filename = Paths::versionedPart($buildId, $part);
            $target = $root . '/' . $filename;

            if (is_link($target) || file_exists($target)) {
                throw new \RuntimeException('Целевой файл карты уже существует; безопасная публикация остановлена: ' . $filename);
            }

            $temporary = tempnam($root, '.dharmasitemap-part-');

            if ($temporary === false || !copy($cacheDirectory . '/sitemap-' . $part . '.xml', $temporary) || !chmod($temporary, 0644) || !rename($temporary, $target)) {
                if (\is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }

                throw new \RuntimeException('Не удалось опубликовать статическую часть карты: ' . $filename);
            }

            $locations[] = $base . '/' . $filename;
        }

        $stamp = gmdate('Y-m-d\TH:i:s\Z');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" . $marker . "\n<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($locations as $location) {
            $xml .= '<sitemap><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $stamp . "</lastmod></sitemap>\n";
        }

        $this->replaceAtomically($root, $indexPath, $xml . "</sitemapindex>\n");
    }

    /**
     * Публикует `/llms.txt` в корне сайта по тем же адресам, что вошли в карту. Ошибка здесь не отменяет карту: она попадает в отчёт.
     *
     * @return array{status:string,count?:int,message?:string}
     */
    private function publishLlms(int $buildId, object $params): array
    {
        if ((int) $params->get('llms_enabled', 0) !== 1) {
            return ['status' => 'off'];
        }

        try {
            $db = $this->db;
            $exclusions = Exclusions::fromSettings((string) $params->get('llms_exclude_url_patterns', ''), '');
            $items = [];
            $last = 0;

            while (true) {
                $rows = $db->setQuery($db->createQuery()->select($db->quoteName(['id', 'url', 'identity_key', 'title', 'description', 'section']))->from($db->quoteName('#__dharmasitemap_urls'))
                    ->where('build_id = ' . $buildId)->where('id > ' . $last)->order($db->quoteName('id') . ' ASC'), 0, self::WRITE_BATCH)->loadObjectList();

                if (!$rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $last = (int) $row->id;

                    if (!$exclusions->matches((string) $row->identity_key, (string) $row->url)) {
                        $items[] = ['url' => (string) $row->url, 'title' => $row->title, 'description' => $row->description, 'section' => $row->section];
                    }
                }
            }

            $lines = static fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
            $rename = [];

            foreach ($lines((string) $params->get('llms_rename_sections', '')) as $line) {
                if (str_contains($line, '=')) {
                    [$from, $to] = array_map('trim', explode('=', $line, 2));

                    if ($from !== '' && $to !== '') {
                        $rename[$from] = $to;
                    }
                }
            }

            $text = LlmsTxtBuilder::build([
                'title'     => trim((string) $params->get('llms_title', '')) ?: (string) Factory::getApplication()->get('sitename', 'Site'),
                'summary'   => (string) $params->get('llms_summary', ''),
                'details'   => (string) $params->get('llms_details', ''),
                'rename'    => $rename,
                'order'     => $lines((string) $params->get('llms_section_order', '')),
                'max_links' => (int) $params->get('llms_max_links', 300),
            ], $items);

            $root = rtrim(JPATH_ROOT, '/\\');
            $target = $root . '/llms.txt';

            if (is_link($target)) {
                throw new \RuntimeException('Файл llms.txt является символической ссылкой; публикация остановлена.');
            }

            if (is_file($target)) {
                $existing = (string) file_get_contents($target);

                if (!str_contains($existing, LlmsTxtBuilder::MARKER)) {
                    throw new \RuntimeException('В корне уже есть чужой llms.txt. Компонент не будет его перезаписывать.');
                }
            }

            $this->replaceAtomically($root, $target, $text);

            return ['status' => 'ok', 'count' => substr_count($text, "\n- [")];
        } catch (\Throwable $error) {
            return ['status' => 'error', 'message' => mb_substr($error->getMessage(), 0, 300)];
        }
    }

    /** Записывает файл через временный и заменяет целевой одним rename: посетители видят либо старую карту, либо новую. */
    private function replaceAtomically(string $directory, string $target, string $contents): void
    {
        $temporary = tempnam($directory, '.dharmasitemap-index-');

        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
            || !chmod($temporary, 0644) || !rename($temporary, $target)) {
            if (\is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw new \RuntimeException('Не удалось атомарно опубликовать статический файл sitemap.xml.');
        }
    }
}
