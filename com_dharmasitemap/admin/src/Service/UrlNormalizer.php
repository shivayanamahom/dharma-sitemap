<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

\defined('_JEXEC') or die;

final class UrlNormalizer
{
    /** Метки рекламы и аналитики: удаляются всегда; `*` в конце — любое продолжение имени. */
    private const TRACKING = ['utm_*', '_ym_*', 'fbclid', 'gclid', 'yclid', 'ysclid', 'dclid', 'msclkid', '_openstat', 'erid'];

    public static function normalize(string $url, string $baseUrl = '', string $trackingParameters = ''): ?string
    {
        // Кодируем до parse_url: он заменяет часть байтов UTF-8 (управляющие символы C1) на «_» и портит кириллические адреса.
        $parts = parse_url(self::encode(trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        if (!$parts || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $path = preg_replace('~/+~', '/', $parts['path'] ?? '/') ?? '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $path = self::encode($path === '' ? '/' : $path);

        $patterns = self::patterns($trackingParameters);
        $pieces = [];

        foreach (explode('&', (string) ($parts['query'] ?? '')) as $index => $piece) {
            if ($piece === '') {
                continue;
            }

            $name = strtolower(urldecode(explode('=', $piece, 2)[0]));
            $bracket = strpos($name, '[');
            $top = $bracket === false ? $name : substr($name, 0, $bracket);

            if ($top === '' || self::matches($top, $patterns)) {
                continue;
            }

            $pieces[] = [$name, $index, self::encode($piece)];
        }

        // Порядок параметров не должен менять адрес: сортируем по имени, равные имена сохраняют исходный порядок.
        usort($pieces, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $query = implode('&', array_column($pieces, 2));

        $scheme = strtolower($parts['scheme']);
        $authority = strtolower($parts['host']);
        $base = $baseUrl !== '' ? parse_url($baseUrl) : false;

        if ($base && isset($base['scheme'], $base['host']) && \in_array(strtolower($base['scheme']), ['http', 'https'], true) && ($base['path'] ?? '/') === '/') {
            $scheme = strtolower($base['scheme']);
            $authority = strtolower($base['host']);

            if (isset($base['port']) && !\in_array((int) $base['port'], [80, 443], true)) {
                $authority .= ':' . (int) $base['port'];
            }
        } elseif (isset($parts['port']) && !\in_array((int) $parts['port'], [80, 443], true)) {
            $authority .= ':' . (int) $parts['port'];
        }

        return $scheme . '://' . $authority . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Кодирует то, что нельзя оставлять в адресе карты (не ASCII, пробелы, кавычки, скобки уголков). Уже закодированные
     * последовательности `%XX` и разрешённые символы адреса остаются как есть.
     */
    private static function encode(string $value): string
    {
        return (string) preg_replace_callback('/[^\x21-\x7E]|["<>\\\\^`{|}]/', static fn (array $match): string => rawurlencode($match[0]), $value);
    }

    /** @return list<string> */
    private static function patterns(string $configured): array
    {
        $items = array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            preg_split('/[,\s]+/', $configured) ?: []
        ));

        return array_values(array_unique(array_merge(self::TRACKING, $items)));
    }

    /** Имя совпадает с шаблоном: `имя` — точно, `имя*` — начало, `*имя` — конец, `*имя*` — вхождение. */
    private static function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $needle = trim($pattern, '*');

            if ($needle === '') {
                continue;
            }

            $starts = str_starts_with($pattern, '*');
            $ends = str_ends_with($pattern, '*');

            if (($starts && $ends && str_contains($name, $needle))
                || ($ends && !$starts && str_starts_with($name, $needle))
                || ($starts && !$ends && str_ends_with($name, $needle))
                || (!$starts && !$ends && $name === $needle)) {
                return true;
            }
        }

        return false;
    }
}
