<?php
// v3 setup: Telegram webhook (instant buttons) + panel Mini App. Idempotent. Report: setup3-report.txt
declare(strict_types=1);
$cfgf = __DIR__ . '/config.php';
$cfg = file_get_contents($cfgf);
if (!str_contains($cfg, 'PANEL_DIR')) $cfg = rtrim($cfg) . "\ndefine('PANEL_DIR', 'tg-" . bin2hex(random_bytes(6)) . "');\n";
if (!str_contains($cfg, 'HOOK_SECRET')) $cfg = rtrim($cfg) . "\ndefine('HOOK_SECRET', '" . bin2hex(random_bytes(16)) . "');\n";
file_put_contents($cfgf, $cfg);
require __DIR__ . '/core.php';
$rep = [];
$dir = PUB . '/' . PANEL_DIR;
@mkdir("$dir/app", 0755, true);
copy(__DIR__ . '/panel/hook.php', "$dir/hook.php");
copy(__DIR__ . '/panel/api.php', "$dir/app/api.php");
copy(__DIR__ . '/panel/app.html', "$dir/app/index.html");
copy(__DIR__ . '/panel/htaccess.txt', "$dir/.htaccess");
foreach (["$dir/hook.php", "$dir/app/api.php", "$dir/app/index.html", "$dir/.htaccess"] as $f) @chmod($f, 0644);
file_put_contents("$dir/ping.php", '<?php require "/home/mrnazif/autoblog/core.php"; echo json_encode(["php"=>PHP_VERSION,"user"=>function_exists("posix_geteuid")?posix_getpwuid(posix_geteuid())["name"]:get_current_user(),"state_writable"=>is_writable(AB."/state/state.json"),"gd"=>function_exists("imagewebp")]);');
$p = http(SITE . '/' . PANEL_DIR . '/ping.php?t=' . time());
$rep[] = 'ping: ' . $p['code'] . ' ' . substr($p['body'], 0, 300);
$ping = json_decode($p['body'], true);
$okWeb = is_array($ping) && version_compare($ping['php'] ?? '0', '8.1', '>=') && !empty($ping['state_writable']);
@unlink("$dir/ping.php");
if ($okWeb) {
    $wh = tg('setWebhook', ['url' => SITE . '/' . PANEL_DIR . '/hook.php', 'secret_token' => HOOK_SECRET, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => false]);
    $rep[] = 'setWebhook: ' . json_encode($wh);
    with_state(function (array &$s) use ($wh, &$rep) {
        if ($wh) { $s['webhook'] = true; $s['hook_last'] = (int)$s['tg_offset']; }
        foreach ($s['chat_ids'] as $cid) {
            $m = tg('setChatMenuButton', ['chat_id' => $cid, 'menu_button' => ['type' => 'web_app', 'text' => 'پنل', 'web_app' => ['url' => panel_url()]]]);
            $rep[] = 'menu button: ' . json_encode($m);
            tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'reply_markup' => main_kb2(), 'text' =>
                "✨ <b>به‌روزرسانی ربات</b>\n\n• دکمه‌ها حالا همان لحظه جواب می‌دهند.\n• هر پیش‌نویس دکمه‌ی «🚀 انتشار همین حالا» دارد؛ دیگر لازم نیست منتظر نوبت بمانی.\n• از «⚙️ تنظیمات» می‌توانی بازبینی را خاموش کنی تا مقاله‌ها خودکار منتشر شوند.\n• پنل کامل از دکمه‌ی «پنل» کنار کادر پیام باز می‌شود."]);
            tg('sendMessage', ['chat_id' => $cid, 'text' => 'پنل مدیریت بلاگ:', 'reply_markup' => ['inline_keyboard' => [[['text' => '🗂 باز کردن پنل', 'web_app' => ['url' => panel_url()]]]]]]);
        }
        // refresh buttons on drafts still waiting, so they get the new "publish now" button
        foreach ($s['drafts'] as $k => $d) if (in_array($d['status'] ?? '', ['pending', 'queued'], true)) set_buttons($s, $k);
    }, 60);
} else $rep[] = 'web PHP not suitable — webhook NOT set, cron polling stays on';
$rep[] = 'panel: ' . panel_url();
$rep[] = 'done ' . date('c');
file_put_contents(__DIR__ . '/setup3-report.txt', implode("\n", $rep) . "\n");
