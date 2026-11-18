<?php
// Nik-Negar kit — Google reviews count and rating on the site, refreshed once a day, without any API key.
// Turned on by site/reviews.json in the content repo (no file = off). One of:
//   {"place": "0x…:0x…", "lat": 35.7, "lng": 51.3, "at": "04:20"}   read the numbers from Google Maps, write /reviews.json
//   {"source": "https://other.site/reviews.json", "at": "04:50"}      take them from another install (hosts that cannot reach Google);
//                                                                     with "place" as well, Google is tried when the source fails
// The numbers are changed in place in the site's HTML/JS and in templates/ — only where they sit right next to
// "Google reviews" wording, so other numbers (a timeline's "from 112 reviews", individual review ratings) are left alone.
// Problems reach the team in the bot: after 2 failed tries in a row, then once a day while it stays broken.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const RV_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const RV_N = '[0-9۰-۹](?:[0-9۰-۹]|[,٬](?=[0-9۰-۹]{3}))*';      // a count: 152, ۱۵۲, 1,204, ۱٬۲۰۴
const RV_R = '[0-5۰-۵][.٫][0-9۰-۹]';                            // a rating: 4.9, ۴.۹

/** site/reviews.json from the content repo, checked. null = feature off (or not readable right now: $err says). */
function reviews_config(?string &$err = null): ?array {
    $err = null;
    if (GITHUB_REPO === '') return null;
    $r = gh_api('GET', '/contents/site/reviews.json?ref=main');
    if ($r['code'] === 404) return null;
    if ($r['code'] !== 200) { $err = 'github ' . $r['code']; return null; }
    $c = json_decode((string)base64_decode((string)($r['json']['content'] ?? '')), true);
    if (!is_array($c)) { $err = 'site/reviews.json is not valid JSON'; return null; }
    if (!empty($c['place']) && !preg_match('/^0x[0-9a-f]+:0x[0-9a-f]+$/i', (string)$c['place'])) { $err = 'reviews.json: "place" must look like 0x…:0x…'; return null; }
    if (empty($c['place']) && !preg_match('#^https://#', (string)($c['source'] ?? ''))) { $err = 'reviews.json needs "place" or an https "source"'; return null; }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($c['at'] ?? ''))) $c['at'] = '04:20';
    return $c;
}

/** Reads rating and count from Google Maps' own place endpoint (the one the Maps page itself calls). */
function reviews_from_google(array $c, string &$err): ?array {
    $pb = '!1m14!1s' . $c['place'] . '!3m12!1m3!1d1000!2d' . (float)($c['lng'] ?? 51.3) . '!3d' . (float)($c['lat'] ?? 35.7)
        . '!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1';
    $url = 'https://www.google.com/maps/preview/place?authuser=0&hl=en&gl=us&pb=' . rawurlencode($pb);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['User-Agent: ' . RV_UA, 'Accept: */*', 'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.google.com/maps/', 'Cookie: CONSENT=YES+; SOCS=CAESHAgBEhJnd3NfMjAyNDA1MjctMF9SQzIaAmVuIAEaBgiA7_GyBg']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    $body = is_string($body) ? $body : '';
    if ($code !== 200) {
        $err = $code === 0 ? "به گوگل وصل نشد ($cerr)" : ($code === 429 || str_contains($body, 'captcha') ? "گوگل درخواست را محدود کرد (HTTP $code)" : "گوگل جواب نداد (HTTP $code)");
        return null;
    }
    $j = json_decode(substr($body, (int)strpos($body, "\n") + 1), true);
    $pick = function ($a) {
        if (!is_array($a) || !isset($a[7], $a[8]) || !is_numeric($a[7]) || !is_int($a[8])) return null;
        $r = (float)$a[7]; $n = (int)$a[8];
        if ($r < 1 || $r > 5 || $n < 1) return null;
        if (isset($a[3][1]) && is_string($a[3][1]) && preg_match('/^([\d,]+) reviews?$/', $a[3][1], $m) && (int)str_replace(',', '', $m[1]) !== $n) return null;
        return ['rating' => round($r, 1), 'count' => $n];
    };
    $hit = is_array($j) ? $pick($j[6][4] ?? null) : null;            // where Maps keeps it today
    if (!$hit && is_array($j)) {                                       // layout moved: look for the same block anywhere
        $walk = function ($v, int $d) use (&$walk, $pick) {
            if (!is_array($v) || $d > 6) return null;
            if (isset($v[3][1]) && is_string($v[3][1]) && preg_match('/^[\d,]+ reviews?$/', $v[3][1]) && ($p = $pick($v))) return $p;
            foreach ($v as $x) if (is_array($x) && ($p = $walk($x, $d + 1))) return $p;
            return null;
        };
        $hit = $walk($j, 0);
    }
    if (!$hit) { $err = is_array($j) ? 'ساختار جواب گوگل عوض شده؛ عدد پیدا نشد' : 'جواب گوگل قابل خواندن نبود (احتمالاً صفحه‌ی تأیید یا کپچا)'; return null; }
    $hit['name'] = is_string($j[6][11] ?? null) ? $j[6][11] : '';
    return $hit;
}

