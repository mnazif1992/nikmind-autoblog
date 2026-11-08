# nikmind-autoblog

Daily article drafts for the blog at iranian.nikmindclinic.com (Persian `/blog/` and English `/en/blog/`).

- `topics.json` — topic queue (keyword pairs, status)
- `prompts/article-guide.md` — writing and SEO rules
- `prompts/daily-task.md` — what the daily scheduled task does
- `tools/check.py` — pre-commit checker (same rules as the server)
- `drafts/` — one JSON per article; the website server picks these up, builds a hidden preview and asks for approval in Telegram

Nothing in this repo is published directly. Publishing happens on the web server only after approval.
