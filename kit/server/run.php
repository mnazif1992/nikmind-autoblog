<?php
// Nik-Negar kit — cron entry, every minute:
//  - Telegram updates (only when the webhook is off; normally they arrive instantly via the webhook)
//  - every 5 min: new drafts from the content repo -> validate -> cover photo -> hidden previews -> Telegram
//  - approved / auto-mode articles go live at today's jittered slots
//  - once a day (around 04:00): self-update from the kit repo, if "auto_update" is on in site.json
declare(strict_types=1);
require __DIR__ . '/core.php';

with_state(function (array &$s) use ($argv) {
    poll_telegram($s);
    if (site('auto_update', true) && (int)date('G') === 4 && ($s['last_update'] ?? '') !== date('Y-m-d')) {
        $s['last_update'] = date('Y-m-d');
        require_once __DIR__ . '/update.php';
        $r = kit_update();
        if ($r['changed']) { logline('kit updated: ' . implode(', ', $r['changed'])); tg_all($s, '🔧 نیک‌نگار به‌روز شد (' . h(implode('، ', $r['changed'])) . ').'); }
        if ($r['errors']) logline('kit update problems: ' . implode(' | ', $r['errors']));
    }
    if (!empty($s['paused'])) return;
    if (time() - (int)$s['last_gh'] >= 5 * 60 || in_array('--now', $argv ?? [], true)) {
        $s['last_gh'] = time();
        check_drafts($s);
    }
    run_schedule($s);
}, 0);
