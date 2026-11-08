<?php
// NikMind autoblog v2 — bilingual (fa + en). Runs on the DirectAdmin host (PHP 8.1 CLI via cron).
declare(strict_types=1);

const SITE     = 'https://iranian.nikmindclinic.com';
const HOME_DIR = '/home/mrnazif';
const PUB      = HOME_DIR . '/domains/iranian.nikmindclinic.com/public_html';
const BLOG     = PUB . '/blog';
const AB       = HOME_DIR . '/autoblog';

require_once AB . '/config.php';
require_once AB . '/images.php';

function L(string $lang): array {
    static $L = null;
    $L ??= [
        'fa' => ['base' => '/blog/', 'dir' => PUB . '/blog', 'tpl' => 'article.html', 'cta' => '#precounsel',
                 'author' => 'دکتر میثم نیک‌دانش', 'author_url' => SITE . '/therapists/meysam-nikdanesh/',
                 'author_title' => 'دکترای روانشناسی بالینی، روان‌درمانگر و بنیان‌گذار کلینیک نیک‌مایند',
                 'disclaimer' => 'مطالب این بخش برای آگاهی عمومی است و جای جلسه‌ی ارزیابی با روانشناس یا روانپزشک را نمی‌گیرد.',
                 'read' => ' دقیقه مطالعه', 'toc' => 'فهرست مطالب', 'faq' => 'سؤال‌های متداول', 'suffix' => ' | نیک‌مایند',
                 'publisher' => 'کلینیک روانشناسی نیک‌مایند', 'home' => SITE . '/', 'blog_name' => 'مقالات', 'home_name' => 'خانه',
                 'inlang' => 'fa', 'feed_title' => 'مقالات نیک‌مایند — سلامت روان برای ایرانیان خارج از کشور', 'min_words' => 900],
        'en' => ['base' => '/en/blog/', 'dir' => PUB . '/en/blog', 'tpl' => 'article-en.html', 'cta' => '/en/#precounsel',
                 'author' => 'Dr. Meysam Nikdanesh', 'author_url' => SITE . '/en/therapists/meysam-nikdanesh/',
                 'author_title' => 'PhD in Clinical Psychology, psychotherapist and founder of NikMind Clinic',
                 'disclaimer' => 'Articles here are for general information and do not replace an assessment session with a psychologist or psychiatrist.',
                 'read' => ' min read', 'toc' => 'Contents', 'faq' => 'Frequently asked questions', 'suffix' => ' | NikMind',
                 'publisher' => 'NikMind Clinic', 'home' => SITE . '/en/', 'blog_name' => 'Articles', 'home_name' => 'Home',
                 'inlang' => 'en', 'feed_title' => 'NikMind Articles — Mental health for Iranians abroad', 'min_words' => 900],
    ];
    return $L[$lang];
}

/* ---------------- logging & state ---------------- */
function logline(string $m): void {
    @mkdir(AB . '/logs', 0700, true);
    file_put_contents(AB . '/logs/' . date('Y-m') . '.log', date('c') . ' ' . $m . "\n", FILE_APPEND);
}
function state_load(): array {
    $f = AB . '/state/state.json';
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $def = ['chat_ids' => [], 'tg_offset' => 0, 'drafts' => [], 'published' => [], 'queue' => [], 'slots' => [],
            'last_gh' => 0, 'fails' => 0, 'paused' => false, 'last_pub' => 0];
    return is_array($s) ? array_merge($def, $s) : $def;
}
function state_save(array $s): void {
    @mkdir(AB . '/state', 0700, true);
    $tmp = AB . '/state/state.json.tmp';
    file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, AB . '/state/state.json');
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
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'nikmind-autoblog/2.0',
            CURLOPT_FOLLOWLOCATION => true];
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

