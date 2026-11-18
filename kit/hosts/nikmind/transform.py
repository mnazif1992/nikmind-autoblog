# python3 transform.py <orig_root> <out_root> <rel1,rel2,...>
import sys, re, json, os, asyncio
sys.path.insert(0, '/tmp'); from render import run
ORIG, OUT, RELS = sys.argv[1], sys.argv[2], sys.argv[3].split(',')
PRE = ['profileBody', 'patternsGrid', 'timelineItems', 'principlesGrid', 'boundaryGrid', 'beliefsGrid', 'doctorsGrid', 'servicesGrid', 'faqList', 'homeTherapists', 'reviewTrack', 'blogGrid']
H1CSS = '<style id="nn-route-h1">.section-head h1{margin-top:1rem;font-size:clamp(1.5rem,4vw,2.25rem);color:var(--foreground)}</style>'
def kind(rel):
    if re.fullmatch(r'(en/)?index\.html', rel): return 'home'
    if re.fullmatch(r'(en/)?about/index\.html', rel): return 'about'
    if re.fullmatch(r'(en/)?therapists/index\.html', rel): return 'therapists'
    if re.fullmatch(r'(en/)?therapists/[^/]+/index\.html', rel): return 'profile'
    return 'city'
KEEP = {'home': {'home'}, 'city': {'home'}, 'about': {'about'}, 'therapists': {'therapists'}, 'profile': set()}
def sections(h):
    """(start, end, page) of every top-level <section data-page=...> block"""
    out = []; i = 0
    for m in re.finditer(r'<section\b[^>]*\bdata-page="([a-z]+)"[^>]*>', h):
        if m.start() < i: continue
        depth = 0; j = m.start()
        for t in re.finditer(r'<(/?)section\b', h[m.start():]):
            depth += -1 if t.group(1) else 1
            if depth == 0: j = m.start() + t.end(); break
        k = h.index('>', j) + 1
        out.append((m.start(), k, m.group(1))); i = k
    return out
def clean(x):
    x = re.sub(r'\s+in-view\b', '', x)
    return x
def transform(raw, rel, ren):
    k = kind(rel); base = '/en/' if rel.startswith('en/') else '/'
    h = raw
    filled = []
    for i in PRE:
        v = ren['ids'].get(i, '')
        if not v.strip(): continue
        m = re.search(r'(<(\w+)\b[^>]*\bid="' + i + r'"[^>]*>)\s*(</\2>)', h)
        if not m: continue
        v = clean(v)
        if i == 'doctorsGrid':   # crawlable link on every card
            v = re.sub(r'(<article class="dir-card" data-profile="([^"]+)">[\s\S]*?)<span class="go">([\s\S]*?)</span>(\s*</div>\s*</article>)',
                       lambda x: x.group(1) + '<a class="go" href="' + base + 'therapists/' + x.group(2) + '/">' + x.group(3) + '</a>' + x.group(4), v)
        h = h[:m.end(1)] + v + h[m.start(3):]
        filled.append(i)
    for s, e, page in reversed(sections(h)):
        if page not in KEEP[k]:
            # this page does not show the block: keep only an empty skeleton (the section tag and every element
            # with an id, empty) so the site's script still finds what it looks for; the text is gone from the HTML
            blk = h[s:e]
            head = re.match(r'<section\b[^>]*>', blk).group(0)
            stubs = []
            for t in re.finditer(r'<(\w+)\b[^>]*\bid="[^"]+"[^>]*>', blk[len(head):]):
                tag = t.group(1).lower()
                stubs.append(t.group(0) + ('' if tag in ('input', 'img', 'br', 'hr', 'source', 'meta', 'link') else '</' + tag + '>'))
            pre = h[:s]; pre2 = re.sub(r'<!--[^>]*-->\s*$', '', pre)
            h = pre2 + head + ''.join(stubs) + '</section>' + h[e:]
    if k in ('about', 'therapists'):
        sec = [x for x in sections(h) if x[2] == k]
        if sec:
            s0, e0, _ = sec[0]
            blk = h[s0:e0]
            blk2 = re.sub(r'<h2( class="reveal"[^>]*)>([\s\S]*?)</h2>', r'<h1\1>\2</h1>', blk, count=1)
            h = h[:s0] + blk2 + h[e0:]
            h = h.replace('</head>', H1CSS + '\n</head>', 1)
    filled = [i for i in PRE if (lambda m: m and m.group(2).strip())(re.search(r'<(\w+)\b[^>]*\bid="' + i + r'"[^>]*>([\s\S]{0,40})', h)) and not re.search(r'<(\w+)\b[^>]*\bid="' + i + r'"[^>]*></', h)]
    return h, filled
async def main():
    urls = ['http://localhost:8811/' + r.replace('index.html', '') for r in RELS]
    ren = await run(urls, 'ids')
    rep = {}
    for r, u in zip(RELS, urls):
        raw = open(os.path.join(ORIG, r), encoding='utf-8').read()
        new, filled = transform(raw, r, ren[u])
        p = os.path.join(OUT, r); os.makedirs(os.path.dirname(p), exist_ok=True)
        open(p, 'w', encoding='utf-8').write(new)
        rep[r] = {'kind': kind(r), 'before': len(raw), 'after': len(new), 'filled': filled, 'errors': ren[u]['errors']}
    print(json.dumps(rep, ensure_ascii=False, indent=1))
asyncio.run(main())
