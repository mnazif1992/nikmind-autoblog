<?php
// GEO fixes for nikmindclinic.com:  php geo-domestic.php [apply]
//  1. Dr. Nikdanesh's profile: the university-teaching lines go (he does not want them listed)
//  2. old articles bylined "تیم تحریریه نیک‌مایند" go under Dr. Meysam Nikdanesh (byline, author box, schema Person)
//  3. dateModified earlier than datePublished: modified = published (schema, meta, visible "updated" date)
//  4. the clinic schema gets its Google Maps profile in sameAs
//  5. /llms.txt built from the site's own pages
// Without "apply" it only reports. Files are copied to ~/geo-backup-<time>/ before they change. Nothing is deleted.
declare(strict_types=1);
require __DIR__ . '/llms-lib.php';
$PUB = '/home/nikda2/domains/nikmindclinic.com/public_html';
$SITE = 'https://nikmindclinic.com';
$APPLY = in_array('apply', $argv, true);
$BK = getenv('HOME') . '/geo-backup-' . date('Ymd-His');
$NAME = 'دکتر میثم نیک‌دانش'; $PURL = '/therapists/meysam-nikdanesh/';
$OLD = 'تیم تحریریه نیک‌مایند';
$c = ['teach' => 0, 'author-schema' => 0, 'author-byline' => 0, 'author-box' => 0, 'dates' => 0, 'sameAs' => 0];
$files = 0; $left = []; $rep = [];
$photo = '';   // his photo, from his own profile's Person schema
if (preg_match('#"@type":"Person","name":"' . preg_quote($NAME, '#') . '".*?"image":"([^"]+)"#u', (string)@file_get_contents($PUB . $PURL . 'index.html'), $m)) $photo = (string)parse_url($m[1], PHP_URL_PATH);

// articles Nik-Negar publishes keep the byline chosen in its panel (it may be the editorial team on purpose)
$nn = [];
foreach ((array)json_decode((string)@file_get_contents(getenv('HOME') . '/niknegar/state/posts-fa.json'), true) as $x) if (!empty($x['slug'])) $nn['/blog/' . $x['slug'] . '/index.html'] = 1;
foreach (glob(getenv('HOME') . '/niknegar/drafts/*.json') ?: [] as $df) { $x = json_decode((string)file_get_contents($df), true); if (!empty($x['slug'])) $nn['/blog/' . $x['slug'] . '/index.html'] = 1; }
$rep[] = 'Nik-Negar articles left alone: ' . count($nn);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!$f->isFile() || !str_ends_with($p, '.html') || preg_match('#/(tg-[a-z0-9]+|wp-content|_data)/#', $p)) continue;
    $t = (string)file_get_contents($p); $o = $t;

    // 1. teaching lines (only Dr. Nikdanesh's own wording)
    foreach ([' و در دانشگاه‌های علوم پزشکی شهید بهشتی و ایران تدریس کرده‌ام.' => '.',
              '<div class="xp-item"><p>تدریس در دانشگاه علوم پزشکی شهید بهشتی</p></div><div class="xp-item"><p>تدریس در دانشگاه علوم پزشکی ایران</p></div>' => ''] as $a => $b)
        if ($n = substr_count($t, $a)) { $t = str_replace($a, $b, $t); $c['teach'] += $n; }

    // 2. byline of the old articles
    if (!isset($nn[substr($p, strlen($PUB))]) && str_contains($t, '"author":{"@type":"Organization","name":"' . $OLD . '"')) {
        $t = preg_replace('#"author":\{"@type":"Organization","name":"' . $OLD . '","url":"[^"]*"\}#u',
            '"author":{"@type":"Person","name":"' . $NAME . '","url":"' . $SITE . $PURL . '","jobTitle":"دکترای روانشناسی بالینی، مؤسس و مدیر فنی کلینیک نیک‌مایند"}', $t, -1, $n);
        $c['author-schema'] += $n;
        $t = preg_replace('#(<use href="\#i-user"/></svg>)' . $OLD . '(</span>)#u', '$1<a href="' . $PURL . '">' . $NAME . '</a>$2', $t, -1, $n); $c['author-byline'] += $n;
        $t = preg_replace('#<p class="who">' . $OLD . '</p>#u', '<p class="who"><a href="' . $PURL . '">' . $NAME . '</a></p>', $t, -1, $n); $c['author-box'] += $n;
        if ($n && $photo !== '') $t = preg_replace('#(<div class="byline">\s*<img src=")[^"]*(")#u', '${1}' . $photo . '$2', $t, 1);
    }

    // 3. modified before published
    if (preg_match('#"datePublished":"([^"]+)","dateModified":"([^"]+)"#', $t, $d) && strtotime($d[2]) < strtotime($d[1])) {
        $t = str_replace($d[0], '"datePublished":"' . $d[1] . '","dateModified":"' . $d[1] . '"', $t);
        $t = preg_replace('#(<meta property="article:modified_time" content=")[^"]*(")#', '${1}' . $d[1] . '$2', $t);
        // visible dates: the "updated" one takes the published one's text
        if (preg_match('#<use href="\#i-calendar-check"/></svg><time datetime="([^"]+)">([^<]*)</time>#u', $t, $pub))
            $t = preg_replace('#(<use href="\#i-refresh"/></svg>[^<]*<time datetime=")[^"]*(">)[^<]*(</time>)#u', '${1}' . $pub[1] . '${2}' . $pub[2] . '$3', $t);
        $c['dates']++;
    }

    // 4. Google Maps in the clinic's sameAs
    $t = preg_replace_callback('#("@id":"https://nikmindclinic\.com/\#organization".*?"sameAs":\[)([^\]]*)\]#u', function ($m) use (&$c) {
        if (str_contains($m[2], 'maps.google.com/?cid=')) return $m[0];
        $c['sameAs']++; return $m[1] . $m[2] . ',"https://maps.google.com/?cid=11891624365515058342"]';
    }, $t, 1);

    if (str_contains($t, $OLD) && !isset($nn[substr($p, strlen($PUB))])) $left[] = substr($p, strlen($PUB));
    if ($t !== $o) {
        $files++;
        if ($APPLY) { $dst = $BK . substr($p, strlen($PUB)); @mkdir(dirname($dst), 0700, true); copy($p, $dst); file_put_contents("$p.tmp", $t); chmod("$p.tmp", fileperms($p) & 0777); rename("$p.tmp", $p); }
    }
}
$rep[] = ($APPLY ? 'APPLIED' : 'DRY RUN') . " — files changed: $files" . ($APPLY ? " (backup: $BK)" : '') . " · photo: $photo";
foreach ($c as $k => $n) $rep[] = "  $k: $n";
$rep[] = 'still mentioning «' . $OLD . '» (' . count($left) . '): ' . implode(' ', array_slice($left, 0, 30));

