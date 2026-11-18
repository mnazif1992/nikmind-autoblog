<?php
// Puts the per-route pages of iranian.nikmindclinic.com in place:  php apply-routes.php <commit-sha> [apply]
// Each new page was built from a snapshot of the live file. A file that changed on the server since that snapshot
// is left alone (reported), so nothing newer is overwritten. Backup first; nothing is deleted.
declare(strict_types=1);
$PUB = '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html';
$REPO = 'mnazif1992/nikmind-autoblog';
$REF = $argv[1] ?? '';
$APPLY = in_array('apply', $argv, true);
$LOCK = __DIR__ . '/apply-routes.lock';
if (!preg_match('/^[a-f0-9]{40}$/', $REF)) exit("usage: php apply-routes.php <commit-sha> [apply]\n");
if (is_file($LOCK) && filemtime($LOCK) > time() - 1800) exit("already running\n");
touch($LOCK);
$BK = getenv('HOME') . '/geo-backup-routes-' . date('Ymd-His');
function raw(string $repo, string $path, string $ref): ?string {
    $c = curl_init("https://api.github.com/repos/$repo/contents/" . str_replace('%2F', '/', rawurlencode($path)) . "?ref=$ref");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['User-Agent: nik-negar', 'Accept: application/vnd.github.raw']]);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return $code === 200 && is_string($b) ? $b : null;
}
$man = json_decode((string)raw($REPO, 'out/manifest.json', $REF), true);
if (!is_array($man)) { @unlink($LOCK); exit("manifest not readable\n"); }
$rep = []; $done = 0; $skipped = [];
foreach ($man as $rel => $m) {
    $p = "$PUB/$rel";
    if (!is_file($p)) { $skipped[] = "$rel (missing)"; continue; }
    if (hash_file('sha256', $p) !== $m['orig']) { $skipped[] = "$rel (changed on the server since the snapshot)"; continue; }
    $new = raw($REPO, "out/$rel", $REF);
    if ($new === null || hash('sha256', $new) !== $m['new']) { $skipped[] = "$rel (download failed)"; continue; }
    $done++;
    if ($APPLY) { $dst = "$BK/$rel"; @mkdir(dirname($dst), 0700, true); copy($p, $dst); file_put_contents("$p.tmp", $new); chmod("$p.tmp", fileperms($p) & 0777); rename("$p.tmp", $p); }
}
$rep[] = ($APPLY ? 'APPLIED' : 'DRY RUN') . " — pages: $done of " . count($man) . ($APPLY ? " (backup: $BK)" : '');
$rep[] = 'skipped (' . count($skipped) . '): ' . implode(', ', $skipped);
file_put_contents(__DIR__ . '/apply-routes-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n");
@unlink($LOCK);
echo implode("\n", $rep), "\n";
