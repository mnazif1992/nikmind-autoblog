<?php
// One-off: adds /members to the bot's command list and tells the owner how adding people works.
require __DIR__ . '/core.php';
$rep = [];
$rep[] = 'commands: ' . json_encode(tg('setMyCommands', ['commands' => [
    ['command' => 'status', 'description' => 'وضعیت نیک‌نگار'], ['command' => 'settings', 'description' => 'تنظیمات انتشار'],
    ['command' => 'write', 'description' => 'نوشتن مقاله‌ی جدید'], ['command' => 'publishnow', 'description' => 'انتشار فوری مقاله‌ی بعدی صف'],
    ['command' => 'members', 'description' => 'اعضا و دسترسی‌ها']]]) !== null);
with_state(function (array &$s) use (&$rep) {
    $o = owner_id($s);
    $rep[] = "owner $o members " . json_encode($s['chat_ids']);
    $r = tg('sendMessage', ['chat_id' => $o, 'parse_mode' => 'HTML', 'text' =>
        "👥 <b>نیک‌نگار حالا چندنفره است</b>\n\n"
        . "هر کسی ربات را استارت کند یا پیامی به آن بدهد، درخواستش همین‌جا با دو دکمه می‌آید: «✅ دسترسی کامل بده» و «🚫 نه».\n\n"
        . "با تأیید، آن آدم هر چیزی که تو می‌بینی را می‌بیند و می‌تواند همان کارها را بکند: پیش‌نویس‌ها و پیش‌نویس‌های باز فعلی، دکمه‌های انتشار و رد و عکس دیگر، تنظیمات و پنل نیک‌نگار. پیام راهنما هم برایش می‌رود.\n\n"
        . "• هر تصمیمی که یکی بگیرد برای همه ثبت می‌شود و اسمش زیر پیام می‌آید.\n"
        . "• اضافه و حذف اعضا فقط با توست: /members\n\n"
        . "دو نفری که قبلاً ربات را استارت کرده بودند، قبل از این تغییر ثبت نشده‌اند؛ کافی است یک بار دیگر /start بزنند تا درخواستشان برسد."]);
    $rep[] = 'notice ' . json_encode($r !== null);
}, 60);
file_put_contents(__DIR__ . '/members-report.txt', implode("\n", $rep) . "\n");
