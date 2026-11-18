<?php
// GEO fixes, part 2, for iranian.nikmindclinic.com:  php geo-iranian-2.php [apply]
//  1. JSON-LD (MedicalClinic + WebSite + FAQPage from the page's own FAQ) on the main pages that have none
//  2. /llms.txt built from the site's own pages
//  3. a real 404 page (404.html + ErrorDocument)
//  4. the Nik-Negar article templates get the same text/link fixes as the pages (fix-iranian.php)
// Without "apply" it only reports. Files are copied to ~/geo-backup-2-<time>/ before they change. Nothing is deleted.
declare(strict_types=1);
require __DIR__ . '/llms-lib.php';
$PUB = '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html';
$SITE = 'https://iranian.nikmindclinic.com';
$APPLY = in_array('apply', $argv, true);
$BK = getenv('HOME') . '/geo-backup-2-' . date('Ymd-His');
$rep = [];
$save = function (string $p, string $c) use ($APPLY, $BK, &$rep) {
    if (is_file($p) && (string)file_get_contents($p) === $c) return;
    $rep[] = '  write ' . str_replace(getenv('HOME'), '~', $p) . ' (' . strlen($c) . ' bytes)';
    if (!$APPLY) return;
    if (is_file($p)) { $dst = $BK . str_replace(getenv('HOME'), '', $p); @mkdir(dirname($dst), 0700, true); copy($p, $dst); }
    file_put_contents("$p.tmp", $c); @chmod("$p.tmp", 0644); rename("$p.tmp", $p);
};
$J = fn($x) => json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$rv = json_decode((string)@file_get_contents("$PUB/reviews.json"), true) ?: ['rating' => 4.9, 'count' => 152];

