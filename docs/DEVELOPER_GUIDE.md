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

Источник реализует `getId()`, `getTitle()`, `getPriority()`, `isAvailable()` и `fetchBatch(?int $afterId, int $limit)`. Источники обходятся по возрастанию `getPriority()`, затем по `getId()`; при совпадении адреса остаётся запись источника, который шёл раньше (источники сущностей — 10 и 20, пункты меню — 90). Порция имеет вид:

```php
[
    'entries' => [
        ['url' => 'https://example.com/page', 'lastmod' => '2026-10-01 12:00:00', 'identity' => 'provider:type:id:language', 'robots' => 'index, follow'],
    ],
    'next_cursor' => 123,
]
```

Верните `next_cursor = null`, когда записей больше нет. Используйте keyset-пагинацию по стабильному ID, фильтруйте публикацию и публичный ACL в запросе источника, ограничивайте URL страницами без POST/сессионных данных. Исключения источника останавливают и отклоняют сборку; предыдущая успешная карта остаётся активной.

`robots` — итоговое значение robots страницы (запись → пункт меню → настройки) или `null`, если оно неизвестно; записи с `noindex` координатор отбрасывает при включённой настройке. Для расчёта используйте `RobotsPolicy::effective()`, `RobotsPolicy::fromMetadata()` и `MenuRobotsIndex::lookup()`. Для условия доступа берите `PublicAccess::list()` — уровни гостя, а не константу.

Адрес страницы стройте штатным `Route::link('site', …)` так же, как это делает сама страница для canonical: без лишних параметров (для категории — только `id`).

Глобальный нормализатор оставляет только локальные HTTP(S) адреса, удаляет fragment и tracking query, нормализует путь и порядок оставшихся query-параметров. Уникальные индексы БД по URL и identity защищают от дублей даже при повторных курсорах.

## Проверки

```sh
php tests/run.php               # логика без Joomla: robots, нормализация URL, исключения, публикация файлов, очистка
python3 dev/check_languages.py  # ключи ru-RU и en-GB совпадают, используемые ключи существуют
```

Запросы источников и маршруты проверяйте на тестовом сайте: соберите карту и сверьте выборку адресов с кодами ответа и canonical страниц. GitHub Actions (`ci.yml`) на каждый push и pull request проверяет синтаксис PHP, манифесты, языки, тесты и сборку пакета.

## Локальная сборка

```sh
./build.sh
unzip -tqq dist/pkg_dharmasitemap-$(cat VERSION).zip
```

GitHub Actions запускается на теге `v*`: проверяет и собирает пакет, вычисляет SHA-256, создаёт GitHub Release (текст — раздел версии из `CHANGELOG.md` плюс блок быстрой установки по URL, `dev/release_notes.py`; файлы `pkg_dharmasitemap-<версия>.zip` и `pkg_dharmasitemap.zip` для ссылки `releases/latest/download/`) и обновляет публичный `updates.xml`. Перед тегом добавьте в `CHANGELOG.md` раздел `## <версия>`. Версию повышайте только при подготовке релиза; версии всех вложенных расширений берутся из `VERSION` во время сборки.
