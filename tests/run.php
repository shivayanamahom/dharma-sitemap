<?php
/**
 * Тесты логики без Joomla и без зависимостей: `php tests/run.php`.
 * Запросы к БД и маршрутизатор проверяются на тестовом сайте; здесь — чистая логика, разбор URL, исключения,
 * публикация файлов и очистка.
 */
declare(strict_types=1);

const _JEXEC = 1;

$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/dharma-sitemap-tests-' . getmypid();
mkdir($sandbox . '/root', 0777, true);
mkdir($sandbox . '/cache', 0777, true);
define('JPATH_ROOT', $sandbox . '/root');
define('JPATH_CACHE', $sandbox . '/cache');

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Dharma\\Component\\DharmaSitemap\\Administrator\\';

    if (str_starts_with($class, $prefix)) {
        $file = $root . '/com_dharmasitemap/admin/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        is_file($file) && require $file;
    }
});

use Dharma\Component\DharmaSitemap\Administrator\Service\Exclusions;
use Dharma\Component\DharmaSitemap\Administrator\Service\MenuViewPolicy;
use Dharma\Component\DharmaSitemap\Administrator\Service\Paths;
use Dharma\Component\DharmaSitemap\Administrator\Service\Retention;
use Dharma\Component\DharmaSitemap\Administrator\Service\RobotsPolicy;
use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Dharma\Component\DharmaSitemap\Administrator\Service\UrlNormalizer;

Paths::$cacheBase = JPATH_CACHE;

$failures = 0;
$checks = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL: $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
    }
}

function throws(string $name, callable $call, string $contains): void
{
    global $failures, $checks;
    $checks++;

    try {
        $call();
        $failures++;
        fwrite(STDERR, "FAIL: $name (no exception)\n");
    } catch (\Throwable $error) {
        if (!str_contains($error->getMessage(), $contains)) {
            $failures++;
            fwrite(STDERR, "FAIL: $name (message: {$error->getMessage()})\n");
        }
    }
}

function rrmdir(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $item) {
        rrmdir($path . '/' . $item);
    }

    is_dir($path) && rmdir($path);
}

function clearRoot(): void
{
    foreach (array_diff(scandir(JPATH_ROOT) ?: [], ['.', '..']) as $item) {
        rrmdir(JPATH_ROOT . '/' . $item);
    }
}

// ---------------------------------------------------------------- RobotsPolicy
check('noindex plain', RobotsPolicy::isNoindex('noindex'), true);
check('noindex with follow', RobotsPolicy::isNoindex('noindex, follow'), true);
check('noindex any case and order', RobotsPolicy::isNoindex('Follow,NoIndex'), true);
check('none means noindex', RobotsPolicy::isNoindex('none'), true);
check('index follow', RobotsPolicy::isNoindex('index, follow'), false);
check('nofollow only is indexable', RobotsPolicy::isNoindex('index, nofollow'), false);
check('empty', RobotsPolicy::isNoindex(''), false);
check('null', RobotsPolicy::isNoindex(null), false);
check('substring is not a directive', RobotsPolicy::isNoindex('noindexed'), false);
check('effective picks first non-empty', RobotsPolicy::effective(null, '  ', 'index, follow', 'noindex'), 'index, follow');
check('effective all empty', RobotsPolicy::effective(null, ''), null);
check('metadata robots', RobotsPolicy::fromMetadata('{"robots":"noindex, follow","author":"x"}'), 'noindex, follow');
check('metadata empty robots', RobotsPolicy::fromMetadata('{"robots":""}'), null);
check('metadata broken json', RobotsPolicy::fromMetadata('{broken'), null);
check('metadata null', RobotsPolicy::fromMetadata(null), null);

