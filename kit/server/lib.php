<?php
// Nik-Negar kit — helpers: languages, state, files, http, telegram, validation, rendering, blog index/sitemap/feed.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once AB . '/images.php';

if (!defined('GITHUB_REPO')) define('GITHUB_REPO', (string)site('content_repo', ''));

/* ---------------- languages ---------------- */
const LANG_DEFAULTS = [
    'fa' => ['dir_attr' => 'rtl', 'label' => 'فارسی', 'read' => ' دقیقه مطالعه', 'toc' => 'فهرست مطالب', 'faq' => 'سؤال‌های متداول',
             'blog_name' => 'مقالات', 'home_name' => 'خانه', 'related' => 'مقاله‌های دیگر', 'cta_text' => 'تماس با ما', 'min_words' => 900,
             'empty' => 'به‌زودی اولین مقاله این‌جا منتشر می‌شود.', 'words_per_min' => 200],
    'en' => ['dir_attr' => 'ltr', 'label' => 'English', 'read' => ' min read', 'toc' => 'Contents', 'faq' => 'Frequently asked questions',
             'blog_name' => 'Articles', 'home_name' => 'Home', 'related' => 'More articles', 'cta_text' => 'Contact us', 'min_words' => 900,
             'empty' => 'The first article will appear here soon.', 'words_per_min' => 230],
    'ar' => ['dir_attr' => 'rtl', 'label' => 'العربية', 'read' => ' دقائق للقراءة', 'toc' => 'المحتويات', 'faq' => 'الأسئلة الشائعة',
             'blog_name' => 'المقالات', 'home_name' => 'الرئيسية', 'related' => 'مقالات أخرى', 'cta_text' => 'تواصل معنا', 'min_words' => 800,
             'empty' => 'ستُنشر المقالة الأولى هنا قريبًا.', 'words_per_min' => 200],
];
function langs(): array { return array_map(fn($l) => (string)$l['code'], (array)site('langs', [])); }
function lang1(): string { return langs()[0] ?? 'fa'; }
function L(string $lang): array {
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    $cfg = null;
    foreach ((array)site('langs', []) as $l) if (($l['code'] ?? '') === $lang) $cfg = $l;
    if (!$cfg) throw new RuntimeException("language $lang is not in site.json");
    $L = array_merge(LANG_DEFAULTS['en'], LANG_DEFAULTS[$lang] ?? [], ['inlang' => $lang], $cfg);
    $L['base'] = '/' . trim((string)$L['base'], '/') . '/';
    $L['home'] = SITE . '/' . ltrim((string)($cfg['home'] ?? '/'), '/');
    $L['dir'] = PUB . rtrim($L['base'], '/');
    $L['tpl'] = "article-$lang.html";
    $L['author_url'] = abs_url((string)($L['author_url'] ?? ''));
    $L['site_name'] = $L['site_name'] ?? $L['publisher'] ?? site('site_name', '');
    $L['suffix'] = $L['suffix'] ?? (' | ' . $L['site_name']);
    $L['feed_title'] = $L['feed_title'] ?? ($L['blog_title'] ?? ($L['blog_name'] . ' — ' . $L['site_name']));
    return $cache[$lang] = $L;
}
function abs_url(string $u): string { return ($u === '' || preg_match('#^https?://#', $u)) ? $u : SITE . '/' . ltrim($u, '/'); }
function post_url(string $lang, string $slug): string { return SITE . L($lang)['base'] . $slug . '/'; }

/* ---------------- logging & state ---------------- */
function logline(string $m): void {
    @mkdir(AB . '/logs', 0700, true);
    file_put_contents(AB . '/logs/' . date('Y-m') . '.log', date('c') . ' ' . $m . "\n", FILE_APPEND);
}
function json_load(string $f, $def) { $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null; return is_array($j) ? $j : $def; }
function json_save(string $f, $data): void {
    @mkdir(dirname($f), 0700, true);
    file_put_contents("$f.tmp", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename("$f.tmp", $f);
}
function state_load(): array {
    $def = ['chat_ids' => [], 'tg_offset' => 0, 'drafts' => [], 'published' => [], 'queue' => [], 'slots' => [],
            'last_gh' => 0, 'fails' => 0, 'paused' => false, 'last_pub' => 0];
    return array_merge($def, json_load(AB . '/state/state.json', []));
}
function state_save(array $s): void { json_save(AB . '/state/state.json', $s); }

/** Published posts per language, newest first: slug, title, excerpt, date, read_min, card. Drives index, related, sitemap, feed. */
function posts(string $lang): array { return json_load(AB . "/state/posts-$lang.json", []); }
function posts_add(string $lang, array $a): void {
    $list = array_values(array_filter(posts($lang), fn($p) => $p['slug'] !== $a['slug']));
    array_unshift($list, ['slug' => $a['slug'], 'title' => $a['title'], 'excerpt' => $a['excerpt'], 'date' => $a['date'],
                          'read_min' => $a['read_min'], 'card' => $a['cover']['card'] ?? '', 'cat' => $a['cluster'] ?? '']);
    json_save(AB . "/state/posts-$lang.json", $list);
}

/* ---------------- safe file writes ---------------- */
function backup_file(string $path): void {
    if (!is_file($path)) return;
    $rel = str_starts_with($path, PUB) ? ltrim(substr($path, strlen(PUB)), '/') : '_internal/' . basename($path);
    $dst = AB . '/backups/' . date('Y-m-d') . '/' . date('His') . '/' . $rel;
    @mkdir(dirname($dst), 0700, true);
    copy($path, $dst);
}
function write_file(string $path, string $content): void {
    backup_file($path);
    @mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content) === false) throw new RuntimeException("write failed: $path");
    @chmod($tmp, 0644);
    rename($tmp, $path);
}

