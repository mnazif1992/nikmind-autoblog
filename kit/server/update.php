<?php
// Nik-Negar kit — self-update from the kit repo, and the panel installer shared with install.php.
// Only code is updated. site.json, config.php, secrets.auto.php, templates/, state/ and logs/ are never touched.
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

const KIT_KEEP = ['config.php', 'site.json', 'secrets.auto.php'];

function kit_raw(string $file): array {
    $url = 'https://raw.githubusercontent.com/' . site('kit_repo', 'mnazif1992/nikmind-autoblog') . '/' . site('kit_branch', 'main') . '/'
         . trim((string)site('kit_path', 'kit/server'), '/') . '/' . $file . '?t=' . time();
    $c = curl_init($url);
    $h = ['User-Agent: nik-negar/' . KIT_VERSION];
    if (preg_match('/^(github_pat_|ghp_)/', GH_TOKEN)) $h[] = 'Authorization: Bearer ' . GH_TOKEN;
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $h]);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return ['code' => $code, 'body' => is_string($b) ? $b : ''];
}

/** @return array{changed: string[], errors: string[]} */
function kit_update(): array {
    $out = ['changed' => [], 'errors' => []];
    $m = kit_raw('SHA256SUMS');
    if ($m['code'] !== 200) { $out['errors'][] = 'manifest ' . $m['code']; return $out; }
    $want = [];
    foreach (preg_split('/\R/', trim($m['body'])) as $line) {
        if (!preg_match('/^([a-f0-9]{64})\s+\*?(\S+)$/', trim($line), $x)) continue;
        $f = $x[2];
        if (in_array($f, KIT_KEEP, true) || str_contains($f, '..') || str_starts_with($f, '/')) continue;
        $want[$f] = $x[1];
    }
    $got = [];
    foreach ($want as $f => $sum) {
        $local = AB . '/' . $f;
        if (is_file($local) && hash_file('sha256', $local) === $sum) continue;
        $r = kit_raw($f);
        if ($r['code'] !== 200 || hash('sha256', $r['body']) !== $sum) { $out['errors'][] = "download $f"; continue; }
        $got[$f] = $r['body'];
    }
    if ($out['errors']) return $out;                // all or nothing: never leave a half-updated install
    if (!$got) return $out;
    $bk = AB . '/backups/code-' . date('Ymd-His');
    @mkdir($bk, 0700, true);
    foreach ($got as $f => $b) {
        $dst = AB . '/' . $f;
        @mkdir(dirname($dst), 0700, true);
        if (is_file($dst)) copy($dst, $bk . '/' . str_replace('/', '_', $f));
        file_put_contents("$dst.tmp", $b);
        rename("$dst.tmp", $dst);
        $out['changed'][] = $f;
    }
    if (array_filter(array_keys($got), fn($f) => str_starts_with($f, 'panel/'))) {
        $p = install_panel();
        if ($p !== true) $out['errors'][] = $p;
    }
    return $out;
}

/** Copies the webhook and the Telegram panel into public_html/<PANEL_DIR>/ with this install's path written in. */
function install_panel() {
    if (!preg_match('/^[a-z0-9-]{8,}$/', PANEL_DIR)) return 'PANEL_DIR is not set';
    $dir = PUB . '/' . PANEL_DIR;
    @mkdir("$dir/app", 0755, true);
    $map = ['panel/hook.php' => "$dir/hook.php", 'panel/api.php' => "$dir/app/api.php", 'panel/app.html' => "$dir/app/index.html", 'panel/htaccess.txt' => "$dir/.htaccess"];
    foreach ($map as $src => $dst) {
        $c = (string)@file_get_contents(AB . '/' . $src);
        if ($c === '') return "missing $src";
        write_file($dst, str_replace('__NN_AB__', AB, $c));
    }
    copy("$dir/.htaccess", "$dir/app/.htaccess");
    return true;
}