/* ---------------- telegram ---------------- */
function tg(string $method, array $params): ?array {
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '' || str_contains(TG_BOT_TOKEN, 'PASTE')) return null;
    $r = http('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/' . $method, $params, 40);
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['ok'])) { logline("tg $method failed: " . substr($r['body'] ?: $r['err'], 0, 300)); return null; }
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
function normalize_fa(string $s): string {
    return strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', '٤' => '۴', '٥' => '۵', '٦' => '۶']);
}
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
    if ($lang === 'en') return date('F j, Y', strtotime($ymd . ' 12:00'));
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    [$jy, $jm, $jd] = jalali($y, $m, $d);
    $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return fa_digits("$jd {$months[$jm]} $jy");
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
    $h = mb_strtolower(str_replace("\u{200C}", ' ', $hay));
    return mb_strpos($h, $n) !== false;
}

/* ---------------- site knowledge ---------------- */
function known_urls(): array {
    $urls = [SITE . '/', SITE . '/blog/', SITE . '/en/', SITE . '/en/blog/'];
    foreach (['precounsel', 'services', 'contact', 'faq', 'how', 'pricing'] as $a) { $urls[] = SITE . "/#$a"; $urls[] = SITE . "/en/#$a"; }
    foreach ([PUB . '/sitemap.xml', PUB . '/blog/sitemap-blog.xml', PUB . '/en/blog/sitemap-blog.xml'] as $f) {
        if (preg_match_all('#<loc>([^<]+)</loc>#', (string)@file_get_contents($f), $m)) $urls = array_merge($urls, $m[1]);
    }
    return array_values(array_unique($urls));
}
function index_file(string $lang): string { return L($lang)['dir'] . '/index.html'; }
function index_cards(string $lang): array {
    $html = (string)@file_get_contents(index_file($lang));
    $base = preg_quote(L($lang)['base'], '#');
    preg_match_all('#<a class="post" href="(' . $base . '[a-z0-9-]+/)">.*?</a>#s', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => ['href' => $x[1], 'html' => $x[0]], $m);
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

/** Validates one language version. Returns prepared array; appends problems to $errors (prefixed with lang). */
function prepare_lang(string $lang, array $a, string $slug, string $date, array &$errors, array &$warnings): array {
    $P = strtoupper($lang) . ': ';
    $Lc = L($lang);
    foreach (['title', 'seo_title', 'meta_description', 'excerpt', 'body_html', 'primary_keyword'] as $k)
        if (empty($a[$k]) || !is_string($a[$k])) { $errors[] = $P . "missing $k"; }
    if ($errors) return $a;
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
    if ($wc < $Lc['min_words'] || $wc > 2800) $errors[] = $P . "word count $wc ({$Lc['min_words']}–2800)";
    if (!kw_in($a['primary_keyword'], mb_substr(strip_tags($body), 0, 900))) $errors[] = $P . 'primary keyword not in the opening paragraphs';
    preg_match_all('#<h2>(.*?)</h2>#su', $body, $hm);
    if (count($hm[1]) < 3) $errors[] = $P . 'needs at least 3 H2';

    $known = known_urls();
    $internal = 0;
    $self = SITE . $Lc['base'] . $slug . '/';
    // unknown internal links are unwrapped (kept as text) instead of failing the article
    $body = preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#su', function ($m) use (&$errors, &$warnings, &$internal, $known, $self, $P) {
        $href = html_entity_decode($m[1]);
        $abs = str_starts_with($href, '/') ? SITE . $href : $href;
        if (str_starts_with($abs, SITE)) {
            if ($abs === $self || !in_array($abs, $known, true)) { $warnings[] = $P . "unlinked: $href"; return $m[2]; }
            $internal++;
            return '<a href="' . h($href) . '">' . $m[2] . '</a>';
        }
        if (str_starts_with($abs, 'https://')) return '<a href="' . h($href) . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
        $warnings[] = $P . "unlinked: $href";
        return $m[2];
    }, $body);
    if ($internal < 2) $errors[] = $P . "only $internal valid internal links (min 2)";
    if (!str_contains($body, $Lc['cta'])) $errors[] = $P . "booking link {$Lc['cta']} missing";
    if ($a['faq'] && (count($a['faq']) < 3 || count($a['faq']) > 6)) $errors[] = $P . 'FAQ must have 3–6 items';

    $toc = [];
    $seen = [];
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
    $a['read_min'] = max(3, (int)ceil($wc / ($lang === 'fa' ? 200 : 230)));
    $a['slug'] = $slug;
    $a['date'] = $date;
    return $a;
}