function reviews_from_source(array $c, string &$err): ?array {
    $r = http((string)$c['source'], null, 25);
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || !is_array($j)) { $err = 'فایل ' . $c['source'] . ' خوانده نشد (HTTP ' . $r['code'] . ($r['err'] ? ', ' . $r['err'] : '') . ')'; return null; }
    $rating = (float)($j['rating'] ?? 0); $count = (int)($j['count'] ?? 0);
    if ($rating < 1 || $rating > 5 || $count < 1) { $err = 'عددهای فایل ' . $c['source'] . ' معتبر نیست'; return null; }
    $age = time() - (int)strtotime((string)($j['checked_at'] ?? ''));
    if ($age > 4 * 86400) { $err = 'فایل ' . $c['source'] . ' بیش از ۴ روز است به‌روز نشده (سایت مبدأ را ببین)'; return null; }
    return ['rating' => round($rating, 1), 'count' => $count, 'name' => (string)($j['name'] ?? '')];
}

/* ---------------- rewriting the numbers in the site's files ---------------- */
function rv_fmt_count(int $n, string $like): string {
    $fa = (bool)preg_match('/[۰-۹]/u', $like);
    $s = $n >= 1000 ? number_format($n, 0, '.', $fa ? '٬' : ',') : (string)$n;
    return $fa ? fa_digits($s) : $s;
}
function rv_fmt_rating(float $r, string $like): string {
    $sep = str_contains($like, '٫') ? '٫' : '.';
    $s = str_replace('.', $sep, number_format($r, 1, '.', ''));
    return preg_match('/[۰-۹]/u', $like) ? fa_digits($s) : $s;
}
/** @return array{0: string, 1: int} new text and how many numbers were changed */
function reviews_rewrite(string $t, float $rating, int $count): array {
    $n = 0; $N = RV_N; $R = RV_R;
    $cnt = function (array $m) use (&$n, $count) { $v = rv_fmt_count($count, $m[2]); if ($v !== $m[2]) $n++; return $m[1] . $v . $m[3]; };
    $rat = function (array $m) use (&$n, $rating) { $v = rv_fmt_rating($rating, $m[2]); if ($v !== $m[2]) $n++; return $m[1] . $v . $m[3]; };
    $count_res = [
        "/(^|[^0-9۰-۹.,٬\/])($N)(\s*\+?\s*(?:نظر|ریویو)(?:\s+واقعی)?(?:\s+[۰-۹0-9]\s+ستاره)?\s+(?:(?:در|از)\s+)?(?:گوگل|Google))/u",   // ۱۵۲ نظر در گوگل · از ۱۵۲ نظر واقعی در گوگل · ۱۵۲ نظر ۵ ستاره در گوگل
        "/(^|[^0-9۰-۹.,٬\/])($N)(\+?\s+(?:real\s+)?Google\s+reviews?)/iu",                                    // 152 Google reviews · from 152 real Google reviews
        "/(^|[^0-9۰-۹.,٬\/])($N)(\+?\s+reviews?\s+on\s+Google)/iu",                                           // 152 reviews on Google
    ];
    foreach ($count_res as $re) $t = (string)preg_replace_callback($re, $cnt, $t);
    $rating_res = [
        "/(class=\"(?:rev-num|rate)\">\s*)($R)(\s*<|\s)/u",                                                  // <span class="rev-num">4.9</span> · <span class="rate">4.9 <svg
        "/(^|[^0-9۰-۹.٫\/])($R)(\s+(?:در|on)\s+(?:Google(?:\s+Reviews)?|گوگل))/iu",                           // ۴.۹ در Google Reviews · ۴.۹ در گوگل · 4.9 on Google
        "/(class=\"(?:num|stat-val)\">\s*)($R)(\s*<\/p>\s*<p class=\"(?:lbl|stat-lbl)\">[^<]{0,30}(?:Google|گوگل))/iu",   // big stat: 4.9 / out of 5 on Google Reviews · ۴.۹ / امتیاز در گوگل
        "/(^|[^0-9۰-۹.٫\/])($R)(\s*[·•|]\s*$N\s*(?:\+\s*)?(?:نظر|ریویو|(?:real\s+)?Google\s+reviews?))/iu",   // ۴.۹ · ۱۵۲ نظر در گوگل
    ];
    foreach ($rating_res as $re) $t = (string)preg_replace_callback($re, $rat, $t);
    // schema.org aggregateRating (only that block: single reviews keep their own ratingValue)
    $t = (string)preg_replace_callback('/("aggregateRating"\s*:\s*\{)([^{}]*)(\})/u', function ($m) use (&$n, $rating, $count) {
        $in = preg_replace_callback('/("(?:reviewCount|ratingCount)"\s*:\s*"?)(\d+)/', function ($x) use (&$n, $count) { if ((int)$x[2] !== $count) $n++; return $x[1] . $count; }, $m[2]);
        $in = preg_replace_callback('/("ratingValue"\s*:\s*"?)(\d(?:\.\d+)?)/', function ($x) use (&$n, $rating) { $v = number_format($rating, 1, '.', ''); if ($x[2] !== $v) $n++; return $x[1] . $v; }, (string)$in);
        return $m[1] . $in . $m[3];
    }, $t);
    return [$t, $n];
}
/** Does this text carry any of the patterns above (with any value)? Used to tell "already right" from "nothing to update". */
function reviews_has_spot(string $t): bool {
    [$x] = reviews_rewrite($t, 0.1, 999999999);   // impossible values: anything recognised changes
    return $x !== $t;
}

