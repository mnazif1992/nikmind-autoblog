<?php
// NikMind autoblog — cron entry (every minute).
//  - Telegram updates (only when the webhook is off; normally they arrive instantly via the webhook)
//  - every 5 min: new drafts from GitHub -> validate -> cover photo -> hidden previews -> Telegram
//  - approved / auto-mode articles go live at today's two jittered slots (~11:00 and ~20:00 Tehran)
declare(strict_types=1);
require __DIR__ . '/core.php';

with_state(function (array &$s) use ($argv) {
    poll_telegram($s);
    if (!empty($s['paused'])) return;
    if (time() - (int)$s['last_gh'] >= 5 * 60 || in_array('--now', $argv, true)) {
        $s['last_gh'] = time();
        check_drafts($s);
    }
    run_schedule($s);
}, 0);
