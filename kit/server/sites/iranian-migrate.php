<?php
// One-time move of iranian.nikmindclinic.com from the first Nik-Negar (autoblog v2, before the kit) to the kit,
// so it gets the nightly self-update like the other sites. Run once in ~/autoblog:  php iranian-migrate.php
// Report: migrate-report.txt (then install-report.txt). Everything replaced is backed up first; undo with
//   php backups/pre-kit-<time>/restore.php
// Never deletes anything; nothing on the public site changes except two HTML comments around the cards of each blog index.
declare(strict_types=1);

$AB = __DIR__;
$PUB = getenv('NN_TEST_PUB') ?: '/home/mrnazif/domains/iranian.nikmindclinic.com/public_html';
$KIT = 'mnazif1992/nikmind-autoblog';
$rep = [];
$say = function (string $m) use (&$rep) { $rep[] = $m; echo $m, "\n"; };
$save = function () use (&$rep, $AB) { file_put_contents("$AB/migrate-report.txt", '[' . date('c') . "]\n" . implode("\n", $rep) . "\n"); };
$stop = function (string $m) use ($say, $save) { $say("❌ $m"); $say('Nothing was changed after this point.'); $save(); exit(1); };

if (is_file("$AB/bootstrap.php") && is_file("$AB/site.json")) { $say('Already on the kit — nothing to do. Health check: php install.php --check'); $save(); exit(0); }
if (!str_contains((string)@file_get_contents("$AB/lib.php"), 'NikMind autoblog v2')) $stop('this folder is not the first-version install (lib.php is not "NikMind autoblog v2")');
if (!is_dir($PUB)) $stop("public_html not found: $PUB");
if (PHP_VERSION_ID < 80100) $stop('PHP 8.1+ needed, this is ' . PHP_VERSION);

