<?php
// Nik-Negar kit — shared actions used by the cron runner, the Telegram webhook and the Telegram panel (Mini App).
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ondemand.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/topics.php';

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
function draft_buttons(string $key, string $status, string $byline = ''): array {
    $edit = ['text' => '✏️ ویرایش متن و عکس', 'web_app' => ['url' => panel_url() . '?edit=' . $key]];   // opens the panel straight in this article's editor
    // who the article goes out under; a tap opens the list of the site's authors on this message
    $by = [['text' => '✍️ نویسنده: ' . byline_name(lang1(), $byline ?: byline_default()), 'callback_data' => "w:$key"]];
    if ($status === 'pending') return [[['text' => '🚀 انتشار همین حالا', 'callback_data' => "n:$key"], ['text' => '⏱ در نوبت بعدی', 'callback_data' => "p:$key"]],
                                       [$edit, ['text' => '❌ رد', 'callback_data' => "r:$key"]], $by];
    if ($status === 'queued') return [[['text' => '🚀 انتشار همین حالا', 'callback_data' => "n:$key"], ['text' => '❌ رد', 'callback_data' => "r:$key"]], [$edit], $by];
    if ($status === 'published') return [$by];
    return [];
}
function set_buttons(array $s, string $key): void {
    $kb = draft_buttons($key, $s['drafts'][$key]['status'] ?? '', (string)($s['drafts'][$key]['byline'] ?? ''));
    foreach ($s['drafts'][$key]['msgs'] ?? [] as [$c, $m]) tg('editMessageReplyMarkup', ['chat_id' => $c, 'message_id' => $m, 'reply_markup' => ['inline_keyboard' => $kb]]);
}
function settings_text(array $s): string {
    return "⚙️ <b>تنظیمات</b>\n\nحالت انتشار: " . (auto_mode($s) ? '🤖 <b>خودکار</b> — پیش‌نویس‌ها بدون تأیید در نوبت منتشر می‌شوند' : '👀 <b>با بازبینی</b> — هر پیش‌نویس منتظر تأیید توست')
        . "\nوضعیت: " . (!empty($s['paused']) ? '⏸ متوقف' : '▶️ فعال')
        . "\nنوبت‌های امروز: " . implode(' و ', array_map(fn($t) => fa_digits(date('H:i', $t)), todays_slots($s)))
        . "\nاعضا: " . fa_digits((string)count($s['chat_ids'])) . ' نفر · /members';
}
function settings_buttons(array $s): array {
    return [[auto_mode($s) ? ['text' => '👀 برگرد به بازبینی دستی', 'callback_data' => 'm:manual'] : ['text' => '🤖 انتشار خودکار (بدون تأیید)', 'callback_data' => 'm:auto']],
            [!empty($s['paused']) ? ['text' => '▶️ ادامه', 'callback_data' => 's:resume'] : ['text' => '⏸ توقف موقت', 'callback_data' => 's:pause']],
            [['text' => '🗂 باز کردن پنل نیک‌نگار', 'web_app' => ['url' => panel_url()]]]];
}

function draft_body(array $a): string {
    $p = $a[lang1()];
    $t = '<b>' . h($p['title']) . "</b>\n" . h($p['meta_description']) . "\n";
    foreach (langs() as $l) {
        $t .= "\n" . h(L($l)['label']) . ': ' . ($l === lang1() ? '' : h($a[$l]['title']) . ' · ') . fa_digits((string)$a[$l]['words']) . ' کلمه · '
            . h($a[$l]['primary_keyword']) . "\n" . post_url($l, $a['slug']) . "\n";
    }
    return $t . "\nپیش‌نمایش‌ها فعلاً از دید گوگل مخفی‌اند.";
}
function draft_msg(array $s, array $a, bool $queued): string {
    return ($queued ? "⏱ <b>پیش‌نویس در صف انتشار</b> (" . next_slot_text($s) . ")\n\n" : "📝 <b>پیش‌نویس منتظر تأیید</b>\n\n") . draft_body($a);
}

