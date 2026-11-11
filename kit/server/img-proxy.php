<?php
// Nik-Negar image proxy — put this on a server that can reach photo CDNs and photo APIs (Pixabay, Openverse, Flickr…)
// and point the other site's site.json "image_proxy" at it: "https://this-host/path/img-proxy.php?k=SECRET&u=".
// Images from anywhere; JSON only from the photo search APIs below. Shared secret required, at most 15 MB.
declare(strict_types=1);
const PROXY_KEY = 'CHANGE_ME';
const API_HOSTS = ['pixabay.com', 'api.openverse.org'];
$u = (string)($_GET['u'] ?? '');
if (PROXY_KEY === 'CHANGE_ME' || !hash_equals(PROXY_KEY, (string)($_GET['k'] ?? '')) || !preg_match('#^https://[^\s]+$#', $u)) { http_response_code(403); exit; }
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