// ---------------------------------------------------------------- UrlNormalizer
$n = static fn (string $url, string $base = '', string $tracking = ''): ?string => UrlNormalizer::normalize($url, $base, $tracking);
check('plain url kept', $n('https://example.com/shop/item'), 'https://example.com/shop/item');
check('host lowercased, trailing slash removed', $n('https://EXAMPLE.com/Shop/'), 'https://example.com/Shop');
check('root stays root', $n('https://example.com/'), 'https://example.com/');
check('empty path becomes root', $n('https://example.com'), 'https://example.com/');
check('duplicate slashes collapsed', $n('https://example.com//a///b'), 'https://example.com/a/b');
check('fragment dropped', $n('https://example.com/a#top'), 'https://example.com/a');
check('utm_* removed', $n('https://example.com/a?utm_source=x&utm_medium=y&id=5'), 'https://example.com/a?id=5');
check('click ids removed', $n('https://example.com/a?gclid=1&fbclid=2&yclid=3&_ym_uid=9&id=5'), 'https://example.com/a?id=5');
check('all params removed leaves clean url', $n('https://example.com/a?utm_source=x'), 'https://example.com/a');
check('query sorted by name', $n('https://example.com/a?b=2&a=1'), 'https://example.com/a?a=1&b=2');
check('array params keep raw form', $n('https://example.com/a?filter[price][min]=0&sort=asc'), 'https://example.com/a?filter[price][min]=0&sort=asc');
check('param with dot is not mangled', $n('https://example.com/a?price.min=1'), 'https://example.com/a?price.min=1');
check('configured exact name', $n('https://example.com/a?ref=1&id=2', '', 'ref'), 'https://example.com/a?id=2');
check('configured prefix wildcard', $n('https://example.com/a?mc_cid=1&mc_eid=2&id=2', '', 'mc_*'), 'https://example.com/a?id=2');
check('configured suffix wildcard', $n('https://example.com/a?foo_token=1&id=2', '', '*_token'), 'https://example.com/a?id=2');
check('configured list with commas and spaces', $n('https://example.com/a?x=1&y=2&id=2', '', "x, y\nz"), 'https://example.com/a?id=2');
check('not configured stays', $n('https://example.com/a?ref=1'), 'https://example.com/a?ref=1');
check('html entities decoded', $n('https://example.com/a?id=1&amp;lang=ru'), 'https://example.com/a?id=1&lang=ru');
check('non-ascii path encoded', $n('https://example.com/каталог/товар'), 'https://example.com/%D0%BA%D0%B0%D1%82%D0%B0%D0%BB%D0%BE%D0%B3/%D1%82%D0%BE%D0%B2%D0%B0%D1%80');
check('existing percent encoding kept', $n('https://example.com/a%20b/%D1%82'), 'https://example.com/a%20b/%D1%82');
check('space in path encoded', $n('https://example.com/a b'), 'https://example.com/a%20b');
check('base url replaces scheme and host', $n('http://old.example.com/a?id=1', 'https://new.example.com'), 'https://new.example.com/a?id=1');
check('base url with port', $n('https://old.example.com/a', 'https://new.example.com:8443'), 'https://new.example.com:8443/a');
check('base url with path is ignored', $n('https://old.example.com/a', 'https://new.example.com/sub'), 'https://old.example.com/a');
check('default port dropped', $n('https://example.com:443/a'), 'https://example.com/a');
check('custom port kept', $n('https://example.com:8080/a'), 'https://example.com:8080/a');
check('relative url rejected', $n('/just/a/path'), null);
check('mailto rejected', $n('mailto:a@b.c'), null);
check('javascript rejected', $n('javascript:alert(1)'), null);

