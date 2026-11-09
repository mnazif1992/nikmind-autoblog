# Daily task — write today's articles for this site

Run every day. Nobody is watching; do not ask questions. Decide, do it, and keep the final message short.

1. Get this repo: attach it with push access, clone it with `git clone --depth 1`, and work on `main`. If the clone already exists, `git pull` first.
2. Read these files in full:
   - `prompts/article-guide.md` — the writing rules. Section 1 is specific to this site; follow it strictly.
   - `site/profile.json` — languages, call-to-action link, word targets, `posts_per_day`.
   - `topics.json`, `site/links.json`, `site/articles.json`.
3. Today's date is the current date in the site's time zone (Asia/Tehran unless the guide says otherwise).
   - If `drafts/` already has files for today's date, stop. Today is already done.
4. Pick the first `posts_per_day` topics with `"status": "queued"`, in file order.
   - File order is the owner's priority (they reorder from the Telegram panel). Never write topics with `"status": "removed"`.
   - If two picked topics share a `cluster`, swap the second for the next queued topic from another cluster, if there is one.
   - If a topic's `slug` starts with `topic-`, replace it with a short English slug (lowercase words with dashes) that describes the keyword, and save it in `topics.json`.
5. For each topic, write `drafts/<date>-<slug>.json`:
   - One key per language in `site/profile.json` (e.g. `"fa"`, `"en"`), each with the fields in the guide. Use the topic's `keywords.<code>` as that language's `primary_keyword`, and its `angle`.
   - Write the full article. No placeholders, no "TODO".
   - `image_queries` must fit this article specifically (see the guide), not a generic scene.
   - Run `python3 tools/check.py drafts/<file>.json`. Fix every ERROR; fix the WARNs that are reasonable.
   - Repeat until it prints `OK`, at most 4 rounds. If it still fails, delete that draft, set the topic's `status` to `"needs_review"` with a short `"note"`, and move on to the next queued topic.
   - Before saving, reread each language once as a picky native editor. Cut sentences that sound translated or machine-written. For Persian: ZWNJ (نیم‌فاصله), Persian punctuation, no Arabic ي/ك.
6. Bookkeeping:
   - In `topics.json`, set each drafted topic to `"status": "drafted"` and add `"drafted": "<date>"`.
   - Append one entry per language to `site/articles.json`:
     `{"slug": ..., "lang": "<code>", "url": "<that language's base><slug>/", "title": ..., "image_queries": [...]}`.
7. If fewer than 14 topics are still `queued`, append 20 new ones at the end of `topics.json`:
   - continue the ID numbering; unique English slugs; same cluster keys as the existing topics;
   - real search phrases this audience types (follow the guide's Section 1), not duplicates of existing topics or articles;
   - `"keywords"` has one phrase per language in `site/profile.json`; `"status": "queued"`.
8. Commit with the message `drafts: <date> <slug1>, <slug2>` and push to `main`.
   - If the push is rejected, pull with rebase and push again, once.
9. The website server picks up new files in `drafts/` within 5 minutes, adds a cover photo, and asks the owner in Telegram. Do not touch anything else.

Final message: one line per article (slug and title in the first language), or the reason nothing was written.

## On-demand mode (the "✍️ new article" button in Telegram)

A second scheduled task runs every hour.

1. Clone the repo as in step 1 and read `requests.json` at the repo root. It looks like `{"pending": 1, "updated": "..."}`.
   - If the file is missing, or `pending` is 0 or missing, stop immediately and write nothing.
2. If `pending` is greater than 0, write `min(pending, 2)` articles following steps 1–9, with these differences:
   - Ignore the "drafts for today already exist" rule.
   - Take the next queued topics, skipping any that already have a draft file.
   - Add `"on_demand": true` to the top level of each draft JSON.
   - Commit message: `drafts (on demand): <date> <slugs>`.
3. Never edit `requests.json`. The website server lowers `pending` itself when it receives a draft marked `on_demand`. If your push is rejected because the server changed `requests.json` meanwhile, pull with rebase and push again.