/* ---------------- http ---------------- */
function http(string $url, ?array $json = null, int $timeout = 30): array {
    $c = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'nik-negar/' . KIT_VERSION, CURLOPT_FOLLOWLOCATION => true];
    if ($json !== null) {
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($c, $opt);
    $body = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'err' => $err];
}

/* ---------------- messenger (Telegram or Bale) ---------------- */
// Bale's bot API mirrors Telegram's, with these differences handled here: no HTML parse_mode (we convert to its markdown),
// and no command menu / menu button / descriptions through the API. Mini apps (web_app buttons) work as in Telegram.
function messenger(): string { return site('messenger', 'telegram') === 'bale' ? 'bale' : 'telegram'; }
function messenger_name(): string { return messenger() === 'bale' ? 'بله' : 'تلگرام'; }
const BALE_SKIP = ['setMyCommands', 'setChatMenuButton', 'setMyDescription', 'setMyShortDescription', 'setMyName'];
function html_to_bale(string $h): string {
    $h = preg_replace('#<a href="([^"]+)">(.*?)</a>#su', '[$2]($1)', $h);
    $h = preg_replace('#</?(b|strong)>#', '*', $h);
    $h = preg_replace('#</?(i|em)>#', '_', $h);
    return html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
/** Signed link that opens the panel in any browser (used where the messenger has no mini apps). Valid 14 days. */
function panel_link($uid): string {
    $exp = time() + 14 * 86400;
    $sig = substr(hash_hmac('sha256', "$uid.$exp", HOOK_SECRET . TG_BOT_TOKEN), 0, 32);
    return panel_url() . '#t=' . rawurlencode("$uid.$exp.$sig");
}
function panel_token_uid(string $t): int {
    if (!preg_match('/^(-?\d+)\.(\d+)\.([a-f0-9]{32})$/', $t, $m)) return 0;
    if ((int)$m[2] < time()) return 0;
    return hash_equals(substr(hash_hmac('sha256', "$m[1].$m[2]", HOOK_SECRET . TG_BOT_TOKEN), 0, 32), $m[3]) ? (int)$m[1] : 0;
}
function tg(string $method, array $params): ?array {
    if (TG_BOT_TOKEN === '' || str_contains(TG_BOT_TOKEN, 'PASTE')) return null;
    $base = 'https://api.telegram.org/bot';
    if (messenger() === 'bale') {
        if (in_array($method, BALE_SKIP, true)) return null;
        $base = 'https://tapi.bale.ai/bot';
        foreach (['text', 'caption'] as $k) if (isset($params[$k]) && ($params['parse_mode'] ?? '') === 'HTML') $params[$k] = html_to_bale((string)$params[$k]);
        unset($params['parse_mode'], $params['disable_web_page_preview'], $params['disable_notification'], $params['is_persistent']);
    }
    $photo = is_string($params['photo'] ?? null) ? (string)$params['photo'] : '';
    $local = str_starts_with($photo, SITE . '/') ? PUB . (string)parse_url($photo, PHP_URL_PATH) : '';
    if ($local !== '' && is_file($local)) {
        // upload our own images as a file: the messenger's servers cannot always reach the site (Bale often cannot)
        $form = [];
        foreach ($params as $k => $v) $form[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v);
        $tmp = '';
        if (str_ends_with($local, '.webp') && function_exists('imagecreatefromwebp') && ($im = @imagecreatefromwebp($local))) {   // JPEG is the safest photo format for both messengers
            $tmp = AB . '/state/upload-' . getmypid() . '.jpg'; imagejpeg($im, $tmp, 88); imagedestroy($im);
        }
        $form['photo'] = $tmp !== '' ? new CURLFile($tmp, 'image/jpeg', 'cover.jpg') : new CURLFile($local, mime_content_type($local) ?: 'image/webp', basename($local));
        $c = curl_init($base . TG_BOT_TOKEN . '/' . $method);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form]);
        $b = curl_exec($c); $r = ['code' => (int)curl_getinfo($c, CURLINFO_HTTP_CODE), 'body' => is_string($b) ? $b : '', 'err' => curl_error($c)]; curl_close($c);
        if ($tmp !== '') @unlink($tmp);
    } else $r = http($base . TG_BOT_TOKEN . '/' . $method, $params, 40);
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['ok'])) { logline("msg $method failed: " . substr($r['body'] ?: $r['err'], 0, 300)); return null; }
    return $j;
}
function tg_all(array $s, string $html, ?array $buttons = null): array {
    $ids = [];
    foreach ($s['chat_ids'] as $cid) {
        $p = ['chat_id' => $cid, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($buttons) $p['reply_markup'] = ['inline_keyboard' => $buttons];
        $r = tg('sendMessage', $p);
        if ($r) $ids[] = [$cid, $r['result']['message_id']];
    }
    return $ids;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------------- text helpers ---------------- */
function fa_digits(string $s): string { return strtr($s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); }
function num(string $lang, $n): string { return $lang === 'fa' ? fa_digits((string)$n) : (string)$n; }
function normalize_fa(string $s): string { return strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', '٤' => '۴', '٥' => '۵', '٦' => '۶']); }
function jalali(int $gy, int $gm, int $gd): array {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}
function show_date(string $lang, string $ymd): string {
    $t = strtotime($ymd . ' 12:00');
    if ($lang === 'fa') {
        [$jy, $jm, $jd] = jalali((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
        $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return fa_digits("$jd {$months[$jm]} $jy");
    }
    return $lang === 'en' ? date('F j, Y', $t) : date('Y-m-d', $t);
}
function heading_id(string $text): string {
    $t = str_replace("\u{200C}", '', trim(strip_tags(html_entity_decode($text))));
    $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $t));
    return trim(preg_replace('/\s+/u', '-', $t), '-');
}
function word_count(string $html): int {
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    return $t === '' ? 0 : count(explode(' ', $t));
}
function kw_in(string $needle, string $hay): bool {
    $n = mb_strtolower(str_replace("\u{200C}", ' ', $needle));
    return mb_strpos(mb_strtolower(str_replace("\u{200C}", ' ', $hay)), $n) !== false;
}

/* ---------------- site knowledge ---------------- */
/** URLs an article may link to: home pages, blog pages, configured anchors, site sitemap, our own posts. */
function known_urls(): array {
    $urls = [SITE . '/'];
    foreach (langs() as $l) { $urls[] = L($l)['home']; $urls[] = SITE . L($l)['base']; foreach (posts($l) as $p) $urls[] = post_url($l, $p['slug']); }
    foreach ((array)site('link_targets', []) as $u) $urls[] = abs_url((string)$u);
    foreach ((array)site('sitemaps', ['/sitemap.xml']) as $f) {
        $x = (string)@file_get_contents(PUB . '/' . ltrim($f, '/'));
        if ($x === '') {   // the site's own sitemap is served by the app, not from public_html: fetch it (cached for an hour)
            $c = AB . '/state/sitemap-' . substr(md5($f), 0, 8) . '.xml';
            if (!is_file($c) || filemtime($c) < time() - 3600) { $r = http(SITE . '/' . ltrim($f, '/'), null, 20); if ($r['code'] === 200 && str_contains($r['body'], '<loc>')) @file_put_contents($c, $r['body']); }
            $x = (string)@file_get_contents($c);
        }
        if (preg_match_all('#<loc>([^<]+)</loc>#', $x, $m)) $urls = array_merge($urls, array_map('trim', $m[1]));
    }
    return array_values(array_unique($urls));
}

/* ---------------- sanitizing & checks ---------------- */
function sanitize_body(string $html, array &$errors): string {
    $allowed = ['p','h2','h3','h4','ul','ol','li','strong','em','b','i','a','blockquote','br','table','thead','tbody','tr','th','td'];
    $drop = ['script','style','iframe','object','embed','form','input','img','svg','h1'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('root');
    if (!$root) { $errors[] = 'body_html unreadable'; return ''; }
    $walk = function (DOMNode $n) use (&$walk, $allowed, $drop, &$errors) {
        for ($c = $n->firstChild; $c; $c = $next) {
            $next = $c->nextSibling;
            if ($c instanceof DOMComment) { $n->removeChild($c); continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, $drop, true)) { if ($tag === 'h1') $errors[] = 'H1 inside body'; $n->removeChild($c); continue; }
            $walk($c);
            if (!in_array($tag, $allowed, true)) {
                while ($c->firstChild) $n->insertBefore($c->firstChild, $c);
                $n->removeChild($c);
                continue;
            }
            $keep = [];
            if ($tag === 'a') $keep['href'] = $c->getAttribute('href');
            while ($c->attributes->length) $c->removeAttribute($c->attributes->item(0)->nodeName);
            foreach ($keep as $k => $v) $c->setAttribute($k, $v);
            if ($tag === 'p' && trim($c->textContent) === '') $n->removeChild($c);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $ch) $out .= $doc->saveHTML($ch);
    return trim($out);
}

/** Validates one language version. Returns the prepared array; appends problems to $errors (prefixed with the language). */
function prepare_lang(string $lang, array $a, string $slug, string $date, array &$errors, array &$warnings): array {
    $P = strtoupper($lang) . ': ';
    $Lc = L($lang);
    $n0 = count($errors);
    foreach (['title', 'seo_title', 'meta_description', 'excerpt', 'body_html', 'primary_keyword'] as $k)
        if (empty($a[$k]) || !is_string($a[$k])) $errors[] = $P . "missing $k";
    if (count($errors) > $n0) return $a;
    foreach (['title', 'seo_title', 'meta_description', 'excerpt', 'body_html', 'primary_keyword'] as $k)
        $a[$k] = $lang === 'fa' ? normalize_fa(trim($a[$k])) : trim($a[$k]);
    $a['faq'] = array_values(array_filter((array)($a['faq'] ?? []), fn($x) => !empty($x['q']) && !empty($x['a'])));
    foreach ($a['faq'] as &$f) {
        $f['q'] = trim(strip_tags($f['q'])); $f['a'] = trim(strip_tags($f['a']));
        if ($lang === 'fa') { $f['q'] = normalize_fa($f['q']); $f['a'] = normalize_fa($f['a']); }
    } unset($f);

    $len = mb_strlen($a['seo_title']);
    if ($len < 20 || $len > 65) $errors[] = $P . "seo_title length $len (20–65)";
    $len = mb_strlen($a['meta_description']);
    if ($len < 110 || $len > 170) $errors[] = $P . "meta_description length $len (110–170)";
    if (!kw_in($a['primary_keyword'], $a['title'] . ' ' . $a['seo_title'])) $errors[] = $P . 'primary keyword not in title';

    $body = sanitize_body($a['body_html'], $errors);
    $wc = word_count($body);
    if ($wc < $Lc['min_words'] || $wc > 3000) $errors[] = $P . "word count $wc ({$Lc['min_words']}–3000)";
    if (!kw_in($a['primary_keyword'], mb_substr(strip_tags($body), 0, 900))) $errors[] = $P . 'primary keyword not in the opening paragraphs';
    preg_match_all('#<h2>(.*?)</h2>#su', $body, $hm);
    if (count($hm[1]) < 3) $errors[] = $P . 'needs at least 3 H2';

    $known = known_urls();
    $internal = 0;
    $self = post_url($lang, $slug);
    // unknown internal links are unwrapped (kept as text) instead of failing the article
    $body = preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#su', function ($m) use (&$warnings, &$internal, $known, $self, $P) {
        $href = html_entity_decode($m[1]);
        $abs = str_starts_with($href, '/') ? SITE . $href : $href;
        if (str_starts_with($abs, SITE)) {
            $page = explode('#', $abs)[0];   // an anchor on a known page (e.g. /#contact) is fine
            if ($page === $self || (!in_array($abs, $known, true) && !in_array($page, $known, true))) { $warnings[] = $P . "unlinked: $href"; return $m[2]; }
            $internal++;
            return '<a href="' . h($href) . '">' . $m[2] . '</a>';
        }
        if (str_starts_with($abs, 'https://')) return '<a href="' . h($href) . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
        $warnings[] = $P . "unlinked: $href";
        return $m[2];
    }, $body);
    $minInternal = (int)site('min_internal_links', 2);
    if ($internal < $minInternal) $errors[] = $P . "only $internal valid internal links (min $minInternal)";
    if (!empty($Lc['cta']) && !str_contains($body, (string)$Lc['cta'])) $errors[] = $P . "call-to-action link {$Lc['cta']} missing";
    if ($a['faq'] && (count($a['faq']) < 3 || count($a['faq']) > 6)) $errors[] = $P . 'FAQ must have 3–6 items';

    $toc = []; $seen = [];
    $body = preg_replace_callback('#<h2>(.*?)</h2>#su', function ($m) use (&$toc, &$seen) {
        $id = heading_id($m[1]) ?: 'section';
        while (isset($seen[$id])) $id .= '-2';
        $seen[$id] = 1;
        $toc[] = [$id, trim(html_entity_decode(strip_tags($m[1])))];
        return '<h2 id="' . h($id) . '">' . $m[1] . '</h2>';
    }, $body);
    $a['body_clean'] = $body;
    $a['toc'] = $toc;
    $a['words'] = $wc;
    $a['read_min'] = max(3, (int)ceil($wc / (int)$Lc['words_per_min']));
    $a['slug'] = $slug;
    $a['date'] = $date;
    return $a;
}

function validate_draft(array $d, array &$errors, array &$warnings): array {
    $slug = (string)($d['slug'] ?? '');
    $date = (string)($d['date'] ?? date('Y-m-d'));
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 60) $errors[] = 'bad slug';
    else foreach (langs() as $l) if (is_dir(L($l)['dir'] . '/' . $slug)) { $errors[] = 'slug already exists'; break; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $cl = (string)($d['cluster'] ?? '');
    if ($cl !== '' && !array_key_exists($cl, (array)site('clusters', []))) { $warnings[] = "unknown cluster $cl"; $cl = ''; }
    $out = ['slug' => $slug, 'date' => $date, 'topic_id' => $d['topic_id'] ?? '', 'image_queries' => $d['image_queries'] ?? [], 'cluster' => $cl];
    foreach (langs() as $lang) {
        if (!isset($d[$lang]) || !is_array($d[$lang])) { $errors[] = strtoupper($lang) . ': version missing'; continue; }
        $out[$lang] = prepare_lang($lang, $d[$lang], $slug, $date, $errors, $warnings);
        $out[$lang]['cluster'] = $cl;
    }
    return $out;
}

/* ---------------- rendering ---------------- */
function tpl(string $name): string {
    $f = AB . '/templates/' . $name;
    if (!is_file($f)) throw new RuntimeException("template missing: templates/$name");
    return (string)file_get_contents($f);
}
function common_vars(string $lang): array {
    $Lc = L($lang);
    $alts = ''; $switch = '';
    if (count(langs()) > 1) {
        foreach (langs() as $l) $alts .= '<link rel="alternate" hreflang="' . $l . '" href="{{ALT_' . $l . '}}">';
        $alts .= '<link rel="alternate" hreflang="x-default" href="{{ALT_' . lang1() . '}}">';
        foreach (langs() as $l) if ($l !== $lang) $switch .= '<a class="nn-lang" href="{{ALT_' . $l . '}}" hreflang="' . $l . '" lang="' . $l . '">' . h(L($l)['label']) . '</a>';
    }
    return ['{{LANG}}' => $lang, '{{DIR}}' => $Lc['dir_attr'], '{{SITE_NAME}}' => h((string)$Lc['site_name']),
            '{{HOME_URL}}' => h($Lc['home']), '{{HOME_NAME}}' => h($Lc['home_name']), '{{BLOG_URL}}' => h(SITE . $Lc['base']),
            '{{BLOG_NAME}}' => h($Lc['blog_name']), '{{CTA_URL}}' => h((string)($Lc['cta'] ?? '')), '{{CTA_TEXT}}' => h($Lc['cta_text']),
            '{{FEED_URL}}' => h(SITE . $Lc['base'] . 'feed.xml'), '{{YEAR}}' => num($lang, date('Y')), '{{HREFLANG}}' => $alts, '{{LANG_SWITCH}}' => $switch];
}
function fill_alts(string $html, callable $urlOf): string {
    foreach (langs() as $l) $html = str_replace('{{ALT_' . $l . '}}', h($urlOf($l)), $html);
    return $html;
}

function render_article(string $lang, array $a, bool $live): string {
    $Lc = L($lang);
    $url = post_url($lang, $a['slug']);
    $iso = $a['date'] . 'T09:00:00' . date('P');
    $ogimg = !empty($a['cover']) ? strtok($a['cover']['url'], '?') : abs_url((string)site('default_og_image', ''));
    $person = array_filter(['@type' => 'Person', 'name' => $Lc['author'], 'url' => $Lc['author_url'], 'jobTitle' => $Lc['author_title'] ?? '',
                            'image' => abs_url((string)site('author_image', ''))]);
    $publisher = array_filter(['@type' => (string)site('publisher_type', 'Organization'), 'name' => $Lc['site_name'], 'url' => $Lc['home'],
                               'logo' => abs_url((string)site('logo', ''))]);
    $ld = [array_filter([
        '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $a['title'], 'description' => $a['meta_description'],
        'datePublished' => $iso, 'dateModified' => $iso, 'inLanguage' => $lang, 'mainEntityOfPage' => $url,
        'author' => $person, 'publisher' => $publisher, 'image' => $ogimg,
    ]), [
        '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $Lc['home_name'], 'item' => $Lc['home']],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $Lc['blog_name'], 'item' => SITE . $Lc['base']],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $a['title']],
        ]]];
    if ($a['faq']) $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $a['faq'])];
    $ldHtml = '';
    foreach ($ld as $obj) $ldHtml .= '<script type="application/ld+json">' . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";

    $toc = '';
    if (count($a['toc']) >= 3) {
        $toc = '<details class="toc nn-toc"><summary>' . h($Lc['toc']) . '</summary><ol>';
        foreach ($a['toc'] as [$id, $t]) $toc .= '<li><a href="#' . h($id) . '">' . h($t) . '</a></li>';
        if ($a['faq']) $toc .= '<li><a href="#faq-section">' . h($Lc['faq']) . '</a></li>';
        $toc .= '</ol></details>';
    }
    $prose = $a['body_clean'];
    if ($a['faq']) {
        $prose .= '<h2 id="faq-section">' . h($Lc['faq']) . '</h2>';
        foreach ($a['faq'] as $f) $prose .= '<h3>' . h($f['q']) . '</h3><p>' . h($f['a']) . '</p>';
    }
    $html = strtr(tpl($Lc['tpl']), common_vars($lang) + [
        '{{SEO_TITLE}}' => h($a['seo_title'] . $Lc['suffix']),
        '{{DESC}}' => h($a['meta_description']),
        '{{ROBOTS}}' => $live ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, nofollow',
        '{{URL}}' => h($url),
        '{{OG_IMAGE}}' => h($ogimg),
        '{{ISO}}' => $iso,
        '{{LD}}' => $ldHtml,
        '{{TITLE}}' => h($a['title']),
        '{{AUTHOR_HTML}}' => $Lc['author_url'] ? '<a href="' . h($Lc['author_url']) . '" rel="author">' . h($Lc['author']) . '</a>' : h($Lc['author']),
        '{{AUTHOR_NAME}}' => h($Lc['author']),
        '{{AUTHOR_BIO}}' => h(trim(($Lc['author_title'] ?? '') . (empty($Lc['disclaimer']) ? '' : '. ' . $Lc['disclaimer']), '. ')),
        '{{TIME}}' => '<time datetime="' . $a['date'] . '">' . show_date($lang, $a['date']) . '</time>',
        '{{READ}}' => num($lang, $a['read_min']) . $Lc['read'],
        '{{COVER}}' => cover_html($lang, $a),
        '{{TOC}}' => $toc,
        '{{PROSE}}' => $prose,
        '{{RELATED_TITLE}}' => h($Lc['related']),
        '{{CAT_NAME}}' => h(cat_name((string)($a['cluster'] ?? ''))),
        '{{CAT_URL}}' => h(cat_url((string)($a['cluster'] ?? '')) ?: SITE . $Lc['base']),
        '{{MODIFIED}}' => '<time datetime="' . $a['date'] . '">' . show_date($lang, $a['date']) . '</time>',
        '{{RELATED}}' => '<!--nn:related-->' . related_cards($lang, $a['slug'], (string)($a['cluster'] ?? '')) . '<!--/nn:related-->',
    ]);
    return fill_alts($html, fn($l) => post_url($l, $a['slug']));
}

