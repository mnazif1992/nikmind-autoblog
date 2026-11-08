<?php
// NikMind autoblog — shared actions used by the cron runner, the Telegram webhook and the Telegram panel (Mini App).
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ondemand.php';
date_default_timezone_set('Asia/Tehran');

/* ---------------- locking ---------------- */
function with_state(callable $fn, int $waitSec = 40) {
    @mkdir(AB . '/state', 0700, true);
    $lock = fopen(AB . '/state/run.lock', 'c');
    $t = time();
    while (!flock($lock, LOCK_EX | LOCK_NB)) { if (time() - $t > $waitSec) return null; usleep(250000); }
    try { $s = state_load(); $r = $fn($s); state_save($s); return $r; }
    finally { flock($lock, LOCK_UN); fclose($lock); }
}
function auto_mode(array $s): bool { return array_key_exists('auto_publish', $s) ? (bool)$s['auto_publish'] : !REQUIRE_APPROVAL; }
function panel_url(): string { return SITE . '/' . PANEL_DIR . '/app/'; }

/* ---------------- keyboards ---------------- */
function draft_buttons(string $key, string $status): array {
    if ($status === 'pending') return [[['text' => '🚀 انتشار همین حالا', 'callback_data' => "n:$key"], ['text' => '⏱ در نوبت بعدی', 'callback_data' => "p:$key"]],
                                       [['text' => '❌ رد', 'callback_data' => "r:$key"]]];
    if ($status === 'queued') return [[['text' => '🚀 انتشار همین حالا', 'callback_data' => "n:$key"], ['text' => '❌ رد', 'callback_data' => "r:$key"]]];
    return [];
}
function set_buttons(array $s, string $key): void {
    $kb = draft_buttons($key, $s['drafts'][$key]['status'] ?? '');
    foreach ($s['drafts'][$key]['msgs'] ?? [] as [$c, $m]) tg('editMessageReplyMarkup', ['chat_id' => $c, 'message_id' => $m, 'reply_markup' => ['inline_keyboard' => $kb]]);
}
function settings_text(array $s): string {
    return "⚙️ <b>تنظیمات</b>\n\nحالت انتشار: " . (auto_mode($s) ? '🤖 <b>خودکار</b> — پیش‌نویس‌ها بدون تأیید در نوبت منتشر می‌شوند' : '👀 <b>با بازبینی</b> — هر پیش‌نویس منتظر تأیید توست')
        . "\nوضعیت: " . (!empty($s['paused']) ? '⏸ متوقف' : '▶️ فعال')
        . "\nنوبت‌های امروز: " . implode(' و ', array_map(fn($t) => fa_digits(date('H:i', $t)), todays_slots($s)));
}
function settings_buttons(array $s): array {
    return [[auto_mode($s) ? ['text' => '👀 برگرد به بازبینی دستی', 'callback_data' => 'm:manual'] : ['text' => '🤖 انتشار خودکار (بدون تأیید)', 'callback_data' => 'm:auto']],
            [!empty($s['paused']) ? ['text' => '▶️ ادامه', 'callback_data' => 's:resume'] : ['text' => '⏸ توقف موقت', 'callback_data' => 's:pause']],
            [['text' => '🗂 باز کردن پنل', 'web_app' => ['url' => panel_url()]]]];
}

