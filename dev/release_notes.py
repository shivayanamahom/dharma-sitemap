#!/usr/bin/env python3
"""Текст GitHub Release: раздел версии из CHANGELOG.md и блок быстрой установки по URL."""

import argparse
import re
import sys
from pathlib import Path

REPO = "shivayanamahom/dharma-sitemap"


def changelog_section(text: str, version: str) -> str:
    match = re.search(rf"^## {re.escape(version)}\b[^\n]*\n(.*?)(?=^## |\Z)", text, re.S | re.M)
    if not match:
        sys.exit(f"В CHANGELOG.md нет раздела «## {version}»: добавьте описание релиза перед тегом.")
    # Заголовки раздела в теле релиза — на уровень ниже заголовка «Релиз».
    return re.sub(r"^### ", "#### ", match.group(1).strip(), flags=re.M)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", required=True)
    parser.add_argument("--sha256", required=True)
    parser.add_argument("--changelog", default="CHANGELOG.md")
    args = parser.parse_args()

    tag = f"v{args.version}"
    download = f"https://github.com/{REPO}/releases/download/{tag}/pkg_dharmasitemap-{args.version}.zip"
    latest = f"https://github.com/{REPO}/releases/latest/download/pkg_dharmasitemap.zip"
    body = changelog_section(Path(args.changelog).read_text(encoding="utf-8"), args.version)

    print(f"""### Релиз Dharma Sitemap {tag}

{body}

---

### Быстрая установка / обновление по URL:
```text
{download}
```

1. В админке Joomla: **Система** → **Установка** → **Расширения** → вкладка **«Установить по URL-адресу»**.
2. Вставьте ссылку в поле **«URL пакета установки»** и нажмите **«Проверить и установить»**.

Ссылка на всегда последнюю версию: `{latest}`

После установки новые версии появляются в **Система → Обновление → Расширения**. Дальше: **Компоненты → Dharma Sitemap → «Собрать сейчас»** ([руководство](https://github.com/{REPO}/blob/main/docs/USER_GUIDE.md), [что попадает в карту](https://github.com/{REPO}/blob/main/docs/USER_GUIDE.md#что-попадает-в-карту)).

Требования: Joomla 6, PHP 8.3+, HTTPS. Версия 0.x тестовая: перед обновлением на рабочем сайте сделайте резервную копию.

SHA-256: `{args.sha256}`""")


if __name__ == "__main__":
    main()
