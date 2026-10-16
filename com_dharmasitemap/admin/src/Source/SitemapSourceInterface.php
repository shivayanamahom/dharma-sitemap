<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Source;

\defined('_JEXEC') or die;

interface SitemapSourceInterface
{
    public function getId(): string;

    public function getTitle(): string;

    public function isAvailable(): bool;

    /** @return array{entries: list<array{url:string,lastmod:?string,identity:string}>, next_cursor:?int} */
    public function fetchBatch(?int $afterId, int $limit): array;
}
