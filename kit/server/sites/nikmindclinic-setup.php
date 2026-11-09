<?php
// One-off for nikmindclinic.com: builds Nik-Negar templates from one of the site's own article pages,
// and adds the <!--nn:cards--> markers to /blog/ and every category page so new posts appear at the top.
// Usage (from the Nik-Negar folder): php domestic-setup.php [sample-article-slug]
// Backs up every file it changes; never deletes anything; safe to run again.
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
$rep = [];
$sample = $argv[1] ?? 'first-counseling-session';
$src = PUB . "/blog/$sample/index.html";
$h = (string)@file_get_contents($src);
if ($h === '') { echo "sample article not found: $src\n"; exit(1); }

$n = 0; $miss = [];
$sub = function (string $re, string $to) use (&$h, &$miss) {
    $h2 = preg_replace($re, $to, $h, 1, $c);   // $to may use $1-style backreferences; none of the replacements contain a literal $
    if ($c) $h = $h2; else $miss[] = $re;
};
$sub('#<title>.*?</title>#s', '<title>{{SEO_TITLE}}</title>');
$sub('#<meta name="description" content="[^"]*">#', '<meta name="description" content="{{DESC}}">');
$sub('#<meta name="robots" content="[^"]*">#', '<meta name="robots" content="{{ROBOTS}}">');
$sub('#<link rel="canonical" href="[^"]*">#', '<link rel="canonical" href="{{URL}}">');
$sub('#<meta property="og:title" content="[^"]*">#', '<meta property="og:title" content="{{TITLE}}">');
$sub('#<meta property="og:description" content="[^"]*">#', '<meta property="og:description" content="{{DESC}}">');
$sub('#<meta property="og:url" content="[^"]*">#', '<meta property="og:url" content="{{URL}}">');
$sub('#<meta property="og:image" content="[^"]*">#', '<meta property="og:image" content="{{OG_IMAGE}}">');
$sub('#<meta property="article:published_time" content="[^"]*">#', '<meta property="article:published_time" content="{{ISO}}">');
$h = preg_replace('#<meta property="article:modified_time" content="[^"]*">#', '<meta property="article:modified_time" content="{{ISO}}">', $h);
$h = preg_replace('#<link rel="preload" as="image" href="[^"]*"[^>]*>#', '', $h);
$h = preg_replace('#<script type="application/ld\+json">.*?</script>\s*#s', '', $h);
$h = preg_replace('#</head>#', "{{LD}}\n</head>", $h, 1);
$sub('#<nav class="crumbs"[^>]*>.*?</nav>#s', '<nav class="crumbs" aria-label="مسیر صفحه"> <a href="/">خانه</a> <span class="sep">/</span><a href="/blog/">مقالات</a><span class="sep">/</span><a href="{{CAT_URL}}">{{CAT_NAME}}</a><span class="sep">/</span><span aria-current="page">{{TITLE}}</span></nav>');
$sub('#<a class="cat-chip([^"]*)" href="[^"]*">[^<]*</a>#', '<a class="cat-chip$1" href="{{CAT_URL}}">{{CAT_NAME}}</a>');
$sub('#(<header class="article-head"[^>]*>.*?)<h1>.*?</h1>#s', '$1<h1>{{TITLE}}</h1>');
$sub('#<div class="article-meta">.*?</div>#s', '<div class="article-meta"> <span><svg class="ic"><use href="#i-user"/></svg>{{AUTHOR_HTML}}</span> <span><svg class="ic"><use href="#i-calendar-check"/></svg>{{TIME}}</span> <span><svg class="ic"><use href="#i-clock"/></svg>{{READ}}</span> </div>');
$sub('#<figure class="article-cover">.*?</figure>#s', '{{COVER}}');
$h = preg_replace('#<details class="toc">.*?</details>#s', '{{TOC}}', $h, 1, $c); if (!$c) $h = str_replace('<div class="prose"', '{{TOC}} <div class="prose"', $h);
// prose: everything between <div class="prose"…> and the wrapper </div>s that close the article
$cta = '<aside class="post-cta"><div class="t"><b>وقتش رسیده با یک متخصص حرف بزنید؟</b><p>جلسه‌ها حضوری در کلینیک (اکباتان، غرب تهران) و آنلاین برگزار می‌شوند. فرم رزرو را پر کنید تا همکاران پذیرش برای هماهنگی وقت با شما تماس بگیرند.</p></div>'
     . '<a href="{{CTA_URL}}" data-booking data-source="انتهای مقاله"><svg class="ic"><use href="#i-calendar-heart"/></svg>{{CTA_TEXT}}</a></aside>';
