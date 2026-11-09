<?php
// Messenger webhook (Telegram or Bale): button taps and messages arrive here instantly.
declare(strict_types=1);
require '__NN_AB__/core.php';   // install.php writes the real path here
$given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ($_GET['k'] ?? ''));   // Bale has no secret header, so the secret rides in the URL
if (HOOK_SECRET === '' || !hash_equals(HOOK_SECRET, $given)) { http_response_code(403); exit; }
$u = json_decode((string)file_get_contents('php://input'), true);
@set_time_limit(120);
ignore_user_abort(true);
// reply to Telegram right away, then do the work
ob_start(); echo '{}'; header('Content-Type: application/json'); header('Content-Length: ' . ob_get_length()); header('Connection: close');
ob_end_flush(); @flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
if (!is_array($u)) exit;
with_state(function (array &$s) use ($u) {
    if ((int)($u['update_id'] ?? 0) <= (int)($s['hook_last'] ?? 0)) return;   // Telegram retry of an update we already handled
    $s['hook_last'] = (int)$u['update_id'];
    handle_update($s, $u);
}, 90);
