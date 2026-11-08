<?php
// On-demand writing: a Telegram button raises a public flag file; an hourly Claude task reads it and writes the article.
declare(strict_types=1);

function req_file(): string { return PUB . '/' . REQ_KEY . '.json'; }
function write_flag(array $s): void {
    file_put_contents(req_file(), json_encode(['pending' => (int)($s['write_pending'] ?? 0), 'updated' => date('c')]));
    @chmod(req_file(), 0644);
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
    write_flag($s);
    tg('sendMessage', ['chat_id' => $cid, 'reply_markup' => main_kb(), 'text' =>
        "✍️ درخواست ثبت شد (" . fa_digits((string)($n + 1)) . " مقاله در صف نوشتن).\n"
        . 'نوشتن حدود ساعت ' . next_check_text() . ' شروع می‌شود و ۱۰ تا ۱۵ دقیقه طول می‌کشد؛ بعد پیش‌نویس با عکس این‌جا می‌آید.']);
    logline('write requested, pending=' . ($n + 1));
}
function done_request(array &$s): void {
    $s['write_pending'] = max(0, (int)($s['write_pending'] ?? 0) - 1);
    write_flag($s);
}