/* ---------------- drafts from GitHub / inbox ---------------- */
function check_drafts(array &$s): void {
    $files = [];
    $r = http('https://api.github.com/repos/' . GITHUB_REPO . '/contents/drafts');
    if ($r['code'] === 200) $files = json_decode($r['body'], true) ?: [];
    elseif ($r['code'] !== 404) logline('github list failed ' . $r['code']);
    foreach (glob(AB . '/inbox/*.json') ?: [] as $p) $files[] = ['name' => basename($p), 'local' => $p];
    usort($files, fn($x, $y) => strcmp($x['name'], $y['name']));
    foreach ($files as $f) {
        if (!str_ends_with($f['name'], '.json')) continue;
        $key = substr(md5($f['name']), 0, 10);
        if (isset($s['drafts'][$key])) continue;
        $d = json_decode(isset($f['local']) ? (string)file_get_contents($f['local']) : http($f['download_url'])['body'], true);
        $errors = []; $warnings = [];
        $a = is_array($d) ? validate_draft($d, $errors, $warnings) : [];
        if (!is_array($d)) $errors[] = 'broken JSON';
        if ($errors) {
            $s['drafts'][$key] = ['file' => $f['name'], 'status' => 'invalid', 'errors' => $errors, 'at' => date('c')];
            $s['fails'] = (int)$s['fails'] + 1;
            tg_all($s, "⚠️ <b>یک پیش‌نویس رد شد و منتشر نمی‌شود</b>\n<code>" . h($f['name']) . "</code>\n\n• " . implode("\n• ", array_map('h', $errors)));
            logline("invalid {$f['name']}: " . implode(' | ', $errors));
            if ($s['fails'] >= 3) { $s['paused'] = true; tg_all($s, '⏸ سه پیش‌نویس پشت سر هم خطا داشتند؛ سیستم متوقف شد. از ⚙️ تنظیمات ادامه بده.'); }
            continue;
        }
        $s['fails'] = 0;
        $a = attach_cover($a, $s);
        if (!empty($d['on_demand'])) done_request($s);
        @mkdir(AB . '/drafts', 0700, true);
        file_put_contents(AB . "/drafts/$key.json", json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($warnings) logline("warnings {$a['slug']}: " . implode(' | ', $warnings));
        foreach (['fa', 'en'] as $lang) write_file(L($lang)['dir'] . '/' . $a['slug'] . '/index.html', render_article($lang, $a[$lang], false));

        $auto = auto_mode($s);
        $status = $auto ? 'queued' : 'pending';
        $s['drafts'][$key] = ['file' => $f['name'], 'slug' => $a['slug'], 'title' => $a['fa']['title'], 'status' => $status, 'msgs' => [], 'at' => date('c'),
                              'words' => $a['fa']['words'], 'kw' => $a['fa']['primary_keyword']];
        if ($auto) $s['queue'][] = $key;
        $msg = ($auto ? "🤖 <b>پیش‌نویس تازه — در صف انتشار خودکار</b> (" . next_slot_text($s) . ")\n\n" : "📝 <b>پیش‌نویس تازه</b>\n\n")
            . '<b>' . h($a['fa']['title']) . "</b>\n" . h($a['fa']['meta_description'])
            . "\n\n🇮🇷 " . fa_digits((string)$a['fa']['words']) . ' کلمه · ' . h($a['fa']['primary_keyword']) . "\n" . SITE . L('fa')['base'] . $a['slug'] . '/'
            . "\n\n🇬🇧 " . h($a['en']['title']) . ' · ' . fa_digits((string)$a['en']['words']) . ' کلمه · ' . h($a['en']['primary_keyword'])
            . "\n" . SITE . L('en')['base'] . $a['slug'] . '/' . "\n\nپیش‌نمایش‌ها فعلاً از دید گوگل مخفی‌اند.";
        $s['drafts'][$key]['msgs'] = tg_all($s, $msg, draft_buttons($key, $status));
        send_cover_preview($s, $key, $a);
        logline("draft ready {$a['slug']} ($status)");
    }
}

/* ---------------- schedule & publishing ---------------- */
function run_schedule(array &$s): void {
    if (!$s['queue'] || !empty($s['paused'])) return;
    foreach (todays_slots($s) as $t) {
        if ($t <= time() && time() < $t + 2400 && (int)$s['last_pub'] < $t) { publish_next($s); return; }
    }
}
function publish_next(array &$s): void {
    $key = $s['queue'][0] ?? null;
    if ($key) publish_key($s, $key);
}
function publish_key(array &$s, string $key): bool {
    $st = $s['drafts'][$key]['status'] ?? '';
    if (!in_array($st, ['pending', 'queued'], true)) return false;
    $s['queue'] = array_values(array_diff($s['queue'], [$key]));
    try { do_publish($s, $key); return true; }
    catch (Throwable $e) {
        logline('publish failed: ' . $e->getMessage());
        tg_all($s, '⚠️ انتشار با خطا روبه‌رو شد: ' . h($e->getMessage()));
        $s['drafts'][$key]['status'] = 'failed';
        return false;
    }
}
function do_publish(array &$s, string $key): void {
    $d = &$s['drafts'][$key];
    $a = json_decode((string)file_get_contents(AB . "/drafts/$key.json"), true);
    $today = date('Y-m-d');
    $done = []; $urls = []; $ok = true;
    foreach (['fa', 'en'] as $lang) {
        $a[$lang]['date'] = $today;
        write_file(L($lang)['dir'] . '/' . $a['slug'] . '/index.html', render_article($lang, $a[$lang], true));
        $done = array_merge($done, array_map(fn($x) => "$lang $x", publish_side_effects($lang, $a[$lang])));
        $url = SITE . L($lang)['base'] . $a['slug'] . '/';
        $urls[] = $url;
        $r = http($url . '?v=' . time());
        $ok = $ok && $r['code'] === 200 && str_contains($r['body'], '<link rel="canonical" href="' . $url . '">') && str_contains($r['body'], 'index, follow');
    }
    $done[] = 'IndexNow ' . indexnow(array_merge($urls, [SITE . '/blog/', SITE . '/en/blog/']));
    $d['status'] = 'published';
    $d['published_at'] = date('c');
    $s['published'][] = ['slug' => $a['slug'], 'title' => $a['fa']['title'], 'pub_day' => $today, 'at' => date('c'), 'key' => $key];
    $s['last_pub'] = time();
    set_buttons($s, $key);
    tg_all($s, ($ok ? '✅' : '⚠️') . ' <b>منتشر شد:</b> ' . h($a['fa']['title']) . "\n" . $urls[0] . "\n" . $urls[1]
        . "\n\n" . ($ok ? 'هر دو صفحه زنده‌اند و چک شدند.' : 'منتشر شد ولی چک نهایی کامل رد نشد؛ لاگ را ببین.')
        . "\nدر صف انتشار: " . fa_digits((string)count($s['queue'])));
    logline("published {$a['slug']} verify=" . ($ok ? 'ok' : 'fail') . ' ' . implode(',', $done));
}
function approve_key(array &$s, string $key): bool {
    if (($s['drafts'][$key]['status'] ?? '') !== 'pending') return false;
    $s['drafts'][$key]['status'] = 'queued';
    $s['queue'][] = $key;
    set_buttons($s, $key);
    tg_all($s, '⏱ در صف رفت. زمان انتشار: ' . next_slot_text($s) . "\n(" . h($s['drafts'][$key]['title']) . ')');
    return true;
}
function reject_key(array &$s, string $key): bool {
    $d = &$s['drafts'][$key];
    if (!in_array($d['status'] ?? '', ['pending', 'queued'], true)) return false;
    foreach (['fa', 'en'] as $lang) {
        $dir = L($lang)['dir'] . '/' . $d['slug'];
        if (is_dir($dir)) { @mkdir(AB . '/rejected', 0700, true); rename($dir, AB . "/rejected/{$d['slug']}-$lang-" . date('Ymd-His')); }
    }
    foreach (glob(COVER_DIR . '/' . $d['slug'] . '*.webp') ?: [] as $f) { @mkdir(AB . '/rejected', 0700, true); rename($f, AB . '/rejected/' . basename($f)); }
    $d['status'] = 'rejected';
    $s['queue'] = array_values(array_diff($s['queue'], [$key]));
    set_buttons($s, $key);
    tg_all($s, '🗑 رد شد و پیش‌نمایش‌ها از سایت برداشته شدند: ' . h($d['title']));
    logline("rejected {$d['slug']}");
    return true;
}
function set_mode(array &$s, bool $auto): void {
    $s['auto_publish'] = $auto;
    if ($auto) {   // everything waiting for review joins the queue
        foreach ($s['drafts'] as $k => $d) if (($d['status'] ?? '') === 'pending') { $s['drafts'][$k]['status'] = 'queued'; $s['queue'][] = $k; set_buttons($s, $k); }
    }
    logline('mode ' . ($auto ? 'auto' : 'manual'));
}

/* ---------------- telegram updates ---------------- */
function main_kb2(): array {
    return ['keyboard' => [[['text' => '✍️ مقاله‌ی جدید'], ['text' => '⚙️ تنظیمات']], [['text' => '/status']]], 'resize_keyboard' => true, 'is_persistent' => true];
}
function handle_update(array &$s, array $u): void {
    $s['tg_offset'] = max((int)$s['tg_offset'], (int)($u['update_id'] ?? 0));
    if (isset($u['message'])) {
        $cid = $u['message']['chat']['id'];
        $text = trim($u['message']['text'] ?? '');
        if (!$s['chat_ids']) {
            $s['chat_ids'][] = $cid;
            tg('sendMessage', ['chat_id' => $cid, 'reply_markup' => main_kb2(), 'text' => "سلام 👋\nاین چت ثبت شد. پیش‌نویس‌ها و خبر انتشار مقاله‌های iranian.nikmindclinic.com از این به بعد این‌جا می‌آید."]);
            logline("registered chat $cid");
            return;
        }
        if (!in_array($cid, $s['chat_ids'], true)) return;
        if ($text === '✍️ مقاله‌ی جدید' || $text === '/write') { request_write($s, $cid); return; }
        if ($text === '⚙️ تنظیمات' || $text === '/settings') {
            tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => settings_text($s), 'reply_markup' => ['inline_keyboard' => settings_buttons($s)]]);
            return;
        }
        if ($text === '/start') { tg('sendMessage', ['chat_id' => $cid, 'text' => 'دکمه‌ها پایین صفحه‌اند؛ پنل کامل هم از دکمه‌ی «پنل» کنار کادر پیام باز می‌شود.', 'reply_markup' => main_kb2()]); return; }
        if ($text === '/status') {
            $pend = count(array_filter($s['drafts'], fn($d) => ($d['status'] ?? '') === 'pending'));
            $last = end($s['published']) ?: null;
            tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'reply_markup' => main_kb2(), 'text' =>
                'وضعیت: ' . (!empty($s['paused']) ? '⏸ متوقف' : '▶️ فعال') . ' · حالت: ' . (auto_mode($s) ? '🤖 خودکار' : '👀 بازبینی')
                . "\nمنتظر تأیید: " . fa_digits((string)$pend) . ' · در صف انتشار: ' . fa_digits((string)count($s['queue'])) . ' · نوبت بعدی: ' . next_slot_text($s)
                . "\nدر صف نوشتن: " . fa_digits((string)(int)($s['write_pending'] ?? 0)) . ' · منتشرشده‌ها: ' . fa_digits((string)count($s['published']))
                . ($last ? "\nآخرین: " . h($last['title']) : '')]);
            return;
        }
        if ($text === '/pause') { $s['paused'] = true; tg('sendMessage', ['chat_id' => $cid, 'text' => '⏸ متوقف شد.']); return; }
        if ($text === '/resume') { $s['paused'] = false; $s['fails'] = 0; tg('sendMessage', ['chat_id' => $cid, 'text' => '▶️ دوباره فعال شد.']); return; }
        if ($text === '/publishnow') { if ($s['queue']) publish_next($s); else tg('sendMessage', ['chat_id' => $cid, 'text' => 'صف انتشار خالی است.']); return; }
        return;
    }
    if (isset($u['callback_query'])) {
        $cq = $u['callback_query'];
        $cid = $cq['message']['chat']['id'] ?? 0;
        if (!in_array($cid, $s['chat_ids'], true)) return;
        [$act, $arg] = array_pad(explode(':', $cq['data'] ?? '', 2), 2, '');
        $ack = fn($t) => tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $t]);
        if ($act === 'm' || $act === 's') {
            if ($act === 'm') set_mode($s, $arg === 'auto'); else { $s['paused'] = $arg === 'pause'; if (!$s['paused']) $s['fails'] = 0; }
            $ack('ذخیره شد');
            tg('editMessageText', ['chat_id' => $cid, 'message_id' => $cq['message']['message_id'], 'parse_mode' => 'HTML', 'text' => settings_text($s), 'reply_markup' => ['inline_keyboard' => settings_buttons($s)]]);
            return;
        }
        $st = $s['drafts'][$arg]['status'] ?? '';
        try {
            if ($act === 'i') { $ack('دنبال عکس دیگر…'); if (in_array($st, ['pending', 'queued'], true)) swap_cover($s, $arg); return; }
            if ($act === 'n') { $ack(in_array($st, ['pending', 'queued'], true) ? 'در حال انتشار…' : 'قبلاً تصمیم گرفته شده'); publish_key($s, $arg); return; }
            if ($act === 'p') { $ack($st === 'pending' ? 'رفت در صف' : 'قبلاً تصمیم گرفته شده'); approve_key($s, $arg); return; }
            if ($act === 'r') { $ack(in_array($st, ['pending', 'queued'], true) ? 'رد شد' : 'قبلاً تصمیم گرفته شده'); reject_key($s, $arg); return; }
        } catch (Throwable $e) {
            logline('action failed: ' . $e->getMessage());
            tg_all($s, '⚠️ خطا: ' . h($e->getMessage()));
        }
    }
}
function poll_telegram(array &$s): void {
    if (!empty($s['webhook'])) return;            // updates arrive through the webhook instead
    $r = tg('getUpdates', ['offset' => (int)$s['tg_offset'] + 1, 'timeout' => 0, 'allowed_updates' => ['message', 'callback_query']]);
    if (!$r) return;
    foreach ($r['result'] as $u) handle_update($s, $u);
}

