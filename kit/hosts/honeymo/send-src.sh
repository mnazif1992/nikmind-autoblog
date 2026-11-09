#!/usr/bin/env bash
# Read-only: copies a few HoneyMo app source files to the private content repo (server-report/src/) so a fix can be prepared.
set -uo pipefail
REPO=mnazif1992/honeymo-blog; SRC=/srv/honeymo
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
for f in apps/web/src/app/Link.tsx apps/web/src/app/router.ts apps/web/src/app/App.tsx apps/web/src/features/blog/BlogPage.tsx apps/web/src/features/blog/PostPage.tsx apps/web/src/features/blog/data.ts apps/web/package.json deploy/remote-build.sh deploy/update.sh deploy/nginx.conf deploy/agent.sh; do
  [ -f "$SRC/$f" ] || { echo "missing $f"; continue; }
  p="server-report/src/$(echo "$f" | tr '/' '_')"
  curl -s -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$p" -o /tmp/nn-get.json
  sha=$(grep -m1 '"sha"' /tmp/nn-get.json | cut -d'"' -f4)
  printf '{"message":"app source for review","content":"%s"%s}' "$(base64 -w0 "$SRC/$f")" "${sha:+,\"sha\":\"$sha\"}" > /tmp/nn-put.json
  curl -s -o /dev/null -w "$f -> %{http_code}\n" -X PUT -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$p" --data @/tmp/nn-put.json
done
rm -f /tmp/nn-put.json /tmp/nn-get.json
echo done
