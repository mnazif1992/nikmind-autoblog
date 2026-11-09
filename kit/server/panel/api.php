<?php
// Panel backend. Every request is checked: Telegram mini app initData, or a signed panel link sent by the bot.
declare(strict_types=1);
require '__NN_AB__/core.php';   // install.php writes the real path here
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function out(array $x, int $code = 200): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$uid = 0; $user = [];
$init = (string)($_SERVER['HTTP_X_INIT_DATA'] ?? '');
if ($init !== '') {                                   // opened as a Telegram mini app: verify the signed initData
    $pairs = [];
    foreach (explode('&', $init) as $kv) { if ($kv === '') continue; [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $pairs[rawurldecode($k)] = rawurldecode($v); }
    $hash = $pairs['hash'] ?? '';
    unset($pairs['hash']);
    ksort($pairs);
    $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($pairs), $pairs));
    $secret = hash_hmac('sha256', TG_BOT_TOKEN, 'WebAppData', true);
    if ($hash === '' || !hash_equals(hash_hmac('sha256', $check, $secret), $hash)) out(['ok' => false, 'error' => 'دسترسی نداری'], 401);
    if (time() - (int)($pairs['auth_date'] ?? 0) > 86400) out(['ok' => false, 'error' => 'نشست منقضی شده؛ پنل را دوباره باز کن'], 401);
    $user = json_decode($pairs['user'] ?? '{}', true) ?: [];
    $uid = (int)($user['id'] ?? 0);
} else {                                              // opened from a signed link the bot sent (Bale, or any browser)
    $uid = panel_token_uid((string)($_SERVER['HTTP_X_PANEL_TOKEN'] ?? ''));
    if (!$uid) out(['ok' => false, 'error' => 'لینک پنل منقضی شده؛ از ربات لینک تازه بگیر (/panel)'], 401);
    $user = ['id' => $uid, 'first_name' => member_name(state_load(), $uid)];
}
if (!in_array($uid, state_load()['chat_ids'], true)) out(['ok' => false, 'error' => 'دسترسی نداری'], 403);

$req = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($req['action'] ?? 'state');
$key = preg_replace('/[^a-f0-9]/', '', (string)($req['key'] ?? ''));
@set_time_limit(120);
ignore_user_abort(true);
set_actor($user);

if (str_starts_with($action, 'topic')) {                       // keyword queue (topics.json in the GitHub repo)
    require_once '__NN_AB__/topics.php';
    try {
        $t = null;
        if ($action === 'topic_add') $t = topic_add($req);
        elseif ($action === 'topic_del') $t = topic_remove((string)($req['id'] ?? ''));
        elseif ($action === 'topic_top') $t = topic_top((string)($req['id'] ?? ''));
        out(['ok' => true, 'topics' => topics_view($t)]);
    } catch (Throwable $e) { out(['ok' => false, 'error' => $e->getMessage()], 400); }
}

$res = with_state(function (array &$s) use ($action, $key, $req, $uid) {
    switch ($action) {
        case 'state': break;
        case 'approve': approve_key($s, $key); break;
        case 'publish': publish_key($s, $key); break;
        case 'reject': reject_key($s, $key); break;
        case 'cover': if (in_array($s['drafts'][$key]['status'] ?? '', ['pending', 'queued'], true)) swap_cover($s, $key); break;
        case 'mode':
            $was = auto_mode($s); set_mode($s, !empty($req['auto']));
            if ($was !== auto_mode($s)) notify_others($s, $uid, settings_change_text($s, 'm'));
            break;
        case 'pause':
            $was = !empty($s['paused']); $s['paused'] = !empty($req['paused']); if (!$s['paused']) $s['fails'] = 0;
            if ($was !== $s['paused']) notify_others($s, $uid, settings_change_text($s, 's'));
            break;
        case 'write': request_write($s, $uid); break;
        default: return ['ok' => false, 'error' => 'unknown action'];
    }
    return ['ok' => true, 'state' => panel_state($s)];
}, 60);
out($res ?? ['ok' => false, 'error' => 'سرور مشغول است؛ چند ثانیه بعد دوباره امتحان کن'], $res ? 200 : 503);