/** Every .html/.js under public_html (minus the panel and bulky folders) and the kit's own templates. */
function reviews_files(): array {
    $out = [];
    $skip = array_filter([PANEL_DIR, 'wp-content', 'wp-includes', 'node_modules', '.well-known', 'cgi-bin', '.git']);
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(PUB, FilesystemIterator::SKIP_DOTS),
        fn($f) => $f->isDir() ? !in_array($f->getFilename(), $skip, true) : (bool)preg_match('/\.(html?|js)$/i', $f->getFilename())
    ));
    foreach ($it as $f) if ($f->isFile() && $f->getSize() < 6 * 1024 * 1024) $out[] = $f->getPathname();
    foreach (glob(AB . '/templates/*.html') ?: [] as $f) $out[] = $f;
    return $out;
}
/** @return array{files: int, numbers: int, spots: int} */
function reviews_apply(float $rating, int $count): array {
    $res = ['files' => 0, 'numbers' => 0, 'spots' => 0];
    foreach (reviews_files() as $f) {
        $t = (string)@file_get_contents($f);
        if ($t === '' || !preg_match('/Google|گوگل/u', $t)) continue;
        [$new, $n] = reviews_rewrite($t, $rating, $count);
        if ($n === 0) { if (reviews_has_spot($t)) $res['spots']++; continue; }
        $res['spots']++;
        // one rolling backup per file (the change is a few digits; a dated copy of every page each time would fill the disk)
        $bk = AB . '/backups/reviews-last/' . (str_starts_with($f, PUB) ? ltrim(substr($f, strlen(PUB)), '/') : '_internal/' . basename($f));
        @mkdir(dirname($bk), 0700, true); @copy($f, $bk);
        $tmp = "$f.tmp";
        if (file_put_contents($tmp, $new) === false) throw new RuntimeException("write failed: $f");
        @chmod($tmp, (fileperms($f) & 0777) ?: 0644);
        rename($tmp, $f);
        $res['files']++; $res['numbers'] += $n;
    }
    return $res;
}

