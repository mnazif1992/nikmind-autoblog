# Daily task — write today's articles (fa + en) for iranian.nikmindclinic.com

The site gets **6 articles a day**, written in **3 runs of 2** (around 06:47, 11:47 and 16:47 Tehran time). The same scheduled task fires three times; each run writes the next 2.

Run every day. The person is not watching; do not ask questions. Decide, do it, and keep the final message short.

1. Get the repo `mnazif1992/nikmind-autoblog`: attach it with push access, clone it, and work on `main`. If the clone already exists, `git pull` first.
2. Read these files in full:
   - `prompts/article-guide.md` — the writing rules. Follow them strictly.
   - `topics.json`
   - `site/links.json`
   - `site/articles.json`
3. Today's date is the current date in Asia/Tehran.
   - Count today's files in `drafts/` (`drafts/<today>-*.json`) that do **not** have `"on_demand": true`. Call it `done`.
   - If `done` is 6 or more, stop. Today is already done.
   - Otherwise this run writes `min(2, 6 - done)` articles.
4. Pick the next topics with `"status": "queued"`, in file order. The owner reorders, adds and removes topics from the Telegram panel: file order is the priority, and topics with `"status": "removed"` must never be written.
   - The queue is built around the site's location pages: most topics have a `hub` (a city or country page such as `/cities/perth/` or `/countries/usa/`) and a `region` (`australia`, `usa` or `other`). Australia and the USA come first; the file order already mixes them.
   - Do not write two articles with the same `hub` on the same day (check today's drafts too). If the next topic's `hub` is already used today, take the next queued one and leave the skipped topic where it is.
5. For each topic, write `drafts/<date>-<slug>.json`:
   - It holds both the Persian and the English version, in the format in the guide.
   - Use the topic's `slug`, `fa_keyword`, `en_keyword` and `angle`.
   - If the topic has a `hub`, follow the guide's section on location articles: link to that page in Persian (`hub`) and in English (`/en` + `hub`), and write about the problem *in that place*, not a second copy of the city page.
   - Write the full article. No placeholders, no "TODO".
   - Write `image_prompt` (the cover scene the AI draws) and `image_queries` for this article specifically, as in the guide's cover section. Picture the article's core moment first, then describe it.
   - Run `python3 tools/check.py drafts/<file>.json` and fix every ERROR. Read the WARNs and fix what is reasonable.
   - Repeat until it prints `OK`, at most 4 rounds. If it still fails, delete that draft file, set the topic `status` to `"needs_review"` with a short `"note"`, and move on to the next queued topic.
   - Before saving, reread the Persian text once as a picky Persian editor. Check ZWNJ (نیم‌فاصله), Persian punctuation, and no Arabic ي/ك. Cut sentences that sound translated or machine-written.
6. Update the bookkeeping:
   - In `topics.json`, set each drafted topic to `"status": "drafted"` and add `"drafted": "<date>"`.
   - Append both language versions to `site/articles.json`:
     `{"slug": ..., "lang": "fa", "url": "/blog/<slug>/", "title": ..., "image_prompt": "..."}` and
     `{"slug": ..., "lang": "en", "url": "/en/blog/<slug>/", "title": ...}`.
7. If fewer than 24 queued topics have a `hub`, add 30 new ones. Put them **right after the last queued topic that has a `hub`** (before the general topics without one), in the order Australia, USA, other, Australia, USA, other… Each new topic:
   - continues the ID numbering;
   - has a unique English slug;
   - has `hub`: a page that exists in `site/links.json` (`/cities/...` or `/countries/...`), and `region`: `australia` (Australia; Perth comes up most — give it more than its share), `usa`, or `other` (Canada, UK, Germany, Netherlands, Sweden, Turkey, UAE, Armenia, New Zealand and the rest of the location pages);
   - is a real problem in a real place (work, study, housing, couple, family, weather, visa wait, loneliness…), never "Iranian psychologist in <city>" — that is the city page's own keyword;
   - stays in the same four clusters (country / migration / online / family);
   - follows the keyword rules in the guide: real search phrases Iranians abroad use, not "free/chat" phrases, not cities inside Iran;
   - does not duplicate an existing topic or article, and does not repeat an angle another city already has (no "loneliness in X" for every city);
   - has `"status": "queued"`.
8. Commit with the message `drafts: <date> <slug1>, <slug2>` and push to `main`.
   - If the push is rejected, pull with rebase and push again, once.
9. The website server picks up new files in `drafts/` within 15 minutes and sends them to Telegram for approval. Do not touch anything else.

Final message: one line per article (slug and Persian title), or the reason nothing was written.

The website publishes approved articles at the times in `site/schedule.json` (6 a day). Do not edit that file.

## On-demand mode (the "✍️ new article" button in Telegram)

A second scheduled task runs every hour.

1. Attach and clone the repo as in step 1 (`git clone --depth 1`), then read `requests.json` at the repo root. It looks like `{"pending": 1, "updated": "..."}`.
   - If the file is missing, or `pending` is 0 or missing, stop immediately and write nothing.
2. If `pending` is greater than 0, write `min(pending, 2)` articles following steps 1–9 above, with these differences:
   - Ignore the daily count in step 3 (on-demand drafts do not count toward the 6).
   - Take the next queued topics, skipping any that already have a draft file.
   - Add `"on_demand": true` to the top level of each draft JSON.
   - Commit message: `drafts (on demand): <date> <slugs>`.
3. Never edit `requests.json`. The website server lowers `pending` by itself when it receives a draft marked `on_demand`. If your push is rejected because the server changed `requests.json` meanwhile, pull with rebase and push again.