/** Category (cluster) label and page; the page exists only when site.json has "category_base", e.g. "/blog/category/". */
function cat_name(string $key): string { return (string)(((array)site('clusters', []))[$key] ?? ''); }
function cat_url(string $key): string { $b = (string)site('category_base', ''); return ($b !== '' && $key !== '') ? '/' . trim($b, '/') . "/$key/" : ''; }
function card_html(string $lang, array $p): string {
    $Lc = L($lang);
    $img = $p['card'] ? '<img src="' . h($p['card']) . '" alt="' . h($p['title']) . '" loading="lazy" width="768" height="480">' : '';
    return strtr(tpl('card.html'), ['{{HREF}}' => h($Lc['base'] . $p['slug'] . '/'), '{{IMG}}' => $img, '{{TITLE}}' => h($p['title']),
        '{{EXCERPT}}' => h($p['excerpt']), '{{DATE}}' => show_date($lang, $p['date']), '{{READ}}' => num($lang, $p['read_min']) . $Lc['read'],
        '{{CAT}}' => h(cat_name((string)($p['cat'] ?? ''))), '{{CAT_URL}}' => h(cat_url((string)($p['cat'] ?? '')))]);
}
/** Three "more articles" cards: new posts of the same category first, then the site's own older cards from that
 *  category page (so the block is never empty on a site that already had articles), then any other new post. */
