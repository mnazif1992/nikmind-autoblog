<?php
// Team access: anyone who starts the bot sends an access request to the owner (the first registered chat).
// The owner grants or refuses with one tap; members get everything the owner gets (drafts, buttons, panel).
declare(strict_types=1);

function owner_id(array $s): int { return (int)($s['chat_ids'][0] ?? 0); }
function is_owner(array $s, $cid): bool { return (int)$cid === owner_id($s) && owner_id($s) !== 0; }
function person_label(array $from): string {
    $n = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    if ($n === '') $n = 'بی‌نام';
    return $n . (!empty($from['username']) ? ' (@' . $from['username'] . ')' : '');
}
function member_name(array $s, $cid): string { return (string)($s['members'][(string)$cid]['name'] ?? ('#' . $cid)); }
function remember_member(array &$s, array $from): void {
    $id = (string)($from['id'] ?? '');
    if ($id === '' || !in_array((int)$id, $s['chat_ids'], true)) return;
    $s['members'][$id] = ['name' => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: 'بی‌نام',
                          'username' => (string)($from['username'] ?? ''), 'since' => $s['members'][$id]['since'] ?? date('c')];
}

/** Who pressed the button, shown under shared notices when more than one person runs the bot. */
function actor_note(array $s): string {
    $a = (string)($GLOBALS['ab_actor'] ?? '');
    return ($a !== '' && count($s['chat_ids']) > 1) ? "\n👤 " . h($a) : '';
}
function set_actor(array $from): void { $GLOBALS['ab_actor'] = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')); }

/** Tells the other members that one of them changed a setting. */
function notify_others(array $s, $cid, string $html): void {
    foreach ($s['chat_ids'] as $c) if ((int)$c !== (int)$cid) tg('sendMessage', ['chat_id' => $c, 'parse_mode' => 'HTML', 'text' => $html]);
}

function settings_change_text(array $s, string $what): string {
    $t = $what === 'm'
        ? (auto_mode($s) ? '🤖 حالت انتشار روی <b>خودکار</b> رفت؛ پیش‌نویس‌ها بدون تأیید در نوبت منتشر می‌شوند.' : '👀 حالت انتشار روی <b>بازبینی دستی</b> برگشت.')
        : (!empty($s['paused']) ? '⏸ نیک‌نگار <b>متوقف</b> شد.' : '▶️ نیک‌نگار دوباره <b>فعال</b> شد.');
    return $t . actor_note($s);
}

/* ---------------- requests from people who are not members ---------------- */
function handle_stranger(array &$s, array $m): void {
    $cid = $m['chat']['id'];
    if (($m['chat']['type'] ?? '') !== 'private') return;
    $k = (string)$cid;
    if (isset($s['denied'][$k]) && strtotime((string)$s['denied'][$k]) > time() - 7 * 86400) return;   // a refused person can ask again after a week
    $from = $m['from'] ?? ['id' => $cid];
    if (isset($s['access_req'][$k])) {
        if (empty($s['access_req'][$k]['nudged'])) {
            $s['access_req'][$k]['nudged'] = 1;
            tg('sendMessage', ['chat_id' => $cid, 'text' => 'درخواستت فرستاده شده؛ به محض تأیید همین‌جا خبرت می‌کنم.']);
        }
        return;
    }
    $label = person_label($from);
    $r = tg('sendMessage', ['chat_id' => owner_id($s), 'parse_mode' => 'HTML',
        'text' => "🔐 <b>درخواست دسترسی به نیک‌نگار</b>\n\n" . h($label) . "\nشناسه: <code>$cid</code>\n\nبا تأیید، هر چیزی که تو می‌بینی را می‌بیند: پیش‌نویس‌ها، دکمه‌های انتشار و رد، تنظیمات و پنل.",
        'reply_markup' => ['inline_keyboard' => [[['text' => '✅ دسترسی کامل بده', 'callback_data' => "g:$cid"], ['text' => '🚫 نه', 'callback_data' => "x:$cid"]]]]]);
    $s['access_req'][$k] = ['label' => $label, 'from' => $from, 'at' => date('c'), 'msg' => $r['result']['message_id'] ?? 0];
    tg('sendMessage', ['chat_id' => $cid, 'text' => "سلام 👋\nاین ربات خصوصی است. درخواست دسترسی‌ات برای مدیر فرستاده شد؛ بعد از تأیید همین‌جا خبرت می‌کنم."]);
    logline("access requested by $cid");
}

function grant_access(array &$s, $ownerCid, string $k): string {
    $req = $s['access_req'][$k] ?? null;
    if (!$req) return in_array((int)$k, $s['chat_ids'], true) ? 'قبلاً دسترسی دارد' : 'درخواست پیدا نشد';
    unset($s['access_req'][$k], $s['denied'][$k]);
    $cid = (int)$k;
    if (!in_array($cid, $s['chat_ids'], true)) $s['chat_ids'][] = $cid;
    remember_member($s, $req['from']);
    tg('editMessageText', ['chat_id' => $ownerCid, 'message_id' => $req['msg'], 'parse_mode' => 'HTML',
        'text' => '✅ <b>دسترسی کامل داده شد:</b> ' . h($req['label']) . "\nفهرست اعضا و حذف دسترسی: /members"]);
    send_welcome($s, $cid);
    resend_open_drafts($s, $cid);
    logline("access granted to $cid");
    return 'دسترسی داده شد';
}
function deny_access(array &$s, $ownerCid, string $k): string {
    $req = $s['access_req'][$k] ?? null;
    if (!$req) return 'درخواست پیدا نشد';
    unset($s['access_req'][$k]);
    $s['denied'][$k] = date('c');
    tg('editMessageText', ['chat_id' => $ownerCid, 'message_id' => $req['msg'], 'text' => '🚫 درخواست ' . $req['label'] . ' رد شد.']);
    tg('sendMessage', ['chat_id' => (int)$k, 'text' => 'دسترسی به این ربات داده نشد.']);
    logline("access denied to $k");
    return 'رد شد';
}
function revoke_access(array &$s, string $k): string {
    $cid = (int)$k;
    if ($cid === owner_id($s)) return 'مدیر را نمی‌شود حذف کرد';
    if (!in_array($cid, $s['chat_ids'], true)) return 'عضو نیست';
    $name = member_name($s, $cid);
    $s['chat_ids'] = array_values(array_filter($s['chat_ids'], fn($c) => (int)$c !== $cid));
    unset($s['members'][$k]);
    tg('sendMessage', ['chat_id' => $cid, 'text' => 'دسترسی‌ات به نیک‌نگار برداشته شد.', 'reply_markup' => ['remove_keyboard' => true]]);
    logline("access revoked from $cid ($name)");
    return 'دسترسی ' . $name . ' برداشته شد';
}

function members_text(array $s): string {
    $lines = [];
    foreach ($s['chat_ids'] as $i => $c) {
        $m = $s['members'][(string)$c] ?? [];
        $lines[] = ($i === 0 ? '👑 ' : '👤 ') . h($m['name'] ?? ('#' . $c)) . (!empty($m['username']) ? ' @' . h($m['username']) : '') . ($i === 0 ? ' — مدیر' : '');
    }
    $wait = count($s['access_req'] ?? []);
    return "👥 <b>اعضای نیک‌نگار</b>\n\n" . implode("\n", $lines)
        . "\n\nهمه‌ی اعضا همه‌چیز را می‌بینند و می‌توانند منتشر، رد یا تنظیمات را عوض کنند. اضافه و حذف اعضا فقط با مدیر است."
        . ($wait ? "\n⏳ درخواست در انتظار: " . fa_digits((string)$wait) : '')
        . "\nبرای اضافه کردن کسی، کافی است ربات را استارت کند؛ درخواستش این‌جا می‌آید.";
}
function members_buttons(array $s): array {
    $kb = [];
    foreach (array_slice($s['chat_ids'], 1) as $c) $kb[] = [['text' => '➖ حذف ' . member_name($s, $c), 'callback_data' => "k:$c"]];
    return $kb;
}

/* ---------------- welcome + catching up a new member ---------------- */
function welcome_text(array $s): string {
    $host = (string)parse_url(SITE, PHP_URL_HOST);
    $labels = array_map(fn($l) => L($l)['label'], langs());
    $langTxt = count($labels) > 1 ? implode(' و ', $labels) : $labels[0];
    $slots = array_map(fn($t) => fa_digits(substr($t, 0, 5)), (array)site('publish_slots', ['11:00', '20:00']));
    $per = fa_digits((string)site('posts_per_day', 2));
    return "👋 <b>به نیک‌نگار خوش آمدی</b>\n"
     . "میز تحریریه‌ی بلاگ <b>" . h($host) . "</b>\n\n"
     . "نیک‌نگار هر روز برای بلاگ " . h((string)site('site_name', $host)) . " مقاله می‌نویسد ($langTxt)، به نام " . h(L(lang1())['author']) . ". برایش عکس کاور پیدا می‌کند و پیش‌نویس را این‌جا می‌فرستد تا درباره‌اش تصمیم گرفته شود.\n\n"
     . "<b>🗓 هر روز چه اتفاقی می‌افتد</b>\n"
     . "• ساعت " . fa_digits((string)site('daily_write_time', '06:47')) . " صبح $per مقاله‌ی تازه از صف کلمات کلیدی نوشته می‌شود.\n"
     . "• چند دقیقه بعد پیش‌نویس‌ها با عکس کاور این‌جا می‌رسند.\n"
     . "• مقاله‌های تأییدشده در نوبت‌های حدود ساعت " . implode(' و ', $slots) . " منتشر می‌شوند. ساعت هر روز چند دقیقه جابه‌جا می‌شود تا انتشار طبیعی به نظر برسد.\n\n"
     . "<b>📝 زیر هر پیش‌نویس</b>\n"
     . "🚀 <b>انتشار همین حالا</b>: در چند ثانیه روی سایت می‌رود.\n"
     . "⏱ <b>در نوبت بعدی</b>: در صف می‌ماند و در نوبت بعد منتشر می‌شود.\n"
     . "❌ <b>رد</b>: پیش‌نمایش از سایت برداشته می‌شود.\n"
     . "🔄 <b>عکس دیگر</b>، زیر عکس کاور: یک عکس تازه پیدا می‌کند.\n"
     . "لینک‌های پیش‌نمایش را قبل از تصمیم باز کن. تا وقتی منتشر نشده‌اند، گوگل آن‌ها را نمی‌بیند.\n\n"
     . "<b>⌨️ دکمه‌های پایین صفحه</b>\n"
     . "✍️ <b>مقاله‌ی جدید</b>: یک مقاله‌ی اضافه سفارش می‌دهی و حداکثر حدود یک ساعت بعد پیش‌نویسش می‌رسد.\n"
     . "⚙️ <b>تنظیمات</b>: روشن و خاموش کردن «انتشار خودکار بدون بازبینی» و «توقف موقت».\n"
     . "/status: خلاصه‌ی وضعیت، صف‌ها و نوبت بعدی. /members: اعضا و دسترسی‌ها.\n\n"
     . "<b>🗂 پنل نیک‌نگار</b>\n"
     . "از دکمه‌ی «نیک‌نگار» کنار کادر پیام باز می‌شود: پیش‌نویس‌ها، صف کلمات کلیدی (افزودن، حذف، اول صف) و تنظیمات.\n\n"
     . "<b>💡 چند نکته</b>\n"
     . "• حالت فعلی: " . (auto_mode($s) ? '🤖 انتشار خودکار' : '👀 با بازبینی؛ هیچ مقاله‌ای بدون تأیید منتشر نمی‌شود') . "\n"
     . (count($s['chat_ids']) > 1 ? "• چند نفر با هم از این ربات استفاده می‌کنند؛ هر تصمیمی که یکی بگیرد برای همه ثبت می‌شود و اسمش زیر پیام می‌آید.\n" : '')
     . "• هر انتشار در sitemap ثبت می‌شود و به موتورهای جست‌وجو خبر داده می‌شود.\n"
     . "• اگر سه پیش‌نویس پشت سر هم خطا داشته باشند، نیک‌نگار خودش متوقف می‌شود و خبر می‌دهد.\n\n"
     . "<i>نیک‌نگار — طراحی و ساخت: دکتر محمدرضا نظیف</i>";
}
function send_welcome(array $s, int $cid): void {
    tg('setChatMenuButton', ['chat_id' => $cid, 'menu_button' => ['type' => 'web_app', 'text' => 'نیک‌نگار', 'web_app' => ['url' => panel_url()]]]);
    $r = tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'text' => welcome_text($s), 'disable_web_page_preview' => true,
        'reply_markup' => ['inline_keyboard' => [[['text' => '🗂 باز کردن پنل نیک‌نگار', 'web_app' => ['url' => panel_url()]]]]]]);
    if ($r) tg('pinChatMessage', ['chat_id' => $cid, 'message_id' => $r['result']['message_id'], 'disable_notification' => true]);
    tg('sendMessage', ['chat_id' => $cid, 'text' => (is_owner($s, $cid) ? '✅ ربات به نام تو ثبت شد؛ تو مدیر هستی.' : '✅ دسترسی کامل داری.') . ' دکمه‌ها پایین صفحه‌اند 👇', 'reply_markup' => main_kb2()]);
}
/** Sends the drafts that are still open to a new member, with working buttons that stay in sync with everyone else's. */
function resend_open_drafts(array &$s, int $cid): void {
    $n = 0;
    foreach ($s['drafts'] as $key => $d) {
        $st = $d['status'] ?? '';
        if (!in_array($st, ['pending', 'queued'], true)) continue;
        $a = json_decode((string)@file_get_contents(AB . "/drafts/$key.json"), true);
        if (!is_array($a)) continue;
        if ($n === 0) tg('sendMessage', ['chat_id' => $cid, 'text' => 'پیش‌نویس‌هایی که الان باز هستند 👇']);
        $r = tg('sendMessage', ['chat_id' => $cid, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            'text' => draft_msg($s, $a, $st === 'queued'), 'reply_markup' => ['inline_keyboard' => draft_buttons((string)$key, $st)]]);
        if ($r) $s['drafts'][$key]['msgs'][] = [$cid, $r['result']['message_id']];
        send_cover_preview($s, (string)$key, $a, [$cid]);
        $n++;
    }
}
