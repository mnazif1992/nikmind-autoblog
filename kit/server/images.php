<?php
// Cover photos: Pixabay when a free API key is set, otherwise CC0 / public-domain photos from Openverse,
// downloaded to our own host, cropped and converted to WebP.
declare(strict_types=1);

define('COVER_DIR', PUB . '/' . trim((string)site('covers_path', 'blog/assets/covers'), '/'));
define('COVER_URL', SITE . '/' . trim((string)site('covers_path', 'blog/assets/covers'), '/'));

/** Hosts that block image CDNs or photo APIs (e.g. servers in Iran) go through a small proxy on another server: site.json "image_proxy". */
function image_fetch_url(string $u): string { $p = (string)site('image_proxy', ''); return $p === '' ? $u : $p . rawurlencode($u); }

/* ---------------- photo sources ----------------
 * 1. Pixabay (free API key; real, modern photos; relevant results) — used when a key is set.
 *    The owner sends "/photokey <key>" to the bot once, or puts const PIXABAY_KEY = '…'; in config.php.
 *    Pixabay's license allows use without attribution and asks that photos be downloaded, not hotlinked — which is what we do.
 * 2. Openverse (no key) — only CC0 / public-domain photos; the pool is small and loosely tagged, so results are
 *    scored against the query words and weak matches are dropped instead of taking whatever comes first. */
const PHOTO_KEYS_FILE = AB . '/state/photo-keys.json';
function photo_key(string $provider): string {
    $const = strtoupper($provider) . '_KEY';
    if (defined($const) && (string)constant($const) !== '') return (string)constant($const);
    $j = json_decode((string)@file_get_contents(PHOTO_KEYS_FILE), true);
    return is_array($j) ? (string)($j[$provider] ?? '') : '';
}
function save_photo_key(string $provider, string $key): void {
    @mkdir(dirname(PHOTO_KEYS_FILE), 0700, true);
    $j = json_decode((string)@file_get_contents(PHOTO_KEYS_FILE), true);
    $j = is_array($j) ? $j : [];
    if ($key === '') unset($j[$provider]); else $j[$provider] = $key;
    file_put_contents(PHOTO_KEYS_FILE, json_encode($j));
    @chmod(PHOTO_KEYS_FILE, 0600);
}
/** JSON GET; through the proxy when site.json has "image_proxy" and a direct call fails. */
function photo_api(string $url, array $headers = []): array {
    $try = function (string $u, array $h): array {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'nik-negar/' . KIT_VERSION, CURLOPT_HTTPHEADER => $h]);
        $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        $j = is_string($b) ? json_decode($b, true) : null;
        return [$code, is_array($j) ? $j : null];
    };
    [$code, $j] = $try($url, $headers);
    if ($j === null && (string)site('image_proxy', '') !== '') {       // the proxy passes "X-NN-Auth" on as the Authorization header
        $ph = array_map(fn($h) => preg_replace('/^Authorization:/i', 'X-NN-Auth:', $h), $headers);
        [$code, $j] = $try(image_fetch_url($url), $ph);
    }
    return [$code, $j];
}

/** Normalised candidate: id, url (download), w, h, text (for filtering), creator, source, page, license, ranked. */
function pixabay_search(string $q): array {
    $key = photo_key('pixabay');
    if ($key === '') return [];
    [$code, $j] = photo_api('https://pixabay.com/api/?' . http_build_query(['key' => $key, 'q' => $q, 'image_type' => 'photo', 'orientation' => 'horizontal',
        'safesearch' => 'true', 'min_width' => 1200, 'per_page' => 40, 'lang' => 'en']));
    if ($j === null || $code !== 200) { logline("pixabay '$q' -> $code"); return []; }
    $out = [];
    foreach ($j['hits'] ?? [] as $p) {
        $out[] = ['id' => 'pb' . $p['id'], 'url' => (string)($p['largeImageURL'] ?? ''), 'w' => (int)($p['imageWidth'] ?? 0), 'h' => (int)($p['imageHeight'] ?? 0),
            'text' => strtolower((string)($p['tags'] ?? '')), 'creator' => (string)($p['user'] ?? ''), 'source' => 'Pixabay',
            'page' => (string)($p['pageURL'] ?? ''), 'license' => 'Pixabay License', 'ranked' => true];
    }
    return $out;
}
function openverse_search(string $q, string $sources = ''): array {
    $p = ['q' => $q, 'license' => 'cc0,pdm', 'category' => 'photograph', 'mature' => 'false', 'page_size' => 20];
    if ($sources !== '') $p['source'] = $sources;
    static $limited = false;                          // anonymous Openverse allows ~20 calls a minute; stop asking once it says no
    if ($limited) return [];
    [$code, $j] = photo_api('https://api.openverse.org/v1/images/?' . http_build_query($p));
    if ($code === 429) $limited = true;
    if ($j === null || $code !== 200) { logline("openverse '$q' -> $code"); return []; }
    $out = [];
    foreach ($j['results'] ?? [] as $img) {
        $out[] = ['id' => (string)($img['id'] ?? ''), 'url' => (string)($img['url'] ?? ''), 'w' => (int)($img['width'] ?? 0), 'h' => (int)($img['height'] ?? 0),
            'title' => strtolower((string)($img['title'] ?? '')),
            'text' => strtolower(($img['title'] ?? '') . ' ' . implode(' ', array_column($img['tags'] ?? [], 'name'))),
            'creator' => trim((string)($img['creator'] ?? '')), 'source' => ucfirst((string)($img['source'] ?? $img['provider'] ?? 'Openverse')),
            'page' => (string)($img['foreign_landing_url'] ?? ''), 'license' => strtoupper((string)($img['license'] ?? 'cc0')), 'ranked' => false];
    }
    return $out;
}