function related_cards(string $lang, string $slug, string $cat = ''): string {
    $others = array_values(array_filter(posts($lang), fn($p) => $p['slug'] !== $slug));
    $same = array_values(array_filter($others, fn($p) => $cat !== '' && ($p['cat'] ?? '') === $cat));
    $cards = array_map(fn($p) => card_html($lang, $p), array_slice($same, 0, 3));
    $fallback = cat_url($cat) !== '' ? cat_url($cat) . 'index.html' : (string)site('related_fallback', '');   // e.g. "/blog/index.html"
    if (count($cards) < 3 && $fallback !== '') {
        $page = (string)@file_get_contents(PUB . '/' . ltrim($fallback, '/'));
        $page = preg_replace('#<!--nn:cards-->.*?<!--/nn:cards-->#s', '', $page);
        preg_match_all('#<a (?=[^>]*class="(?:post|nn-card)\b)[^>]*href="([^"]+)"[^>]*>.*?</a>#s', $page, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (count($cards) >= 3) break;
            if (str_contains($x[1], "/$slug/") || str_ends_with($x[1], "/$slug")) continue;
            $cards[] = $x[0];
        }
    }
    foreach ($others as $p) {
        if (count($cards) >= 3) break;
        if (!in_array($p, $same, true)) $cards[] = card_html($lang, $p);
    }
    return implode("\n", $cards) . ($cards ? "\n" : '');
}
function replace_region(string $html, string $name, string $inner, ?int &$count = 0): string {
    return preg_replace_callback('#<!--nn:' . $name . '-->.*?<!--/nn:' . $name . '-->#s',
        fn() => "<!--nn:$name-->\n$inner<!--/nn:$name-->", $html, 1, $count);
}