/* ---------------- the daily run ---------------- */
/** Called every minute by run.php; does nothing except at the configured time (and hourly retries after a failure). */
function reviews_tick(array &$s, bool $force = false): ?string {
    $st = (array)($s['reviews'] ?? []);
    $now = time(); $today = date('Y-m-d');
    if (!$force) {
        if (($st['day'] ?? '') === $today) return null;                       // done (or given up) for today
        if ($now < (int)($st['next_try'] ?? 0)) return null;
        if (date('H:i') < (string)($st['at'] ?? '04:20')) return null;
    }
    $cerr = null;
    $c = reviews_config($cerr);
    if ($c === null && $cerr !== null && empty($st['on']) && !$force) {   // could not look (GitHub hiccup) on a site that never had it on: just look again later
        $st['next_try'] = $now + 3600; $s['reviews'] = $st; return null;
    }
    if ($c === null && $cerr === null) { $st['on'] = false; $st['next_try'] = $now + 6 * 3600; $s['reviews'] = $st; return $force ? 'به‌روزرسانی نظرهای گوگل برای این سایت روشن نیست (site/reviews.json در ریپوی محتوا نیست).' : null; }
    if ($c !== null) { $st['on'] = true; $st['at'] = $c['at']; if (!$force && date('H:i') < $c['at']) { $s['reviews'] = $st; return null; } }

    $err = (string)$cerr;
    $got = null; $fromGoogle = false;
    if ($c && !empty($c['source'])) $got = reviews_from_source($c, $err);           // another install's /reviews.json first
    if ($c && !$got && !empty($c['place'])) {                                         // then Google itself
        $e2 = ''; $got = reviews_from_google($c, $e2); $fromGoogle = (bool)$got;
        $err = $err !== '' && $e2 !== '' ? "{$err}؛ {$e2}" : $err . $e2;
    }
    $prev = isset($st['count']) ? (int)$st['count'] : 0;
    if ($got && $prev > 0 && ($got['count'] < $prev - max(3, (int)ceil($prev * 0.05)) || $got['count'] > $prev * 2 + 20)) {
        $err = 'عدد خوانده‌شده عجیب است (' . fa_digits((string)$got['count']) . ' نظر، قبلاً ' . fa_digits((string)$prev) . ')؛ سایت دست نخورد';
        $got = null;
    }
    $msg = null;
    if ($got) {
        $a = reviews_apply($got['rating'], $got['count']);
        if ($fromGoogle) {   // for installs on hosts that cannot reach Google ("source")
            json_save(PUB . '/' . ltrim((string)($c['publish'] ?? 'reviews.json'), '/'),
                ['rating' => $got['rating'], 'count' => $got['count'], 'name' => $got['name'], 'checked_at' => date('c'), 'from' => 'google-maps']);
            @chmod(PUB . '/' . ltrim((string)($c['publish'] ?? 'reviews.json'), '/'), 0644);
        }
        $line = '⭐️ ' . fa_digits(number_format($got['rating'], 1)) . ' · ' . fa_digits((string)$got['count']) . ' نظر';
        logline("google reviews: {$got['rating']} / {$got['count']} — {$a['numbers']} numbers in {$a['files']} files ({$a['spots']} files carry them)");
        if ($a['spots'] === 0 && empty($st['warned_nospot'])) {
            $st['warned_nospot'] = 1;
            tg_all($s, "⚠️ <b>نظرهای گوگل</b>\nعدد از گوگل خوانده شد ($line) ولی هیچ جای سایت با الگوی «… نظر در گوگل» / «… Google reviews» پیدا نشد که آپدیت شود.");
        }
        if (!empty($st['alerted'])) tg_all($s, "✅ <b>به‌روزرسانی نظرهای گوگل دوباره کار می‌کند</b>\n$line");
        elseif (empty($st['ok_at'])) tg_all($s, "✅ <b>به‌روزرسانی خودکار نظرهای گوگل راه افتاد</b>\n$line\n"
            . ($a['files'] ? 'عدد در ' . fa_digits((string)$a['files']) . ' فایل سایت عوض شد.' : 'سایت از قبل همین عدد را داشت.') . "\nاز این به بعد هر روز خودکار چک می‌شود؛ اگر خراب شد همین‌جا خبر می‌دهم.");
        if (!empty($st['ok_at']) && (int)date('N') === 6 && ($st['wk_day'] ?? '') !== $today) {   // weekly note, Saturdays
            $from = (int)($st['wk_from'] ?? $got['count']);
            tg_all($s, "📊 <b>نظرهای گوگل این هفته</b>\n" . ($from !== $got['count']
                ? 'از ' . fa_digits((string)$from) . ' به ' . fa_digits((string)$got['count']) . ' نظر رسید (امتیاز ' . fa_digits(number_format($got['rating'], 1)) . ').'
                : "بدون تغییر: $line.") . "\nسایت به‌روز است.");
            $st['wk_day'] = $today; $st['wk_from'] = $got['count'];
        }
        if (empty($st['ok_at'])) $st['wk_from'] = $got['count'];
        $st = array_merge($st, ['day' => $today, 'count' => $got['count'], 'rating' => $got['rating'], 'ok_at' => date('c'), 'fails' => 0, 'alerted' => 0, 'err' => '', 'next_try' => 0]);
        $msg = "$line\n" . ($a['files'] ? 'عدد در ' . fa_digits((string)$a['files']) . ' فایل عوض شد.' : 'سایت همین عدد را داشت (' . fa_digits((string)$a['spots']) . ' فایل).');
    } else {
        $st['fails'] = (int)($st['fails'] ?? 0) + 1;
        $st['err'] = $err;
        $st['next_try'] = $now + 3600;                                       // hourly retries, at most 3 tries a day
        $st['tries'] = ($st['tries_day'] ?? '') === $today ? (int)($st['tries'] ?? 0) + 1 : 1; $st['tries_day'] = $today;
        if ($st['tries'] >= 3 || (int)date('G') >= 23) $st['day'] = $today;  // tomorrow again
        logline("google reviews failed ({$st['fails']}): $err");
        if ($st['fails'] >= 2 && ($st['alerted_day'] ?? '') !== $today) {
            $st['alerted'] = 1; $st['alerted_day'] = $today;
            tg_all($s, "⚠️ <b>به‌روزرسانی نظرهای گوگل کار نکرد</b>\n" . h($err) . "\n\nعدد فعلی سایت دست نخورده"
                . (!empty($st['ok_at']) ? ' (آخرین به‌روزرسانی موفق: ' . show_date('fa', date('Y-m-d', (int)strtotime($st['ok_at']))) . ')' : '')
                . ".\nامروز چند بار دیگر و از فردا هر روز دوباره امتحان می‌کنم. وضعیت و امتحان دستی: /reviews");
        }
        $msg = '⚠️ ' . h($err);
    }
    $s['reviews'] = $st;
    return $msg;
}

/** Text for /reviews: runs now and says how it went. */
function reviews_command(array &$s): string {
    $r = reviews_tick($s, true);
    $st = (array)($s['reviews'] ?? []);
    return "⭐️ <b>نظرهای گوگل</b>\n" . ($r ?? '') . (!empty($st['ok_at']) ? "\nآخرین به‌روزرسانی موفق: " . show_date('fa', date('Y-m-d', (int)strtotime($st['ok_at']))) . '، ساعت ' . fa_digits(date('H:i', (int)strtotime($st['ok_at']))) : '')
        . (!empty($st['on']) ? "\nچک روزانه: ساعت " . fa_digits((string)($st['at'] ?? '04:20')) : '');
}
