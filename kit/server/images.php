<?php
// Cover photos: free CC0 / public-domain photos from Openverse (no API key needed),
// downloaded to our own host, cropped and converted to WebP.
declare(strict_types=1);

define('COVER_DIR', PUB . '/' . trim((string)site('covers_path', 'blog/assets/covers'), '/'));
define('COVER_URL', SITE . '/' . trim((string)site('covers_path', 'blog/assets/covers'), '/'));

/** Hosts that block image CDNs (e.g. servers in Iran) fetch photos through a small proxy on another server: site.json "image_proxy". */
function image_fetch_url(string $u): string { $p = (string)site('image_proxy', ''); return $p === '' ? $u : $p . rawurlencode($u); }
function openverse_search(string $q, string $sources = ''): array {
    $p = ['q' => $q, 'license' => 'cc0,pdm', 'category' => 'photograph', 'mature' => 'false', 'page_size' => 20];
    if ($sources !== '') $p['source'] = $sources;
    $r = http('https://api.openverse.org/v1/images/?' . http_build_query($p), null, 30);
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !is_array($j)) { logline("openverse '$q' -> {$r['code']}"); return []; }
    return $j['results'] ?? [];
}

const BAD_PHOTO_WORDS = ['christmas', 'xmas', 'santa', 'halloween', 'easter', 'party', 'gift', 'costume', 'wedding', 'bikini', 'nude', 'naked', 'topless',
    'underwear', 'sexy', 'lingerie', 'gun', 'weapon', 'blood', 'cigarette', 'smoking', 'alcohol', 'beer', 'wine', 'cocktail', 'logo', 'second life',
    'mural', 'painting', 'illustration', 'drawing'];
/** Photos whose title or tags contain one of these words are skipped (kit defaults + site.json "bad_photo_words"). */
function bad_photo_re(): string {
    static $re = null;
    return $re ??= '/' . implode('|', array_map(fn($w) => preg_quote(strtolower((string)$w), '/'), array_merge(BAD_PHOTO_WORDS, (array)site('bad_photo_words', [])))) . '/';
}

/** Picks the next unused landscape photo for the article and stores cover + card WebP files. */
function attach_cover(array $a, array &$s, bool $next = false): array {
    @ini_set('memory_limit', '512M');
    $queries = array_values(array_filter(array_map('trim', (array)($a['image_queries'] ?? []))));
    $fallback = (array)site('fallback_image_queries', ['city street', 'person laptop']);
    if (!$queries) $queries = $fallback;
    $variants = [];
    foreach ($queries as $q) {                       // full phrase first, then a shorter 2-word version
        $variants[] = $q;
        $w = preg_split('/\s+/', $q);
        if (count($w) > 2) $variants[] = $w[0] . ' ' . end($w);
    }
    $variants = array_values(array_unique(array_merge($variants, $fallback)));
    $used = $s['used_images'] ?? [];
    $skip = [];
    foreach ($variants as $q) {
        foreach (['stocksnap,rawpixel,nappy', ''] as $src) {
            foreach (openverse_search($q, $src) as $img) {
                $id = (string)($img['id'] ?? '');
                if ($id === '' || in_array($id, $used, true)) { $skip['used'] = ($skip['used'] ?? 0) + 1; continue; }
                $txt = strtolower(($img['title'] ?? '') . ' ' . implode(' ', array_column($img['tags'] ?? [], 'name')));
                if (preg_match(bad_photo_re(), $txt)) { $skip['topic'] = ($skip['topic'] ?? 0) + 1; continue; }
                $w = (int)($img['width'] ?? 0); $h = (int)($img['height'] ?? 0);
                if ($w < 1000 || $h < 600 || $w < $h * 1.25 || $w * $h > 40000000) { $skip['size'] = ($skip['size'] ?? 0) + 1; continue; }
                $file = http(image_fetch_url((string)$img['url']), null, 60);
                if ($file['code'] !== 200 || strlen($file['body']) < 20000) { $skip['download'] = ($skip['download'] ?? 0) + 1; continue; }
                $srcImg = @imagecreatefromstring($file['body']);
                if (!$srcImg) { $skip['decode'] = ($skip['decode'] ?? 0) + 1; continue; }
                $saved = save_cover_files($srcImg, $a['slug']);
                imagedestroy($srcImg);
                if (!$saved) continue;
                $s['used_images'][] = $id;
                $a['cover'] = [
                    'id' => $id, 'url' => COVER_URL . '/' . $a['slug'] . '.webp?v=' . substr($id, 0, 6),
                    'card' => COVER_URL . '/' . $a['slug'] . '-card.webp?v=' . substr($id, 0, 6),
                    'w' => $saved[0], 'h' => $saved[1],
                    'creator' => trim((string)($img['creator'] ?? '')), 'source' => (string)($img['source'] ?? $img['provider'] ?? ''),
                    'page' => (string)($img['foreign_landing_url'] ?? ''), 'license' => strtoupper((string)($img['license'] ?? 'cc0')),
                    'query' => $q,
                ];
                foreach (langs() as $l) if (isset($a[$l])) $a[$l]['cover'] = $a['cover'];
                logline("cover {$a['slug']}: $id ({$a['cover']['source']}) for '$q'");
                return $a;
            }
        }
    }
    logline("cover {$a['slug']}: nothing found " . json_encode($skip));
    return $a;
}

