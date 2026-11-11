<?php
// Nik-Negar kit — loads the site profile (site.json) and secrets (config.php + secrets.auto.php).
// Everything site-specific lives in site.json; the code in this folder is the same on every site.
declare(strict_types=1);

const AB = __DIR__;
const KIT_VERSION = '1.2.0';

(function () {
    $f = AB . '/site.json';
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($j)) { error_log('nik-negar: site.json missing or not valid JSON in ' . AB); if (PHP_SAPI === 'cli') echo "site.json missing or not valid JSON in " . AB . "\n"; exit(1); }
    $GLOBALS['NN_SITE'] = $j;
    define('SITE', rtrim((string)$j['url'], '/'));
    define('PUB', rtrim((string)$j['public_html'], '/'));
    date_default_timezone_set((string)($j['timezone'] ?? 'Asia/Tehran'));
})();

if (is_file(AB . '/config.php')) require_once AB . '/config.php';
if (is_file(AB . '/secrets.auto.php')) require_once AB . '/secrets.auto.php';
foreach (['TG_BOT_TOKEN' => '', 'GH_TOKEN' => '', 'REQUIRE_APPROVAL' => true, 'HOOK_SECRET' => '', 'PANEL_DIR' => '', 'INDEXNOW_KEY' => ''] as $k => $v)
    if (!defined($k)) define($k, $v);

/** Site profile value, e.g. site('content_repo'). */
function site(string $key, $default = null) { return $GLOBALS['NN_SITE'][$key] ?? $default; }
