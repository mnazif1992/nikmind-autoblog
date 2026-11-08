<?php
// one-off: bot profile (name, descriptions, menu button) for «نیک‌نگار» + refreshed panel files
declare(strict_types=1);
require __DIR__ . '/core.php';
$rep = [];
$dir = PUB . '/' . PANEL_DIR;
copy(__DIR__ . '/panel/app.html', "$dir/app/index.html");
copy(__DIR__ . '/panel/api.php', "$dir/app/api.php");
copy(__DIR__ . '/panel/hook.php', "$dir/hook.php");
$rep[] = 'panel files copied';
$rep[] = 'name: ' . json_encode(tg('setMyName', ['name' => 'نیک‌نگار | بلاگ نیک‌مایند']));
$rep[] = 'short: ' . json_encode(tg('setMyShortDescription', ['short_description' => 'میز تحریریه‌ی بلاگ نیک‌مایند — طراحی و ساخت: دکتر محمدرضا نظیف']));
$rep[] = 'desc: ' . json_encode(tg('setMyDescription', ['description' => "نیک‌نگار هر روز برای بلاگ iranian.nikmindclinic.com مقاله‌ی فارسی و انگلیسی می‌نویسد، عکس کاور پیدا می‌کند و پیش‌نویس را برای تأیید می‌فرستد.\n\nطراحی و ساخت: دکتر محمدرضا نظیف"]));
$rep[] = 'commands: ' . json_encode(tg('setMyCommands', ['commands' => [
    ['command' => 'status', 'description' => 'وضعیت نیک‌نگار'], ['command' => 'settings', 'description' => 'تنظیمات انتشار'],
    ['command' => 'write', 'description' => 'نوشتن مقاله‌ی جدید'], ['command' => 'publishnow', 'description' => 'انتشار فوری مقاله‌ی بعدی صف']]]));
$s = state_load();
foreach ($s['chat_ids'] as $cid) {
    $rep[] = 'menu: ' . json_encode(tg('setChatMenuButton', ['chat_id' => $cid, 'menu_button' => ['type' => 'web_app', 'text' => 'نیک‌نگار', 'web_app' => ['url' => panel_url()]]]));
    tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => "✨ ربات حالا اسم دارد: <b>نیک‌نگار</b>\nپنلش هم از نو طراحی شد. از دکمه‌ی «نیک‌نگار» کنار کادر پیام بازش کن.\n\n<i>طراحی و ساخت: دکتر محمدرضا نظیف</i>",
        'reply_markup' => ['inline_keyboard' => [[['text' => '🗂 باز کردن نیک‌نگار', 'web_app' => ['url' => panel_url()]]]]]]);
}
file_put_contents(__DIR__ . '/brand-report.txt', implode("\n", $rep) . "\n");
