<?php
// One-off site fix (1405-08-18):
//  1) make the blog reachable from the main site: "مقالات" pill in the header, link in the footer,
//     and the "latest articles" section on the home page filled with the real latest posts;
//  2) a "book a session" call-to-action box at the end of every article (existing pages + templates).
// Every changed file is backed up first. Nothing is deleted. Run again safely: already-patched files are skipped.
declare(strict_types=1);
$HOME = '/home/mrnazif';
$PUB = "$HOME/domains/iranian.nikmindclinic.com/public_html";
$AB = "$HOME/autoblog";
$BK = "$AB/backups/sitefix-" . date('Ymd-His');
$rep = [];
$save = function (string $f, string $new) use ($PUB, $AB, $BK) {
    $rel = str_starts_with($f, $PUB) ? substr($f, strlen($PUB)) : '/_autoblog' . substr($f, strlen($AB));
    @mkdir(dirname($BK . $rel), 0700, true);
    copy($f, $BK . $rel);
    file_put_contents("$f.tmp", $new);
    @chmod("$f.tmp", 0644);
    rename("$f.tmp", $f);
};

/* ---------- 1. main site pages (home, about, therapists, landing pages; fa + en) ---------- */
$GRID_JS = <<<'JS'
(function () {
      const grid = $("#blogGrid"); if (!grid) return;
      const base = (document.documentElement.lang || "").indexOf("en") === 0 ? "/en/blog/" : "/blog/";
      fetch(base, { cache: "no-cache" }).then((r) => (r.ok ? r.text() : "")).then((html) => {
        const doc = new DOMParser().parseFromString(html, "text/html");
        const cards = [...doc.querySelectorAll(".blog-grid a.post[href]")].slice(0, 3);
        if (!cards.length) { const s = grid.closest("section"); if (s) s.remove(); return; }
        grid.innerHTML = cards.map((a) => {
          const img = a.querySelector("img"), h = a.querySelector("h3"), t = h ? h.textContent.trim() : "";
          const read = [...a.querySelectorAll(".post-meta span")].map((s) => s.textContent.trim())[1] || "";
          return `<a class="post" href="${esc(a.getAttribute("href"))}" style="text-decoration:none;color:inherit"><div class="post-img">${img ? `<img src="${esc(img.getAttribute("src"))}" alt="${esc(t)}" loading="lazy" />` : ""}</div><div class="post-body"><h3>${esc(t)}</h3>${read ? `<p class="post-read">${icon("i-clock")}${esc(read)}</p>` : ""}</div></a>`;
        }).join("");
      }).catch(() => {});
    })();
JS;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $f = $file->getPathname();
    if ($file->getFilename() !== 'index.html') continue;
    $rel = substr($f, strlen($PUB));
    if (preg_match('#^/(en/)?blog/|^/tg-#', $rel)) continue;
    $h = (string)file_get_contents($f);
    if (!str_contains($h, 'class="site-header"')) continue;
    $en = (bool)preg_match('#<html[^>]*lang="en#', $h);
    $blog = $en ? '/en/blog/' : '/blog/';
    $label = $en ? 'Articles' : 'مقالات';
    $done = [];
    // header pill, next to the language switch
    if (!str_contains($h, 'class="lang-switch nav-blog"')) {
        $h2 = preg_replace('#(<div class="header-actions">\s*)(<a class="lang-switch")#', '$1<a class="lang-switch nav-blog" href="' . $blog . '">' . $label . '</a> $2', $h, 1, $n);
        if ($n) { $h = $h2; $done[] = 'header'; }
    }
    // footer link, after "about"
    $about = $en ? '<li><a href="/en/about/">About us</a></li>' : '<li><a href="/about/">درباره‌ی ما</a></li>';
    if (str_contains($h, $about) && !str_contains($h, '<li><a href="' . $blog . '">')) {
        $h = str_replace($about, $about . '<li><a href="' . $blog . '">' . $label . '</a></li>', $h);
        $done[] = 'footer';
    }
    // home page "latest articles": show it on the home page, link the button, fill it with the real latest posts
    if (str_contains($h, '<section data-page="blog"')) {
        $h = str_replace('<section data-page="blog"', '<section data-page="home" id="latest-articles"', $h);
        $done[] = 'blog section on home';
    }
    $h2 = preg_replace('#<button class="btn btn-ghost reveal" style="color:var\(--coral\)">((?:دیدن همه‌ی مطالب|See all articles)\s*<svg class="ic"><use href="\#i-arrow-left"></use></svg>)</button>#u',
        '<a class="btn btn-ghost reveal" style="color:var(--coral)" href="' . $blog . '">$1</a>', $h, 1, $n);
    if ($n) { $h = $h2; $done[] = 'see-all link'; }
    $h2 = preg_replace('#\$\("\#blogGrid"\)\.innerHTML = DATA\.blog\.map\(.*?\.join\(""\);#s', str_replace(['\\', '$'], ['\\\\', '\\$'], $GRID_JS), $h, 1, $n);
    if ($n) { $h = $h2; $done[] = 'latest posts script'; }
    if ($done) { $save($f, $h); $rep[] = "$rel: " . implode(', ', $done); }
}

