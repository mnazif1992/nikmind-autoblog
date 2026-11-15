<?php
// Cover photos. In order: an AI photo made for this article (FLUX.2 [klein] on Cloudflare Workers AI, when the owner has
// set a key with /aikey), Pixabay (free key, /photokey), then CC0 / public-domain photos from Openverse. Everything is
// downloaded to our own host, cropped and converted to WebP. The panel can also show a dozen options to pick from.
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
        'safesearch' => 'true', 'min_width' => 1200, 'per_page' => 80, 'lang' => 'en']));
    if ($j === null || $code !== 200) { logline("pixabay '$q' -> $code"); return []; }
    $out = []; $hits = (array)($j['hits'] ?? []); $n = max(1, count($hits));
    foreach (array_values($hits) as $i => $p) {
        $tags = strtolower((string)($p['tags'] ?? ''));
        // Pixabay's "popular" order puts the most overused photos first. Rank instead by relevance (its order), how well the
        // photo is liked for its views, and how far it is from the posed-stock look.
        $views = max(1, (int)($p['views'] ?? 0));
        $score = 0.45 * (1 - $i / $n) + 0.35 * min(1, ((int)($p['likes'] ?? 0) * 1000 / $views) / 4) + 0.2 * min(1, log10(1 + (int)($p['likes'] ?? 0)) / 2.5);
        if (preg_match(cliche_re(), $tags)) $score -= 0.45;
        $out[] = ['id' => 'pb' . $p['id'], 'url' => (string)($p['largeImageURL'] ?? ''), 'thumb' => (string)($p['webformatURL'] ?? ''),
            'w' => (int)($p['imageWidth'] ?? 0), 'h' => (int)($p['imageHeight'] ?? 0),
            'text' => $tags, 'creator' => (string)($p['user'] ?? ''), 'source' => 'Pixabay',
            'page' => (string)($p['pageURL'] ?? ''), 'license' => 'Pixabay License', 'ranked' => true, 'score' => $score];
    }
    usort($out, fn($x, $y) => $y['score'] <=> $x['score']);
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
            'thumb' => (string)($img['thumbnail'] ?? ''),
            'creator' => trim((string)($img['creator'] ?? '')), 'source' => ucfirst((string)($img['source'] ?? $img['provider'] ?? 'Openverse')),
            'page' => (string)($img['foreign_landing_url'] ?? ''), 'license' => strtoupper((string)($img['license'] ?? 'cc0')), 'ranked' => false];
    }
    return $out;
}

const BAD_PHOTO_WORDS = ['christmas', 'xmas', 'santa', 'halloween', 'easter', 'party', 'gift', 'costume', 'wedding', 'bikini', 'nude', 'naked', 'topless',
    'underwear', 'sexy', 'lingerie', 'gun', 'weapon', 'blood', 'cigarette', 'smoking', 'alcohol', 'beer', 'wine', 'cocktail', 'logo', 'second life',
    'mural', 'painting', 'illustration', 'drawing', 'vintage', 'antique', 'circa', 'engraving', 'postcard', 'museum', 'statue'];
/** Tags of the posed, staged stock look; such photos are ranked down, not dropped. */
const CLICHE_PHOTO_WORDS = ['business', 'businessman', 'businesswoman', 'smiling', 'smile', 'happy', 'laughing', 'thumbs', 'isolated', 'white background',
    'studio', 'posing', 'pose', 'model', 'fashion', 'beautiful', 'pretty', 'handsome', 'cute', 'headset', 'call center', 'handshake', 'teamwork',
    'success', 'corporate', 'concept', 'conceptual', 'copy space', 'copyspace', 'mockup', 'mock up', 'stock', 'selfie', 'glamour', 'make-up', 'makeup'];