// ---------------------------------------------------------------- Exclusions
check('parseList lines, commas, comments', Exclusions::parseList("a\n# note\n b ,c\n\n"), ['a', 'b', 'c']);
$ex = Exclusions::fromSettings("/search/*\n/dharma-shop/tovar-x\ncart?\n", "joomla.content:12\ndharmashop.products:7");
check('path wildcard', $ex->matches('x:1:*', 'https://e.com/search/result/1'), true);
check('exact path', $ex->matches('x:1:*', 'https://e.com/dharma-shop/tovar-x'), true);
check('exact path does not match longer', $ex->matches('x:1:*', 'https://e.com/dharma-shop/tovar-xyz'), false);
check('question mark is one char', $ex->matches('x:1:*', 'https://e.com/cart1'), true);
check('pattern without leading slash', $ex->matches('x:1:*', 'https://e.com/cart1'), true);
check('query is ignored by path pattern', $ex->matches('x:1:*', 'https://e.com/other?search=1'), false);
check('entity key', $ex->matches('joomla.content:12:*', 'https://e.com/a'), true);
check('entity key ignores language part', $ex->matches('joomla.content:12:ru-RU', 'https://e.com/a'), true);
check('other entity', $ex->matches('joomla.content:13:*', 'https://e.com/a'), false);
check('product entity', $ex->matches('dharmashop.products:7:*', 'https://e.com/a'), true);
$cyr = Exclusions::fromSettings('/каталог/*', '');
check('cyrillic pattern matches encoded path', $cyr->matches('x:1:*', 'https://e.com/%D0%BA%D0%B0%D1%82%D0%B0%D0%BB%D0%BE%D0%B3/item'), true);
check('no rules matches nothing', (new Exclusions())->matches('a:1:*', 'https://e.com/a'), false);

// ---------------------------------------------------------------- MenuViewPolicy
$view = static fn (string $link, bool $home = false): string => MenuViewPolicy::classify($link, $home)['verdict'];
foreach (['cart', 'checkout', 'checkoutcomplete', 'favorites', 'order', 'orders', 'payment', 'search'] as $private) {
    check("shop $private is private", $view("index.php?option=com_dharmashop&view=$private"), MenuViewPolicy::PRIVATE);
}
check('login is private', $view('index.php?option=com_users&view=login'), MenuViewPolicy::PRIVATE);
check('profile is private', $view('index.php?option=com_users&view=profile'), MenuViewPolicy::PRIVATE);
check('finder search is private', $view('index.php?option=com_finder&view=search'), MenuViewPolicy::PRIVATE);
check('article form is private', $view('index.php?option=com_content&view=form&layout=edit'), MenuViewPolicy::PRIVATE);
foreach ([['com_content', 'article'], ['com_content', 'category'], ['com_dharmashop', 'product'], ['com_dharmashop', 'category'], ['com_dharmashop', 'brand'], ['com_dharmashop', 'badge'], ['com_dharmashop_landing', 'landing'], ['com_contact', 'contact'], ['com_newsfeeds', 'newsfeed']] as [$component, $name]) {
    check("$component $name is covered by entity sources", $view("index.php?option=$component&view=$name&id=5"), MenuViewPolicy::COVERED);
}
foreach ([['com_dharmashop', 'categories&id=0'], ['com_dharmashop', 'brands&id=0'], ['com_dharmashop', 'badges'], ['com_dharmashop_landing', 'landings'], ['com_content', 'featured'], ['com_content', 'categories&id=2'], ['com_tags', 'tags']] as [$component, $name]) {
    check("$component $name comes from the menu", $view("index.php?option=$component&view=$name"), MenuViewPolicy::INCLUDE);
}
foreach ([['category', 0], ['category', 1], ['brand', 0], ['badge', 1]] as [$name, $rootId]) {
    check("shop $name root (id $rootId) is a list page from the menu", $view("index.php?option=com_dharmashop&view=$name&id=$rootId"), MenuViewPolicy::INCLUDE);
}
check('shop category without id is a list page', $view('index.php?option=com_dharmashop&view=category'), MenuViewPolicy::INCLUDE);
check('shop category with id stays covered', $view('index.php?option=com_dharmashop&view=category&id=52'), MenuViewPolicy::COVERED);
check('content category root is not a shop list', $view('index.php?option=com_content&view=category&id=0'), MenuViewPolicy::COVERED);
check('home article keeps the root address', $view('index.php?option=com_content&view=article&id=3', true), MenuViewPolicy::INCLUDE);
check('home cart is still private', $view('index.php?option=com_dharmashop&view=cart', true), MenuViewPolicy::PRIVATE);
check('link without component is skipped', $view('index.php?view=article'), MenuViewPolicy::SKIP);
check('parsed id', MenuViewPolicy::classify('index.php?option=com_content&view=article&id=42')['id'], 42);