/* ---------- 2. call-to-action box at the end of every article ---------- */
$CSS = '<style id="post-cta-css">.post-cta{margin:2.25rem 0 .5rem;padding:1.35rem 1.5rem;border-radius:1.25rem;background:linear-gradient(135deg,#3d8fc4,#2c78a8);color:#fff;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem 1.5rem;box-shadow:0 10px 30px rgba(44,120,168,.22)}'
     . '.post-cta .t{flex:1 1 16rem}.post-cta b{display:block;font-size:1.15rem;font-weight:800;margin-bottom:.3rem;color:#fff}.post-cta p{margin:0;font-size:.95rem;line-height:1.85;color:rgba(255,255,255,.92)}'
     . '.post-cta a{display:inline-flex;align-items:center;gap:.5rem;background:#fff;color:#1f5f88;font-weight:800;border-radius:9999px;padding:.8rem 1.5rem;text-decoration:none;white-space:nowrap;transition:transform .15s}'
     . '.post-cta a:hover{transform:translateY(-1px)}.post-cta .ic{width:1.15rem;height:1.15rem}</style>';
$box = function (bool $en, bool $icon): string {
    $ic = $icon ? '<svg class="ic"><use href="#i-calendar-heart"/></svg>' : '';
    return $en
        ? '<aside class="post-cta" dir="ltr"><div class="t"><b>Ready to talk to someone?</b><p>Sessions are held online with Farsi-speaking therapists. Leave your details and our reception team will get in touch to arrange a time.</p></div><a href="/en/#precounsel">' . $ic . 'Book a session</a></aside>'
        : '<aside class="post-cta"><div class="t"><b>وقتش رسیده با یک متخصص حرف بزنی؟</b><p>جلسه‌ها آنلاین و به زبان فارسی‌اند. فرم رزرو را پر کن تا همکاران پذیرش برای هماهنگی وقت با تو تماس بگیرند.</p></div><a href="/reserve/">' . $ic . 'رزرو جلسه</a></aside>';
};
$targets = array_merge(glob("$PUB/blog/*/index.html") ?: [], glob("$PUB/en/blog/*/index.html") ?: [], ["$AB/templates/article.html", "$AB/templates/article-en.html"]);
foreach ($targets as $f) {
    if (!is_file($f)) continue;
    $h = (string)file_get_contents($f);
    if (str_contains($h, 'class="post-cta"')) continue;
    $en = str_contains($f, '/en/blog/') || str_ends_with($f, 'article-en.html');
    $icon = str_contains($h, 'id="i-calendar-heart"');
    if (!str_contains($h, '<div class="byline">')) { $rep[] = 'NO BYLINE ' . $f; continue; }
    $h = preg_replace('#<div class="byline">#', $box($en, $icon) . ' <div class="byline">', $h, 1);
    if (!str_contains($h, 'id="post-cta-css"')) $h = preg_replace('#</head>#', $CSS . '</head>', $h, 1);
    $save($f, $h);
    $rep[] = 'cta ' . str_replace([$PUB, $AB], ['', '~autoblog'], $f);
}
file_put_contents("$AB/sitefix-report.txt", implode("\n", $rep) . "\nbackups: $BK\n");