$byline = '<div class="nn-author"><b>{{AUTHOR_HTML}}</b><span>{{AUTHOR_BIO}}</span></div>';
$a0 = strpos($h, '<article'); $p0 = $a0 === false ? false : strpos($h, '<div class="prose"', $a0); $a1 = $p0 === false ? false : strpos($h, '</article>', $p0);
if ($p0 !== false && $a1 !== false) {
    $before = substr($h, $a0, $p0 - $a0);
    $wrappers = substr_count($before, '<div') - substr_count($before, '</div>');      // divs still open around the prose
    $tail = substr($h, $p0, $a1 - $p0);
    $open = substr($tail, 0, strpos($tail, '>') + 1);
    $closing = '';
    for ($i = 0; $i <= $wrappers; $i++) {                                          // peel prose </div> + wrapper </div>s off the end
        $k = strrpos($tail, '</div>');
        $closing = substr($tail, $k) . $closing;
        $tail = substr($tail, 0, $k);
    }
    $closing = preg_replace('#^</div>#', '', ltrim($closing));                     // the prose's own </div>
    $h = substr($h, 0, $p0) . $open . '{{PROSE}}</div>' . $cta . $byline . $closing . substr($h, $a1);
} else $miss[] = 'prose block';
// related block
$sub('#(<span class="eyebrow">مقالات مرتبط</span>.*?<a class="btn btn-ghost"[^>]*href=")[^"]*("[^>]*>)[^<]*#s', '$1{{CAT_URL}}$2همه‌ی مقالات این موضوع ');
$sub('#(<span class="eyebrow">مقالات مرتبط</span>.*?<div class="blog-grid list">).*?(</div>\s*</div>\s*</section>)#s', '$1{{RELATED}}$2');
$css = '<style id="nn-css">.post-cta{margin:2.25rem 0 .5rem;padding:1.35rem 1.5rem;border-radius:1.25rem;background:linear-gradient(135deg,#3d8fc4,#2c78a8);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem 1.5rem;box-shadow:0 10px 30px rgba(44,120,168,.22)}'
     . '.post-cta .t{flex:1 1 16rem}.post-cta b{display:block;font-size:1.15rem;font-weight:800;margin-bottom:.3rem;color:#fff}.post-cta p{margin:0;font-size:.95rem;line-height:1.85;color:rgba(255,255,255,.92)}'
     . '.post-cta a{display:inline-flex;align-items:center;gap:.5rem;background:#fff;color:#1f5f88;font-weight:800;border-radius:9999px;padding:.8rem 1.5rem;text-decoration:none;white-space:nowrap}.post-cta .ic{width:1.15rem;height:1.15rem}'
     . '.nn-author{margin-top:1.5rem;padding:1rem 1.2rem;border:1px solid var(--border,#e3e9ec);border-radius:1rem;font-size:.92rem;line-height:1.9}.nn-author b{display:block}.nn-author span{color:var(--muted-foreground,#5d6670)}'
     . '.nn-cover img,.article-cover img{width:100%;height:auto;border-radius:1.25rem}.nn-cover figcaption{font-size:.75rem;opacity:.65;margin-top:.35rem}.nn-cover figcaption a{color:inherit}</style>';
$h = preg_replace('#</head>#', $css . '</head>', $h, 1);

@mkdir(AB . '/templates', 0700, true);
foreach (['article-fa.html' => $h] as $name => $body) {
    $f = AB . "/templates/$name";
    if (is_file($f)) backup_file($f);
    file_put_contents($f, $body);
}
$card = '<a class="post" href="{{HREF}}"> <div class="post-img"> {{IMG}}<span class="post-cat chip-mint">{{CAT}}</span> </div> <div class="post-body"> <h3>{{TITLE}}</h3> <p class="ex">{{EXCERPT}}</p> <div class="post-meta"><span><svg class="ic"><use href="#i-calendar-check"/></svg>{{DATE}}</span><span><svg class="ic"><use href="#i-clock"/></svg>{{READ}}</span></div> </div> </a>';
file_put_contents(AB . '/templates/card.html', $card);
$left = [];
foreach (['{{PROSE}}', '{{RELATED}}', '{{LD}}', '{{COVER}}', '{{TOC}}', '{{AUTHOR_HTML}}', '{{CAT_URL}}', '{{CTA_URL}}'] as $p) if (!str_contains($h, $p)) $left[] = $p;
$rep[] = 'template from /blog/' . $sample . '/ — ' . (count($miss) ? 'NOT MATCHED: ' . implode(' | ', $miss) : 'all parts matched') . ($left ? ' — MISSING ' . implode(' ', $left) : '');

// markers on /blog/ and category pages
$pages = array_merge([PUB . '/blog/index.html'], glob(PUB . '/blog/category/*/index.html') ?: []);
foreach ($pages as $f) {
    $x = (string)file_get_contents($f);
    if (str_contains($x, '<!--nn:cards-->')) { $rep[] = 'markers already: ' . substr($f, strlen(PUB)); continue; }
    $x2 = preg_replace('#<div class="blog-grid list">#', '<div class="blog-grid list"><!--nn:cards--><!--/nn:cards-->', $x, 1, $c);
    if (!$c) { $rep[] = 'NO GRID: ' . substr($f, strlen(PUB)); continue; }
    write_file($f, $x2);
    $rep[] = 'markers added: ' . substr($f, strlen(PUB));
}
$out = implode("\n", $rep) . "\n";
file_put_contents(AB . '/domestic-setup-report.txt', $out);
echo $out;