/* ---------------- drafts from GitHub / inbox ---------------- */
function check_drafts(array &$s): void {
    $files = [];
    if (GITHUB_REPO !== '') {
        $r = gh_api('GET', '/contents/drafts?ref=main');      // uses GH_TOKEN when set, so the content repo may be private
        if ($r['code'] === 200) $files = array_values(array_filter((array)$r['json'], fn($x) => ($x['type'] ?? '') === 'file'));
        elseif ($r['code'] !== 404) logline('github list failed ' . $r['code']);
    }
    foreach (glob(AB . '/inbox/*.json') ?: [] as $p) $files[] = ['name' => basename($p), 'local' => $p];
    usort($files, fn($x, $y) => strcmp($x['name'], $y['name']));
    foreach ($files as $f) {
        if (!str_ends_with($f['name'], '.json')) continue;
        $key = substr(md5($f['name']), 0, 10);
        if (isset($s['drafts'][$key])) continue;
        if (isset($f['local'])) $raw = (string)file_get_contents($f['local']);
        else { $g = gh_api('GET', '/contents/' . rawurlencode('drafts') . '/' . rawurlencode($f['name']) . '?ref=main'); $raw = (string)base64_decode((string)($g['json']['content'] ?? '')); }
        $d = json_decode($raw, true);
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
        foreach (langs() as $lang) write_file(L($lang)['dir'] . '/' . $a['slug'] . '/index.html', render_article($lang, $a[$lang], false));

        $auto = auto_mode($s);
        $status = $auto ? 'queued' : 'pending';
        $s['drafts'][$key] = ['file' => $f['name'], 'slug' => $a['slug'], 'title' => $a[lang1()]['title'], 'status' => $status, 'msgs' => [], 'at' => date('c'),
                              'words' => $a[lang1()]['words'], 'kw' => $a[lang1()]['primary_keyword'], 'byline' => byline_of($a)];
        if ($auto) $s['queue'][] = $key;
        $msg = ($auto ? "🤖 <b>پیش‌نویس تازه — در صف انتشار خودکار</b> (" . next_slot_text($s) . ")\n\n" : "📝 <b>پیش‌نویس تازه</b>\n\n") . draft_body($a);
        $s['drafts'][$key]['msgs'] = tg_all($s, $msg, draft_buttons($key, $status, byline_of($a)));
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
    foreach (langs() as $lang) {
        $a[$lang]['date'] = $today;
        $done = array_merge($done, array_map(fn($x) => "$lang $x", publish_side_effects($lang, $a[$lang])));
        write_file(L($lang)['dir'] . '/' . $a['slug'] . '/index.html', render_article($lang, $a[$lang], true));
        $url = post_url($lang, $a['slug']);
        $urls[] = $url;
        $r = http($url . '?v=' . time());
        if ($r['code'] === 0) $r = ['code' => 200, 'body' => (string)@file_get_contents(L($lang)['dir'] . '/' . $a['slug'] . '/index.html')];   // host cannot open its own domain: check the file
        $ok = $ok && $r['code'] === 200 && str_contains($r['body'], '<link rel="canonical" href="' . $url . '">') && str_contains($r['body'], 'index, follow');
    }
    $done[] = 'IndexNow ' . indexnow(array_merge($urls, array_map(fn($l) => SITE . L($l)['base'], langs())));
    draft_store($key, $a);                         // with the publish date, so later edits and byline changes keep it
    $d['status'] = 'published';
    $d['published_at'] = date('c');
    $s['published'][] = ['slug' => $a['slug'], 'title' => $a[lang1()]['title'], 'pub_day' => $today, 'at' => date('c'), 'key' => $key];
    $s['last_pub'] = time();
    set_buttons($s, $key);
    tg_all($s, ($ok ? '✅' : '⚠️') . ' <b>منتشر شد:</b> ' . h($a[lang1()]['title']) . "\n" . implode("\n", $urls)
        . "\n\n" . ($ok ? (count($urls) > 1 ? 'هر دو صفحه زنده‌اند و چک شدند.' : 'صفحه زنده است و چک شد.') : 'منتشر شد ولی چک نهایی کامل رد نشد؛ لاگ را ببین.')
        . "\nدر صف انتشار: " . fa_digits((string)count($s['queue'])) . actor_note($s));
    logline("published {$a['slug']} verify=" . ($ok ? 'ok' : 'fail') . ' ' . implode(',', $done));
}
function approve_key(array &$s, string $key): bool {
    if (($s['drafts'][$key]['status'] ?? '') !== 'pending') return false;
    $s['drafts'][$key]['status'] = 'queued';
    $s['queue'][] = $key;
    set_buttons($s, $key);
    tg_all($s, '⏱ در صف رفت. زمان انتشار: ' . next_slot_text($s) . "\n(" . h($s['drafts'][$key]['title']) . ')' . actor_note($s));
    return true;
}
function reject_key(array &$s, string $key): bool {
    $d = &$s['drafts'][$key];
    if (!in_array($d['status'] ?? '', ['pending', 'queued'], true)) return false;
    foreach (langs() as $lang) {
        $dir = L($lang)['dir'] . '/' . $d['slug'];
        if (is_dir($dir)) { @mkdir(AB . '/rejected', 0700, true); rename($dir, AB . "/rejected/{$d['slug']}-$lang-" . date('Ymd-His')); }
    }
    foreach (glob(COVER_DIR . '/' . $d['slug'] . '*.webp') ?: [] as $f) { @mkdir(AB . '/rejected', 0700, true); rename($f, AB . '/rejected/' . basename($f)); }
    $d['status'] = 'rejected';
    $s['queue'] = array_values(array_diff($s['queue'], [$key]));
    set_buttons($s, $key);
    tg_all($s, '🗑 رد شد و پیش‌نمایش‌ها از سایت برداشته شدند: ' . h($d['title']) . actor_note($s));
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
    $last = messenger() === 'bale' ? [['text' => '🗂 پنل', 'web_app' => ['url' => panel_url()]], ['text' => '/status']] : [['text' => '/status']];
    return ['keyboard' => [[['text' => '✍️ مقاله‌ی جدید'], ['text' => '⚙️ تنظیمات']], $last], 'resize_keyboard' => true, 'is_persistent' => true];
}
function send_panel_link($cid): void {
    $rows = [[['text' => '🗂 باز کردن پنل نیک‌نگار', 'web_app' => ['url' => panel_url()]]]];
    if (messenger() === 'bale') $rows[] = [['text' => '🌐 باز کردن در مرورگر (لینک شخصی، ۱۴ روز)', 'url' => panel_link($cid)]];
    tg('sendMessage', ['chat_id' => $cid, 'text' => 'پنل نیک‌نگار 👇', 'reply_markup' => ['inline_keyboard' => $rows]]);
}
function handle_update(array &$s, array $u): void {
    $s['tg_offset'] = max((int)$s['tg_offset'], (int)($u['update_id'] ?? 0));
    if (isset($u['message'])) {
        $cid = $u['message']['chat']['id'];
        $text = trim($u['message']['text'] ?? '');
        if (!$s['chat_ids']) {   // first owner: only whoever opens the claim link printed by install.php
            if (!defined('OWNER_CLAIM') || OWNER_CLAIM === '' || $text !== '/start ' . OWNER_CLAIM) return;
            $s['chat_ids'][] = $cid;
            remember_member($s, $u['message']['from'] ?? []);
            send_welcome($s, (int)$cid);
            logline("owner registered: chat $cid");
            return;
        }
        if (!in_array($cid, $s['chat_ids'], true)) { handle_stranger($s, $u['message']); return; }
        remember_member($s, $u['message']['from'] ?? []);
        set_actor($u['message']['from'] ?? []);
        if ($text === '/members' || $text === '👥 اعضا') {
            if (!is_owner($s, $cid)) { tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => members_text($s)]); return; }
            $kb = members_buttons($s);
            tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => members_text($s)] + ($kb ? ['reply_markup' => ['inline_keyboard' => $kb]] : []));
            return;
        }
        if ($text === '✍️ مقاله‌ی جدید' || $text === '/write') { request_write($s, $cid); return; }
        if ($text === '🗂 پنل' || $text === '/panel') { send_panel_link($cid); return; }
        if ($text === '⚙️ تنظیمات' || $text === '/settings') {
            tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => settings_text($s), 'reply_markup' => ['inline_keyboard' => settings_buttons($s)]]);
            return;
        }
        if ($text === '/start' || str_starts_with($text, '/start ')) { tg('sendMessage', ['chat_id' => $cid, 'text' => 'نیک‌نگار آماده است. دکمه‌ها پایین صفحه‌اند؛ پنل کامل هم از دکمه‌ی «نیک‌نگار» کنار کادر پیام باز می‌شود.', 'reply_markup' => main_kb2()]); return; }
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
        if ($text === '/pause') { $s['paused'] = true; tg('sendMessage', ['chat_id' => $cid, 'text' => '⏸ متوقف شد.']); notify_others($s, $cid, '⏸ نیک‌نگار متوقف شد.' . actor_note($s)); return; }
        if ($text === '/resume') { $s['paused'] = false; $s['fails'] = 0; tg('sendMessage', ['chat_id' => $cid, 'text' => '▶️ دوباره فعال شد.']); notify_others($s, $cid, '▶️ نیک‌نگار دوباره فعال شد.' . actor_note($s)); return; }
        if ($text === '/photokey' || str_starts_with($text, '/photokey ')) {   // owner sets the free Pixabay API key for cover photos
            if (!is_owner($s, $cid)) { tg('sendMessage', ['chat_id' => $cid, 'text' => 'فقط مالک ربات می‌تواند کلید عکس را تنظیم کند.']); return; }
            $k = trim(substr($text, 9));
            if ($k !== '' && isset($u['message']['message_id'])) tg('deleteMessage', ['chat_id' => $cid, 'message_id' => $u['message']['message_id']]);
            if ($k === '') { tg('sendMessage', ['chat_id' => $cid, 'text' => photo_key('pixabay') !== '' ? "🖼 کلید Pixabay تنظیم شده و کاورها از Pixabay می‌آیند.\nعوض کردن: /photokey کلید-جدید · حذف: /photokey off" : "🖼 کلید Pixabay تنظیم نشده؛ کاورها از Openverse می‌آیند که عکس کم دارد.\nتنظیم: /photokey کلید"]); return; }
            if ($k === 'off') { save_photo_key('pixabay', ''); tg('sendMessage', ['chat_id' => $cid, 'text' => '🖼 کلید Pixabay حذف شد.']); return; }
            if (!preg_match('/^[0-9]+-[a-f0-9]{16,48}$/i', $k)) { tg('sendMessage', ['chat_id' => $cid, 'text' => 'این شبیه کلید Pixabay نیست (شکلش این است: عدد-حروف، مثل 12345678-0a1b2c…).']); return; }
            [$code, $j] = photo_api('https://pixabay.com/api/?' . http_build_query(['key' => $k, 'q' => 'window', 'per_page' => 3]));
            if ($code !== 200 || $j === null) { tg('sendMessage', ['chat_id' => $cid, 'text' => "Pixabay این کلید را قبول نکرد (کد $code). ذخیره نشد."]); return; }
            save_photo_key('pixabay', $k);
            tg('sendMessage', ['chat_id' => $cid, 'text' => '✅ کلید Pixabay ذخیره و تست شد (پیامت هم پاک شد). از این به بعد کاورها از Pixabay می‌آیند.']);
            logline('pixabay key set');
            return;
        }
        if ($text === '/authors' || $text === '/team') { authors_message($cid); return; }
        if (str_starts_with($text, '/team ')) { team_command($s, $cid, trim(substr($text, 6))); return; }
        if ($text === '/aikey' || str_starts_with($text, '/aikey ')) {   // owner sets the Cloudflare Workers AI key for AI cover photos
            if (!is_owner($s, $cid)) { tg('sendMessage', ['chat_id' => $cid, 'text' => 'فقط مالک ربات می‌تواند کلید هوش مصنوعی را تنظیم کند.']); return; }
            $k = trim(substr($text, 6));
            if ($k !== '' && isset($u['message']['message_id'])) tg('deleteMessage', ['chat_id' => $cid, 'message_id' => $u['message']['message_id']]);
            if ($k === '') { tg('sendMessage', ['chat_id' => $cid, 'text' => ai_enabled()
                ? "✨ عکس کاورها با هوش مصنوعی ساخته می‌شوند (FLUX.2 روی Cloudflare).\nعوض کردن کلید: /aikey شناسه‌ی‌حساب توکن · خاموش کردن: /aikey off"
                : "✨ ساخت عکس با هوش مصنوعی خاموش است؛ کاورها از عکس‌های آماده پیدا می‌شوند.\nروشن کردن: /aikey شناسه‌ی‌حساب توکن"]); return; }
            if ($k === 'off') { save_photo_key('cloudflare', ''); tg('sendMessage', ['chat_id' => $cid, 'text' => '✨ ساخت عکس با هوش مصنوعی خاموش شد؛ از این به بعد عکس‌های آماده.']); return; }
            $p = preg_split('/[\s:]+/', $k);
            if (count($p) !== 2 || !preg_match('/^[a-f0-9]{32}$/i', $p[0]) || !preg_match('/^[A-Za-z0-9_\-]{30,}$/', $p[1])) {
                tg('sendMessage', ['chat_id' => $cid, 'text' => "شکلش درست نیست. این‌طوری بفرست:\n/aikey شناسه‌ی‌حساب توکن\n(شناسه‌ی حساب ۳۲ نویسه است؛ با یک فاصله بعدش توکن)"]); return;
            }
            tg('sendMessage', ['chat_id' => $cid, 'text' => '⏳ دارم با این کلید یک عکس آزمایشی می‌سازم…']);
            try { [$bin] = ai_generate('A cup of tea on a wooden table by a window, morning light. ' . AI_STYLE, 512, 512, [strtolower($p[0]), $p[1]]); }
            catch (Throwable $e) { tg('sendMessage', ['chat_id' => $cid, 'text' => "Cloudflare جواب درست نداد؛ ذخیره نشد.\n" . $e->getMessage()]); return; }
            save_photo_key('cloudflare', strtolower($p[0]) . ':' . $p[1]);
            $f = AB . '/state/ai-test.jpg';
            if (($im = @imagecreatefromstring($bin))) { imagejpeg($im, $f, 88); imagedestroy($im); }
            tg('sendMessage', ['chat_id' => $cid, 'text' => '✅ کلید کار کرد و ذخیره شد (پیامت هم پاک شد). از این به بعد کاور هر مقاله را هوش مصنوعی برای همان مقاله می‌سازد؛ اگر نشد، عکس آماده می‌آید.']);
            logline('cloudflare AI key set');
            return;
        }
        if ($text === '/publishnow') { if ($s['queue']) publish_next($s); else tg('sendMessage', ['chat_id' => $cid, 'text' => 'صف انتشار خالی است.']); return; }
        return;
    }
    if (isset($u['callback_query'])) {
        $cq = $u['callback_query'];
        $cid = $cq['message']['chat']['id'] ?? 0;
        if (!in_array($cid, $s['chat_ids'], true)) return;
        remember_member($s, $cq['from'] ?? []);
        set_actor($cq['from'] ?? []);
        [$act, $arg] = array_pad(explode(':', $cq['data'] ?? '', 2), 2, '');
        $ack = fn($t) => tg('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $t]);
        if (in_array($act, ['g', 'x', 'k'], true)) {               // access management: owner only
            $arg = preg_replace('/[^0-9\-]/', '', $arg);
            if (!is_owner($s, $cid)) { $ack('فقط مدیر می‌تواند اعضا را تغییر دهد'); return; }
            if ($act === 'g') $ack(grant_access($s, $cid, $arg));
            elseif ($act === 'x') $ack(deny_access($s, $cid, $arg));
            else {
                $ack(revoke_access($s, $arg));
                $kb = members_buttons($s);
                tg('editMessageText', ['chat_id' => $cid, 'message_id' => $cq['message']['message_id'], 'parse_mode' => 'HTML', 'text' => members_text($s),
                    'reply_markup' => ['inline_keyboard' => $kb]]);
            }
            return;
        }
        if ($act === 'm' || $act === 's') {
            $before = [auto_mode($s), !empty($s['paused'])];
            if ($act === 'm') set_mode($s, $arg === 'auto'); else { $s['paused'] = $arg === 'pause'; if (!$s['paused']) $s['fails'] = 0; }
            if ($before !== [auto_mode($s), !empty($s['paused'])]) notify_others($s, $cid, settings_change_text($s, $act));
            $ack('ذخیره شد');
            tg('editMessageText', ['chat_id' => $cid, 'message_id' => $cq['message']['message_id'], 'parse_mode' => 'HTML', 'text' => settings_text($s), 'reply_markup' => ['inline_keyboard' => settings_buttons($s)]]);
            return;
        }
        $st = $s['drafts'][$arg]['status'] ?? '';
        try {
            if ($act === 'w' || $act === 'v' || $act === 'u') {        // author: open the list / pick one / close the list
                [$k, $id] = array_pad(explode(':', $arg, 2), 2, '');
                if (!editable($s, $k)) { $ack('این مقاله دیگر قابل تغییر نیست'); return; }
                $mid = ['chat_id' => $cid, 'message_id' => $cq['message']['message_id']];
                if ($act === 'w') { $ack('به نام چه کسی منتشر شود؟'); tg('editMessageReplyMarkup', $mid + ['reply_markup' => ['inline_keyboard' => author_buttons($k, byline_of(draft_load($k)))]]); return; }
                if ($act === 'u') { $ack('بدون تغییر'); tg('editMessageReplyMarkup', $mid + ['reply_markup' => ['inline_keyboard' => draft_buttons($k, $s['drafts'][$k]['status'] ?? '', byline_of(draft_load($k)))]]); return; }
                if (!in_array($id, author_ids(), true)) { $ack('این نویسنده دیگر در فهرست نیست'); return; }
                $ack('✔︎ از حالا به نام ' . byline_name(lang1(), $id));
                set_byline($s, $k, $id, (int)$cid);
                return;
            }
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
            'title2' => count(langs()) > 1 ? ($a[langs()[1]]['title'] ?? '') : '', 'words' => (int)($a[lang1()]['words'] ?? 0), 'kw' => $a[lang1()]['primary_keyword'] ?? '',
            'cover' => $a['cover']['card'] ?? '', 'at' => $d['published_at'] ?? $d['at'] ?? '', 'byline' => byline_of($a),
            'links' => array_map(fn($l) => ['label' => L($l)['label'], 'url' => post_url($l, (string)($d['slug'] ?? ''))], langs()),
            'queue_pos' => ($i = array_search($k, $s['queue'], true)) === false ? null : $i + 1];
        if (count($items) >= 40) break;
    }
    return ['auto' => auto_mode($s), 'paused' => !empty($s['paused']), 'write_pending' => (int)($s['write_pending'] ?? 0),
            'slots' => array_map(fn($t) => date('H:i', $t), todays_slots($s)), 'next' => next_slot_text($s), 'items' => $items,
            'published_total' => count($s['published']),
            'published_week' => count(array_filter($s['published'], fn($p) => strtotime($p['at'] ?? '') > time() - 7 * 86400)),
            'site' => site('site_name', ''), 'langs' => array_map(fn($l) => ['code' => $l, 'label' => L($l)['label'], 'dir' => L($l)['dir_attr']], langs()),
            'write_time' => (string)site('daily_write_time', '06:47'), 'per_day' => (int)site('posts_per_day', 2), 'version' => KIT_VERSION,
            'authors' => authors_view($s), 'default_author' => byline_default()];
}

