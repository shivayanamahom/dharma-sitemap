# Руководство разработчика

## Структура

- `com_dharmasitemap` — компонент, администрирование, схема, публичная отдача XML.
- `plugins/plg_dharmasitemap_core` — Joomla providers.
- `plugins/plg_dharmasitemap_dharmashop` — необязательная интеграция с магазином.
- `plugins/plg_system_dharmasitemap` — публичный endpoint.
- `plugins/plg_task_dharmasitemap` — задача Joomla Scheduler.
- `plugins/plg_console_dharmasitemap` — команда Joomla Console/CLI `dharmasitemap:build`.
- `build.sh` — сборка вложенных расширений и общего пакета.

## Source API

Плагин в группе `dharmasitemap` подписывается на `onDharmaSitemapCollectSources` и добавляет список объектов `SitemapSourceInterface` к результату события:

```php
$event->addResult([$source]);
```

Источник реализует `getId()`, `getTitle()`, `isAvailable()` и `fetchBatch(?int $afterId, int $limit)`. Порция имеет вид:

```php
[
    'entries' => [
        ['url' => 'https://example.com/page', 'lastmod' => '2026-10-01 12:00:00', 'identity' => 'provider:type:id:language'],
    ],
    'next_cursor' => 123,
]
```

Верните `next_cursor = null`, когда записей больше нет. Используйте keyset-пагинацию по стабильному ID, фильтруйте публикацию и публичный ACL в запросе источника, ограничивайте URL страницами без POST/сессионных данных. Исключения источника останавливают и отклоняют сборку; предыдущая успешная карта остаётся активной.

Глобальный нормализатор оставляет только локальные HTTP(S) адреса, удаляет fragment и tracking query, нормализует путь и порядок оставшихся query-параметров. Уникальные индексы БД по URL и identity защищают от дублей даже при повторных курсорах.

## Локальная сборка

```sh
./build.sh
unzip -tqq dist/pkg_dharmasitemap-0.1.0.zip
```

GitHub Actions запускается на теге `v*`: собирает пакет, вычисляет SHA-256, создаёт GitHub Release и обновляет публичный `updates.xml`. Версию повышайте только при подготовке релиза; версии всех вложенных расширений берутся из `VERSION` во время сборки.
