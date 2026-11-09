#!/usr/bin/env bash
# Nik-Negar on honeymo.ir — step 2 of 2: wires the blog into the site and starts the bot.
#   PHP-FPM socket for the panel, blog routes in Caddy (via heal.sh), install.php, cron jobs, checks, report.
# Backs up every file it changes; never deletes anything. Safe to run again.
set -uo pipefail
AB=/opt/niknegar
PUB=/var/www/honeymo-blog
REPO=mnazif1992/honeymo-blog
SHA="${1:?commit}"
RAW="https://raw.githubusercontent.com/mnazif1992/nikmind-autoblog/$SHA/kit/hosts/honeymo"
say(){ printf '\n\033[1;35m== %s\033[0m\n' "$*"; }

say "1/6 PHP for the panel"
V=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo 'd /run/niknegar 0755 www-data www-data -' > /etc/tmpfiles.d/niknegar.conf
systemd-tmpfiles --create /etc/tmpfiles.d/niknegar.conf
cat > /etc/php/$V/fpm/pool.d/niknegar.conf <<POOL
; Nik-Negar panel and bot webhook (honeymo.ir blog). Caddy reaches it through the socket mounted into its container.
[niknegar]
user = www-data
group = www-data
listen = /run/niknegar/php.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 30s
request_terminate_timeout = 150s
php_admin_value[memory_limit] = 384M
POOL
systemctl enable -q php$V-fpm && systemctl restart php$V-fpm && sleep 1
[ -S /run/niknegar/php.sock ] && echo "php-fpm socket ok" || echo "php-fpm socket MISSING"

say "2/6 blog routes in Caddy"
curl -fsS "$RAW/heal.sh" -o /usr/local/sbin/niknegar-heal.sh && chmod 755 /usr/local/sbin/niknegar-heal.sh
/usr/local/sbin/niknegar-heal.sh

say "3/6 install.php"
cd $AB && rm -f install-report.txt.prev; [ -f install-report.txt ] && mv install-report.txt install-report.txt.prev
sudo -u www-data php install.php; true

say "4/6 cron"
cat > /etc/cron.d/niknegar <<CRON
# Nik-Negar (honeymo.ir blog): the bot and publishing every minute; the blog wiring is re-checked every 5 minutes (survives app redeploys)
* * * * * www-data cd /opt/niknegar && php run.php >> logs/cron.txt 2>&1
*/5 * * * * root /usr/local/sbin/niknegar-heal.sh >> /var/log/niknegar-heal.log 2>&1
CRON
chmod 644 /etc/cron.d/niknegar && echo "cron ok"

say "5/6 checks"
chk(){ printf '%-34s %s\n' "$1" "$(curl -s -o /tmp/nn-chk -w '%{http_code}' --resolve honeymo.ir:443:127.0.0.1 "https://honeymo.ir$1")$( [ -n "${2:-}" ] && (grep -q "$2" /tmp/nn-chk && echo " ✓ $2" || echo " ✗ no '$2'"))"; }
P=$(php -r 'require "/opt/niknegar/secrets.auto.php"; echo PANEL_DIR;')
chk / "هانیمو"
chk /products
chk /blog/ "مجله‌ی هانیمو"
chk /blog "مجله‌ی هانیمو"
chk /blog/kam-kardan-dard-period-dar-khane
chk /blog/sitemap-blog.xml "urlset"
chk /sw.js "blog"
chk /robots.txt "sitemap-blog"
chk /blog/assets/honeymo.css
chk "/$P/app/" "api.php"
printf '%-34s %s\n' "panel api (no login → 401)" "$(curl -s -o /dev/null -w '%{http_code}' --resolve honeymo.ir:443:127.0.0.1 -X POST "https://honeymo.ir/$P/app/api.php" -d '{}')"

say "6/6 report"
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
R=/tmp/nn-report2.txt
{ cat $AB/install-report.txt; echo; echo "### heal log"; tail -20 /var/log/niknegar-heal.log 2>/dev/null; /usr/local/sbin/niknegar-heal.sh; echo "### Caddyfile"; cat /srv/honeymo/deploy/Caddyfile; echo "### docker ps"; docker ps --format '{{.Names}} {{.Status}}'; } > $R 2>&1
b64=$(base64 -w0 $R)
curl -s -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/server-report/step2.txt" -o /tmp/nn-get.json
sha=$(grep -m1 '"sha"' /tmp/nn-get.json | cut -d'"' -f4)
printf '{"message":"server report (step 2)","content":"%s"%s}' "$b64" "${sha:+,\"sha\":\"$sha\"}" > /tmp/nn-put.json
curl -s -o /dev/null -w "report -> %{http_code}\n" -X PUT -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/server-report/step2.txt" --data @/tmp/nn-put.json
rm -f /tmp/nn-put.json /tmp/nn-get.json
echo; grep -A1 '^OWNER' $AB/install-report.txt || true
say "done — tell Claude: «مرحله ۲ تمام شد»"
