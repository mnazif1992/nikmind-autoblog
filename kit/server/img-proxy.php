<?php
// Nik-Negar image proxy — put this on a server that can reach photo CDNs and photo APIs (Pixabay, Openverse, Flickr…)
// and point the other site's site.json "image_proxy" at it: "https://this-host/path/img-proxy.php?k=SECRET&u=".
// Images from anywhere; JSON only from the photo search APIs below; AI images from Cloudflare (see below). Shared secret required, at most 15 MB.
declare(strict_types=1);
const PROXY_KEY = 'CHANGE_ME';
const API_HOSTS = ['pixabay.com', 'api.openverse.org'];
$u = (string)($_GET['u'] ?? '');
if (PROXY_KEY === 'CHANGE_ME' || !hash_equals(PROXY_KEY, (string)($_GET['k'] ?? '')) || !preg_match('#^https://[^\s]+$#', $u)) { http_response_code(403); exit; }
// AI images (Nik-Negar 1.3+): POST with a JSON body, forwarded as the multipart form Cloudflare Workers AI expects.
// Only the FLUX.2 [klein] models; the token comes in X-NN-Auth and is passed through, never stored.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_SERVER['HTTP_X_NN_AI'] ?? '') === '1') {
    if (!preg_match('#^https://api\.cloudflare\.com/client/v4/accounts/[a-f0-9]{32}/ai/run/@cf/black-forest-labs/flux-2-klein-(4b|9b)$#', $u)) { http_response_code(403); exit; }
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in) || !is_string($in['prompt'] ?? null)) { http_response_code(400); exit; }
    $form = ['prompt' => mb_substr($in['prompt'], 0, 2000)];
    foreach (['width', 'height', 'seed'] as $f) if (isset($in[$f])) $form[$f] = (string)(int)$in[$f];
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 95, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form,
        CURLOPT_HTTPHEADER => ['Authorization: ' . (string)($_SERVER['HTTP_X_NN_AUTH'] ?? '')], CURLOPT_USERAGENT => 'nik-negar-proxy']);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE); curl_close($c);
    http_response_code($code ?: 502);
    header('Content-Type: ' . ($type ?: 'application/json'));
    header('Cache-Control: no-store');
    echo is_string($b) ? $b : '';
    exit;
}
$host = strtolower((string)parse_url($u, PHP_URL_HOST));
$api = in_array($host, API_HOSTS, true);
$h = [];
if ($api && ($_SERVER['HTTP_X_NN_AUTH'] ?? '') !== '') $h[] = 'Authorization: ' . $_SERVER['HTTP_X_NN_AUTH'];   // the photo API key, passed through, never stored
$c = curl_init($u);
curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 50, CURLOPT_FOLLOWLOCATION => !$api, CURLOPT_MAXREDIRS => 4, CURLOPT_HTTPHEADER => $h,
    CURLOPT_USERAGENT => 'nik-negar-proxy', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
$b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE); curl_close($c);
$okType = str_starts_with($type, 'image/') || ($api && str_starts_with($type, 'application/json'));
if (!is_string($b) || strlen($b) > 15 * 1024 * 1024 || !$okType || ($code !== 200 && !$api)) { http_response_code(502); exit; }
http_response_code($code);
header('Content-Type: ' . $type);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $b;
