#!/usr/bin/env bash
# Nik-Negar on honeymo.ir — step 1 of 2.
# Installs PHP, downloads the kit into /opt/niknegar, asks for the two tokens (typed by the owner, never shown),
# and sends a read-only report of the current web setup to the private content repo so the next step can be prepared.
# Changes nothing in the running site. Safe to run again.
set -euo pipefail
AB=/opt/niknegar
PUB=/var/www/honeymo-blog
REPO=mnazif1992/honeymo-blog
SRC=/srv/honeymo

say(){ printf '\n\033[1;35m== %s\033[0m\n' "$*"; }

say "1/5 PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >/dev/null
apt-get install -y -qq php-cli php-fpm php-curl php-gd php-mbstring php-xml >/dev/null
php -v 2>&1 | sed -n 1p
php -r 'echo function_exists("imagewebp") ? "GD webp: ok\n" : "GD webp: MISSING\n";'

say "2/5 kit files"
curl -fsS https://api.github.com/repos/mnazif1992/nikmind-autoblog/commits/main -o /tmp/nn-commit.json
KIT_SHA=$(grep -m1 '"sha"' /tmp/nn-commit.json | cut -d'"' -f4)
RAW="https://raw.githubusercontent.com/mnazif1992/nikmind-autoblog/$KIT_SHA/kit"
echo "kit commit $KIT_SHA"
mkdir -p "$AB" "$PUB" "$AB/backups"
curl -fsS "$RAW/server/SHA256SUMS" -o /tmp/nn-sums
bad=0
while read -r sum f; do
  f=${f#\*}
  case "$f" in config.php|site.json|secrets.auto.php|*..*) continue;; esac
  mkdir -p "$(dirname "$AB/$f")"
  curl -fsS "$RAW/server/$f" -o "$AB/$f.dl" && [ "$(sha256sum "$AB/$f.dl" | cut -d' ' -f1)" = "$sum" ] && mv "$AB/$f.dl" "$AB/$f" || { echo "BAD $f"; bad=1; }
done < /tmp/nn-sums
[ $bad = 0 ] && echo "all kit files ok"
for f in site.json templates/article-fa.html templates/index-fa.html templates/card.html; do
  mkdir -p "$(dirname "$AB/$f")"
  [ -f "$AB/$f" ] && cp "$AB/$f" "$AB/backups/$(basename "$f").$(date +%s)"
  curl -fsS "$RAW/hosts/honeymo/$f" -o "$AB/$f"
done
echo "site.json + templates ok"

say "3/5 tokens (typed by you, not shown on screen)"
if [ ! -f "$AB/config.php" ] || grep -q PASTE "$AB/config.php"; then
  while :; do read -rsp "Paste the Bale bot token, then press Enter: " BT </dev/tty; echo; [[ "$BT" =~ ^[0-9]+:[A-Za-z0-9_-]{20,}$ ]] && break; echo "That does not look like a bot token, try again."; done
  while :; do read -rsp "Paste the GitHub token (github_pat_...), then press Enter: " GT </dev/tty; echo; [[ "$GT" =~ ^github_pat_[A-Za-z0-9_]{40,}$ ]] && break; echo "That does not look like a GitHub token, try again."; done
  umask 077
  printf "<?php\n// Nik-Negar secrets for honeymo.ir. Never share this file.\nconst TG_BOT_TOKEN = '%s';\nconst GH_TOKEN = '%s';\nconst REQUIRE_APPROVAL = true;\n" "$BT" "$GT" > "$AB/config.php"
  unset BT GT
  echo "config.php written"
else
  echo "config.php already has tokens (kept)"
fi
chown -R www-data:www-data "$AB" "$PUB"; chmod 600 "$AB/config.php"
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
BT=$(php -r 'require "/opt/niknegar/config.php"; echo TG_BOT_TOKEN;')
echo "bale bot: $(curl -s "https://tapi.bale.ai/bot$BT/getMe" | grep -o '"username":"[^"]*"' || echo 'token rejected')"
echo "github repo: $(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $GT" https://api.github.com/repos/$REPO)"
unset BT

say "4/5 read-only report of the current web setup"
R=/tmp/nn-report.txt
set +e +o pipefail   # the report only reads; a missing file must not stop it
{
  echo "### date"; date; echo "### php"; php -v | head -1; ls /etc/php/; systemctl is-active "php*-fpm" 2>/dev/null || true
  echo "### docker ps"; docker ps --format '{{.Names}} | {{.Image}} | {{.Status}}'
  echo "### caddy mounts"; docker inspect honeymo-caddy-1 --format '{{json .Mounts}}' 2>/dev/null || true
  echo "### web image"; docker inspect honeymo-web-1 --format '{{.Config.Image}} {{json .Config.Cmd}} {{.Config.WorkingDir}}' 2>/dev/null || true
  echo "### deploy dir"; ls -la $SRC/deploy
  for f in $SRC/deploy/Caddyfile $SRC/deploy/docker-compose.yml $SRC/deploy/*.Dockerfile $SRC/deploy/Dockerfile* $SRC/apps/web/Dockerfile $SRC/apps/web/vite.config.* $SRC/deploy/push.sh $SRC/DEPLOY.md; do
    [ -f "$f" ] && { echo; echo "### FILE $f"; cat "$f"; }
  done
  echo; echo "### web src tree"; (cd $SRC/apps/web && find src -maxdepth 3 -type f | sort | head -150)
  echo; echo "### '/blog' in web src"; grep -rn --include='*.ts' --include='*.tsx' --include='*.js' --include='*.jsx' "/blog" $SRC/apps/web/src | head -60
  echo; echo "### files with main nav"; grep -rln 'ناوبری اصلی' $SRC/apps/web/src || true
  echo; echo "### public/blog"; ls -la $SRC/apps/web/public/blog 2>/dev/null | head -30
  echo; echo "### git"; (cd $SRC && git log --oneline -3 2>/dev/null && git status --short | head -20) || echo "no git"
} > $R 2>&1
put(){ # path file
  local b64 sha; b64=$(base64 -w0 "$2")
  curl -s -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$1" -o /tmp/nn-get.json || true
  sha=$(grep -m1 '"sha"' /tmp/nn-get.json | cut -d'"' -f4 || true)
  printf '{"message":"server report","content":"%s"%s}' "$b64" "${sha:+,\"sha\":\"$sha\"}" > /tmp/nn-put.json
  curl -s -o /dev/null -w "$1 -> %{http_code}\n" -X PUT -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$1" --data @/tmp/nn-put.json
}
put server-report/report.txt $R
for f in $(grep -rln 'ناوبری اصلی' $SRC/apps/web/src 2>/dev/null | head -3); do put "server-report/src/$(basename "$f")" "$f"; done
rm -f /tmp/nn-put.json

say "5/5 done — tell Claude: «مرحله ۱ تمام شد»"
