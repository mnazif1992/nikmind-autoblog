# Daily task — write today's 2 articles (fa + en) for iranian.nikmindclinic.com

Run every day. The person is not watching; do not ask questions. Decide, do it, and keep the final message short.

1. Get the repo `mnazif1992/nikmind-autoblog`: attach it with push access, clone it, and work on `main`. If the clone already exists, `git pull` first.
2. Read these files in full:
   - `prompts/article-guide.md` — the writing rules. Follow them strictly.
   - `topics.json`
   - `site/links.json`
   - `site/articles.json`
3. Today's date is the current date in Asia/Tehran.
   - If `drafts/` already has files for today's date, stop. Today is already done.
4. Pick the first 2 topics with `"status": "queued"`, in file order. The owner reorders, adds and removes topics from the Telegram panel: file order is the priority, and topics with `"status": "removed"` must never be written. If the first two share a `cluster`, take the next queued topic from a different cluster as the second one.
5. For each topic, write `drafts/<date>-<slug>.json`:
   - It holds both the Persian and the English version, in the format in the guide.
   - Use the topic's `slug`, `fa_keyword`, `en_keyword` and `angle`.
   - Write the full article. No placeholders, no "TODO".
   - Run `python3 tools/check.py drafts/<file>.json` and fix every ERROR. Read the WARNs and fix what is reasonable.
   - Repeat until it prints `OK`, at most 4 rounds. If it still fails, delete that draft file, set the topic `status` to `"needs_review"` with a short `"note"`, and move on to the next queued topic.
   - Before saving, reread the Persian text once as a picky Persian editor. Check ZWNJ (نیم‌فاصله), Persian punctuation, and no Arabic ي/ك. Cut sentences that sound translated or machine-written.
6. Update the bookkeeping:
   - In `topics.json`, set each drafted topic to `"status": "drafted"` and add `"drafted": "<date>"`.
   - Append both language versions to `site/articles.json`:
     `{"slug": ..., "lang": "fa", "url": "/blog/<slug>/", "title": ...}` and
     `{"slug": ..., "lang": "en", "url": "/en/blog/<slug>/", "title": ...}`.
7. If fewer than 14 topics are still `queued`, append 20 new ones at the end of `topics.json`. Each new topic:
   - continues the ID numbering;
   - has a unique English slug;
   - stays in the same four clusters (country / migration / online / family);
   - follows the keyword rules in the guide: real search phrases Iranians abroad use, not "free/chat" phrases, not cities inside Iran;
   - does not duplicate an existing topic or article;
   - has `"status": "queued"`.
8. Commit with the message `drafts: <date> <slug1>, <slug2>` and push to `main`.
   - If the push is rejected, pull with rebase and push again, once.
9. The website server picks up new files in `drafts/` within 15 minutes and sends them to Telegram for approval. Do not touch anything else.

Final message: one line per article (slug and Persian title), or the reason nothing was written.

## On-demand mode (the "✍️ new article" button in Telegram)

A second scheduled task runs every hour. Its prompt gives you a flag URL on the website.

1. Fetch the flag URL, adding `?t=<current unix time>` so you get a fresh copy. It returns JSON like `{"pending": 1}`.
   - If `pending` is 0, missing, or the URL cannot be read, stop immediately and write nothing.
2. If `pending` is greater than 0, write `min(pending, 2)` articles following steps 1–9 above, with these differences:
   - Ignore the "drafts for today already exist" rule.
   - Take the next queued topics, skipping any that already have a draft file.
   - Add `"on_demand": true` to the top level of each draft JSON.
   - Commit message: `drafts (on demand): <date> <slugs>`.
3. The server lowers `pending` by itself when it receives a draft marked `on_demand`. Never try to change the flag yourself.
