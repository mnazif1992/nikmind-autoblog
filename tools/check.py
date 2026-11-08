#!/usr/bin/env python3
"""Checks a draft JSON against the same rules the server enforces (plus style rules).
Usage: python3 tools/check.py drafts/2026-11-09-some-slug.json   -> exit 0 if OK, prints problems otherwise."""
import json, re, sys, html, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
ALLOWED = {"p", "h2", "h3", "h4", "ul", "ol", "li", "strong", "em", "b", "i", "a", "blockquote", "br", "table", "thead", "tbody", "tr", "th", "td"}
BANNED = {
    "fa": ["در دنیای امروز", "به بررسی", "می‌پردازیم", "با ما همراه باشید", "در نهایت می‌توان گفت", "لازم به ذکر است", "حائز اهمیت",
           "نقش کلیدی", "سفر مهاجرت", "یکی از مراجعانم", "مراجعانم", "در جلسه‌هایم", "در جلساتم"],
    "en": ["in today's fast-paced", "delve", "navigate the complexities", "it's important to note", "it is important to note",
           "tapestry", "embark", "in conclusion", "game-changer", "unlock", "one of my clients", "my clients", "in my sessions"],
}
CTA = {"fa": "/#precounsel", "en": "/en/#precounsel"}

def text(h): return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html.unescape(h))).strip()
def norm(s): return s.replace("‌", " ").lower()

def check_lang(lang, a, slug, links, arts, errs, warns):
    P = lang.upper() + ": "
    for k in ["title", "seo_title", "meta_description", "excerpt", "body_html", "primary_keyword"]:
        if not isinstance(a.get(k), str) or not a[k].strip(): errs.append(P + f"missing {k}")
    if errs: return
    if lang == "fa" and re.search("[يك]", json.dumps(a, ensure_ascii=False)): errs.append(P + "Arabic ي/ك found")
    n = len(a["seo_title"]);       (30 <= n <= 60) or errs.append(P + f"seo_title {n} chars (30–60)")
    n = len(a["meta_description"]); (120 <= n <= 160) or errs.append(P + f"meta_description {n} chars (120–160)")
    len(a["excerpt"]) <= 200 or errs.append(P + "excerpt too long")
    kw = norm(a["primary_keyword"])
    kw in norm(a["title"] + " " + a["seo_title"]) or errs.append(P + "keyword not in title/seo_title")
    body = a["body_html"]
    tags = {t.lower() for t in re.findall(r"<\s*([a-zA-Z0-9]+)", body)}
    bad = tags - ALLOWED
    if bad: errs.append(P + f"disallowed tags {sorted(bad)}")
    if re.search(r"<(?!a\s)[a-z0-9]+\s+[^>]*=", body, re.I): errs.append(P + "attributes on non-<a> tags")
    t = text(body)
    wc = len(t.split())
    lo = 1100 if lang == "fa" else 1000
    if wc < 900 or wc > 2800: errs.append(P + f"{wc} words (server min 900)")
    elif wc < lo: warns.append(P + f"{wc} words (target {lo}+)")
    kw in norm(" ".join(t.split()[:110])) or errs.append(P + "keyword not in first ~100 words")
    h2 = re.findall(r"<h2>(.*?)</h2>", body, re.S)
    len(h2) >= 3 or errs.append(P + f"only {len(h2)} H2")
    any(kw in norm(text(x)) for x in h2) or warns.append(P + "keyword not in any H2")
    hrefs = re.findall(r'<a href="([^"]+)">', body)
    allowed = {l["url"] for l in links[lang]} | {a2["url"] for a2 in arts if a2.get("lang") == lang}
    internal = [h for h in hrefs if h.startswith("/")]
    for h in internal:
        if h not in allowed: errs.append(P + f"internal link not allowed: {h}")
        if h.rstrip("/").endswith(slug): errs.append(P + "self link")
    for h in hrefs:
        if not h.startswith("/") and not h.startswith("https://"): errs.append(P + f"bad href {h}")
    3 <= len(internal) <= 6 or errs.append(P + f"{len(internal)} internal links (3–6)")
    CTA[lang] in hrefs or errs.append(P + f"CTA {CTA[lang]} missing")
    faq = a.get("faq") or []
    (len(faq) == 0 or 3 <= len(faq) <= 5) or errs.append(P + "FAQ needs 3–5 items")
    low = norm(json.dumps(a, ensure_ascii=False))
    for b in BANNED[lang]:
        if norm(b) in low: errs.append(P + f"banned phrase: {b}")
    if re.search(r"\d+(\.\d+)?\s?%|[۰-۹]+\s?٪|درصد", t): warns.append(P + "contains a percentage — make sure the source is real and named")

def main(path):
    d = json.load(open(path, encoding="utf-8"))
    links = json.load(open(ROOT / "site/links.json", encoding="utf-8"))
    arts = json.load(open(ROOT / "site/articles.json", encoding="utf-8"))
    errs, warns = [], []
    slug = d.get("slug", "")
    re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", slug) and len(slug) <= 60 or errs.append("bad slug")
    if any(x.get("slug") == slug for x in arts): errs.append("slug already drafted before")
    pathlib.Path(path).name.endswith(f"-{slug}.json") or errs.append("file name must be YYYY-MM-DD-<slug>.json")
    iq = d.get("image_queries")
    if not (isinstance(iq, list) and 2 <= len(iq) <= 3 and all(isinstance(q, str) and 2 <= len(q.split()) <= 5 and q.isascii() for q in iq)):
        errs.append("image_queries must be 2–3 short English phrases")
    elif any(w in " ".join(iq).lower() for w in ["iranian", "persian", "anxiety", "depression", "therapy", "suicide", "crying"]):
        errs.append("image_queries: use concrete visual scenes, not abstract/sensitive words")
    for lang in ["fa", "en"]:
        if not isinstance(d.get(lang), dict): errs.append(f"{lang} version missing"); continue
        check_lang(lang, d[lang], slug, links, arts, errs, warns)
    for w in warns: print("WARN ", w)
    for e in errs: print("ERROR", e)
    print("OK" if not errs else f"{len(errs)} error(s)")
    sys.exit(1 if errs else 0)

if __name__ == "__main__":
    main(sys.argv[1])