/* ---------------- blog index, related blocks, sitemap, feed ---------------- */
function render_index(string $lang): string {
    $Lc = L($lang);
    $cards = '';
    foreach (posts($lang) as $p) $cards .= card_html($lang, $p) . "\n";
    if ($cards === '') $cards = '<p class="nn-empty">' . h($Lc['empty']) . "</p>\n";
    $html = strtr(tpl("index-$lang.html"), common_vars($lang) + [
        '{{ROBOTS}}' => posts($lang) ? 'index, follow, max-image-preview:large' : 'noindex, follow',
        '{{URL}}' => h(SITE . $Lc['base']),
        '{{BLOG_TITLE}}' => h((string)($Lc['blog_title'] ?? $Lc['blog_name'] . ' ' . $Lc['site_name'])),
        '{{BLOG_DESC}}' => h((string)($Lc['blog_description'] ?? '')),
        '{{CARDS}}' => "<!--nn:cards-->\n$cards<!--/nn:cards-->",
    ]);
    return fill_alts($html, fn($l) => SITE . L($l)['base']);
}
/** Rebuilds the blog index: only the card region if the page has the markers, otherwise the whole page from the template. */
function update_index(string $lang): string {
    $f = L($lang)['dir'] . '/index.html';
    $cur = (string)@file_get_contents($f);
    if ($cur !== '' && str_contains($cur, '<!--nn:cards-->')) {
        $cards = '';
        foreach (posts($lang) as $p) $cards .= card_html($lang, $p) . "\n";
        $new = replace_region($cur, 'cards', $cards);
        if (posts($lang)) $new = preg_replace('#<meta name="robots" content="noindex[^"]*">#', '<meta name="robots" content="index, follow, max-image-preview:large">', $new);
        write_file($f, $new);
        return 'index (cards)';
    }
    if ($cur !== '') { logline("index $f has no <!--nn:cards--> markers; left untouched"); return 'index skipped (no markers)'; }
    write_file($f, render_index($lang));
    return 'index (new)';
}
function write_sitemap(string $lang): void {
    $Lc = L($lang);
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
       . '<url><loc>' . SITE . $Lc['base'] . '</loc><lastmod>' . date('Y-m-d') . "</lastmod></url>\n";
    foreach (posts($lang) as $p) $x .= '<url><loc>' . post_url($lang, $p['slug']) . '</loc><lastmod>' . $p['date'] . "</lastmod></url>\n";
    write_file($Lc['dir'] . '/sitemap-blog.xml', $x . "</urlset>\n");
}
function build_feed(string $lang): string {
    $Lc = L($lang);
    $items = '';
    foreach (array_slice(posts($lang), 0, 20) as $p) {
        $u = post_url($lang, $p['slug']);
        $items .= '  <item><title>' . h($p['title']) . "</title><link>$u</link><guid>$u</guid><pubDate>" . date(DATE_RSS, strtotime($p['date'] . ' 09:00'))
            . '</pubDate><description>' . h($p['excerpt']) . "</description></item>\n";
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<rss version=\"2.0\"><channel>\n<title>" . h($Lc['feed_title']) . "</title>\n<link>"
        . SITE . $Lc['base'] . "</link>\n<description>" . h($Lc['feed_title']) . "</description>\n<language>$lang</language>\n" . $items . "</channel></rss>\n";
}
/* ---------------- home page "latest articles" block ---------------- */
/** File of a language's home page, e.g. "/" -> public_html/index.html, "/en/" -> public_html/en/index.html. */
function home_file(string $lang): string {
    $p = trim((string)parse_url(L($lang)['home'], PHP_URL_PATH), '/');
    return PUB . ($p === '' ? '' : "/$p") . '/index.html';
}
/** First time only: wraps the cards already in the home page's article grid in <!--nn:latest:N--> markers.
 *  The grid is the first <div> whose class has one of site.json "home_grid_classes" (default: home-posts). */
function home_mark(string $html): ?string {
    foreach ((array)site('home_grid_classes', ['home-posts']) as $cls) {
        $re = '#(<div\b[^>]*\bclass="[^"]*\b' . preg_quote((string)$cls, '#') . '\b[^"]*"[^>]*>)'
            . '((?:\s*<a\b(?=[^>]*\bclass="(?:post|nn-card)\b)[^>]*>.*?</a>)+)#s';
        if (!preg_match($re, $html, $m, PREG_OFFSET_CAPTURE)) continue;
        $n = preg_match_all('#<a\b[^>]*>#', $m[2][0]);
        $wrapped = $m[1][0] . "<!--nn:latest:$n-->" . $m[2][0] . "\n<!--/nn:latest-->";
        return substr($html, 0, $m[0][1]) . $wrapped . substr($html, $m[0][1] + strlen($m[0][0]));
    }
    return null;
}
/** Newest articles first, then the site's own older cards from the blog index, as many as the block held at first. */
function home_cards(string $lang, int $n): string {
    $cards = []; $seen = [];
    foreach (posts($lang) as $p) {
        if (count($cards) >= $n) break;
        $href = L($lang)['base'] . $p['slug'] . '/';
        if (isset($seen[$href])) continue;
        $seen[$href] = 1; $cards[] = card_html($lang, $p);
    }
    $page = (string)@file_get_contents(L($lang)['dir'] . '/index.html');
    $page = preg_replace('#<!--nn:cards-->.*?<!--/nn:cards-->#s', '', $page);
    preg_match_all('#<a (?=[^>]*class="(?:post|nn-card)\b)[^>]*href="([^"]+)"[^>]*>.*?</a>#s', $page, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if (count($cards) >= $n) break;
        $href = '/' . ltrim((string)parse_url($x[1], PHP_URL_PATH), '/');
        if (isset($seen[$href])) continue;
        $seen[$href] = 1; $cards[] = $x[0];
    }
    return implode("\n", $cards) . "\n";
}
/** Puts the newest articles at the top of the home page's article block. Pages without such a block are left alone. */
function update_home(string $lang): string {
    $f = home_file($lang);
    $html = (string)@file_get_contents($f);
    if ($html === '') return 'home skipped (no page)';
    if (!preg_match('#<!--nn:latest(?::(\d+))?-->#', $html)) {
        $marked = home_mark($html);
        if ($marked === null) { logline("home $f has no article grid (" . implode(', ', (array)site('home_grid_classes', ['home-posts'])) . '); left untouched'); return 'home skipped (no grid)'; }
        $html = $marked;
    }
    preg_match('#<!--nn:latest(?::(\d+))?-->#', $html, $mm);
    $n = max(1, (int)($mm[1] ?? 0) ?: (int)site('home_cards', 6));
    $new = preg_replace_callback('#<!--nn:latest(?::\d+)?-->.*?<!--/nn:latest-->#s',
        fn() => "<!--nn:latest:$n-->\n" . home_cards($lang, $n) . '<!--/nn:latest-->', $html, 1);
    if ($new !== null && $new !== (string)@file_get_contents($f)) write_file($f, $new);
    return 'home';
}
/** Runs from cron: refreshes the home block whenever the newest articles changed (also heals installs updated after publishing). */
function sync_homes(array &$s): void {
    foreach (langs() as $lang) {
        $sig = md5(implode('|', array_map(fn($p) => $p['slug'], array_slice(posts($lang), 0, 12))) . '#' . @filemtime(L($lang)['dir'] . '/index.html'));
        if (($s['home_sig'][$lang] ?? '') === $sig) continue;
        try { $r = update_home($lang); logline("home $lang: $r"); }
        catch (Throwable $e) { logline("home $lang failed: " . $e->getMessage()); }
        $s['home_sig'][$lang] = $sig;
    }
}

function publish_side_effects(string $lang, array $a): array {
    posts_add($lang, $a);
    $done = [update_index($lang)];
    try { $done[] = update_home($lang); } catch (Throwable $e) { logline('home update failed: ' . $e->getMessage()); }
    write_sitemap($lang); $done[] = 'sitemap';
    write_file(L($lang)['dir'] . '/feed.xml', build_feed($lang)); $done[] = 'rss';
    // category page: same marker region, only this category's posts
    $cu = cat_url((string)($a['cluster'] ?? ''));
    if ($cu !== '' && is_file(PUB . $cu . 'index.html')) {
        $f = PUB . $cu . 'index.html';
        $html = (string)file_get_contents($f);
        if (str_contains($html, '<!--nn:cards-->')) {
            $cards = '';
            foreach (posts($lang) as $p) if (($p['cat'] ?? '') === $a['cluster']) $cards .= card_html($lang, $p) . "\n";
            write_file($f, replace_region($html, 'cards', $cards));
            $done[] = 'category ' . $a['cluster'];
        }
    }
    // refresh the "more articles" block on the two newest older posts so the new one gets links right away
    foreach (array_slice(array_values(array_filter(posts($lang), fn($p) => $p['slug'] !== $a['slug'])), 0, 2) as $p) {
        $f = L($lang)['dir'] . '/' . $p['slug'] . '/index.html';
        $html = (string)@file_get_contents($f);
        if ($html === '' || !str_contains($html, '<!--nn:related-->')) continue;
        write_file($f, replace_region($html, 'related', related_cards($lang, $p['slug'], (string)($p['cat'] ?? ''))));
        $done[] = 'related ' . $p['slug'];
    }
    return $done;
}

function indexnow(array $urls): string {
    if (!INDEXNOW_KEY) return 'no key';
    $r = http((string)site('indexnow_endpoint', 'https://api.indexnow.org/indexnow'), ['host' => (string)parse_url(SITE, PHP_URL_HOST), 'key' => INDEXNOW_KEY,
        'keyLocation' => SITE . '/' . INDEXNOW_KEY . '.txt', 'urlList' => $urls], 20);
    return (string)$r['code'];
}

/* ---------------- publishing schedule ---------------- */
/** Today's publish times: each configured slot shifted by a random number of minutes either way, fixed for the day. */
function todays_slots(array &$s): array {
    $day = date('Y-m-d');
    if (empty($s['slots'][$day])) {
        [$lo, $hi] = (array)site('slot_jitter_minutes', [7, 25]) + [7, 25];
        $mk = function (string $base) use ($day, $lo, $hi) {
            $shift = random_int((int)$lo, (int)$hi) * 60 + random_int(0, 59);
            return strtotime("$day $base") + (random_int(0, 1) ? $shift : -$shift);
        };
        $s['slots'] = [$day => array_map($mk, (array)site('publish_slots', ['11:00', '20:00']))];
    }
    return $s['slots'][$day];
}
function next_slot_text(array &$s): string {
    foreach (todays_slots($s) as $t) if ($t > time()) return 'امروز حدود ساعت ' . fa_digits(date('H:i', $t));
    return 'فردا';
}