// ---------------------------------------------------------------- lastmod and paths
check('lastmod ISO', SitemapBuilder::lastmod('2026-10-15 10:20:30'), '2026-10-15 10:20:30');
check('lastmod null', SitemapBuilder::lastmod(null), null);
check('lastmod empty', SitemapBuilder::lastmod(''), null);
check('lastmod zero date', SitemapBuilder::lastmod('0000-00-00 00:00:00'), null);
check('lastmod garbage', SitemapBuilder::lastmod('not a date'), null);
check('lastmod before 1990', SitemapBuilder::lastmod('1970-01-01 00:00:00'), null);
check('lastmod with zone converted to UTC', SitemapBuilder::lastmod('2026-10-15T12:00:00+03:00'), '2026-10-15 09:00:00');
check('versioned part number', Paths::buildFromVersionedPart('sitemap-12-3.xml'), 12);
check('plain sitemap is not versioned', Paths::buildFromVersionedPart('sitemap.xml'), null);
check('foreign file is not versioned', Paths::buildFromVersionedPart('sitemap-12-3.xml.bak'), null);
check('versioned part name', Paths::versionedPart(7, 2), 'sitemap-7-2.xml');

// ---------------------------------------------------------------- static publication
$builder = (new ReflectionClass(SitemapBuilder::class))->newInstanceWithoutConstructor();
$publish = new ReflectionMethod(SitemapBuilder::class, 'publishStaticFiles');
$marker = '<!-- Generated by Dharma Sitemap -->';
$makeBuild = static function (int $id, int $parts): void {
    $dir = Paths::buildDirectory($id);
    @mkdir($dir, 0777, true);

    for ($i = 1; $i <= $parts; $i++) {
        file_put_contents($dir . "/sitemap-$i.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n<url><loc>https://e.com/p$i?a=1&amp;b=2</loc></url>\n</urlset>\n");
    }
};

clearRoot();
$makeBuild(1, 1);
$publish->invoke($builder, 1, 1, 'https://e.com', Paths::buildDirectory(1));
$single = (string) file_get_contents(JPATH_ROOT . '/sitemap.xml');
check('single part: marker present', str_contains($single, $marker), true);
check('single part: one xml declaration', substr_count($single, '<?xml'), 1);
check('single part: urlset published', str_contains($single, '<urlset') && str_contains($single, 'https://e.com/p1?a=1&amp;b=2'), true);
check('single part: well formed', simplexml_load_string($single) !== false, true);
check('single part: no temp files left', array_values(array_diff(scandir(JPATH_ROOT) ?: [], ['.', '..'])), ['sitemap.xml']);
check('single part: file mode', substr(sprintf('%o', fileperms(JPATH_ROOT . '/sitemap.xml')), -4), '0644');

// Our own previous file is replaced without complaint.
$makeBuild(2, 1);
$publish->invoke($builder, 2, 1, 'https://e.com', Paths::buildDirectory(2));
check('own file is replaced', is_file(JPATH_ROOT . '/sitemap.xml'), true);

clearRoot();
$makeBuild(3, 3);
$publish->invoke($builder, 3, 3, 'https://e.com', Paths::buildDirectory(3));
$index = simplexml_load_string((string) file_get_contents(JPATH_ROOT . '/sitemap.xml'));
check('multi part: index published', $index !== false && $index->getName() === 'sitemapindex', true);
check('multi part: three entries', count($index->sitemap), 3);
check('multi part: first location', (string) $index->sitemap[0]->loc, 'https://e.com/sitemap-3-1.xml');
check('multi part: lastmod present', (string) $index->sitemap[0]->lastmod !== '', true);
check('multi part: versioned files copied', [is_file(JPATH_ROOT . '/sitemap-3-1.xml'), is_file(JPATH_ROOT . '/sitemap-3-3.xml')], [true, true]);