// 5. llms.txt
$home = (string)@file_get_contents("$PUB/index.html");
$desc = preg_match('#<meta name="description" content="([^"]*)"#u', $home, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
$intro = "# کلینیک روانشناسی نیک‌مایند (NikMind Clinic)\n\n> " . ($desc ?: 'کلینیک تخصصی روانشناسی، روانپزشکی و مشاوره در شهرک اکباتان تهران، با جلسه‌های حضوری و آنلاین.') . "\n\n"
    . "- کلینیک تخصصی روانشناسی، روانپزشکی و مشاوره؛ جلسه‌ی حضوری در شهرک اکباتان (فاز ۲، بلوک ۸، تهران) و جلسه‌ی آنلاین.\n"
    . "- مؤسس و مدیر فنی: دکتر میثم نیک‌دانش، دکترای روانشناسی بالینی از انستیتو روانپزشکی تهران (دانشگاه علوم پزشکی ایران).\n"
    . "- تلفن: ۰۲۱۸۲۸۰۸۰۵۵ · نقشه و نظرهای گوگل: https://maps.google.com/?cid=11891624365515058342\n"
    . "- برای ایرانیان خارج از کشور: https://iranian.nikmindclinic.com/ (فارسی) و https://iranian.nikmindclinic.com/en/ (English)\n";
$llms = llms_build($PUB, $SITE, ['/sitemap.xml', '/blog/sitemap-blog.xml'], $intro, [
    '#^/$#' => 'صفحه‌های اصلی', '#^/(about|online-therapy|reserve|book|contact|rules|complaints|careers|kids-landing|marriage|podcast)/$#' => 'صفحه‌های اصلی',
    '#^/services/#' => 'خدمات', '#^/therapists/#' => 'درمانگران', '#^/blog/category/#' => 'دسته‌های مقاله', '#^/blog/#' => 'مقاله‌ها', '#.*#' => 'صفحه‌های دیگر'], 160);
$rep[] = 'llms.txt: ' . substr_count($llms, "\n- [") . ' links';
if ($APPLY && (string)@file_get_contents("$PUB/llms.txt") !== $llms) { file_put_contents("$PUB/llms.txt.tmp", $llms); chmod("$PUB/llms.txt.tmp", 0644); rename("$PUB/llms.txt.tmp", "$PUB/llms.txt"); }

file_put_contents(__DIR__ . '/geo-domestic-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n\n----- llms.txt preview -----\n" . substr($llms, 0, 5000));
echo implode("\n", $rep), "\n";
