#!/usr/bin/env python3
"""Checks a draft JSON against the same rules the website server enforces, plus style rules.
Usage: python3 tools/check.py drafts/2026-11-09-some-slug.json   -> prints OK and exits 0, or lists the problems.
Site settings (languages, call-to-action link, word counts, extra banned phrases) come from site/profile.json."""
import json, re, sys, html, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
ALLOWED = {"p", "h2", "h3", "h4", "ul", "ol", "li", "strong", "em", "b", "i", "a", "blockquote", "br", "table", "thead", "tbody", "tr", "th", "td"}
BANNED = {  # phrases that make text read machine-written; site/profile.json can add more per language
    "fa": ["در دنیای امروز", "به بررسی", "می‌پردازیم", "با ما همراه باشید", "در نهایت می‌توان گفت", "لازم به ذکر است", "حائز اهمیت",
           "نقش کلیدی", "در این مقاله", "به طور کلی"],
    "en": ["in today's fast-paced", "delve", "navigate the complexities", "it's important to note", "it is important to note",
           "tapestry", "embark", "in conclusion", "game-changer", "unlock the"],
}
IMAGE_BAD = ["anxiety", "depression", "therapy", "suicide", "crying", "sad", "stress", "logo", "brand"]

def text(h): return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html.unescape(h))).strip()
def norm(s): return s.replace("‌", " ").lower()

def check_lang(L, a, slug, links, arts, prof, errs, warns):
    lang = L["code"]; P = lang.upper() + ": "
    for k in ["title", "seo_title", "meta_description", "excerpt", "body_html", "primary_keyword"]:
        if not isinstance(a.get(k), str) or not a[k].strip(): errs.append(P + f"missing {k}")
    if any(e.startswith(P + "missing") for e in errs): return
    if lang == "fa" and re.search("[يك]", json.dumps(a, ensure_ascii=False)): errs.append(P + "Arabic ي/ك found")
    n = len(a["seo_title"]);        (30 <= n <= 60) or errs.append(P + f"seo_title {n} chars (30–60)")
    n = len(a["meta_description"]); (120 <= n <= 160) or errs.append(P + f"meta_description {n} chars (120–160)")
    len(a["excerpt"]) <= 200 or errs.append(P + "excerpt too long (max 200)")
    kw = norm(a["primary_keyword"])
    kw in norm(a["title"] + " " + a["seo_title"]) or errs.append(P + "keyword not in title/seo_title")
    body = a["body_html"]
    bad = {t.lower() for t in re.findall(r"<\s*([a-zA-Z0-9]+)", body)} - ALLOWED
    if bad: errs.append(P + f"disallowed tags {sorted(bad)}")
    if re.search(r"<(?!a\s)[a-z0-9]+\s+[^>]*=", body, re.I): errs.append(P + "attributes on non-<a> tags")
    if re.search(r"<h1", body, re.I): errs.append(P + "no H1 in body (the template adds it)")
    t = text(body); wc = len(t.split())
    mn = int(L.get("min_words", 900)); lo, hi = L.get("target_words", [mn + 200, 1800])
    if wc < mn or wc > 3000: errs.append(P + f"{wc} words (server accepts {mn}–3000)")
    elif wc < lo: warns.append(P + f"{wc} words (target {lo}–{hi})")
    kw in norm(" ".join(t.split()[:110])) or errs.append(P + "keyword not in the first ~100 words")
    h2 = re.findall(r"<h2>(.*?)</h2>", body, re.S)
    len(h2) >= 3 or errs.append(P + f"only {len(h2)} H2 (min 3)")
    any(kw in norm(text(x)) for x in h2) or warns.append(P + "keyword not in any H2")
    hrefs = re.findall(r'<a href="([^"]+)">', body)
    allowed = {l["url"] for l in links.get(lang, [])} | {x["url"] for x in arts if x.get("lang") == lang}
    internal = [h for h in hrefs if h.startswith("/")]
    for h in internal:
        if h not in allowed: errs.append(P + f"internal link not in site/links.json or site/articles.json: {h}")
        if h.rstrip("/").endswith("/" + slug): errs.append(P + "links to itself")
    for h in hrefs:
        if not h.startswith("/") and not h.startswith("https://"): errs.append(P + f"bad href {h}")
    mi, ma = prof.get("internal_links", [3, 6])
    mi <= len(internal) <= ma or errs.append(P + f"{len(internal)} internal links ({mi}–{ma})")
    if L.get("cta"): L["cta"] in hrefs or errs.append(P + f"call-to-action link {L['cta']} missing")
    faq = a.get("faq") or []
    (len(faq) == 0 or 3 <= len(faq) <= 5) or errs.append(P + "FAQ needs 3–5 items")
    low = norm(json.dumps(a, ensure_ascii=False))
    for b in BANNED.get(lang, []) + L.get("banned", []):
        if norm(b) in low: errs.append(P + f"banned phrase: {b}")
    if re.search(r"\d+(\.\d+)?\s?%|[۰-۹]+\s?٪|درصد|percent", t, re.I): warns.append(P + "contains a percentage — make sure the source is real and named")

def main(path):
    d = json.load(open(path, encoding="utf-8"))
    prof = json.load(open(ROOT / "site/profile.json", encoding="utf-8"))
    links = json.load(open(ROOT / "site/links.json", encoding="utf-8"))
    arts = json.load(open(ROOT / "site/articles.json", encoding="utf-8"))
    errs, warns = [], []
    slug = d.get("slug", "")
    (re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", slug) and len(slug) <= 60) or errs.append("bad slug (lowercase English words with dashes, max 60)")
    if any(x.get("slug") == slug for x in arts): errs.append("slug already used by an earlier article")
    pathlib.Path(path).name.endswith(f"-{slug}.json") or errs.append("file name must be YYYY-MM-DD-<slug>.json")
    if not d.get("cluster"): errs.append("cluster missing (copy it from the topic in topics.json)")
    else:
        topics = json.load(open(ROOT / "topics.json", encoding="utf-8"))
        if d["cluster"] not in {t.get("cluster") for t in topics}: warns.append(f"cluster {d['cluster']} is not used by any topic")
    iq = d.get("image_queries")
    if not (isinstance(iq, list) and 2 <= len(iq) <= 3 and all(isinstance(q, str) and 2 <= len(q.split()) <= 5 and q.isascii() for q in iq)):
        errs.append("image_queries must be 2–3 short English phrases")
    else:
        badw = IMAGE_BAD + prof.get("image_query_banned_words", [])
        if any(w in " ".join(iq).lower() for w in badw): errs.append("image_queries: describe a concrete visible scene, not feelings or sensitive words")
        prev = [q for x in arts for q in x.get("image_queries", [])]
        if iq[0].lower() in {q.lower() for q in prev}: warns.append("image_queries: first query was used before — pick a scene specific to this article")
    for L in prof["langs"]:
        if not isinstance(d.get(L["code"]), dict): errs.append(f"{L['code']} version missing"); continue
        check_lang(L, d[L["code"]], slug, links, arts, prof, errs, warns)
    for w in warns: print("WARN ", w)
    for e in errs: print("ERROR", e)
    print("OK" if not errs else f"{len(errs)} error(s)")
    sys.exit(1 if errs else 0)

if __name__ == "__main__":
    main(sys.argv[1])
