<?php
// On-demand writing: the Telegram button raises a counter in requests.json in the content repo; an hourly Claude task reads it and writes.
declare(strict_types=1);

/** The request count lives in requests.json in the content repo: the scheduled writer can always read GitHub, but usually not this website. */
function write_flag(array $s): bool {
    $n = (int)($s['write_pending'] ?? 0);
    $body = json_encode(['pending' => $n, 'updated' => date('c')]);
    if (!gh_can_write()) { logline('flag: no GitHub token'); return false; }
    for ($i = 0; $i < 3; $i++) {
        $cur = gh_api('GET', '/contents/requests.json?ref=main');
        $put = ['message' => "requests: pending=$n (Telegram button)", 'branch' => 'main', 'content' => base64_encode($body . "\n")];
        if ($cur['code'] === 200) $put['sha'] = (string)$cur['json']['sha'];
        $r = gh_api('PUT', '/contents/requests.json', $put);
        if ($r['code'] === 200 || $r['code'] === 201) return true;
        if (!in_array($r['code'], [409, 422], true)) break;
        usleep(700000);
    }
    logline('flag: GitHub write failed ' . ($r['code'] ?? 0));
    return false;
}
function main_kb(): array {
    return ['keyboard' => [[['text' => '✍️ مقاله‌ی جدید'], ['text' => '⚙️ تنظیمات']], [['text' => '/status']]], 'resize_keyboard' => true, 'is_persistent' => true];
}
/** When the hourly writer runs next (site.json "ondemand_check": minute, first and last hour). */
function next_check_text(): string {
    $c = (array)site('ondemand_check', ['minute' => 23, 'from' => 8, 'to' => 23]);
    $min = (int)($c['minute'] ?? 23); $from = (int)($c['from'] ?? 8); $to = (int)($c['to'] ?? 23);
    $t = time();
    $n = strtotime(date('Y-m-d H:', $t) . sprintf('%02d:00', $min));
    if ($n <= $t + 60) $n += 3600;
    $h = (int)date('G', $n);
    if ($h < $from) $n = strtotime(date('Y-m-d', $n) . sprintf(' %02d:%02d:00', $from, $min));
    if ($h > $to) $n = strtotime(date('Y-m-d', $n + 86400) . sprintf(' %02d:%02d:00', $from, $min));
    return fa_digits(date('H:i', $n));
}
function request_write(array &$s, $cid): void {
    $n = (int)($s['write_pending'] ?? 0);
    if ($n >= 3) { tg('sendMessage', ['chat_id' => $cid, 'text' => 'سه درخواست در صف هست؛ صبر کن تا نوشته شوند.', 'reply_markup' => main_kb()]); return; }
    $s['write_pending'] = $n + 1;
    if (!write_flag($s)) {
        $s['write_pending'] = $n;
        tg('sendMessage', ['chat_id' => $cid, 'text' => '⚠️ درخواست ثبت نشد (گیت‌هاب در دسترس نبود). چند دقیقه بعد دوباره بزن.', 'reply_markup' => main_kb()]);
        return;
    }
    tg('sendMessage', ['chat_id' => $cid, 'reply_markup' => main_kb(), 'text' =>
        "✍️ درخواست ثبت شد (" . fa_digits((string)($n + 1)) . " مقاله در صف نوشتن).\n"
        . 'نوشتن حدود ساعت ' . next_check_text() . ' شروع می‌شود و ۱۰ تا ۱۵ دقیقه طول می‌کشد؛ بعد پیش‌نویس با عکس این‌جا می‌آید.']);
    logline('write requested, pending=' . ($n + 1));
}
function done_request(array &$s): void {
    $s['write_pending'] = max(0, (int)($s['write_pending'] ?? 0) - 1);
    write_flag($s);
}