function cliche_re(): string { static $re = null; return $re ??= '/\b(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), CLICHE_PHOTO_WORDS)) . ')\b/'; }
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
    if (ai_enabled() && site('ai_covers', true) !== false) {      // a photo made for this very article, in the site's style
        try { return ai_cover($a, $s); }
        catch (Throwable $e) { logline("cover {$a['slug']}: AI failed, using stock photos (" . $e->getMessage() . ')'); }
    }
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
function save_cover_files(GdImage $src, string $slug, int $q = 84): ?array {
    @mkdir(COVER_DIR, 0755, true);
    $sw = imagesx($src); $sh = imagesy($src);
    $cw = min(1200, $sw); $ch = (int)round($cw * 2 / 3);
    $ok = crop_to($src, $sw, $sh, $cw, $ch, COVER_DIR . "/$slug.webp", $q)
       && crop_to($src, $sw, $sh, 768, 480, COVER_DIR . "/$slug-card.webp", $q - 4);
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
    if (!empty($c['own']) || ($c['source'] ?? '') === 'ai') return '<figure class="article-cover nn-cover"><img src="' . h($c['url']) . '" alt="' . h($a['title']) . '" width="' . (int)$c['w'] . '" height="' . (int)$c['h'] . '" fetchpriority="high"></figure>';
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

/** Sends the cover to the team, with buttons for another photo and for choosing among several. */
function cover_caption(array $c): string {
    if (!empty($c['own'])) return '🖼 کاور: عکسی که از پنل آپلود شد' . (($c['creator'] ?? '') !== '' ? ' (' . $c['creator'] . ')' : '');
    if (($c['source'] ?? '') === 'ai') return '🖼 کاور: عکسی که هوش مصنوعی برای همین مقاله ساخت';
    if (($c['source'] ?? '') === 'pick') return '🖼 کاور: انتخاب‌شده از پنل';
    return '🖼 کاور: ' . $c['source'] . ($c['creator'] !== '' ? ' / ' . $c['creator'] : '') . "\nجست‌وجو: " . $c['query'];
}
function cover_buttons(string $key): array {
    return [[['text' => '🔄 یکی دیگر', 'callback_data' => "i:$key"], ['text' => '🖼 انتخاب از چند عکس', 'web_app' => ['url' => panel_url() . '?photos=' . $key]]]];
}
function send_cover_preview(array &$s, string $key, array $a, ?array $to = null): void {
    if (empty($a['cover'])) {
        if ($to === null) tg_all($s, '🖼 برای این مقاله عکسی پیدا نشد. یکی دیگر بگردم یا خودت از بین چند عکس انتخاب می‌کنی؟', cover_buttons($key));
        return;
    }
    foreach ($to ?? $s['chat_ids'] as $cid) {
        tg('sendPhoto', ['chat_id' => $cid, 'photo' => strtok($a['cover']['url'], '?') . '?t=' . time(),
            'caption' => cover_caption($a['cover']), 'reply_markup' => ['inline_keyboard' => cover_buttons($key)]]);
    }
}

/** Swaps the cover of a pending/queued draft and rebuilds its previews. */
function swap_cover(array &$s, string $key): void {
    $f = AB . "/drafts/$key.json";
    $a = json_decode((string)@file_get_contents($f), true);
    if (!is_array($a)) return;
    $a = attach_cover($a, $s, true);
    rerender_draft($s, $key, $a);
    send_cover_preview($s, $key, $a);
}

/* ---------------- AI photos: FLUX.2 [klein] on Cloudflare Workers AI ----------------
 * Free daily allowance (10,000 "neurons", reset 00:00 UTC): about 7 covers a day with the 9B model at 1200×800, and about
 * 60 more with the 4B model, which is used when the first one fails or the allowance runs low. The owner sets the key once:
 * /aikey <account id> <API token with Workers AI permission>. Servers that cannot reach Cloudflare go through "image_proxy". */
const AI_MODELS = ['@cf/black-forest-labs/flux-2-klein-9b', '@cf/black-forest-labs/flux-2-klein-4b'];
const AI_STYLE = 'Photorealistic editorial photograph, natural light, true-to-life colors, realistic hands and skin, 35mm lens, shallow depth of field, '
    . 'calm documentary mood, nothing staged. No text, no letters, no numbers, no logos, no watermark, no frame, no collage.';
function ai_creds(): array {
    return preg_match('/^([a-f0-9]{32}):([A-Za-z0-9_\-]{30,})$/', photo_key('cloudflare'), $m) ? [$m[1], $m[2]] : [];
}
function ai_enabled(): bool { return (bool)ai_creds(); }
/** The prompt: the article's own scene (written with the article), the site's art direction, then the fixed photo style. */
function ai_prompt(array $a, string $own = ''): string {
    $scene = trim($own) ?: trim((string)($a['image_prompt'] ?? ''));
    if ($scene === '') $scene = 'A quiet, real-life scene: ' . implode('; ', array_slice((array)($a['image_queries'] ?? []), 0, 2)) . '.';
    return mb_substr(implode(' ', array_filter([$scene, trim((string)site('image_style', '')), AI_STYLE])), 0, 1800);
}
/** Makes one image. Returns [binary, model]; throws with a short reason when every model failed. */
function ai_generate(string $prompt, int $w = 1200, int $h = 800, ?array $creds = null): array {
    [$acct, $tok] = $creds ?? ai_creds();
    if (!$acct) throw new RuntimeException('no AI key');
    $why = [];
    foreach (AI_MODELS as $model) {
        $form = ['prompt' => $prompt, 'width' => (string)$w, 'height' => (string)$h, 'seed' => (string)random_int(1, 2147483646)];
        $url = "https://api.cloudflare.com/client/v4/accounts/$acct/ai/run/$model";
        $r = ai_post($url, $tok, $form);
        if ($r['code'] === 0 && (string)site('image_proxy', '') !== '') $r = ai_post_proxy($acct, $model, $tok, $form);
        $bin = '';
        if (str_starts_with($r['type'], 'image/')) $bin = $r['body'];
        else {
            $j = json_decode($r['body'], true);
            $img = $j['result']['image'] ?? ($j['image'] ?? '');
            if (is_string($img) && $img !== '') $bin = (string)base64_decode(preg_replace('#^data:image/\\w+;base64,#', '', $img), true);
            if ($bin === '') $why[] = basename($model) . ': ' . ($r['code'] ?: 'no connection') . ' ' . substr((string)($j['errors'][0]['message'] ?? $r['err'] ?? ''), 0, 120);
        }
        if ($bin !== '' && @getimagesizefromstring($bin)) { logline('ai image ' . basename($model) . " {$w}x$h"); return [$bin, $model]; }
    }
    throw new RuntimeException(implode(' | ', $why) ?: 'no image');
}
function ai_post(string $url, string $tok, array $form): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok], CURLOPT_USERAGENT => 'nik-negar/' . KIT_VERSION]);
    $b = curl_exec($c);
    $r = ['code' => (int)curl_getinfo($c, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE), 'body' => is_string($b) ? $b : '', 'err' => curl_error($c)];
    curl_close($c);
    return $r;
}
/** Same call through the image proxy (img-proxy.php v1.3+), for servers that cannot reach Cloudflare themselves. */
function ai_post_proxy(string $acct, string $model, string $tok, array $form): array {
    $u = (string)site('image_proxy', '') . rawurlencode("https://api.cloudflare.com/client/v4/accounts/$acct/ai/run/$model");
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 75, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($form),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-NN-Auth: Bearer ' . $tok, 'X-NN-AI: 1'], CURLOPT_USERAGENT => 'nik-negar/' . KIT_VERSION]);
    $b = curl_exec($c);
    $r = ['code' => (int)curl_getinfo($c, CURLINFO_HTTP_CODE), 'type' => (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE), 'body' => is_string($b) ? $b : '', 'err' => curl_error($c)];
    curl_close($c);
    return $r;
}
/** AI cover for an article: generated, cropped and saved like any other cover. */
function ai_cover(array $a, array &$s, string $own = ''): array {
    $prompt = ai_prompt($a, $own);
    [$bin, $model] = ai_generate($prompt);
    $im = @imagecreatefromstring($bin);
    if (!$im) throw new RuntimeException('AI image unreadable');
    $saved = save_cover_files($im, $a['slug'], 90);
    imagedestroy($im);
    if (!$saved) throw new RuntimeException('could not save the AI image');
    return set_cover_meta($a, ['id' => 'ai-' . substr(md5($bin), 0, 10), 'w' => $saved[0], 'h' => $saved[1], 'creator' => '', 'source' => 'ai',
        'page' => '', 'license' => '', 'query' => mb_substr($own !== '' ? $own : (string)($a['image_prompt'] ?? ''), 0, 400), 'model' => basename($model)]);
}
function set_cover_meta(array $a, array $c): array {
    $v = substr(md5($c['id'] . microtime()), 0, 6);
    $a['cover'] = $c + ['url' => COVER_URL . '/' . $a['slug'] . '.webp?v=' . $v, 'card' => COVER_URL . '/' . $a['slug'] . '-card.webp?v=' . $v];
    foreach (langs() as $l) if (isset($a[$l])) $a[$l]['cover'] = $a['cover'];
    logline("cover {$a['slug']}: {$c['id']} ({$c['source']})");
    return $a;
}