function validate_draft(array $d, array &$errors, array &$warnings): array {
    $slug = (string)($d['slug'] ?? '');
    $date = (string)($d['date'] ?? date('Y-m-d'));
    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 60) $errors[] = 'bad slug';
    elseif (is_dir(L('fa')['dir'] . '/' . $slug) || is_dir(L('en')['dir'] . '/' . $slug)) $errors[] = 'slug already exists';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $out = ['slug' => $slug, 'date' => $date, 'topic_id' => $d['topic_id'] ?? ''];
    foreach (['fa', 'en'] as $lang) {
        if (!isset($d[$lang]) || !is_array($d[$lang])) { $errors[] = strtoupper($lang) . ': version missing'; continue; }
        $out[$lang] = prepare_lang($lang, $d[$lang], $slug, $date, $errors, $warnings);
    }
    return $out;
}

/* ---------------- rendering ---------------- */
function render_article(string $lang, array $a, bool $live, bool $hasOther = true): string {
    $Lc = L($lang);
    $tpl = (string)file_get_contents(AB . '/templates/' . $Lc['tpl']);
    $url = SITE . $Lc['base'] . $a['slug'] . '/';
    $faUrl = SITE . L('fa')['base'] . $a['slug'] . '/';
    $enUrl = SITE . L('en')['base'] . $a['slug'] . '/';
    $iso = $a['date'] . 'T09:00:00+03:30';
    $ogimg = !empty($a['cover']) ? strtok($a['cover']['url'], '?') : SITE . '/blog/assets/img/og-default.jpg';
    $person = ['@type' => 'Person', 'name' => $Lc['author'], 'url' => $Lc['author_url'], 'jobTitle' => $Lc['author_title'],
               'image' => SITE . '/images/team-1.jpg', 'worksFor' => ['@type' => 'MedicalClinic', 'name' => $Lc['publisher'], 'url' => $Lc['home']]];
    $ld = [[
        '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $a['title'], 'description' => $a['meta_description'],
        'datePublished' => $iso, 'dateModified' => $iso, 'inLanguage' => $Lc['inlang'], 'mainEntityOfPage' => $url,
        'author' => $person, 'reviewedBy' => $person,
        'publisher' => ['@type' => 'Organization', 'name' => $Lc['publisher'], 'url' => $Lc['home'], 'logo' => SITE . '/blog/assets/img/12a7b8f4-3b62-48d5-9eb2-6fae242db22f.png'],
        'image' => $ogimg,
    ], [
        '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $Lc['home_name'], 'item' => $Lc['home']],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $Lc['blog_name'], 'item' => SITE . $Lc['base']],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $a['title']],
        ]]];
    if ($a['faq']) {
        $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $a['faq'])];
    }
    $ldHtml = '';
    foreach ($ld as $obj) $ldHtml .= '<script type="application/ld+json">' . json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";

    $hreflang = $hasOther ? '<link rel="alternate" hreflang="fa" href="' . $faUrl . '"><link rel="alternate" hreflang="en" href="' . $enUrl
        . '"><link rel="alternate" hreflang="x-default" href="' . $faUrl . '">' : '';
    $switch = $hasOther ? ($lang === 'fa' ? '<a class="nav-link" href="' . $enUrl . '" hreflang="en" lang="en">English</a>'
                                          : '<a class="nav-link" href="' . $faUrl . '" hreflang="fa" lang="fa">فارسی</a>') : '';

    $toc = '';
    if (count($a['toc']) >= 3) {
        $toc = '<details class="toc"><summary>' . $Lc['toc'] . ' <svg class="ic"><use href="#i-chevron-down"/></svg></summary><ol>';
        foreach ($a['toc'] as [$id, $t]) $toc .= '<li><a href="#' . h($id) . '">' . h($t) . '</a></li>';
        if ($a['faq']) $toc .= '<li><a href="#faq-section">' . $Lc['faq'] . '</a></li>';
        $toc .= '</ol></details>';
    }
    $prose = $a['body_clean'];
    if ($a['faq']) {
        $prose .= '<h2 id="faq-section">' . $Lc['faq'] . '</h2>';
        foreach ($a['faq'] as $f) $prose .= '<h3>' . h($f['q']) . '</h3><p>' . h($f['a']) . '</p>';
    }
    $related = '';
    foreach (array_slice(array_filter(index_cards($lang), fn($c) => $c['href'] !== $Lc['base'] . $a['slug'] . '/'), 0, 3) as $c)
        $related .= '    ' . $c['html'] . "\n";

    return strtr($tpl, [
        '{{SEO_TITLE}}' => h($a['seo_title'] . $Lc['suffix']),
        '{{DESC}}' => h($a['meta_description']),
        '{{ROBOTS}}' => $live ? 'index, follow, max-image-preview:large, max-snippet:-1' : 'noindex, nofollow',
        '{{URL}}' => h($url),
        '{{HREFLANG}}' => $hreflang,
        '{{LANG_SWITCH}}' => $switch,
        '{{OG_IMAGE}}' => h($ogimg),
        '{{ISO}}' => $iso,
        '{{LD}}' => $ldHtml,
        '{{TITLE}}' => h($a['title']),
        '{{AUTHOR_HTML}}' => '<a href="' . h($Lc['author_url']) . '" rel="author">' . h($Lc['author']) . '</a>',
        '{{AUTHOR_NAME}}' => h($Lc['author']),
        '{{AUTHOR_BIO}}' => h($Lc['author_title']) . '. ' . h($Lc['disclaimer']),
        '{{TIME}}' => '<time datetime="' . $a['date'] . '">' . show_date($lang, $a['date']) . '</time>',
        '{{READ}}' => num($lang, $a['read_min']) . $Lc['read'],
        '{{COVER}}' => cover_html($lang, $a),
        '{{TOC}}' => $toc,
        '{{PROSE}}' => $prose,
        '{{RELATED}}' => $related,
    ]);
}

