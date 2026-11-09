#!/usr/bin/env bash
# Nik-Negar on honeymo.ir — read-only health report, pushed to the private content repo (server-report/status.txt)
# so problems can be diagnosed without anyone logging in to the server. Contains no tokens or secrets.
# Runs from root's cron every 5 minutes; only pushes when something changed.
set -uo pipefail
AB=/opt/niknegar
REPO=mnazif1992/honeymo-blog
R=/tmp/nn-status.txt
{
  echo "### time $(date -Is)   uptime $(uptime -p)"
  echo "### services"; systemctl is-active cron php*-fpm 2>&1 | paste -sd' '; docker ps --format '{{.Names}} {{.Status}}' 2>&1
  echo "### state (no secrets)"
  php -r '$s=json_decode(@file_get_contents("/opt/niknegar/state/state.json"),true)?:[]; $d=[]; foreach(($s["drafts"]??[]) as $k=>$v) $d[]=$k." ".($v["status"]??"")." ".($v["slug"]??""); echo "members=".count($s["chat_ids"]??[])." offset=".($s["tg_offset"]??"")." webhook=".json_encode($s["webhook"]??null)." paused=".json_encode($s["paused"]??null)." last_gh=".date("c",(int)($s["last_gh"]??0))." queue=".count($s["queue"]??[])."\ndrafts:\n  ".implode("\n  ",$d)."\n";' 2>&1
  echo "### log (last 40)"; tail -n 40 $AB/logs/$(date +%Y-%m).log 2>&1
  echo "### cron.txt (last 30)"; tail -n 30 $AB/logs/cron.txt 2>&1
  echo "### heal log (last 10)"; tail -n 10 /var/log/niknegar-heal.log 2>&1
  echo "### files"; ls -la $AB/state $AB/drafts 2>&1 | head -30; ls /var/www/honeymo-blog/blog 2>&1 | head -30
} > $R 2>&1
h=$(sed 1d $R | md5sum | cut -d' ' -f1)
[ "$h" = "$(cat /tmp/nn-status.hash 2>/dev/null)" ] && [ "${1:-}" != "--force" ] && exit 0
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
curl -s -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/server-report/status.txt" -o /tmp/nn-get.json
sha=$(grep -m1 '"sha"' /tmp/nn-get.json | cut -d'"' -f4)
printf '{"message":"server status","content":"%s"%s}' "$(base64 -w0 $R)" "${sha:+,\"sha\":\"$sha\"}" > /tmp/nn-put.json
code=$(curl -s -o /dev/null -w '%{http_code}' -X PUT -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/server-report/status.txt" --data @/tmp/nn-put.json)
rm -f /tmp/nn-put.json /tmp/nn-get.json
[ "$code" = 200 ] || [ "$code" = 201 ] && echo "$h" > /tmp/nn-status.hash
echo "status report -> $code"
