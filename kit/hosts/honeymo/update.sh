#!/usr/bin/env bash
# Nik-Negar on honeymo.ir — refreshes the helper scripts (heal + diag) and the cron file, then sends a status report.
set -uo pipefail
SHA="${1:?commit}"
RAW="https://raw.githubusercontent.com/mnazif1992/nikmind-autoblog/$SHA/kit/hosts/honeymo"
for f in heal diag; do curl -fsS "$RAW/$f.sh" -o /usr/local/sbin/niknegar-$f.sh.dl && mv /usr/local/sbin/niknegar-$f.sh.dl /usr/local/sbin/niknegar-$f.sh && chmod 755 /usr/local/sbin/niknegar-$f.sh && echo "$f ok"; done
cat > /etc/cron.d/niknegar <<CRON
# Nik-Negar (honeymo.ir blog): the bot and publishing every minute; the blog wiring is re-checked every 5 minutes (survives app redeploys);
# a read-only status report goes to the private content repo when something changes
* * * * * www-data cd /opt/niknegar && php run.php >> logs/cron.txt 2>&1
*/5 * * * * root /usr/local/sbin/niknegar-heal.sh >> /var/log/niknegar-heal.log 2>&1; /usr/local/sbin/niknegar-diag.sh >/dev/null 2>&1
CRON
/usr/local/sbin/niknegar-heal.sh
/usr/local/sbin/niknegar-diag.sh --force
echo "done"
