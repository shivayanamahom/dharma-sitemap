#!/usr/bin/env python3
"""Проверка языковых файлов: наборы ключей ru-RU и en-GB совпадают, а ключи, на которые ссылается код компонента, существуют."""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
KEY = re.compile(r'^([A-Z][A-Z0-9_]*)\s*=\s*"(.*)"\s*$')
errors = []


def load(path: Path) -> dict:
    keys = {}
    for number, line in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
        line = line.strip()
        if not line or line.startswith(';'):
            continue
        match = KEY.match(line)
        if not match:
            errors.append(f'{path.relative_to(ROOT)}:{number}: строка не в формате КЛЮЧ="значение"')
            continue
        if match.group(1) in keys:
            errors.append(f'{path.relative_to(ROOT)}:{number}: повтор ключа {match.group(1)}')
        keys[match.group(1)] = match.group(2)
    return keys


pairs = {}
for ru in sorted(ROOT.rglob('ru-RU/*.ini')):
    if {'build', 'dist'} & set(ru.relative_to(ROOT).parts):
        continue
    en = Path(str(ru).replace('ru-RU', 'en-GB'))
    if not en.exists():
        errors.append(f'{ru.relative_to(ROOT)}: нет английского файла')
        continue
    left, right = load(ru), load(en)
    for key in sorted(set(left) ^ set(right)):
        errors.append(f'{ru.parent.parent.relative_to(ROOT)}/{ru.name}: ключ {key} есть не в обоих языках')
    pairs[ru] = left

known = set()
for keys in pairs.values():
    known |= set(keys)

sources = [p for p in (ROOT / 'com_dharmasitemap').rglob('*') if p.suffix in {'.php', '.xml'}]
for path in sources:
    for key in set(re.findall(r'COM_DHARMASITEMAP[A-Z0-9_]*', path.read_text(encoding='utf-8'))):
        if key.endswith('_'):
            continue  # динамический ключ, например COM_DHARMASITEMAP_REASON_ + причина
        if key not in known:
            errors.append(f'{path.relative_to(ROOT)}: ключ {key} не определён ни в одном языковом файле')

# Заголовки в меню админки Joomla переводит только .sys.ini: ключи из манифеста должны быть там, иначе в меню виден сам ключ.
for manifest in sorted(ROOT.rglob('*.xml')):
    if {'build', 'dist', '.github'} & set(manifest.relative_to(ROOT).parts):
        continue
    text = manifest.read_text(encoding='utf-8')
    if '<extension' not in text:
        continue
    keys = set(re.findall(r'<menu[^>]*>([A-Z][A-Z0-9_]+)</menu>', text)) | set(re.findall(r'<name>([A-Z][A-Z0-9_]+)</name>', text))
    if not keys:
        continue
    for lang in ('ru-RU', 'en-GB'):
        sys_files = [p for p in ROOT.rglob(f'{lang}/*.sys.ini') if not {'build', 'dist'} & set(p.relative_to(ROOT).parts)
                     and p.parent.parent in (manifest.parent, manifest.parent / 'language', manifest.parent / 'admin' / 'language')]
        defined = set()
        for p in sys_files:
            defined |= set(load(p))
        for key in sorted(keys - defined):
            errors.append(f'{manifest.relative_to(ROOT)}: ключ {key} из манифеста не определён в .sys.ini ({lang})')

if errors:
    print('\n'.join(errors))
    sys.exit(1)
print(f'Языковые файлы в порядке: {len(pairs)} пар, {len(known)} ключей.')