function card_html(string $lang, array $a): string {
    $Lc = L($lang);
    return '<a class="post" href="' . $Lc['base'] . $a['slug'] . '/"><div class="post-img">' . card_img($a) . '</div>' . "\n"
        . '    <div class="post-body"><h3>' . h($a['title']) . '</h3><p class="ex">' . h($a['excerpt']) . '</p><div class="post-meta"><span>'
        . show_date($lang, $a['date']) . '</span><span>' . num($lang, $a['read_min']) . $Lc['read'] . '</span></div></div></a>';
}

/* ---------------- publish side effects (per language) ---------------- */
function publish_side_effects(string $lang, array $a): array {
    $Lc = L($lang);
    $done = [];
    $url = SITE . $Lc['base'] . $a['slug'] . '/';
    $href = $Lc['base'] . $a['slug'] . '/';
    $card = card_html($lang, $a);
    $older = array_slice(index_cards($lang), 0, 2);

    // 1. index page (newest first); first english post also makes the english index indexable
    $idx = (string)file_get_contents(index_file($lang));
    if (!str_contains($idx, 'href="' . $href . '"')) {
        $new = preg_replace('#(<div class="blog-grid list">)#', '$1    ' . str_replace(['\\', '$'], ['\\\\', '\\$'], $card) . "\n", $idx, 1, $cnt);
        $new = str_replace('<meta name="robots" content="noindex, nofollow">', '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', $new);
        if ($cnt === 1) { write_file(index_file($lang), $new); $done[] = 'index'; }
    }
    // 2. sitemap
    $smf = $Lc['dir'] . '/sitemap-blog.xml';
    $sm = (string)file_get_contents($smf);
    if (!str_contains($sm, $url)) {
        $line = '<url><loc>' . $url . '</loc><lastmod>' . date('Y-m-d') . '</lastmod></url>';
        $sm = preg_replace('#<url><loc>' . preg_quote(SITE . $Lc['base'], '#') . '</loc>(?:<lastmod>[^<]*</lastmod>)?</url>#',
            '<url><loc>' . SITE . $Lc['base'] . '</loc><lastmod>' . date('Y-m-d') . "</lastmod></url>\n" . $line, $sm, 1, $cnt);
        if ($cnt === 1) { write_file($smf, $sm); $done[] = 'sitemap'; }
    }
    // 3. related block on the two newest older articles
    foreach ($older as $o) {
        $f = PUB . $o['href'] . 'index.html';
        $html = (string)@file_get_contents($f);
        if ($html === '' || str_contains($html, 'href="' . $href . '"')) continue;
        if (!preg_match('#(<span class="eyebrow">[^<]+</span><h2>[^<]+</h2></div>.*?<div class="blog-grid list">)(.*?)(\n</div>\n</div></section>)#s', $html, $m)) continue;
        preg_match_all('#<a class="post" href="[^"]+">.*?</a>#s', $m[2], $cards);
        $list = array_slice(array_merge([$card], $cards[0]), 0, 3);
        $grid = '';
        foreach ($list as $c) $grid .= '    ' . $c . "\n";
        write_file($f, str_replace($m[0], $m[1] . rtrim($grid, "\n") . $m[3], $html));
        $done[] = 'related ' . $o['href'];
    }
    // 4. feed
    write_file($Lc['dir'] . '/feed.xml', build_feed($lang));
    $done[] = 'rss';
    return $done;
}

