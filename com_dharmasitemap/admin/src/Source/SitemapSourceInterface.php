<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Source;

\defined('_JEXEC') or die;

/**
 * Поставщик URL для карты сайта.
 *
 * Запись порции (`entries`) содержит:
 * - `url` — абсолютный URL страницы так, как его строит маршрутизатор Joomla;
 * - `lastmod` — дата изменения или null;
 * - `identity` — стабильный ключ сущности `источник:id:язык`;
 * - `robots` — итоговое значение robots страницы (запись → пункт меню → настройки) или null, если оно неизвестно.
 *   Страницы с `noindex` координатор в карту не включает (настройка «Не добавлять страницы с noindex»).
 */
interface SitemapSourceInterface
{
    public function getId(): string;

    public function getTitle(): string;

    /**
     * Порядок обхода: меньше — раньше. При совпадении URL у нескольких источников в карте остаётся запись того, кто идёт раньше,
     * поэтому источники конкретных сущностей идут раньше источника пунктов меню.
     */
    public function getPriority(): int;

    public function isAvailable(): bool;

    /** @return array{entries: list<array{url:string,lastmod:?string,identity:string,robots?:?string}>, next_cursor:?int} */
    public function fetchBatch(?int $afterId, int $limit): array;
}