/** cover: 3:2 at most 1200 wide; card: 768x480. Returns [w, h] of the cover or null. */
function save_cover_files(GdImage $src, string $slug): ?array {
    @mkdir(COVER_DIR, 0755, true);
    $sw = imagesx($src); $sh = imagesy($src);
    $cw = min(1200, $sw); $ch = (int)round($cw * 2 / 3);
    $ok = crop_to($src, $sw, $sh, $cw, $ch, COVER_DIR . "/$slug.webp", 80)
       && crop_to($src, $sw, $sh, 768, 480, COVER_DIR . "/$slug-card.webp", 76);
    return $ok ? [$cw, $ch] : null;
}
function crop_to(GdImage $src, int $sw, int $sh, int $tw, int $th, string $path, int $q): bool {
    $scale = max($tw / $sw, $th / $sh);
    $cw = (int)round($tw / $scale); $ch = (int)round($th / $scale);
    $sx = (int)(($sw - $cw) / 2); $sy = (int)(($sh - $ch) / 2);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
    $tmp = $path . '.tmp';
    $ok = imagewebp($dst, $tmp, $q);
    imagedestroy($dst);
    if ($ok) { backup_file($path); rename($tmp, $path); @chmod($path, 0644); }
    return $ok;
}

function cover_html(string $lang, array $a): string {
    if (empty($a['cover'])) return '';
    $c = $a['cover'];
    $by = $c['creator'] !== '' ? h($c['creator']) . ' / ' : '';
    $credit = ($lang === 'fa' ? 'عکس: ' : 'Photo: ') . $by . h(ucfirst($c['source'])) . ' (' . h($c['license']) . ')';
    if ($c['page'] !== '') $credit = '<a href="' . h($c['page']) . '" target="_blank" rel="noopener nofollow">' . $credit . '</a>';
    return '<figure class="article-cover nn-cover"><img src="' . h($c['url']) . '" alt="' . h($a['title']) . '" width="' . (int)$c['w'] . '" height="' . (int)$c['h']
        . '" fetchpriority="high"><figcaption>' . $credit . '</figcaption></figure>';
}
function card_img(array $a): string {
    if (empty($a['cover'])) return '';
    return '<img src="' . h($a['cover']['card']) . '" alt="' . h($a['title']) . '" loading="lazy" width="768" height="480">';
}

/** Sends the cover to Telegram with a "another photo" button. */
function send_cover_preview(array &$s, string $key, array $a, ?array $to = null): void {
    if (empty($a['cover'])) {
        if ($to === null) tg_all($s, '🖼 برای این مقاله عکس مناسبی پیدا نشد؛ بدون عکس منتشر می‌شود.', [[['text' => '🔄 دوباره بگرد', 'callback_data' => "i:$key"]]]);
        return;
    }
    foreach ($to ?? $s['chat_ids'] as $cid) {
        tg('sendPhoto', ['chat_id' => $cid, 'photo' => strtok($a['cover']['url'], '?') . '?t=' . time(),
            'caption' => 'عکس کاور: ' . $a['cover']['source'] . ' · ' . $a['cover']['license'] . "\nجست‌وجو: " . $a['cover']['query'],
            'reply_markup' => ['inline_keyboard' => [[['text' => '🔄 عکس دیگر', 'callback_data' => "i:$key"]]]]]);
    }
}

/** Swaps the cover of a pending/queued draft and rebuilds its previews. */
function swap_cover(array &$s, string $key): void {
    $f = AB . "/drafts/$key.json";
    $a = json_decode((string)@file_get_contents($f), true);
    if (!is_array($a)) return;
    $a = attach_cover($a, $s, true);
    file_put_contents($f, json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $live = ($s['drafts'][$key]['status'] ?? '') === 'published';
    foreach (langs() as $lang) write_file(L($lang)['dir'] . '/' . $a['slug'] . '/index.html', render_article($lang, $a[$lang], $live));
    send_cover_preview($s, $key, $a);
}