/* ---------- 1. JSON-LD on the main pages ---------- */
$clinic = function (string $lang) use ($SITE, $rv) {
    $fa = $lang === 'fa';
    return ['@context' => 'https://schema.org', '@type' => ['MedicalClinic', 'Organization'], '@id' => "$SITE/#clinic",
        'name' => $fa ? 'کلینیک روانشناسی نیک‌مایند' : 'NikMind Clinic',
        'alternateName' => $fa ? ['نیک‌مایند', 'NikMind Clinic'] : ['NikMind', 'کلینیک روانشناسی نیک‌مایند'],
        'description' => $fa ? 'مشاوره و روان‌درمانی آنلاین به زبان فارسی برای ایرانیان خارج از کشور، با درمانگران فارسی‌زبان کلینیک نیک‌مایند در تهران.'
                             : 'Online therapy in Farsi for Iranians living abroad, with the Farsi speaking therapists of NikMind Clinic in Tehran.',
        'url' => $fa ? "$SITE/" : "$SITE/en/", 'logo' => "$SITE/assets/12a7b8f4-3b62-48d5-9eb2-6fae242db22f.png",
        'image' => "$SITE/assets/6cf831b8-85a5-4f42-bc6a-33a81c97a96f.webp",
        'telephone' => '+98-21-82808055',
        'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => '+98-901-723-5974', 'url' => 'https://wa.me/989017235974',
                           'availableLanguage' => ['fa', 'en'], 'hoursAvailable' => ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 'opens' => '10:00', 'closes' => '22:00']],
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $fa ? 'شهرک اکباتان، فاز ۲، بلوک ۸، انتهای ورودی ۳' : 'Ekbatan Town, Phase 2, Block 8, end of entrance 3', 'addressLocality' => $fa ? 'تهران' : 'Tehran', 'addressCountry' => 'IR'],
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 35.7116153, 'longitude' => 51.3042869],
        'hasMap' => 'https://maps.google.com/?cid=11891624365515058342',
        'areaServed' => $fa ? 'سراسر جهان (جلسه‌ی آنلاین)' : 'Worldwide (online sessions)',
        'availableLanguage' => ['fa', 'en'], 'medicalSpecialty' => 'Psychiatric',
        'founder' => ['@type' => 'Person', 'name' => $fa ? 'دکتر میثم نیک‌دانش' : 'Dr. Meysam Nikdanesh', 'url' => $fa ? "$SITE/therapists/meysam-nikdanesh/" : "$SITE/en/therapists/meysam-nikdanesh/",
                      'jobTitle' => $fa ? 'دکترای روانشناسی بالینی، مؤسس و مدیر فنی نیک‌مایند' : 'PhD in Clinical Psychology, founder and clinical director of NikMind'],
        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => number_format((float)$rv['rating'], 1, '.', ''), 'reviewCount' => (string)(int)$rv['count'], 'bestRating' => '5'],
        'sameAs' => ['https://nikmindclinic.com/', 'https://maps.google.com/?cid=11891624365515058342', 'https://instagram.com/nikmindclinic', 'https://t.me/nikmindclinic']];
};
$faq = function (string $h): ?array {
    $i = strpos($h, 'id="faqList"'); if ($i === false) return null;
    $part = substr($h, $i, 200000);
    preg_match_all('#<div class="faq-item"[^>]*>\s*<button class="faq-q"[^>]*>\s*<span class="lead">(?:<svg.*?</svg>)?(.*?)</span>.*?<div class="faq-a-inner">(.*?)</div>#su', $part, $m, PREG_SET_ORDER);
    $q = [];
    foreach ($m as $x) {
        $n = trim(html_entity_decode(strip_tags($x[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')); $a = trim(html_entity_decode(strip_tags($x[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($n !== '' && $a !== '') $q[] = ['@type' => 'Question', 'name' => preg_replace('/\s+/u', ' ', $n), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => preg_replace('/\s+/u', ' ', $a)]];
    }
    return $q ? ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q] : null;
};
$pages = ['/index.html' => ['fa', true], '/en/index.html' => ['en', true], '/about/index.html' => ['fa', false], '/en/about/index.html' => ['en', false],
          '/therapists/index.html' => ['fa', false], '/en/therapists/index.html' => ['en', false]];
$rep[] = '1. JSON-LD';
foreach ($pages as $rel => [$lang, $home]) {
    $p = $PUB . $rel; $h = (string)@file_get_contents($p);
    if ($h === '') { $rep[] = "  missing $rel"; continue; }
    if (str_contains($h, "\"@id\":\"$SITE/#clinic\"")) { $rep[] = "  $rel already has it"; continue; }
    $blocks = [$clinic($lang)];
    if ($home) {
        $blocks[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => "$SITE/#website-$lang", 'url' => $lang === 'fa' ? "$SITE/" : "$SITE/en/",
                     'name' => $lang === 'fa' ? 'نیک‌مایند — مشاوره آنلاین برای ایرانیان خارج از کشور' : 'NikMind — therapy in Farsi for Iranians abroad', 'inLanguage' => $lang, 'publisher' => ['@id' => "$SITE/#clinic"]];
        if ($f = $faq($h)) { $blocks[] = $f; $rep[] = "  $rel FAQ: " . count($f['mainEntity']) . ' questions'; }
    }
    $ld = ''; foreach ($blocks as $b) $ld .= '<script type="application/ld+json">' . $J($b) . "</script>\n";
    $k = stripos($h, '</head>'); if ($k === false) { $rep[] = "  no </head> in $rel"; continue; }
    $save($p, substr($h, 0, $k) . $ld . substr($h, $k));
}

/* ---------- 2. llms.txt ---------- */
$intro = "# NikMind Clinic (کلینیک روانشناسی نیک‌مایند) — therapy in Farsi for Iranians abroad\n\n"
    . "> NikMind is a psychology, psychiatry and counseling clinic in Tehran (Ekbatan). This site is its online branch for Iranians who live outside Iran: video sessions in Farsi (or English) with the clinic's Farsi speaking therapists, booked in the client's own time zone.\n\n"
    . "- Founder and clinical director: Dr. Meysam Nikdanesh, PhD in Clinical Psychology (Tehran Institute of Psychiatry, Iran University of Medical Sciences), 12 years of clinical experience.\n"
    . "- Google Maps rating: " . number_format((float)$rv['rating'], 1) . " from " . (int)$rv['count'] . " reviews (https://maps.google.com/?cid=11891624365515058342).\n"
    . "- The therapists are licensed in Iran; sessions are online, so they are not a substitute for local emergency services.\n"
    . "- Contact: WhatsApp +98 901 723 5974 · clinic phone +98 21 8280 8055 · Persian site for clients inside Iran: https://nikmindclinic.com/\n"
    . "- Languages: Persian pages at /, English pages at /en/.\n";
$llms = llms_build($PUB, $SITE, ['/sitemap.xml', '/blog/sitemap-blog.xml', '/en/blog/sitemap-blog.xml'], $intro, [
    '#^/(en/)?$#' => 'Main pages', '#^/(en/)?(about|therapists|faq|contact|pricing)/$#' => 'Main pages',
    '#^/(en/)?therapists/#' => 'Therapists', '#^/(en/)?countries/#' => 'Countries', '#^/(en/)?cities/#' => 'Cities',
    '#^/(en/)?blog/#' => 'Articles', '#.*#' => 'Other pages']);
$rep[] = '2. llms.txt: ' . substr_count($llms, "\n- [") . ' links';
$save("$PUB/llms.txt", $llms);

/* ---------- 3. 404 page ---------- */
$p404 = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>صفحه پیدا نشد | Page not found — NikMind</title><meta name="robots" content="noindex, follow">'
    . '<link rel="icon" href="/assets/12a7b8f4-3b62-48d5-9eb2-6fae242db22f.png" type="image/png">'
    . '<style>@font-face{font-family:Vazirmatn;src:url("/assets/ab35d003-11eb-422a-a217-49310806b33b.woff2") format("woff2");font-display:swap}'
    . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f3fbfb;color:#1f2d3a;font-family:Vazirmatn,system-ui,sans-serif;line-height:1.9}'
    . '.box{max-width:34rem;margin:1.5rem;padding:2.25rem 2rem;background:#fff;border-radius:1.5rem;box-shadow:0 12px 40px rgba(44,120,168,.10);text-align:center}'
    . 'h1{font-size:1.5rem;margin:.25rem 0 .5rem}.n{font-size:3rem;font-weight:800;color:#3d8fc4;margin:0}p{margin:.25rem 0 1.25rem;color:#4b5b69}'
    . '.links{display:flex;flex-wrap:wrap;gap:.6rem;justify-content:center}.links a{padding:.55rem 1.1rem;border-radius:999px;background:#e6f4f1;color:#1f2d3a;text-decoration:none;font-weight:600}'
    . '.links a.p{background:#3d8fc4;color:#fff}.en{direction:ltr;margin-top:1.75rem;padding-top:1.25rem;border-top:1px solid #e3eef0}</style></head><body><main class="box">'
    . '<p class="n">۴۰۴</p><h1>این صفحه پیدا نشد</h1><p>شاید آدرس عوض شده باشد. از این‌جا ادامه بدهید:</p>'
    . '<div class="links"><a class="p" href="/">صفحه‌ی اصلی</a><a href="/therapists/">درمانگران</a><a href="/blog/">مقالات</a><a href="https://wa.me/989017235974">واتس‌اپ</a></div>'
    . '<div class="en"><h1>Page not found</h1><p>The page may have moved.</p><div class="links"><a class="p" href="/en/">Home</a><a href="/en/therapists/">Therapists</a><a href="/en/blog/">Articles</a></div></div>'
    . '</main></body></html>';
$rep[] = '3. 404';
$save("$PUB/404.html", $p404);
$ht = (string)@file_get_contents("$PUB/.htaccess");
if (!preg_match('/^\s*ErrorDocument\s+404/mi', $ht)) $save("$PUB/.htaccess", "ErrorDocument 404 /404.html\n" . $ht);

/* ---------- 4. Nik-Negar templates ---------- */
$rep[] = '4. templates';
$fixes = [['۱۸ سال تجربه‌ی بالینی', '۱۲ سال تجربه‌ی بالینی'], ['18 years of clinical experience', '12 years of clinical experience']];
$svc = ['1701' => '/services/immigration-psychology/', 'روانشناسی-مهاجرت' => '/services/immigration-psychology/', 'مشاوره-فردی' => '/services/individual-counseling/',
    'روانپزشکی' => '/services/psychiatry/', 'روانسنجی' => '/services/psychological-testing/', 'مشاوره-کودک' => '/services/child-counseling/', 'مشاوره-نوجوان' => '/services/teen-counseling/',
    'مشاوره-ازدواج' => '/services/couples-therapy/', 'مشاوره-خانواده-حضوری-و-آنلاین' => '/services/family-counseling/', 'مشاوره-خانواده' => '/services/family-counseling/',
    'سکس-تراپی' => '/services/sex-therapy/', 'مشاوره-تحصیلی' => '/services/academic-counseling/', 'مشاوره-پیش-از-ازدواج' => '/services/premarital-counseling/',
    'مهاجرت-روانشناسها' => '/services/psychologist-immigration/', 'مشاوره-آنلاین' => '/online-therapy/', '' => '/services/'];
foreach (glob(getenv('HOME') . '/autoblog/templates/*.html') ?: [] as $p) {
    $t = (string)file_get_contents($p); $o = $t;
    foreach ($fixes as [$a, $b]) $t = str_replace($a, $b, $t);
    $t = preg_replace_callback('#https://nikmindclinic\.com/nicemind_services/([^"\'\s<>]*?)/?(?=["\'\s<>])#u', fn($m) => isset($svc[rawurldecode($m[1])]) ? 'https://nikmindclinic.com' . $svc[rawurldecode($m[1])] : $m[0], $t);
    if ($t !== $o) $save($p, $t);
}

$rep[] = ($APPLY ? 'APPLIED' : 'DRY RUN') . ($APPLY ? " (backup: $BK)" : '');
file_put_contents(__DIR__ . '/geo-iranian-2-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n\n----- llms.txt preview -----\n" . substr($llms, 0, 6000));
echo implode("\n", $rep), "\n";
