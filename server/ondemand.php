<?php
// On-demand writing: a Telegram button raises a public flag file; an hourly Claude task reads it and writes the article.
declare(strict_types=1);

function req_file(): string { return PUB . '/' . REQ_KEY . '.json'; }
/** The request count lives in requests.json in the GitHub repo (the scheduled writer can always read GitHub; it cannot read this website). */
function write_flag(array $s): bool {
    $n = (int)($s['write_pending'] ?? 0);
    $body = json_encode(['pending' => $n, 'updated' => date('c')]);
    file_put_contents(req_file(), $body);
    @chmod(req_file(), 0644);
    require_once __DIR__ . '/topics.php';
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
function next_check_text(): string {
    $t = time();
    $c = strtotime(date('Y-m-d H:23:00', $t));
    if ($c <= $t + 60) $c += 3600;
    $h = (int)date('G', $c);
    if ($h < 8) $c = strtotime(date('Y-m-d', $c) . ' 08:23:00');
    return fa_digits(date('H:i', $c));
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