/* ---------------- choosing among several photos (panel) ---------------- */
// Options are kept on the server (state/options/<key>.json, previews in the covers folder under _opt/), so the panel never
// talks to photo sites itself: those are often unreachable from Iran, and the full-size file is fetched only for the one chosen.
function options_dir(): string { return COVER_DIR . '/_opt'; }
function options_load(string $key): array { return json_load(AB . "/state/options/$key.json", []); }
function options_save(string $key, array $o): void { json_save(AB . "/state/options/$key.json", $o); }
/** Old previews go after two days. */
function options_cleanup(): void {
    foreach (glob(options_dir() . '/*') ?: [] as $f) if (filemtime($f) < time() - 2 * 86400) { @mkdir(AB . '/rejected/_opt', 0700, true); @rename($f, AB . '/rejected/_opt/' . basename($f)); }
    foreach (glob(AB . '/state/options/*') ?: [] as $f) if (filemtime($f) < time() - 2 * 86400) @unlink($f);
}
/** Downloads several URLs at once. */
function fetch_many(array $urls, int $timeout = 25): array {
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $u) {
        $c = curl_init(image_fetch_url($u));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'nik-negar/' . KIT_VERSION]);
        curl_multi_add_handle($mh, $c); $hs[$k] = $c;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $c) {
        $out[$k] = (int)curl_getinfo($c, CURLINFO_HTTP_CODE) === 200 ? (string)curl_multi_getcontent($c) : '';
        curl_multi_remove_handle($mh, $c); curl_close($c);
    }
    curl_multi_close($mh);
    return $out;
}
/** A small WebP preview in the public options folder; returns its URL or ''. */
function option_preview(string $bin, string $name): string {
    $im = @imagecreatefromstring($bin);
    if (!$im) return '';
    @mkdir(options_dir(), 0755, true);
    $w = imagesx($im); $h = imagesy($im);
    $tw = 480; $th = 320; $scale = max($tw / $w, $th / $h); $cw = (int)round($tw / $scale); $ch = (int)round($th / $scale);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $im, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), $tw, $th, $cw, $ch);
    imagedestroy($im);
    $ok = imagewebp($dst, options_dir() . "/$name.webp", 78);
    imagedestroy($dst);
    return $ok ? COVER_URL . "/_opt/$name.webp?t=" . time() : '';
}
/** Up to 12 stock photos for the article (or for the editor's own search words). */
function cover_options(array &$s, string $key, string $q = ''): array {
    options_cleanup();
    $a = draft_load($key);
    $queries = $q !== '' ? [$q] : array_values(array_filter(array_map('trim', (array)($a['image_queries'] ?? []))));
    $used = $s['used_images'] ?? []; $cur = (string)($a['cover']['id'] ?? '');
    $picked = []; $seen = [];
    foreach ($queries as $qq) {
        $cands = photo_key('pixabay') !== '' ? pixabay_search($qq) : [];
        if (!$cands) foreach (array_merge(openverse_search($qq, 'stocksnap,rawpixel,nappy'), openverse_search($qq)) as $c) { $c['score'] = photo_match($qq, $c['text']); if ($c['score'] >= 0.5) $cands[] = $c; }
        foreach ($cands as $c) {
            if (count($picked) >= 12) break 2;
            if ($c['id'] === '' || $c['url'] === '' || isset($seen[$c['id']]) || $c['id'] === $cur || in_array($c['id'], $used, true)) continue;
            if (preg_match(bad_photo_re(), $c['ranked'] ? $c['text'] : ($c['title'] ?? $c['text']))) continue;
            if ($c['w'] < 1000 || $c['h'] < 600 || $c['w'] < $c['h'] * 1.25) continue;
            $seen[$c['id']] = true; $c['query'] = $qq; $picked[] = $c;
            if (count($picked) >= ($q !== '' ? 12 : 4 * (array_search($qq, $queries, true) + 1))) break;   // a few from each of the article's searches
        }
    }
    $bins = fetch_many(array_map(fn($c) => $c['thumb'] ?: $c['url'], $picked));
    $o = options_load($key); $o['stock'] = [];
    foreach ($picked as $i => $c) {
        $prev = ($bins[$i] ?? '') !== '' ? option_preview($bins[$i], "$key-s$i") : '';
        if ($prev === '') continue;
        $o['stock'][] = $c + ['n' => "s$i", 'preview' => $prev];
    }
    options_save($key, $o);
    return options_view($key, $o);
}
/** One AI option (its own prompt can be edited in the panel). */
function cover_option_ai(string $key, string $own): array {
    if (!ai_enabled()) throw new RuntimeException('برای ساختن عکس با هوش مصنوعی، اول کلید Cloudflare را با /aikey در ربات بده');
    $a = draft_load($key);
    [$bin] = ai_generate(ai_prompt($a, $own));
    $o = options_load($key);
    $n = 'a' . (count($o['ai'] ?? []) + 1) . substr(md5($bin), 0, 4);
    @mkdir(AB . '/state/options', 0700, true);
    file_put_contents(AB . "/state/options/$key-$n.img", $bin);
    $o['ai'][] = ['n' => $n, 'preview' => option_preview($bin, "$key-$n"), 'prompt' => mb_substr($own !== '' ? $own : (string)($a['image_prompt'] ?? ''), 0, 400)];
    options_save($key, $o);
    return options_view($key, $o);
}
function options_view(string $key, array $o): array {
    $a = draft_load($key);
    $v = ['ai' => [], 'stock' => [], 'ai_on' => ai_enabled(), 'prompt' => (string)($a['image_prompt'] ?? ''), 'cover' => $a['cover']['card'] ?? '',
          'queries' => array_values((array)($a['image_queries'] ?? []))];
    foreach (array_reverse($o['ai'] ?? []) as $x) $v['ai'][] = ['n' => $x['n'], 'preview' => $x['preview']];
    foreach ($o['stock'] ?? [] as $x) $v['stock'][] = ['n' => $x['n'], 'preview' => $x['preview'], 'source' => $x['source'], 'creator' => $x['creator']];
    return $v;
}
/** Makes the chosen option the cover (full-size file fetched now). */
function cover_pick(array &$s, string $key, string $n, int $uid): array {
    $o = options_load($key); $a = draft_load($key);
    if (str_starts_with($n, 'a')) {
        $x = null; foreach ($o['ai'] ?? [] as $y) if ($y['n'] === $n) $x = $y;
        $bin = $x ? (string)@file_get_contents(AB . "/state/options/$key-$n.img") : '';
        if ($bin === '') throw new RuntimeException('این گزینه دیگر روی سرور نیست؛ دوباره بساز');
        $meta = ['id' => 'ai-' . substr(md5($bin), 0, 10), 'creator' => '', 'source' => 'ai', 'page' => '', 'license' => '', 'query' => $x['prompt']];
    } else {
        $x = null; foreach ($o['stock'] ?? [] as $y) if ($y['n'] === $n) $x = $y;
        if (!$x) throw new RuntimeException('این گزینه دیگر روی سرور نیست؛ فهرست را تازه کن');
        $f = http(image_fetch_url($x['url']), null, 60);
        $bin = $f['code'] === 200 ? $f['body'] : '';
        if (strlen($bin) < 20000) throw new RuntimeException('دانلود این عکس نشد؛ یکی دیگر را انتخاب کن');
        $meta = ['id' => $x['id'], 'creator' => $x['creator'], 'source' => $x['source'], 'page' => $x['page'], 'license' => $x['license'], 'query' => $x['query']];
    }
    @ini_set('memory_limit', '512M');
    $im = @imagecreatefromstring($bin);
    if (!$im) throw new RuntimeException('فایل عکس خوانده نشد');
    $saved = save_cover_files($im, $a['slug'], 88);
    imagedestroy($im);
    if (!$saved) throw new RuntimeException('ذخیره‌ی عکس روی سرور نشد');
    $a = set_cover_meta($a, $meta + ['w' => $saved[0], 'h' => $saved[1]]);
    if ($meta['source'] !== 'ai') $s['used_images'][] = $meta['id'];
    rerender_draft($s, $key, $a);
    $live = ($s['drafts'][$key]['status'] ?? '') === 'published';
    foreach ($s['chat_ids'] as $cid) if ((int)$cid !== $uid) tg('sendPhoto', ['chat_id' => $cid, 'photo' => strtok($a['cover']['url'], '?') . '?t=' . time(),
        'caption' => '🖼 کاور «' . $a[lang1()]['title'] . '» از پنل انتخاب شد' . ($live ? '؛ صفحه‌ی منتشرشده هم عوض شد.' : '.') . html_entity_decode(strip_tags(actor_note($s)))]);
    return ['cover' => $a['cover']['card']];
}

