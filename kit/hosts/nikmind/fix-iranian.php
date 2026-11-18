<?php
// GEO fixes for iranian.nikmindclinic.com — run from ~/autoblog/state:  php fix-iranian.php [apply]
// Without "apply" it only reports. Every changed file is copied to ~/geo-backup-<time>/ first. Nothing is deleted.
declare(strict_types=1);
$PUB = '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html';
$APPLY = in_array('apply', $argv, true);
$BK = getenv('HOME') . '/geo-backup-' . date('Ymd-His');
$rep = [];

$exact = [
    'years-fa'   => ['۱۸ سال تجربه‌ی بالینی', '۱۲ سال تجربه‌ی بالینی'],
    'years-en'   => ['18 years of clinical experience', '12 years of clinical experience'],
    'ten-fa'     => ['در ده سال کار بالینی در تهران', 'در سال‌ها کار بالینی در تهران'],
    'ten-en'     => ['In ten years of clinical work in Tehran', 'Over years of clinical work in Tehran'],
    'teach-bio-fa' => [' و در دانشگاه‌های علوم پزشکی شهید بهشتی و ایران تدریس کرده‌ام.', '.'],
    'teach-bio-en' => [', and I have taught at Shahid Beheshti University of Medical Sciences and Iran University of Medical Sciences.', '.'],
    'teach-xp-fa'  => ['{"yr": "", "text": "تدریس در دانشگاه علوم پزشکی شهید بهشتی"}, {"yr": "", "text": "تدریس در دانشگاه علوم پزشکی ایران"}, ', ''],
    'teach-xp-en'  => ['{"yr":"","text":"Lecturer, Shahid Beheshti University of Medical Sciences"},{"yr":"","text":"Lecturer, Iran University of Medical Sciences"},', ''],
];
// old WordPress service links of nikmindclinic.com -> the new addresses (same table as that site's .htaccess)
$svc = ['1701' => '/services/immigration-psychology/', 'روانشناسی-مهاجرت' => '/services/immigration-psychology/', 'مشاوره-فردی' => '/services/individual-counseling/',
    'روانپزشکی' => '/services/psychiatry/', 'روانسنجی' => '/services/psychological-testing/', 'مشاوره-کودک' => '/services/child-counseling/',
    'مشاوره-نوجوان' => '/services/teen-counseling/', 'مشاوره-ازدواج' => '/services/couples-therapy/', 'مشاوره-خانواده-حضوری-و-آنلاین' => '/services/family-counseling/',
    'مشاوره-خانواده' => '/services/family-counseling/', 'سکس-تراپی' => '/services/sex-therapy/', 'مشاوره-تحصیلی' => '/services/academic-counseling/',
    'مشاوره-پیش-از-ازدواج' => '/services/premarital-counseling/', 'مهاجرت-روانشناسها' => '/services/psychologist-immigration/', 'مشاوره-آنلاین' => '/online-therapy/', '' => '/services/'];
$count = array_fill_keys(array_keys($exact), 0); $count['svc-links'] = 0; $unknown = []; $files = 0; $left = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !preg_match('/\.(html?|js|txt|xml)$/i', $f->getFilename()) || str_contains($f->getPathname(), '/tg-')) continue;
    $p = $f->getPathname(); $t = (string)file_get_contents($p); $o = $t;
    foreach ($exact as $k => [$a, $b]) { $n = substr_count($t, $a); if ($n) { $t = str_replace($a, $b, $t); $count[$k] += $n; } }
    $t = preg_replace_callback('#https://nikmindclinic\.com/nicemind_services/([^"\'\s<>]*?)/?(?=["\'\s<>])#u', function ($m) use ($svc, &$count, &$unknown) {
        $slug = rawurldecode($m[1]);
        if (!isset($svc[$slug])) { $unknown[$slug] = 1; return $m[0]; }
        $count['svc-links']++; return 'https://nikmindclinic.com' . $svc[$slug];
    }, $t);
    foreach (['/۱۸\s*سال/u', '/\b18\s*years?\b/i', '/تدریس در دانشگاه‌های علوم پزشکی شهید بهشتی/u'] as $re)
        if (preg_match_all($re, $t, $mm, PREG_OFFSET_CAPTURE)) foreach ($mm[0] as $x) $left[] = substr($p, strlen($PUB)) . ': …' . preg_replace('/\s+/', ' ', mb_strcut($t, max(0, $x[1] - 120), 260)) . '…';
    if ($t !== $o) {
        $files++;
        if ($APPLY) { $dst = $BK . substr($p, strlen($PUB)); @mkdir(dirname($dst), 0700, true); copy($p, $dst); file_put_contents("$p.tmp", $t); chmod("$p.tmp", fileperms($p) & 0777); rename("$p.tmp", $p); }
    }
}
$rep[] = ($APPLY ? 'APPLIED' : 'DRY RUN') . " — files to change: $files" . ($APPLY ? " (backup: $BK)" : '');
foreach ($count as $k => $n) $rep[] = "  $k: $n";
$rep[] = 'unknown service slugs: ' . implode(', ', array_keys($unknown));
$rep[] = 'left to look at (' . count($left) . '):';
foreach (array_slice(array_unique($left), 0, 40) as $l) $rep[] = '  ' . $l;
file_put_contents(__DIR__ . '/fix-iranian-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n");
echo implode("\n", $rep), "\n";
