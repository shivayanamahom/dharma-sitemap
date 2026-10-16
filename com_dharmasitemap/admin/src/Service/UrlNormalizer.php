<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

\defined('_JEXEC') or die;

final class UrlNormalizer
{
    private const TRACKING = ['fbclid', 'gclid', 'yclid', 'dclid', 'msclkid', 'ref', 'source'];

    public static function normalize(string $url, string $baseUrl = '', string $trackingParameters = ''): ?string
    {
        $parts = parse_url(trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $path = preg_replace('~/+~', '/', $parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        parse_str($parts['query'] ?? '', $query);

        $configured = array_filter(array_map(static fn (string $key): string => strtolower(trim($key)), preg_split('/[,\\s]+/', $trackingParameters) ?: []));
        foreach (array_keys($query) as $key) {
            if (str_starts_with(strtolower((string) $key), 'utm_') || in_array(strtolower((string) $key), array_merge(self::TRACKING, $configured), true)) {
                unset($query[$key]);
            }
        }

        ksort($query, SORT_STRING);
        $scheme = strtolower($parts['scheme']);
        $authority = strtolower($parts['host']);
        $base = $baseUrl !== '' ? parse_url($baseUrl) : false;
        if ($base && isset($base['scheme'], $base['host']) && in_array(strtolower($base['scheme']), ['http', 'https'], true) && ($base['path'] ?? '/') === '/') {
            $scheme = strtolower($base['scheme']);
            $authority = strtolower($base['host']);
            if (isset($base['port']) && !in_array((int) $base['port'], [80, 443], true)) {
                $authority .= ':' . (int) $base['port'];
            }
        } elseif (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            $authority .= ':' . (int) $parts['port'];
        }

        return $scheme . '://' . $authority . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