/* ---------------- photos uploaded from the panel ---------------- */
/** Site path of a photo in our own image folder (absolute or site-relative URL accepted), or '' if it is anything else. */
function own_image_path(string $src): string {
    $src = html_entity_decode(trim($src));
    if (str_starts_with($src, SITE . '/')) $src = substr($src, strlen(SITE));
    $src = strtok($src, '?#') ?: '';
    $base = '/' . trim((string)site('covers_path', 'blog/assets/covers'), '/') . '/';
    if (!str_starts_with($src, $base) || !preg_match('#^[a-z0-9/_.-]+\.webp$#', $src) || str_contains($src, '..')) return '';
    return is_file(PUB . $src) ? $src : '';
}
/** Decodes a photo sent by the panel (base64 data URL or bare base64). Re-encoding later strips anything that is not pixels. */
function upload_image_decode(string $data, int $minW, int $minH): GdImage {
    @ini_set('memory_limit', '512M');
    if (str_starts_with($data, 'data:')) $data = substr($data, (int)strpos($data, ',') + 1);
    $bin = base64_decode($data, true);
    if ($bin === false || strlen($bin) < 100) throw new RuntimeException('فایل عکس خوانده نشد');
    if (strlen($bin) > 12 * 1024 * 1024) throw new RuntimeException('عکس بیش از حد بزرگ است');
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new RuntimeException('فقط عکس JPG، PNG یا WebP');
    if ($info[0] * $info[1] > 60000000) throw new RuntimeException('ابعاد عکس بیش از حد بزرگ است');
    if ($info[0] < $minW || $info[1] < $minH) throw new RuntimeException('عکس کوچک است؛ دست‌کم ' . fa_digits("{$minW}×{$minH}") . ' پیکسل لازم است');
    $im = @imagecreatefromstring($bin);
    if (!$im) throw new RuntimeException('فایل عکس خوانده نشد');
    return $im;
}
/** Uploaded cover: same crops and files as a found photo, no credit line. Returns the updated article. */
function set_uploaded_cover(array &$s, array $a, string $data, string $who): array {
    $im = upload_image_decode($data, 600, 400);
    $saved = save_cover_files($im, $a['slug']);
    imagedestroy($im);
    if (!$saved) throw new RuntimeException('ذخیره‌ی عکس روی سرور نشد');
    $v = substr(md5($data . microtime()), 0, 6);
    $a['cover'] = ['id' => 'upload-' . $v, 'url' => COVER_URL . '/' . $a['slug'] . '.webp?v=' . $v, 'card' => COVER_URL . '/' . $a['slug'] . '-card.webp?v=' . $v,
                   'w' => $saved[0], 'h' => $saved[1], 'creator' => $who, 'source' => 'upload', 'page' => '', 'license' => '', 'query' => '', 'own' => true];
    foreach (langs() as $l) if (isset($a[$l])) $a[$l]['cover'] = $a['cover'];
    logline("cover {$a['slug']}: uploaded from the panel" . ($who !== '' ? " by $who" : ''));
    return $a;
}
/** Photo for inside the article text: at most 1200 px wide, original proportions, WebP. Returns [site path, w, h]. */
function save_inline_image(string $slug, string $data): array {
    $im = upload_image_decode($data, 300, 200);
    $sw = imagesx($im); $sh = imagesy($im);
    $tw = min(1200, $sw); $th = (int)round($sh * $tw / $sw);
    $dst = imagecreatetruecolor($tw, $th);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagedestroy($im);
    @mkdir(COVER_DIR, 0755, true);
    $name = $slug . '-in-' . substr(md5($data . microtime()), 0, 8) . '.webp';
    $ok = imagewebp($dst, COVER_DIR . "/$name.tmp", 80);
    imagedestroy($dst);
    if (!$ok) throw new RuntimeException('ذخیره‌ی عکس روی سرور نشد');
    rename(COVER_DIR . "/$name.tmp", COVER_DIR . "/$name");
    @chmod(COVER_DIR . "/$name", 0644);
    logline("inline photo $name uploaded from the panel");
    return ['/' . trim((string)site('covers_path', 'blog/assets/covers'), '/') . "/$name", $tw, $th];
}