function build_feed(string $lang): string {
    $Lc = L($lang);
    $items = '';
    foreach (array_slice(index_cards($lang), 0, 20) as $c) {
        preg_match('#<h3>(.*?)</h3>#s', $c['html'], $t);
        preg_match('#<p class="ex">(.*?)</p>#s', $c['html'], $d);
        $items .= '  <item><title>' . ($t[1] ?? '') . '</title><link>' . SITE . $c['href'] . '</link><guid>' . SITE . $c['href']
            . '</guid><description>' . ($d[1] ?? '') . "</description></item>\n";
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<rss version=\"2.0\"><channel>\n<title>" . $Lc['feed_title'] . "</title>\n<link>"
        . SITE . $Lc['base'] . "</link>\n<description>" . $Lc['feed_title'] . "</description>\n<language>" . $Lc['inlang'] . "</language>\n"
        . $items . "</channel></rss>\n";
}

function indexnow(array $urls): string {
    if (!defined('INDEXNOW_KEY') || !INDEXNOW_KEY) return 'no key';
    $r = http('https://api.indexnow.org/indexnow', ['host' => 'iranian.nikmindclinic.com', 'key' => INDEXNOW_KEY,
        'keyLocation' => SITE . '/' . INDEXNOW_KEY . '.txt', 'urlList' => $urls], 20);
    return (string)$r['code'];
}

/* ---------------- publishing schedule ---------------- */
// Two slots a day (Tehran time): around 11:00 and 20:00, each shifted by a random 7–25 minutes either way.
function todays_slots(array &$s): array {
    $day = date('Y-m-d');
    if (empty($s['slots'][$day])) {
        $mk = function (string $base) use ($day) {
            $shift = random_int(7, 25) * 60 + random_int(0, 59);
            return strtotime("$day $base") + (random_int(0, 1) ? $shift : -$shift);
        };
        $s['slots'] = [$day => [$mk('11:00'), $mk('20:00')]];
    }
    return $s['slots'][$day];
}
function next_slot_text(array &$s): string {
    foreach (todays_slots($s) as $t) if ($t > time()) return 'امروز حدود ساعت ' . fa_digits(date('H:i', $t));
    return 'فردا صبح';
}
