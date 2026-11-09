# کارهای زمان‌بندی‌شده‌ی Claude

برای هر سایت دو کار لازم است. اگر چند سایت داری، از نسخه‌ی چندسایتی پایین استفاده کن.

| کار | زمان پیشنهادی (ساعت تهران) | cron |
|---|---|---|
| نوشتن روزانه | هر روز ۶:۴۷ (همان `daily_write_time` در `site.json`) | `CRON_TZ=Asia/Tehran 47 6 * * *` |
| دکمه‌ی «مقاله‌ی جدید» | هر ساعت در دقیقه‌ی ۲۳، از ۸ تا ۲۳ (همان `ondemand_check`) | `CRON_TZ=Asia/Tehran 23 8-23 * * *` |

برای ساختن این کارها کافی است به Claude بگویی: «این دو کار زمان‌بندی‌شده را برای ریپوی OWNER/REPO بساز» و همین فایل را بدهی.

---

## ۱. نوشتن روزانه (یک سایت)

```text
Daily job for the blog of SITE_NAME (https://DOMAIN). Write today's articles and push them as drafts to GitHub.

1. Attach the GitHub repo OWNER/REPO with push access (add_repo), clone it with `git clone --depth 1`, and work on main.
2. Open prompts/daily-task.md in that repo and follow it exactly, step by step. It points to prompts/article-guide.md (writing,
   SEO and photo rules; Section 1 is specific to this site), site/profile.json, topics.json, site/links.json, site/articles.json
   and tools/check.py (must print OK for every draft before commit).
3. If drafts for today already exist in drafts/, stop.
4. Commit and push to main. The website server picks up new drafts within 5 minutes; do not touch the website or anything outside this repo.

Quality bar: natural human writing in each language, none of the banned machine-sounding phrases, no invented client stories,
quotes or statistics, nothing that breaks the red lines in Section 1 of the guide. Articles are published under a real person's name.

Finish with one short line per article (slug + title), or the reason nothing was written.
```

## ۲. بررسی ساعتی دکمه‌ی «مقاله‌ی جدید» (یک سایت)

```text
Hourly check for on-demand article requests from the Telegram button of SITE_NAME.

FIRST, before anything else: attach the GitHub repo OWNER/REPO with push access (add_repo), clone it with `git clone --depth 1`,
and read requests.json at the repo root ({"pending": N, ...}). Do not try to read the website; the count lives only in the repo.
- If requests.json is missing, or pending is 0 or missing: stop right away with one line ("no request").

ONLY if pending > 0: follow prompts/daily-task.md in "On-demand mode" — write min(pending, 2) articles, add "on_demand": true to each
draft, run tools/check.py until OK, update topics.json and site/articles.json, commit "drafts (on demand): <date> <slugs>" and push.
If the push is rejected, pull with rebase and push again. Never edit requests.json and never touch the website.

Finish with one line per article (slug + title), or why nothing was written.
```

---

## نسخه‌ی چندسایتی

با چند سایت، به‌جای دو کار برای هر سایت فقط **دو کار در کل** بساز. هر سایت هم ریپوی محتوای خودش را دارد.

### روزانه (همه‌ی سایت‌ها)

```text
Daily job for several Nik-Negar blogs. For EACH repo below, in order, do the full daily task, then move to the next:

- OWNER/REPO_1
- OWNER/REPO_2
- OWNER/REPO_3

For each repo: attach it with push access (add_repo), clone it with `git clone --depth 1` into its own folder, read
prompts/daily-task.md in that repo and follow it exactly (its guide, profile, topics and links are specific to that site — never mix
content, links or keywords between repos). If drafts for today already exist, skip that repo. Commit and push to that repo's main.
A failure in one repo must not stop the others.

Finish with one line per repo: the slugs written, "already done", or the error.
```

### ساعتی (همه‌ی سایت‌ها)

```text
Hourly on-demand check for several Nik-Negar blogs. For EACH repo below:

- OWNER/REPO_1
- OWNER/REPO_2

Read requests.json at the repo root WITHOUT cloning first: run
  curl -s https://raw.githubusercontent.com/OWNER/REPO/main/requests.json
(for a private repo: attach it with add_repo and clone it with --depth 1 instead). If pending is 0 or missing, skip that repo.
If pending > 0: attach and clone it, follow prompts/daily-task.md "On-demand mode" in that repo, commit and push.
Never edit requests.json and never touch any website.

Finish with one line per repo that had requests (or "no requests").
```

> نکته: هر اجرای ساعتی از سهمیه‌ی اشتراکت مصرف می‌کند، حتی وقتی درخواستی در کار نیست. اگر دکمه را کم می‌زنی، بازه را به هر ۲ یا ۳ ساعت تغییر بده (مثلاً `23 8-23/2 * * *`) و `ondemand_check` را در `site.json` هم هماهنگ کن تا پیام ربات ساعت درست را بگوید.
