<?php
// Builds /llms.txt from the site's own pages: the sitemap URLs with each page's <title> and meta description.
// Nothing is invented: the intro comes from the caller, every link line from the page itself.
declare(strict_types=1);

function llms_page(string $pub, string $site, string $url): ?array {
    $path = (string)parse_url($url, PHP_URL_PATH);
    $f = rtrim($pub . rawurldecode($path), '/');
    $f = is_dir($f) ? "$f/index.html" : $f;
    if (!is_file($f)) return null;
    $h = (string)file_get_contents($f, false, null, 0, 400000);
    if (preg_match('#<meta name="robots" content="[^"]*noindex#i', $h)) return null;
    $t = preg_match('#<title>(.*?)</title>#su', $h, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    $d = preg_match('#<meta name="description" content="([^"]*)"#u', $h, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    return $t === '' ? null : ['url' => $url, 'title' => preg_replace('/\s+/u', ' ', $t), 'desc' => preg_replace('/\s+/u', ' ', $d)];
}

/** @param array<string, string> $groups  path regex => section title, first match wins; unmatched go to the last section */
function llms_build(string $pub, string $site, array $sitemaps, string $intro, array $groups, int $maxPerGroup = 80): string {
    $urls = [];
    foreach ($sitemaps as $sm) {
        $x = (string)@file_get_contents($pub . $sm);
        if (preg_match_all('#<loc>([^<]+)</loc>#', $x, $m)) foreach ($m[1] as $u) if (str_starts_with($u, $site)) $urls[$u] = 1;
    }
    $sec = array_fill_keys(array_values($groups), []);
    foreach (array_keys($urls) as $u) {
        $p = llms_page($pub, $site, $u);
        if (!$p) continue;
        $path = (string)parse_url($u, PHP_URL_PATH);
        foreach ($groups as $re => $name) if (preg_match($re, $path)) { $sec[$name][] = $p; break; }
    }
    $out = rtrim($intro) . "\n";
    foreach ($sec as $name => $list) {
        if (!$list) continue;
        $out .= "\n## $name\n\n";
        foreach (array_slice($list, 0, $maxPerGroup) as $p)
            $out .= '- [' . str_replace(['[', ']'], ['(', ')'], $p['title']) . '](' . $p['url'] . ')' . ($p['desc'] !== '' ? ': ' . $p['desc'] : '') . "\n";
    }
    return $out;
}