clearRoot();
file_put_contents(JPATH_ROOT . '/sitemap.xml', '<?xml version="1.0"?><urlset>foreign</urlset>');
$makeBuild(4, 1);
throws('foreign sitemap.xml is never overwritten', fn () => $publish->invoke($builder, 4, 1, 'https://e.com', Paths::buildDirectory(4)), 'чужой sitemap.xml');
check('foreign sitemap.xml untouched', file_get_contents(JPATH_ROOT . '/sitemap.xml'), '<?xml version="1.0"?><urlset>foreign</urlset>');

clearRoot();
file_put_contents($sandbox . '/elsewhere.xml', 'x');
symlink($sandbox . '/elsewhere.xml', JPATH_ROOT . '/sitemap.xml');
throws('symlinked sitemap.xml is refused', fn () => $publish->invoke($builder, 4, 1, 'https://e.com', Paths::buildDirectory(4)), 'символической ссылкой');
check('symlink target untouched', file_get_contents($sandbox . '/elsewhere.xml'), 'x');

clearRoot();
file_put_contents(JPATH_ROOT . '/sitemap-5-2.xml', 'occupied');
$makeBuild(5, 2);
throws('existing versioned part is not overwritten', fn () => $publish->invoke($builder, 5, 2, 'https://e.com', Paths::buildDirectory(5)), 'уже существует');
check('occupied part untouched', file_get_contents(JPATH_ROOT . '/sitemap-5-2.xml'), 'occupied');

// ---------------------------------------------------------------- cache index
clearRoot();
$writeIndex = new ReflectionMethod(SitemapBuilder::class, 'writeCacheIndex');
$makeBuild(6, 2);
$writeIndex->invoke($builder, Paths::buildDirectory(6), 2, 'https://e.com', 6);
$cacheIndex = simplexml_load_string((string) file_get_contents(Paths::buildDirectory(6) . '/sitemap-index.xml'));
check('cache index well formed with escaped ampersands', $cacheIndex !== false && count($cacheIndex->sitemap) === 2, true);
check('cache index location', (string) $cacheIndex->sitemap[1]->loc, 'https://e.com/index.php?option=com_dharmasitemap&view=sitemap&format=xml&part=2&build=6');

// ---------------------------------------------------------------- retention: files
clearRoot();
$retention = (new ReflectionClass(Retention::class))->newInstanceWithoutConstructor();
$removeFiles = new ReflectionMethod(Retention::class, 'removeBuildFiles');
$urlset = '<?xml version="1.0"?><urlset></urlset>';
file_put_contents(JPATH_ROOT . '/sitemap-5-1.xml', $urlset);
file_put_contents(JPATH_ROOT . '/sitemap-5-2.xml', $urlset);
file_put_contents(JPATH_ROOT . '/sitemap-50-1.xml', $urlset);
file_put_contents(JPATH_ROOT . '/sitemap-5-3.xml', 'user file that only looks similar');
file_put_contents(JPATH_ROOT . '/sitemap.xml', $marker . '<urlset></urlset>');
file_put_contents(JPATH_ROOT . '/sitemap-5-1.xml.bak', $urlset);
$makeBuild(5, 2);
mkdir(Paths::buildDirectory(5) . '/nested', 0777, true);
file_put_contents(Paths::buildDirectory(5) . '/nested/x.xml', 'x');
$removed = $removeFiles->invoke($retention, 5);
check('removed count covers cache files and root parts', $removed, 5);
check('own parts of build 5 removed', [is_file(JPATH_ROOT . '/sitemap-5-1.xml'), is_file(JPATH_ROOT . '/sitemap-5-2.xml')], [false, false]);
check('cache dir of build 5 removed', is_dir(Paths::buildDirectory(5)), false);
check('other build kept', is_file(JPATH_ROOT . '/sitemap-50-1.xml'), true);
check('look-alike user file kept', is_file(JPATH_ROOT . '/sitemap-5-3.xml'), true);
check('backup copy kept', is_file(JPATH_ROOT . '/sitemap-5-1.xml.bak'), true);
check('main sitemap never touched', is_file(JPATH_ROOT . '/sitemap.xml'), true);

rrmdir($sandbox);

if ($failures > 0) {
    fwrite(STDERR, "\n$failures of $checks checks failed.\n");
    exit(1);
}

echo "OK: $checks checks passed.\n";
