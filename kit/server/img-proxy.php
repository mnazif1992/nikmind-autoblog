<?php
// Nik-Negar image proxy — put this on a server that can reach photo CDNs (Flickr, StockSnap…) and point
// the other site's site.json "image_proxy" at it: "https://this-host/path/img-proxy.php?k=SECRET&u=".
// Only images, only with the shared secret, at most 15 MB.
declare(strict_types=1);
const PROXY_KEY = 'CHANGE_ME';
$u = (string)($_GET['u'] ?? '');
if (PROXY_KEY === 'CHANGE_ME' || !hash_equals(PROXY_KEY, (string)($_GET['k'] ?? '')) || !preg_match('#^https://[^\s]+$#', $u)) { http_response_code(403); exit; }
$c = curl_init($u);
curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 50, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
    CURLOPT_USERAGENT => 'nik-negar-proxy', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
$b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($c, CURLINFO_CONTENT_TYPE); curl_close($c);
if ($code !== 200 || !is_string($b) || strlen($b) > 15 * 1024 * 1024 || !str_starts_with($type, 'image/')) { http_response_code(502); exit; }
header('Content-Type: ' . $type);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo $b;
