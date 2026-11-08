<?php
// Keyword (topic) queue management from the Telegram panel. The queue lives in topics.json in the GitHub repo,
// so the daily writing task and the panel always see the same list. Writing needs GH_TOKEN (fine-grained, Contents: read/write).
declare(strict_types=1);

const TOPIC_CLUSTERS = ['country' => 'کشورها', 'migration' => 'مهاجرت و روان', 'online' => 'مشاوره‌ی آنلاین', 'family' => 'خانواده'];

function gh_can_write(): bool { return defined('GH_TOKEN') && GH_TOKEN !== '' && !str_contains(GH_TOKEN, 'PASTE'); }
function gh_api(string $method, string $path, ?array $body = null): array {
    $c = curl_init('https://api.github.com/repos/' . GITHUB_REPO . $path);
    $h = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: nik-negar'];
    if (gh_can_write()) $h[] = 'Authorization: Bearer ' . GH_TOKEN;
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h];
    if ($body !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; $opt[CURLOPT_HTTPHEADER] = $h; }
    curl_setopt_array($c, $opt);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'json' => json_decode(is_string($b) ? $b : '', true)];
}
/** @return array{0: array, 1: string} topics and blob sha */
function topics_read(): array {
    $r = gh_api('GET', '/contents/topics.json?ref=main');
    if ($r['code'] !== 200) throw new RuntimeException('خواندن فهرست کلمات کلیدی از گیت‌هاب نشد (' . $r['code'] . ')');
    $t = json_decode((string)base64_decode((string)$r['json']['content']), true);
    if (!is_array($t)) throw new RuntimeException('فایل کلمات کلیدی خراب است');
    return [$t, (string)$r['json']['sha']];
}
function topics_encode(array $t): string {   // same layout as Python json.dump(indent=1, ensure_ascii=False), so diffs stay small
    $j = json_encode(array_values($t), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 4)), $j);
}
/** Applies $fn to the list and commits it; retries once if the daily task pushed in between. */
function topics_change(callable $fn, string $msg): array {
    if (!gh_can_write()) throw new RuntimeException('برای ویرایش کلمات کلیدی، توکن گیت‌هاب هنوز در تنظیمات سرور نیست');
    for ($i = 0; $i < 2; $i++) {
        [$t, $sha] = topics_read();
        $t = $fn($t);
        $r = gh_api('PUT', '/contents/topics.json', ['message' => "topics: $msg (from Nik-Negar panel)", 'sha' => $sha, 'branch' => 'main',
            'content' => base64_encode(topics_encode($t) . "\n")]);
        if ($r['code'] === 200 || $r['code'] === 201) { logline("topics: $msg"); return $t; }
        if ($r['code'] !== 409) throw new RuntimeException('ذخیره در گیت‌هاب نشد (' . $r['code'] . ')');
    }
    throw new RuntimeException('فهرست هم‌زمان در حال تغییر بود؛ دوباره امتحان کن');
}
function slugify_en(string $s): string {
    $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-'));
    return substr($s, 0, 60) ?: 'topic';
}
function topic_add(array $in): array {
    $fa = normalize_fa(trim((string)($in['fa'] ?? '')));
    $en = trim((string)($in['en'] ?? ''));
    $cl = array_key_exists($in['cluster'] ?? '', TOPIC_CLUSTERS) ? $in['cluster'] : 'migration';
    $angle = trim((string)($in['angle'] ?? ''));
    if (mb_strlen($fa) < 3 || mb_strlen($fa) > 80) throw new RuntimeException('کلمه‌ی کلیدی فارسی را بنویس');
    if (strlen($en) < 3 || strlen($en) > 80 || !preg_match('/^[a-zA-Z0-9 \'\-]+$/', $en)) throw new RuntimeException('کلمه‌ی کلیدی انگلیسی فقط با حروف انگلیسی');
    return topics_change(function (array $t) use ($fa, $en, $cl, $angle) {
        foreach ($t as $x) if (mb_strtolower($x['fa_keyword'] ?? '') === mb_strtolower($fa)) throw new RuntimeException('این کلمه‌ی کلیدی از قبل در فهرست هست');
        $max = 0; foreach ($t as $x) $max = max($max, (int)substr((string)($x['id'] ?? 'T0'), 1));
        $slug = slugify_en($en); $base = $slug; $n = 2;
        $used = array_column($t, 'slug');
        while (in_array($slug, $used, true) || is_dir(BLOG . "/$slug")) $slug = $base . '-' . $n++;
        $t[] = ['id' => sprintf('T%03d', $max + 1), 'slug' => $slug, 'fa_keyword' => $fa, 'en_keyword' => strtolower($en), 'cluster' => $cl,
                'angle' => $angle !== '' ? $angle : 'مقاله‌ی کاربردی حول «' . $fa . '» برای ایرانیان خارج از کشور', 'status' => 'queued', 'added_by' => 'panel'];
        return $t;
    }, "add $en");
}
function topic_remove(string $id): array {
    return topics_change(function (array $t) use ($id) {
        foreach ($t as &$x) if (($x['id'] ?? '') === $id) { if (($x['status'] ?? '') !== 'queued') throw new RuntimeException('فقط کلمه‌های در صف را می‌شود حذف کرد'); $x['status'] = 'removed'; }
        return $t;
    }, "remove $id");
}
function topic_top(string $id): array {
    return topics_change(function (array $t) use ($id) {
        $pick = null;
        foreach ($t as $i => $x) if (($x['id'] ?? '') === $id) { $pick = $x; unset($t[$i]); }
        if (!$pick) throw new RuntimeException('پیدا نشد');
        $t = array_values($t);
        $first = 0; foreach ($t as $i => $x) if (($x['status'] ?? '') === 'queued') { $first = $i; break; }
        array_splice($t, $first, 0, [$pick]);
        return $t;
    }, "move $id to front");
}
function topics_view(?array $t = null): array {
    if ($t === null) [$t] = topics_read();
    $q = array_values(array_filter($t, fn($x) => ($x['status'] ?? '') === 'queued'));
    $done = array_values(array_filter($t, fn($x) => in_array($x['status'] ?? '', ['drafted', 'published'], true)));
    $slim = fn($x) => ['id' => $x['id'] ?? '', 'fa' => $x['fa_keyword'] ?? '', 'en' => $x['en_keyword'] ?? '', 'cluster' => $x['cluster'] ?? '',
                       'cluster_fa' => TOPIC_CLUSTERS[$x['cluster'] ?? ''] ?? '', 'status' => $x['status'] ?? '', 'drafted' => $x['drafted'] ?? ''];
    return ['can_edit' => gh_can_write(), 'queued' => array_map($slim, $q), 'done' => array_map($slim, array_reverse($done)),
            'clusters' => TOPIC_CLUSTERS];
}
