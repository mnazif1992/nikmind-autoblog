<?php
// GEO fixes, part 3, for iranian.nikmindclinic.com:  php geo-iranian-3.php [apply]
// Every therapist profile (/therapists/<slug>/ and /en/therapists/<slug>/) gets Person + BreadcrumbList JSON-LD,
// built from the page's own therapist data (name, role, photo, bio, education). Backup first; nothing is deleted.
declare(strict_types=1);
$PUB = '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html';
$SITE = 'https://iranian.nikmindclinic.com';
$APPLY = in_array('apply', $argv, true);
$BK = getenv('HOME') . '/geo-backup-3-' . date('Ymd-His');
$J = fn($x) => json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$rep = []; $done = 0; $skip = [];
foreach (['fa' => '/therapists/', 'en' => '/en/therapists/'] as $lang => $base) {
    foreach (glob($PUB . $base . '*/index.html') ?: [] as $p) {
        $slug = basename(dirname($p));
        $h = (string)file_get_contents($p);
        if (str_contains($h, '"@type":"Person","@id":"' . $SITE . $base . $slug . '/#person"')) { $skip[] = "$lang/$slug (has it)"; continue; }
        if (!preg_match('#\{\s*slug:"' . preg_quote($slug, '#') . '",\s*init:"[^"]*",\s*name:"([^"]+)",\s*role:"([^"]*)",\s*cat:"[^"]*",\s*photo:"([^"]+)"\s*\}#u', $h, $t)) { $skip[] = "$lang/$slug (no data)"; continue; }
        $det = [];
        if (preg_match('#"' . preg_quote($slug, '#') . '":\s*(\{"quote".*?\]\})(?=,\s*"[a-z0-9-]+":\s*\{"quote"|\}\s*,?\s*\n)#su', $h, $d)) $det = json_decode($d[1], true) ?: [];
        $url = $SITE . $base . $slug . '/';
        $person = ['@context' => 'https://schema.org', '@type' => 'Person', '@id' => "$url#person", 'name' => $t[1], 'url' => $url,
            'image' => $SITE . $t[3], 'jobTitle' => $t[2], 'knowsLanguage' => 'fa',
            'worksFor' => ['@type' => 'MedicalClinic', '@id' => "$SITE/#clinic", 'name' => $lang === 'fa' ? 'کلینیک روانشناسی نیک‌مایند' : 'NikMind Clinic']];
        if (!empty($det['bio'][0])) $person['description'] = (string)$det['bio'][0];
        if (!empty($det['education'])) $person['hasCredential'] = array_map(fn($e) => ['@type' => 'EducationalOccupationalCredential', 'name' => (string)$e], array_slice((array)$det['education'], 0, 4));
        $crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $lang === 'fa' ? 'خانه' : 'Home', 'item' => $lang === 'fa' ? "$SITE/" : "$SITE/en/"],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $lang === 'fa' ? 'درمانگران' : 'Therapists', 'item' => $SITE . $base],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $t[1]]]];
        $ld = '<script type="application/ld+json">' . $J($person) . "</script>\n<script type=\"application/ld+json\">" . $J($crumbs) . "</script>\n";
        $k = stripos($h, '</head>'); if ($k === false) { $skip[] = "$lang/$slug (no head)"; continue; }
        $done++;
        if ($APPLY) {
            $dst = $BK . substr($p, strlen($PUB)); @mkdir(dirname($dst), 0700, true); copy($p, $dst);
            file_put_contents("$p.tmp", substr($h, 0, $k) . $ld . substr($h, $k)); chmod("$p.tmp", fileperms($p) & 0777); rename("$p.tmp", $p);
        }
        if ($done <= 2) $rep[] = "  sample $lang/$slug: " . substr($J($person), 0, 700);
    }
}
array_unshift($rep, ($APPLY ? 'APPLIED' : 'DRY RUN') . " — profiles: $done" . ($APPLY ? " (backup: $BK)" : ''), 'skipped: ' . implode(', ', $skip));
file_put_contents(__DIR__ . '/geo-iranian-3-' . ($APPLY ? 'apply' : 'dry') . '.txt', implode("\n", $rep) . "\n");
echo implode("\n", $rep), "\n";