/* ---------- 1. read the live blog: every card of both indexes becomes a post of the kit ---------- */
$digits = fn(string $s) => strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
$clusters = [];   // slug -> cluster, from the drafts this install wrote
foreach (glob("$AB/drafts/*.json") ?: [] as $f) {
    $d = json_decode((string)file_get_contents($f), true);
    if (is_array($d) && !empty($d['slug'])) $clusters[$d['slug']] = (string)($d['cluster'] ?? $d['fa']['cluster'] ?? '');
}
$GRID = '#(<div class="blog-grid list">)((?:\s*<a class="post" href="[^"]+">.*?</a>)+)#s';
$CARD = '#<a class="post" href="([^"]+)"><div class="post-img">(.*?)</div>\s*<div class="post-body"><h3>(.*?)</h3><p class="ex">(.*?)</p><div class="post-meta"><span>([^<]*)</span><span>([^<]*)</span></div></div></a>#s';
$plan = [];
foreach (['fa' => '/blog/', 'en' => '/en/blog/'] as $lang => $base) {
    $idxf = $PUB . $base . 'index.html';
    $html = (string)@file_get_contents($idxf);
    if ($html === '') $stop("$base index.html missing");
    if (str_contains($html, '<!--nn:cards-->')) $stop("$base index.html already has <!--nn:cards--> markers");
    $total = substr_count($html, '<a class="post"');
    if (!preg_match($GRID, $html, $g)) $stop("$base: card grid not found");
    preg_match_all($CARD, $g[2], $cards, PREG_SET_ORDER);
    if (count($cards) !== $total) $stop("$base: read " . count($cards) . " cards but the page has $total — the page layout changed");
    $posts = [];
    foreach ($cards as $c) {
        if (!preg_match('#^' . preg_quote($base, '#') . '([a-z0-9-]+)/$#', $c[1], $m)) $stop("$base: unexpected card link {$c[1]}");
        $slug = $m[1];
        $page = (string)@file_get_contents($PUB . $base . $slug . '/index.html');
        $date = preg_match('#"datePublished":"(\d{4}-\d{2}-\d{2})#', $page, $dm) ? $dm[1]
              : (preg_match('#<time datetime="(\d{4}-\d{2}-\d{2})#', $page, $dm) ? $dm[1] : '');
        if ($date === '') { $date = date('Y-m-d', (int)@filemtime($PUB . $base . $slug . '/index.html') ?: time()); $say("   $base$slug: no date in the page, used the file date $date"); }
        $img = preg_match('#<img[^>]*\bsrc="([^"]+)"#', $c[2], $im) ? html_entity_decode($im[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $read = preg_match('#(\d+)#', $digits($c[6]), $rm) ? (int)$rm[1] : 5;
        $dec = fn($x) => trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $posts[] = ['slug' => $slug, 'title' => $dec($c[3]), 'excerpt' => $dec($c[4]), 'date' => $date, 'read_min' => $read, 'card' => $img, 'cat' => $clusters[$slug] ?? ''];
    }
    $plan[$lang] = ['base' => $base, 'file' => $idxf, 'html' => $html, 'grid' => $g, 'posts' => $posts];
    $say("✅ $base: " . count($posts) . ' articles read from the live index');
}
if (!is_file("$AB/templates/article.html") || !is_file("$AB/templates/article-en.html")) $stop('templates/article.html or article-en.html missing');

/* ---------- 2. download the kit (verified against SHA256SUMS of one commit) ---------- */
$get = function (string $url, array $h = []): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: nik-negar-migrate'], $h)]);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return [$code, is_string($b) ? $b : ''];
};
[$code, $body] = $get("https://api.github.com/repos/$KIT/commits/main");
$sha = (string)(json_decode($body, true)['sha'] ?? '');
if (!preg_match('/^[a-f0-9]{40}$/', $sha)) $stop("could not read the kit version from GitHub ($code)");
$raw = function (string $file) use ($get, $KIT, $sha): string {
    [$code, $b] = $get("https://raw.githubusercontent.com/$KIT/$sha/kit/server/$file");
    if ($code !== 200) [$code, $b] = $get("https://api.github.com/repos/$KIT/contents/kit/server/$file?ref=$sha", ['Accept: application/vnd.github.raw']);
    return $code === 200 ? $b : '';
};
$sums = $raw('SHA256SUMS');
if (!preg_match_all('/^([a-f0-9]{64})\s+\*?(\S+)$/m', $sums, $sm, PREG_SET_ORDER)) $stop('could not read the kit file list');
$files = [];
foreach ($sm as [, $hash, $file]) {
    if (str_contains($file, '..') || in_array($file, ['config.php', 'site.json'], true)) continue;
    $b = $raw($file);
    if ($b === '' || hash('sha256', $b) !== $hash) $stop("download of $file failed or does not match its checksum");
    $files[$file] = $b;
}
$files['SHA256SUMS'] = $sums;
$say('✅ kit ' . substr($sha, 0, 7) . ': ' . count($files) . ' files downloaded and verified');

/* ---------- 3. backup of everything that will be replaced, with a restore script ---------- */
// the cron run and the webhook take this same lock; holding it keeps them out while files are swapped
@mkdir("$AB/state", 0700, true);
$lock = fopen("$AB/state/run.lock", 'c');
$t0 = time();
while (!flock($lock, LOCK_EX | LOCK_NB)) { if (time() - $t0 > 120) $stop('the bot is busy (state lock held for 2 minutes); run again'); usleep(250000); }
$bk = "$AB/backups/pre-kit-" . date('Ymd-His');
$copy = function (string $src, string $dst) { @mkdir(dirname($dst), 0700, true); return copy($src, $dst); };
$saved = [];
foreach (array_merge(glob("$AB/*.php") ?: [], glob("$AB/panel/*") ?: [], glob("$AB/templates/*") ?: [], ["$AB/state/state.json"]) as $f)
    if (is_file($f) && basename($f) !== 'iranian-migrate.php') { $copy($f, "$bk/ab/" . substr($f, strlen($AB) + 1)); $saved[] = substr($f, strlen($AB) + 1); }
foreach ($plan as $p) $copy($p['file'], "$bk/pub" . substr($p['file'], strlen($PUB)));
foreach (['/robots.txt', '/blog/sitemap-blog.xml', '/en/blog/sitemap-blog.xml', '/blog/feed.xml', '/en/blog/feed.xml'] as $f) if (is_file($PUB . $f)) $copy($PUB . $f, "$bk/pub$f");
file_put_contents("$bk/restore.php", '<?php
// Puts the first-version code and the blog pages back as they were before the move to the kit.
$bk = __DIR__; $AB = ' . var_export($AB, true) . '; $PUB = ' . var_export($PUB, true) . ';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bk, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $rel = substr((string)$f, strlen($bk) + 1);
  if (str_starts_with($rel, "ab/")) { $d = $AB . "/" . substr($rel, 3); } elseif (str_starts_with($rel, "pub/")) { $d = $PUB . "/" . substr($rel, 4); } else continue;
  @mkdir(dirname($d), 0755, true); copy((string)$f, $d); }
foreach (["site.json", "bootstrap.php"] as $x) if (is_file("$AB/$x")) rename("$AB/$x", "$AB/$x.kit-off");
echo "restored\n";
');
$say('✅ backup: ' . substr($bk, strlen($AB) + 1) . ' (' . count($saved) . ' files + blog pages; undo: php ' . substr($bk, strlen($AB) + 1) . '/restore.php)');

/* ---------- 4. site.json from the first version's settings ---------- */
$site = [
    'site_name' => 'نیک‌مایند', 'url' => 'https://iranian.nikmindclinic.com', 'public_html' => $PUB,
    'content_repo' => 'mnazif1992/nikmind-autoblog', 'timezone' => 'Asia/Tehran',
    'posts_per_day' => 2, 'daily_write_time' => '06:47', 'publish_slots' => ['11:00', '20:00'], 'slot_jitter_minutes' => [7, 25],
    'ondemand_check' => ['minute' => 23, 'from' => 8, 'to' => 23], 'messenger' => 'telegram',
    'publisher_type' => 'Organization', 'logo' => '/blog/assets/img/12a7b8f4-3b62-48d5-9eb2-6fae242db22f.png',
    'author_image' => '/images/team-1.jpg', 'default_og_image' => '/blog/assets/img/og-default.jpg', 'covers_path' => 'blog/assets/covers',
    'sitemaps' => ['/sitemap.xml'], 'link_targets' => ['/#precounsel', '/en/#precounsel'], 'min_internal_links' => 2,
    'clusters' => ['country' => 'کشورها', 'migration' => 'مهاجرت و روان', 'online' => 'مشاوره‌ی آنلاین', 'family' => 'خانواده'],
    'fallback_image_queries' => ['city street rain', 'tea glass window'],
    'kit_repo' => $KIT, 'kit_path' => 'kit/server', 'kit_branch' => 'main', 'auto_update' => true,
    'langs' => [
        ['code' => 'fa', 'base' => '/blog/', 'home' => '/', 'cta' => '/#precounsel', 'cta_text' => 'رزرو پیش‌مشاوره',
         'author' => 'دکتر میثم نیک‌دانش', 'author_url' => '/therapists/meysam-nikdanesh/',
         'author_title' => 'دکترای روانشناسی بالینی، روان‌درمانگر و بنیان‌گذار کلینیک نیک‌مایند',
         'disclaimer' => 'مطالب این بخش برای آگاهی عمومی است و جای جلسه‌ی ارزیابی با روانشناس یا روانپزشک را نمی‌گیرد.',
         'publisher' => 'کلینیک روانشناسی نیک‌مایند', 'suffix' => ' | نیک‌مایند', 'site_name' => 'نیک‌مایند',
         'blog_title' => 'مقالات روانشناسی برای ایرانیان خارج از کشور',
         'feed_title' => 'مقالات نیک‌مایند — سلامت روان برای ایرانیان خارج از کشور', 'team_author' => 'تحریریه نیک‌مایند', 'min_words' => 900],
        ['code' => 'en', 'base' => '/en/blog/', 'home' => '/en/', 'cta' => '/en/#precounsel', 'cta_text' => 'Book a free first call',
         'author' => 'Dr. Meysam Nikdanesh', 'author_url' => '/en/therapists/meysam-nikdanesh/',
         'author_title' => 'PhD in Clinical Psychology, psychotherapist and founder of NikMind Clinic',
         'disclaimer' => 'Articles here are for general information and do not replace an assessment session with a psychologist or psychiatrist.',
         'publisher' => 'NikMind Clinic', 'suffix' => ' | NikMind', 'site_name' => 'NikMind',
         'feed_title' => 'NikMind Articles — Mental health for Iranians abroad', 'team_author' => 'NikMind Editorial Team', 'min_words' => 900],
    ],
];

/* ---------- 5. switch: kit code, settings, templates, post lists, index markers ---------- */
foreach ($files as $file => $b) { @mkdir(dirname("$AB/$file"), 0755, true); file_put_contents("$AB/$file", $b); }
file_put_contents("$AB/site.json", json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
if (!is_file("$AB/secrets.auto.php"))   // config.php already holds the panel folder, webhook secret and IndexNow key; only the claim code is new
    file_put_contents("$AB/secrets.auto.php", "<?php\n// Generated by iranian-migrate.php. Keep private.\nif (!defined('OWNER_CLAIM')) define('OWNER_CLAIM', '" . bin2hex(random_bytes(8)) . "');\n");
@chmod("$AB/secrets.auto.php", 0600);
if (!is_file("$AB/templates/article-fa.html")) copy("$AB/templates/article.html", "$AB/templates/article-fa.html");
file_put_contents("$AB/templates/card.html", '<a class="post" href="{{HREF}}"><div class="post-img">{{IMG}}</div>' . "\n"
    . '    <div class="post-body"><h3>{{TITLE}}</h3><p class="ex">{{EXCERPT}}</p><div class="post-meta"><span>{{DATE}}</span><span>{{READ}}</span></div></div></a>');
foreach ($plan as $lang => $p) {
    $g = $p['grid'];
    $marked = str_replace($g[0], $g[1] . "<!--nn:cards-->" . $g[2] . "\n<!--/nn:cards-->", $p['html']);
    $tpl = str_replace($g[0], $g[1] . '{{CARDS}}', $p['html']);
    $tpl = preg_replace('#<meta name="robots" content="[^"]*">#', '<meta name="robots" content="{{ROBOTS}}">', $tpl, 1);
    file_put_contents("$AB/templates/index-$lang.html", $tpl);
    file_put_contents($p['file'], $marked);
    @mkdir("$AB/state", 0700, true);
    file_put_contents("$AB/state/posts-$lang.json", json_encode($p['posts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
$st = json_decode((string)@file_get_contents("$AB/state/state.json"), true) ?: [];
$st['last_update'] = date('Y-m-d');      // just updated: the nightly self-update starts tomorrow
file_put_contents("$AB/state/state.json", json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$say('✅ switched to the kit: site.json, templates (article-fa/en from the site\'s own, index-fa/en, card), post lists, index markers');
flock($lock, LOCK_UN); fclose($lock);
$say('Next: install.php (panel, webhook, sitemaps, feed). Its report: install-report.txt');
$save();

/* ---------- 6. the kit's own installer (panel, webhook, sitemaps, feed, checks) ---------- */
chdir($AB);
$argv = ['install.php'];
require "$AB/install.php";