/* ---------------- editing from the panel: text and photos ---------------- */
const EDIT_FIELDS = ['title', 'seo_title', 'meta_description', 'excerpt'];
function editable(array $s, string $key): bool { return in_array($s['drafts'][$key]['status'] ?? '', ['pending', 'queued', 'published'], true); }
function draft_load(string $key): array {
    $a = json_decode((string)@file_get_contents(AB . "/drafts/$key.json"), true);
    if (!is_array($a)) throw new RuntimeException('فایل این مقاله روی سرور پیدا نشد');
    return $a;
}
function draft_store(string $key, array $a): void { json_save(AB . "/drafts/$key.json", $a); }
/** Fingerprint of the text only (a new cover photo does not count), so two people editing at once do not overwrite each other. */
function draft_rev(array $a): string {
    $t = [];
    foreach (langs() as $l) { foreach (EDIT_FIELDS as $f) $t[] = $a[$l][$f] ?? ''; $t[] = $a[$l]['body_clean'] ?? ''; $t[] = json_encode($a[$l]['faq'] ?? []); }
    return substr(md5(implode("\x1f", $t)), 0, 12);
}
/** What the panel editor shows. */
function draft_edit_view(array $s, string $key): array {
    $a = draft_load($key);
    $v = ['key' => $key, 'status' => $s['drafts'][$key]['status'], 'slug' => $a['slug'], 'rev' => draft_rev($a), 'cover' => $a['cover']['card'] ?? '',
          'cover_own' => !empty($a['cover']['own']), 'langs' => []];
    foreach (langs() as $l) {
        $x = ['code' => $l, 'label' => L($l)['label'], 'dir' => L($l)['dir_attr'], 'kw' => $a[$l]['primary_keyword'] ?? '', 'words' => (int)($a[$l]['words'] ?? 0),
              'body' => preg_replace('# (id|target|rel)="[^"]*"#', '', (string)($a[$l]['body_clean'] ?? '')), 'faq' => $a[$l]['faq'] ?? [],
              'url' => post_url($l, $a['slug'])];
        foreach (EDIT_FIELDS as $f) $x[$f] = $a[$l][$f] ?? '';
        $v['langs'][] = $x;
    }
    return $v;
}
/** Re-renders the article pages after a change; a published article also refreshes its card, sitemap, feed and IndexNow. */
function rerender_draft(array &$s, string $key, array $a): array {
    $live = ($s['drafts'][$key]['status'] ?? '') === 'published';
    if ($live) $a = live_dates($a);
    if ($live) foreach (langs() as $l) $a[$l]['modified'] = date('Y-m-d');
    draft_store($key, $a);
    $done = [];
    foreach (langs() as $l) {
        write_file(L($l)['dir'] . '/' . $a['slug'] . '/index.html', render_article($l, $a[$l], $live));
        if ($live) $done = array_merge($done, array_map(fn($x) => "$l $x", refresh_side_effects($l, $a[$l])));
    }
    if ($live) {
        $done[] = 'IndexNow ' . indexnow(array_map(fn($l) => post_url($l, $a['slug']), langs()));
        foreach ($s['published'] as &$p) if (($p['key'] ?? '') === $key) $p['title'] = $a[lang1()]['title'];
        unset($p);
    }
    $s['drafts'][$key]['title'] = $a[lang1()]['title'];
    $s['drafts'][$key]['words'] = $a[lang1()]['words'] ?? 0;
    return $done;
}
/** Problems found by the article checks, in plain Persian for the panel. */
function fa_issue(string $e): string {
    $lang = '';
    if (preg_match('/^([A-Z]{2}): (.*)$/s', $e, $m)) { $lang = count(langs()) > 1 ? L(strtolower($m[1]))['label'] . ': ' : ''; $e = $m[2]; }
    $names = ['title' => 'عنوان', 'seo_title' => 'عنوان سئو', 'meta_description' => 'توضیح متا', 'excerpt' => 'خلاصه', 'body_html' => 'متن مقاله', 'primary_keyword' => 'کلمه‌ی کلیدی'];
    $map = [
        '/^missing (\w+)$/' => fn($m) => '«' . ($names[$m[1]] ?? $m[1]) . '» خالی است',
        '/^seo_title length (\d+)/' => fn($m) => 'عنوان سئو ' . fa_digits($m[1]) . ' نویسه است؛ برای گوگل بهتر است بین ۲۰ و ۶۵ باشد',
        '/^meta_description length (\d+)/' => fn($m) => 'توضیح متا ' . fa_digits($m[1]) . ' نویسه است؛ برای گوگل بهتر است بین ۱۱۰ و ۱۷۰ باشد',
        '/^primary keyword not in title$/' => fn($m) => 'کلمه‌ی کلیدی اصلی در عنوان نیست',
        '/^word count (\d+) \((\d+)/' => fn($m) => 'متن ' . fa_digits($m[1]) . ' کلمه است؛ حداقل پیشنهادی ' . fa_digits($m[2]) . ' کلمه',
        '/^primary keyword not in the opening paragraphs$/' => fn($m) => 'کلمه‌ی کلیدی اصلی در پاراگراف‌های اول نیامده',
        '/^needs at least 3 H2$/' => fn($m) => 'کمتر از ۳ تیتر اصلی (H2) دارد',
        '/^only (\d+) valid internal links \(min (\d+)\)$/' => fn($m) => fa_digits($m[1]) . ' لینک داخلی دارد؛ دست‌کم ' . fa_digits($m[2]) . ' تا پیشنهاد می‌شود',
        '/^call-to-action link (.+) missing$/' => fn($m) => 'لینک دعوت به اقدام (' . $m[1] . ') در متن نیست',
        '/^FAQ must have 3–6 items$/' => fn($m) => 'سؤال‌های متداول باید بین ۳ تا ۶ تا باشند',
        '/^H1 inside body$/' => fn($m) => 'تیتر H1 در متن بود و برداشته شد (عنوان مقاله خودش H1 است)',
        '/^unlinked: (.+)$/' => fn($m) => 'لینک ' . $m[1] . ' به صفحه‌ی شناخته‌شده‌ای نمی‌رسید و به متن ساده تبدیل شد',
    ];
    foreach ($map as $re => $fn) if (preg_match($re, $e, $m)) return $lang . $fn($m);
    return $lang . $e;
}
/** Saves edited text. Only an empty required field blocks the save; the SEO checks come back as notes. */
function save_draft_edit(array &$s, string $key, array $req, int $uid): array {
    if (!editable($s, $key)) throw new RuntimeException('این مقاله دیگر قابل ویرایش نیست');
    $a = draft_load($key);
    if ((string)($req['rev'] ?? '') !== draft_rev($a)) throw new RuntimeException('کس دیگری همین حالا این مقاله را تغییر داده؛ ویرایشگر را ببند و دوباره باز کن تا نسخه‌ی تازه را ببینی');
    $before = draft_rev($a);
    $block = []; $notes = [];
    foreach (langs() as $l) {
        $in = $req['langs'][$l] ?? null;
        if (!is_array($in)) throw new RuntimeException('متن ' . L($l)['label'] . ' نرسید');
        $x = $a[$l];
        foreach (EDIT_FIELDS as $f) $x[$f] = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string)($in[$f] ?? ''))), 0, 300);
        $x['body_html'] = mb_substr((string)($in['body'] ?? ''), 0, 200000);
        $x['faq'] = array_slice(array_values(array_filter(array_map(fn($q) => ['q' => mb_substr(trim((string)($q['q'] ?? '')), 0, 300), 'a' => mb_substr(trim((string)($q['a'] ?? '')), 0, 2000)],
            (array)($in['faq'] ?? [])), fn($q) => $q['q'] !== '' && $q['a'] !== '')), 0, 10);
        $err = []; $warn = [];
        $p = prepare_lang($l, $x, $a['slug'], (string)$a[$l]['date'], $err, $warn);
        foreach ($err as $e) { if (preg_match('/^[A-Z]{2}: missing /', $e)) $block[] = fa_issue($e); else $notes[] = fa_issue($e); }
        foreach ($warn as $w) $notes[] = fa_issue($w);
        if (!array_filter($err, fn($e) => preg_match('/^[A-Z]{2}: missing /', $e))) $a[$l] = $p;
    }
    if ($block) throw new RuntimeException(implode(' · ', $block));
    if (draft_rev($a) === $before) return ['changed' => false, 'notes' => [], 'rev' => $before];
    $done = rerender_draft($s, $key, $a);
    $live = $s['drafts'][$key]['status'] === 'published';
    notify_others($s, $uid, '✏️ متن <b>' . h($a[lang1()]['title']) . '</b> در پنل ویرایش شد'
        . ($live ? '؛ صفحه‌ی منتشرشده هم به‌روز شد.' : '؛ پیش‌نمایش به‌روز شد.') . "\n" . post_url(lang1(), $a['slug']) . actor_note($s));
    logline("edited {$a['slug']} by uid $uid" . ($done ? ' (' . implode(', ', $done) . ')' : ''));
    return ['changed' => true, 'notes' => array_values(array_unique($notes)), 'rev' => draft_rev($a)];
}
/** Cover photo uploaded from the panel. */
function upload_cover(array &$s, string $key, string $data, array $user, int $uid): array {
    if (!editable($s, $key)) throw new RuntimeException('این مقاله دیگر قابل ویرایش نیست');
    $a = set_uploaded_cover($s, draft_load($key), $data, trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    rerender_draft($s, $key, $a);
    notify_others($s, $uid, '🖼 عکس کاور <b>' . h($a[lang1()]['title']) . '</b> با عکسی که از پنل آپلود شد عوض شد.'
        . ($s['drafts'][$key]['status'] === 'published' ? ' صفحه‌ی منتشرشده هم به‌روز شد.' : '') . actor_note($s));
    return ['cover' => $a['cover']['card']];
}

/* ---------------- byline: main author or editorial team ---------------- */
/** A published article keeps the date it went live (drafts saved before v1.3 only had the writing date). */
function live_dates(array $a): array {
    foreach (langs() as $l) foreach (posts($l) as $p) if ($p['slug'] === ($a['slug'] ?? '') && !empty($p['date'])) $a[$l]['date'] = $p['date'];
    return $a;
}
/** Credits an article to one of the site's authors and re-renders its pages (preview or live). */
function set_byline(array &$s, string $key, string $to, int $uid, bool $quiet = false): string {
    if (!in_array($to, author_ids(), true)) throw new RuntimeException('این نویسنده در فهرست نیست');
    if (!editable($s, $key)) throw new RuntimeException('این مقاله دیگر قابل تغییر نیست');
    $a = draft_load($key);
    $name = byline_name(lang1(), $to);
    if (byline_of($a) === $to && ($s['drafts'][$key]['byline'] ?? '') === $to) { set_buttons($s, $key); return $name; }
    $a = with_byline($a, $to);
    $live = ($s['drafts'][$key]['status'] ?? '') === 'published';
    if ($live) $a = live_dates($a);
    draft_store($key, $a);
    foreach (langs() as $l) write_file(L($l)['dir'] . '/' . $a['slug'] . '/index.html', render_article($l, $a[$l], $live));
    $s['drafts'][$key]['byline'] = $to;
    set_buttons($s, $key);
    if (!$quiet) notify_others($s, $uid, '✍️ «' . h($a[lang1()]['title']) . '» از حالا به نام <b>' . h($name) . '</b> است'
        . ($live ? '؛ صفحه‌اش روی سایت هم عوض شد.' : '.') . actor_note($s));
    logline("byline {$a['slug']} -> $to by uid $uid");
    return $name;
}
/** Inline keyboard that replaces a draft's buttons while its author is being picked. */
function author_buttons(string $key, string $cur): array {
    $rows = [];
    foreach (author_ids() as $id) $rows[] = [['text' => ($id === $cur ? '✔︎ ' : '') . byline_name(lang1(), $id), 'callback_data' => "v:$key:$id"]];
    $rows[] = [['text' => '➕ نویسنده‌ی تازه', 'web_app' => ['url' => panel_url() . '?authors=1']], ['text' => '↩︎ بی‌خیال', 'callback_data' => "u:$key"]];
    return $rows;
}
/** Re-renders the open and published articles credited to one author (after their name, bio or link changed). */
function rerender_author_articles(array &$s, string $id): int {
    $n = 0;
    foreach ($s['drafts'] as $k => $d) {
        if (!in_array($d['status'] ?? '', ['pending', 'queued', 'published'], true)) continue;
        $a = json_decode((string)@file_get_contents(AB . "/drafts/$k.json"), true);
        if (!is_array($a) || byline_of($a) !== $id) continue;
        $live = $d['status'] === 'published';
        if ($live) $a = live_dates($a);
        foreach (langs() as $l) write_file(L($l)['dir'] . '/' . $a['slug'] . '/index.html', render_article($l, $a[$l], $live));
        $s['drafts'][$k]['byline'] = $id;
        set_buttons($s, (string)$k);
        $n++;
    }
    return $n;
}
/** How many open or published articles each author has. */
function author_counts(array $s): array {
    $c = [];
    foreach ($s['drafts'] as $k => $d) {
        if (!in_array($d['status'] ?? '', ['pending', 'queued', 'published'], true)) continue;
        $id = (string)($d['byline'] ?? '');
        if ($id === '') { $a = json_decode((string)@file_get_contents(AB . "/drafts/$k.json"), true); $id = is_array($a) ? byline_of($a) : ''; }
        if ($id !== '') $c[$id] = ($c[$id] ?? 0) + 1;
    }
    return $c;
}
/** The author list as the panel shows it. */
function authors_view(array $s): array {
    $b = bylines(); $cnt = author_counts($s); $out = [];
    foreach (author_ids() as $id) {
        $x = ['id' => $id, 'kind' => $id === 'main' || $id === 'team' ? $id : 'added', 'count' => $cnt[$id] ?? 0, 'langs' => []];
        foreach (langs() as $l) {
            $i = author_info($l, $id);
            $x['langs'][$l] = ['name' => $i['name'], 'title' => $i['title'], 'url' => (string)(((array)(($b['authors'][$id] ?? [])['url'] ?? []))[$l] ?? '')];
        }
        $x['name'] = $x['langs'][lang1()]['name'];
        $out[] = $x;
    }
    return $out;
}
/** Adds a writer, or changes the team's or an added writer's name, bio and page. The main author comes from site.json. */
function author_save(array &$s, array $req, int $uid): array {
    $id = preg_replace('/[^a-z0-9]/', '', (string)($req['id'] ?? ''));
    if ($id === 'main') throw new RuntimeException('نویسنده‌ی اصلی از تنظیمات سایت می‌آید و از این‌جا عوض نمی‌شود');
    $b = bylines();
    if ($id !== '' && $id !== 'team' && !isset($b['authors'][$id])) throw new RuntimeException('این نویسنده دیگر در فهرست نیست');
    $x = ['name' => [], 'title' => [], 'url' => []];
    foreach (langs() as $l) {
        $in = (array)($req['langs'][$l] ?? []);
        $clean = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $n);
        $name = $clean($in['name'] ?? '', 60); $title = $clean($in['title'] ?? '', 120); $url = trim((string)($in['url'] ?? ''));
        if ($l === 'fa') { $name = normalize_fa($name); $title = normalize_fa($title); }
        if ($url !== '' && !preg_match('#^(/[^\s<>"]*|https://[^\s<>"]+)$#', $url)) throw new RuntimeException('لینک صفحه‌ی نویسنده باید با / (صفحه‌ای از همین سایت) یا https:// شروع شود');
        if ($url !== '' && str_starts_with($url, 'https://') && !str_starts_with($url, SITE . '/')) throw new RuntimeException('لینک صفحه‌ی نویسنده باید صفحه‌ای از همین سایت باشد');
        if ($name !== '') $x['name'][$l] = $name;
        if ($title !== '') $x['title'][$l] = $title;
        if ($url !== '') $x['url'][$l] = mb_substr($url, 0, 200);
    }
    if (empty($x['name'][lang1()]) && $id !== 'team') throw new RuntimeException('اسم نویسنده را بنویس');
    $new = $x['name'][lang1()] ?? '';
    foreach (author_ids() as $o) if ($o !== $id && $new !== '' && mb_strtolower(byline_name(lang1(), $o)) === mb_strtolower($new))
        throw new RuntimeException('«' . $new . '» همین الان در فهرست نویسنده‌ها هست');
    if ($id === '') { $b['seq'] = (int)($b['seq'] ?? 0) + 1; $id = 'a' . $b['seq']; }
    $b['authors'][$id] = array_filter($x);
    bylines_save($b);
    $n = rerender_author_articles($s, $id);
    notify_others($s, $uid, isset($req['id']) && $req['id'] !== ''
        ? '✍️ مشخصات نویسنده‌ی <b>' . h(byline_name(lang1(), $id)) . '</b> به‌روز شد' . ($n ? ' و ' . fa_digits((string)$n) . ' مقاله‌اش هم.' : '.') . actor_note($s)
        : '✍️ <b>' . h(byline_name(lang1(), $id)) . '</b> به فهرست نویسنده‌ها اضافه شد. از این به بعد زیر هر مقاله می‌شود او را انتخاب کرد.' . actor_note($s));
    logline("author saved $id: " . byline_name(lang1(), $id) . " by uid $uid");
    return ['id' => $id, 'updated' => $n];
}
/** Removes an added writer; their articles go to the editorial team (pages re-rendered). */
function author_delete(array &$s, string $id, int $uid): int {
    $b = bylines();
    if (in_array($id, ['main', 'team'], true) || !isset($b['authors'][$id])) throw new RuntimeException('این نویسنده را نمی‌شود حذف کرد');
    $name = byline_name(lang1(), $id);
    $moved = 0;
    foreach ($s['drafts'] as $k => $d) {
        if (!in_array($d['status'] ?? '', ['pending', 'queued', 'published'], true)) continue;
        $a = json_decode((string)@file_get_contents(AB . "/drafts/$k.json"), true);
        if (is_array($a) && ($a['byline'] ?? '') === $id) { set_byline($s, (string)$k, 'team', $uid, true); $moved++; }
    }
    unset($b['authors'][$id]);
    if (($b['default'] ?? '') === $id) unset($b['default']);
    bylines_save($b);
    notify_others($s, $uid, '✍️ <b>' . h($name) . '</b> از فهرست نویسنده‌ها برداشته شد' . ($moved ? '؛ ' . fa_digits((string)$moved) . ' مقاله‌اش به نام ' . h(team_name(lang1())) . ' رفت.' : '.') . actor_note($s));
    logline("author removed $id ($name), moved $moved by uid $uid");
    return $moved;
}
/** The author new articles go out under. Articles already written keep theirs. */
function author_set_default(array &$s, string $id, int $uid): void {
    if (!in_array($id, author_ids(), true)) throw new RuntimeException('این نویسنده در فهرست نیست');
    $b = bylines(); $b['default'] = $id; bylines_save($b);
    notify_others($s, $uid, '✍️ مقاله‌های تازه از این به بعد به نام <b>' . h(byline_name(lang1(), $id)) . '</b> می‌آیند.' . actor_note($s));
    logline("default author $id by uid $uid");
}
/** /authors: the list, and a button that opens it in the panel. */
function authors_message($cid): void {
    $t = "✍️ <b>نویسنده‌های سایت</b>\n\n";
    foreach (author_ids() as $id) $t .= ($id === byline_default() ? '★ ' : '• ') . h(byline_name(lang1(), $id)) . ($id === byline_default() ? ' — <i>پیش‌فرض مقاله‌های تازه</i>' : '') . "\n";
    $t .= "\nزیر هر مقاله دکمه‌ی «✍️ نویسنده» هست؛ با آن می‌شود مقاله را به نام هر کدام کرد، حتی بعد از انتشار."
        . "\nبرای افزودن نویسنده‌ی تازه یا عوض کردن اسم و معرفی‌ها، پنل را باز کن 👇";
    tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => $t,
        'reply_markup' => ['inline_keyboard' => [[['text' => '✍️ مدیریت نویسنده‌ها', 'web_app' => ['url' => panel_url() . '?authors=1']]]]]]);
}
/** "/team NAME" renames the editorial team (first language), "/team en NAME" another language, "/team off" resets it. */
function team_command(array &$s, $cid, string $arg): void {
    $lang = lang1();
    if (preg_match('/^([a-z]{2})\s+(.+)$/su', $arg, $m) && in_array($m[1], langs(), true)) { $lang = $m[1]; $arg = trim($m[2]); }
    $b = bylines();
    $t = (array)($b['authors']['team'] ?? []);
    if ($arg === 'off') unset($t['name'][$lang]); else $t['name'][$lang] = mb_substr(preg_replace('/\s+/u', ' ', strip_tags($arg)), 0, 60);
    $b['authors']['team'] = array_filter($t);
    bylines_save($b);
    $n = rerender_author_articles($s, 'team');
    tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => '✅ اسم تحریریه' . (count(langs()) > 1 ? ' (' . h(L($lang)['label']) . ')' : '')
        . ' شد <b>' . h(team_name($lang)) . '</b>' . ($n ? "\n" . fa_digits((string)$n) . ' مقاله‌ای که به نام تحریریه بود با اسم تازه به‌روز شد.' : '')]);
    notify_others($s, $cid, '✍️ اسم تحریریه شد <b>' . h(team_name($lang)) . '</b>.' . actor_note($s));
    logline("team name $lang: " . team_name($lang));
}
