<?php
// Opening hours 09:00–22:30 and (for now) the Gmail address, on whichever NikMind site this host carries:
//   php fix-contact.php [apply]      backup to ~/geo-backup-contact-<time>/ first; nothing is deleted.
declare(strict_types=1);
$H = getenv('HOME');
$sites = [
    '/home/nikda2/domains/nikmindclinic.com/public_html' => [
        '"openingHours":"Mo-Su 09:00-17:00"' => '"openingHours":"Mo-Su 09:00-22:30"',
        '"email":"info@nikmindclinic.com"' => '"email":"nikmindclinic1@gmail.com"',
        'mailto:info@nikmindclinic.com' => 'mailto:nikmindclinic1@gmail.com',
        '>info@nikmindclinic.com<' => '>nikmindclinic1@gmail.com<',
    ],
    '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html' => [
        'هر روز از ساعت ۱۰ تا ۲۲ به وقت تهران' => 'هر روز از ساعت ۹ صبح تا ۲۲:۳۰ به وقت تهران',
        'every day from 10:00 to 22:00 Tehran time' => 'every day from 9:00 to 22:30 Tehran time',
        '"opens":"10:00","closes":"22:00"' => '"opens":"09:00","closes":"22:30"',
    ],
];
$APPLY = in_array('apply', $argv, true);
$BK = "$H/geo-backup-contact-" . date('Ymd-His');
$rep = [];
foreach ($sites as $PUB => $map) {
    if (!is_dir($PUB)) continue;
    $n = array_fill_keys(array_keys($map), 0); $files = 0;
    $dirs = [$PUB]; if (is_dir("$H/niknegar/templates")) $dirs[] = "$H/niknegar/templates"; if (is_dir("$H/autoblog/templates")) $dirs[] = "$H/autoblog/templates";
    foreach ($dirs as $D) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($D, FilesystemIterator::SKIP_DOTS)) as $f) {
        $p = $f->getPathname();
        if (!$f->isFile() || !preg_match('/\.(html?|txt)$/i', $p) || preg_match('#/(tg-[a-z0-9]+|wp-content|_data)/#', $p)) continue;
        $t = (string)file_get_contents($p); $o = $t;
        foreach ($map as $a => $b) if ($c = substr_count($t, $a)) { $t = str_replace($a, $b, $t); $n[$a] += $c; }
        if ($t === $o) continue;
        $files++;
        if ($APPLY) { $dst = $BK . str_replace($H, '', $p); @mkdir(dirname($dst), 0700, true); copy($p, $dst); file_put_contents("$p.tmp", $t); chmod("$p.tmp", fileperms($p) & 0777); rename("$p.tmp", $p); }
    }
    $rep[] = ($APPLY ? 'APPLIED' : 'DRY RUN') . " $PUB — files: $files";
    foreach ($n as $a => $c) $rep[] = "  $c × $a";
}
file_put_contents(__DIR__ . '/fix-contact-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n");
echo implode("\n", $rep), "\n";
