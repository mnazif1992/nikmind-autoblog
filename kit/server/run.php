<?php
// Nik-Negar kit — cron entry, every minute:
//  - Telegram updates (only when the webhook is off; normally they arrive instantly via the webhook)
//    Bale: polled every 3 s for ~50 s of each minute (Bale's webhook does not reach most hosts)
//  - every 5 min: new drafts from the content repo -> validate -> cover photo -> hidden previews -> Telegram
//  - approved / auto-mode articles go live at today's jittered slots
//  - once a day (around 04:00): self-update from the kit repo, if "auto_update" is on in site.json
//  - once a day (site/reviews.json in the content repo): Google reviews count and rating on the site (reviews.php)
declare(strict_types=1);
require __DIR__ . '/core.php';
require_once __DIR__ . '/reviews.php';
$START = time();

with_state(function (array &$s) use ($argv) {
    poll_telegram($s);
    if (site('auto_update', true) && (int)date('G') === 4 && ($s['last_update'] ?? '') !== date('Y-m-d')) {
        $s['last_update'] = date('Y-m-d');
        require_once __DIR__ . '/update.php';
        $r = kit_update();
        if ($r['changed']) { logline('kit updated: ' . implode(', ', $r['changed'])); tg_all($s, '🔧 نیک‌نگار به‌روز شد (' . h(implode('، ', $r['changed'])) . ').'); }
        if ($r['errors']) logline('kit update problems: ' . implode(' | ', $r['errors']));
    }
    if (($s['proxy_version'] ?? '') !== KIT_VERSION) {           // hand-made image proxies on this host get the new code (secret kept)
        $s['proxy_version'] = KIT_VERSION;
        require_once __DIR__ . '/update.php';
        foreach (refresh_proxies() as $f) logline("image proxy updated: $f");
    }
    // once per new version: tell the team what changed (installs that never had an owner have nobody to tell)
    if ($s['chat_ids'] && ($s['seen_version'] ?? '') !== KIT_VERSION) {
        $was = (string)($s['seen_version'] ?? '');
        $s['seen_version'] = KIT_VERSION;
        if ($was !== '' || $s['published'] || $s['drafts']) {
            if (version_compare($was ?: '1.2.0', '1.3.0', '<')) tg_all($s, "✨ <b>تازه در نیک‌نگار: هر مقاله، نویسنده‌ی خودش</b>\n\n"
                . 'مقاله‌ها مثل قبل به نام <b>' . h(byline_name(lang1(), byline_default())) . '</b> می‌آیند. ولی هر مقاله‌ای که نخواهی پای اسم او باشد، '
                . 'با یک دکمه می‌رود به نام <b>' . h(team_name(lang1())) . "</b> یا هر نویسنده‌ی دیگری که اضافه کنی.\n\n"
                . "• زیر هر پیش‌نویس دکمه‌ی «✍️ نویسنده» هست. مقاله‌های منتشرشده هم دارند و صفحه‌شان روی سایت همان لحظه عوض می‌شود.\n"
                . "• در پنل، روی کارت هر مقاله نویسنده‌اش را انتخاب کن.\n"
                . "• نویسنده‌ی تازه، اسم تحریریه و یک خط معرفی هر نفر: پنل ← تنظیمات ← نویسنده‌ها (یا /authors).",
                [[['text' => '✍️ نویسنده‌ها', 'web_app' => ['url' => panel_url() . '?authors=1']]]]);
            if (version_compare($was ?: '1.2.0', '1.3.0', '<')) tg_all($s, "🖼 <b>تازه در نیک‌نگار: عکس کاور بهتر</b>\n\n"
                . "• <b>انتخاب از چند عکس:</b> زیر عکس هر پیش‌نویس دکمه‌ی «🖼 انتخاب از چند عکس» هست. پنل حدود ۱۲ عکس مرتبط نشان می‌دهد؛ با کلمه‌های خودت هم می‌شود گشت. روی هر عکس بزنی، همان کاور می‌شود (حتی برای مقاله‌ی منتشرشده).\n"
                . "• <b>عکس‌های آماده‌ی بهتر:</b> عکس‌های ژست‌گرفته و تکراری عقب می‌روند و عکس‌های طبیعی‌تر جلو می‌آیند.\n"
                . (ai_enabled() ? "• <b>عکس مخصوص هر مقاله:</b> کاورها را از این به بعد هوش مصنوعی برای همان مقاله می‌سازد؛ در پنل هم می‌شود صحنه را تغییر داد و دوباره ساخت."
                    : "• <b>عکس مخصوص هر مقاله با هوش مصنوعی:</b> آماده است و با یک کلید رایگان Cloudflare روشن می‌شود (مالک ربات: /aikey)."));
        }
    }
    sync_homes($s);
    try { reviews_tick($s); } catch (Throwable $e) { logline('google reviews error: ' . $e->getMessage()); }
    if (!empty($s['paused'])) return;
    if (time() - (int)$s['last_gh'] >= 5 * 60 || in_array('--now', $argv ?? [], true)) {
        $s['last_gh'] = time();
        check_drafts($s);
    }
    run_schedule($s);
}, 0);

// Bale has no working webhook for us, so for the rest of this minute keep asking for new messages every few seconds
// (the state lock is held only for each short poll, so the panel and the webhook-free bot stay responsive).
if (messenger() === 'bale' && empty(state_load()['webhook'])) {
    $until = $START + 50;
    while (time() < $until) {
        sleep(3);
        with_state(function (array &$s) { poll_telegram($s); }, 10);
    }
}