const BAD_PHOTO_WORDS = ['christmas', 'xmas', 'santa', 'halloween', 'easter', 'party', 'gift', 'costume', 'wedding', 'bikini', 'nude', 'naked', 'topless',
    'underwear', 'sexy', 'lingerie', 'gun', 'weapon', 'blood', 'cigarette', 'smoking', 'alcohol', 'beer', 'wine', 'cocktail', 'logo', 'second life',
    'mural', 'painting', 'illustration', 'drawing', 'vintage', 'antique', 'circa', 'engraving', 'postcard', 'museum', 'statue'];
/** Photos whose title or description contain one of these words are skipped (kit defaults + site.json "bad_photo_words"). */
function bad_photo_re(): string {
    static $re = null;
    return $re ??= '/\b(' . implode('|', array_map(fn($w) => preg_quote(strtolower((string)$w), '/'), array_merge(BAD_PHOTO_WORDS, (array)site('bad_photo_words', [])))) . ')/';
}
/** Share of the query's content words that appear in the photo's title/tags (plural/-ing tolerant). */
function photo_match(string $q, string $text): float {
    $stop = ['a', 'an', 'the', 'of', 'at', 'in', 'on', 'by', 'with', 'and', 'to', 'for', 'out', 'up'];
    $words = array_values(array_diff(preg_split('/[^a-z0-9]+/', strtolower($q), -1, PREG_SPLIT_NO_EMPTY), $stop));
    if (!$words) return 0;
    $hit = 0;
    foreach ($words as $w) {
        $stem = preg_replace('/(ing|es|s)$/', '', $w);
        if (strlen($stem) < 3) $stem = $w;
        if (preg_match('/\b' . preg_quote($stem, '/') . '/', $text)) $hit++;
    }
    return $hit / count($words);
}

/** Picks the most relevant unused landscape photo for the article and stores cover + card WebP files. */
function attach_cover(array $a, array &$s, bool $next = false): array {
    @ini_set('memory_limit', '512M');
    $queries = array_values(array_filter(array_map('trim', (array)($a['image_queries'] ?? []))));
    $fallback = (array)site('fallback_image_queries', ['city street', 'person laptop']);
    $used = $s['used_images'] ?? [];
    $skip = [];
    $pixabay = photo_key('pixabay') !== '';
    // Pass 1: the article's own queries, most specific first. Pass 2: the same queries without their first word.
    // Pass 3: the site's generic fallback scenes. Openverse needs a real word match; Pixabay's own ranking is trusted.
    $variants = [];
    foreach ($queries as $q) $variants[] = [$q, 0.66];
    foreach ($queries as $q) { $w = preg_split('/\s+/', $q); if (count($w) > 2) $variants[] = [implode(' ', array_slice($w, 1)), 0.99]; }
    foreach ($fallback as $q) $variants[] = [(string)$q, 0.5];
    $seen = [];
    foreach ($variants as [$q, $need]) {
        if (isset($seen[$q])) continue;
        $seen[$q] = true;
        $cands = [];
        if ($pixabay) $cands = pixabay_search($q);
        if (!$cands) {
            foreach (array_merge(openverse_search($q, 'stocksnap,rawpixel,nappy'), openverse_search($q)) as $c) {
                $c['score'] = photo_match($q, $c['text']);
                if ($c['score'] >= $need) $cands[] = $c; else $skip['weak'] = ($skip['weak'] ?? 0) + 1;
            }
            usort($cands, fn($x, $y) => $y['score'] <=> $x['score']);
        }
        foreach ($cands as $img) {
            if ($img['id'] === '' || $img['url'] === '' || in_array($img['id'], $used, true)) { $skip['used'] = ($skip['used'] ?? 0) + 1; continue; }
            if (preg_match(bad_photo_re(), $img['ranked'] ? $img['text'] : ($img['title'] ?? $img['text']))) { $skip['topic'] = ($skip['topic'] ?? 0) + 1; continue; }
            if ($img['w'] < 1000 || $img['h'] < 600 || $img['w'] < $img['h'] * 1.25 || $img['w'] * $img['h'] > 120000000) { $skip['size'] = ($skip['size'] ?? 0) + 1; continue; }
            $file = http(image_fetch_url($img['url']), null, 60);
            if ($file['code'] !== 200 || strlen($file['body']) < 20000) { $skip['download'] = ($skip['download'] ?? 0) + 1; continue; }
            $srcImg = @imagecreatefromstring($file['body']);
            if (!$srcImg) { $skip['decode'] = ($skip['decode'] ?? 0) + 1; continue; }
            $saved = save_cover_files($srcImg, $a['slug']);
            imagedestroy($srcImg);
            if (!$saved) continue;
            $s['used_images'][] = $img['id'];
            $v = substr(md5($img['id']), 0, 6);
            $a['cover'] = [
                'id' => $img['id'], 'url' => COVER_URL . '/' . $a['slug'] . '.webp?v=' . $v, 'card' => COVER_URL . '/' . $a['slug'] . '-card.webp?v=' . $v,
                'w' => $saved[0], 'h' => $saved[1], 'creator' => $img['creator'], 'source' => $img['source'],
                'page' => $img['page'], 'license' => $img['license'], 'query' => $q,
            ];
            foreach (langs() as $l) if (isset($a[$l])) $a[$l]['cover'] = $a['cover'];
            logline("cover {$a['slug']}: {$img['id']} ({$img['source']}) for '$q'");
            return $a;
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
    $credit = ($lang === 'fa' ? 'عکس: ' : 'Photo: ') . $by . h(ucfirst($c['source'])) . (str_starts_with((string)$c['license'], 'Pixabay') ? '' : ' (' . h($c['license']) . ')');
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
