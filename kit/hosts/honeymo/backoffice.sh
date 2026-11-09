#!/usr/bin/env bash
# HoneyMo back office — one-time setup on the server (safe to run again).
#   bash <(curl -fsS https://raw.githubusercontent.com/mnazif1992/nikmind-autoblog/<sha>/kit/hosts/honeymo/backoffice.sh) <sha>
# 1. gives the app's API the GitHub token it needs to save the catalog (deploy/.env, backed up first)
# 2. installs the autodeploy (GitHub main -> rebuild the site), every 2 minutes from root's cron
# 3. refreshes the blog helper scripts (heal + status report)
# 4. runs the first build now and waits for it (the running site stays up meanwhile)
# 5. creates the first back-office account; the password is typed here and never shown or stored in plain text
# Nothing is deleted.
set -uo pipefail
SHA="${1:-main}"
SRC=/srv/honeymo
ENVF=$SRC/deploy/.env
ST=/var/lib/honeymo-deploy
REPO=mnazif1992/honeymo-app
RAW="https://raw.githubusercontent.com/mnazif1992/nikmind-autoblog/$SHA/kit/hosts/honeymo"
say(){ printf '\n\033[1m== %s\033[0m\n' "$*"; }
[ "$(id -u)" = 0 ] || { echo "run as root (sudo -i first)"; exit 1; }

say "1/5 GitHub token"
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;' 2>/dev/null)
[ -n "$GT" ] || { echo "no token in /opt/niknegar/config.php"; exit 1; }
canpush=$(curl -s -H "Authorization: Bearer $GT" https://api.github.com/repos/$REPO | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo !empty($j["permissions"]["push"]) ? "yes" : "no";')
[ "$canpush" = yes ] || { echo "The token cannot write to $REPO. In GitHub token settings give it 'Contents: Read and write' on honeymo-app, then run this again."; exit 1; }
cp -a "$ENVF" "/root/honeymo.env.bak.$(date +%s)"
php -r '$f=$argv[1]; $t=(function(){require "/opt/niknegar/config.php"; return GH_TOKEN;})(); $c=file_get_contents($f); $c=preg_replace("/^GITHUB_TOKEN=.*\n?/m","",$c); $c=rtrim($c,"\n")."\nGITHUB_TOKEN=".$t."\n"; file_put_contents($f,$c);' "$ENVF"
echo "token is in deploy/.env (old file backed up in /root)"

say "2/5 autodeploy"
mkdir -p "$ST"
cd "$SRC" || exit 1
git config --global --get-all safe.directory 2>/dev/null | grep -qx "$SRC" || git config --global --add safe.directory "$SRC"
AUTH="Authorization: Basic $(printf 'x-access-token:%s' "$GT" | base64 -w0)"
git -c http.extraHeader="$AUTH" fetch -q origin main || { echo "git fetch failed"; exit 1; }
git show origin/main:deploy/autodeploy.sh > /usr/local/sbin/honeymo-autodeploy.sh.dl && install -m 755 /usr/local/sbin/honeymo-autodeploy.sh.dl /usr/local/sbin/honeymo-autodeploy.sh && rm -f /usr/local/sbin/honeymo-autodeploy.sh.dl
cat > /etc/cron.d/honeymo-deploy <<'CRON'
# HoneyMo: rebuild the site when GitHub main changes (back-office saves land there)
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/2 * * * * root /usr/local/sbin/honeymo-autodeploy.sh >> /var/log/honeymo-deploy.log 2>&1
CRON
echo "installed: /usr/local/sbin/honeymo-autodeploy.sh, cron every 2 minutes"

say "3/5 blog helper scripts"
for f in heal diag; do curl -fsS "$RAW/$f.sh" -o /usr/local/sbin/niknegar-$f.sh.dl && mv /usr/local/sbin/niknegar-$f.sh.dl /usr/local/sbin/niknegar-$f.sh && chmod 755 /usr/local/sbin/niknegar-$f.sh && echo "$f ok"; done

say "4/5 first build (5–15 minutes; the site stays up)"
setsid nohup /usr/local/sbin/honeymo-autodeploy.sh >> /var/log/honeymo-deploy.log 2>&1 < /dev/null &
want=$(git rev-parse origin/main)
for i in $(seq 1 120); do
  sleep 10
  s=$(cat $ST/status.json 2>/dev/null)
  case "$s" in
    *'"state":"ok"'*"$want"*) echo; echo "build ok"; break ;;
    *'"state":"failed"'*"$want"*) echo; echo "BUILD FAILED: $s"; echo "the old version of the site is still running. Details: /var/lib/honeymo-deploy/last-build.log"; /usr/local/sbin/niknegar-diag.sh --force >/dev/null 2>&1; exit 1 ;;
  esac
  printf '.'; [ $((i % 30)) = 0 ] && printf ' %s min\n' $((i / 6))
done
grep -q "$want" $ST/deployed 2>/dev/null || { echo; echo "still building — it carries on in the background. Run this script again later to create the account."; exit 1; }

say "5/5 back-office account"
cd $SRC/deploy
read -rp "Your name (shown in the back office): " ADMIN_NAME </dev/tty
read -rp "Username (latin letters, e.g. mohammadreza): " ADMIN_USERNAME </dev/tty
while :; do
  read -rsp "Password (10+ characters, not shown): " ADMIN_PASSWORD </dev/tty; echo
  read -rsp "Password again: " P2 </dev/tty; echo
  [ "$ADMIN_PASSWORD" = "$P2" ] && [ ${#ADMIN_PASSWORD} -ge 10 ] && break
  echo "not the same, or shorter than 10 — try again"
done
unset P2
export ADMIN_NAME ADMIN_USERNAME ADMIN_PASSWORD ADMIN_OWNER=1
docker compose exec -T -e ADMIN_NAME -e ADMIN_USERNAME -e ADMIN_PASSWORD -e ADMIN_OWNER api node dist/admin-user.js
unset ADMIN_PASSWORD

say "check"
echo "admin page: $(curl -s -o /dev/null -w '%{http_code}' https://honeymo.ir/admin/)   api: $(curl -s -o /dev/null -w '%{http_code}' https://honeymo.ir/api/admin/me) (401 = locked, as it should be)"
/usr/local/sbin/niknegar-heal.sh >> /var/log/niknegar-heal.log 2>&1
/usr/local/sbin/niknegar-diag.sh --force
echo; echo "Done. Open https://honeymo.ir/admin/"