/* ---------------- panel (Mini App) data ---------------- */
function panel_state(array $s): array {
    $items = [];
    foreach (array_reverse($s['drafts'], true) as $k => $d) {
        if (!in_array($d['status'] ?? '', ['pending', 'queued', 'published'], true)) continue;
        $a = json_decode((string)@file_get_contents(AB . "/drafts/$k.json"), true) ?: [];
        $items[] = ['key' => $k, 'status' => $d['status'], 'title' => $d['title'] ?? '', 'slug' => $d['slug'] ?? '',
            'title_en' => $a['en']['title'] ?? '', 'words' => (int)($a['fa']['words'] ?? 0), 'kw' => $a['fa']['primary_keyword'] ?? '',
            'cover' => $a['cover']['card'] ?? '', 'at' => $d['published_at'] ?? $d['at'] ?? '',
            'fa' => SITE . L('fa')['base'] . ($d['slug'] ?? '') . '/', 'en' => SITE . L('en')['base'] . ($d['slug'] ?? '') . '/',
            'queue_pos' => ($i = array_search($k, $s['queue'], true)) === false ? null : $i + 1];
        if (count($items) >= 40) break;
    }
    return ['auto' => auto_mode($s), 'paused' => !empty($s['paused']), 'write_pending' => (int)($s['write_pending'] ?? 0),
            'slots' => array_map(fn($t) => date('H:i', $t), todays_slots($s)), 'next' => next_slot_text($s), 'items' => $items];
}
