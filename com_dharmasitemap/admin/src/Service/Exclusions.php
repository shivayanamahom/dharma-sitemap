<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Service;

\defined('_JEXEC') or die;

/** Исключения из настроек: шаблоны пути URL (`*`, `?`) и ключи сущностей `источник:id`. */
final class Exclusions
{
    /**
     * @param list<string> $urlPatterns
     * @param list<string> $entities
     */
    public function __construct(private readonly array $urlPatterns = [], private readonly array $entities = [])
    {
    }

    /** Строка настройки → список (по одному элементу в строке или через запятую, `#` — комментарий). */
    public static function parseList(string $value): array
    {
        $items = preg_split('/[\r\n,]+/', $value) ?: [];

        return array_values(array_filter(
            array_map('trim', $items),
            static fn (string $item): bool => $item !== '' && !str_starts_with($item, '#')
        ));
    }

    public static function fromSettings(string $urlPatterns, string $entities): self
    {
        return new self(self::parseList($urlPatterns), self::parseList($entities));
    }

    public function matches(string $identity, string $url): bool
    {
        $parts = explode(':', $identity, 3);
        $entityKey = \count($parts) > 1 ? $parts[0] . ':' . $parts[1] : $identity;

        if (\in_array($entityKey, $this->entities, true)) {
            return true;
        }

        // Адрес в карте закодирован (%D0%BA…), а шаблоны администратор пишет обычными буквами.
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));

        foreach ($this->urlPatterns as $pattern) {
            $pattern = str_starts_with($pattern, '/') ? $pattern : '/' . $pattern;
            $regex = '~^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '~')) . '$~u';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
