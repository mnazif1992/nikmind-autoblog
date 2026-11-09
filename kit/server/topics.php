<?php
// Keyword (topic) queue management from the Telegram panel. The queue lives in topics.json in the GitHub repo,
// so the daily writing task and the panel always see the same list. Writing needs GH_TOKEN (fine-grained, Contents: read/write).
declare(strict_types=1);

/** Topic groups from site.json, e.g. {"guides": "راهنماها"}. */
function clusters(): array { return (array)site('clusters', ['general' => 'عمومی']); }

function gh_can_write(): bool { return defined('GH_TOKEN') && (bool)preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', GH_TOKEN); }
function gh_api(string $method, string $path, ?array $body = null): array {
    $c = curl_init('https://api.github.com/repos/' . GITHUB_REPO . $path);
    $h = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: nik-negar/' . KIT_VERSION];
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
/** $in: k1 = keyword in the first language, k2 = keyword in the second language (if the site has two), cluster, angle. */
function topic_add(array $in): array {
    $langs = langs();
    $kw = [];
    foreach ($langs as $i => $l) {
        $v = trim((string)($in['k' . ($i + 1)] ?? ''));
        if ($l === 'fa') $v = normalize_fa($v);
        if (mb_strlen($v) < 3 || mb_strlen($v) > 80) throw new RuntimeException('کلمه‌ی کلیدی ' . L($l)['label'] . ' را بنویس');
        if ($l === 'en' && !preg_match('/^[a-zA-Z0-9 \'\-]+$/', $v)) throw new RuntimeException('کلمه‌ی کلیدی انگلیسی فقط با حروف انگلیسی');
        $kw[$l] = $l === 'en' ? strtolower($v) : $v;
    }
    $cl = array_key_exists($in['cluster'] ?? '', clusters()) ? $in['cluster'] : array_key_first(clusters());
    $angle = trim((string)($in['angle'] ?? ''));
    $first = $kw[$langs[0]];
    return topics_change(function (array $t) use ($kw, $cl, $angle, $first, $langs) {
        foreach ($t as $x) if (mb_strtolower($x['keywords'][$langs[0]] ?? '') === mb_strtolower($first)) throw new RuntimeException('این کلمه‌ی کلیدی از قبل در فهرست هست');
        $max = 0; foreach ($t as $x) $max = max($max, (int)substr((string)($x['id'] ?? 'T0'), 1));
        // without an English keyword the writer picks a real English slug later (see daily-task.md)
        $slug = isset($kw['en']) ? slugify_en($kw['en']) : 'topic-' . ($max + 1);
        $base = $slug; $n = 2;
        $used = array_column($t, 'slug');
        while (in_array($slug, $used, true) || is_dir(L($langs[0])['dir'] . "/$slug")) $slug = $base . '-' . $n++;
        $t[] = ['id' => sprintf('T%03d', $max + 1), 'slug' => $slug, 'keywords' => $kw, 'cluster' => $cl,
                'angle' => $angle !== '' ? $angle : 'مقاله‌ی کاربردی حول «' . $first . '»', 'status' => 'queued', 'added_by' => 'panel'];
        return $t;
    }, 'add ' . ($kw['en'] ?? $first));
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
    $L = langs();
    $slim = fn($x) => ['id' => $x['id'] ?? '', 'k1' => $x['keywords'][$L[0]] ?? '', 'k2' => isset($L[1]) ? ($x['keywords'][$L[1]] ?? '') : '',
                       'cluster' => $x['cluster'] ?? '', 'cluster_fa' => clusters()[$x['cluster'] ?? ''] ?? '', 'status' => $x['status'] ?? '', 'drafted' => $x['drafted'] ?? ''];
    return ['can_edit' => gh_can_write(), 'queued' => array_map($slim, $q), 'done' => array_map($slim, array_reverse($done)),
            'clusters' => clusters()];
}
