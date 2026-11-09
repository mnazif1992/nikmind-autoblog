#!/usr/bin/env bash
# Nik-Negar on honeymo.ir — keeps the blog wired into the HoneyMo stack. Runs from root's cron every 5 minutes and is safe to run any time.
# The app is redeployed from the Mac with push.sh, which replaces /srv/honeymo; this puts the blog parts back right after:
#   1. Caddy: serve /blog/ pages and the panel from /var/www/honeymo-blog when the file exists (everything else still goes to the app)
#   2. docker-compose: mount that folder and the PHP socket into the caddy container
#   3. the app's service worker: let /blog and the panel load from the network instead of the cached app shell
#   4. the app's stylesheet and robots.txt, copied next to the blog pages
# Never deletes anything; every changed file is backed up to /opt/niknegar/backups/heal/ first.
set -uo pipefail
DEPLOY=/srv/honeymo/deploy
PUB=/var/www/honeymo-blog
BK=/opt/niknegar/backups/heal
WEB=honeymo-web-1
HTML=/usr/share/nginx/html
MARK='# nik-negar blog'
mkdir -p "$BK" /run/niknegar "$PUB/blog/assets"
changed=0; recreate=0
log(){ echo "$(date '+%F %T') $*"; }
backup(){ [ -f "$1" ] && cp "$1" "$BK/$(basename "$1").$(date +%s)"; }

# 1. Caddyfile
CF=$DEPLOY/Caddyfile
if [ -f "$CF" ] && ! grep -q "$MARK" "$CF"; then
  if grep -qE '^\s*reverse_proxy web:80\s*$' "$CF"; then
    backup "$CF"
    awk -v mark="$MARK" '
      /^[[:space:]]*reverse_proxy web:80[[:space:]]*$/ && !done {
        print "\t" mark " (added by /usr/local/sbin/niknegar-heal.sh; keep this block when editing)"
        print "\t@nn_php path_regexp ^/tg-[0-9a-f]+/(hook\\.php|app/api\\.php)$"
        print "\thandle @nn_php {"
        print "\t\troot * /var/www/honeymo-blog"
        print "\t\tphp_fastcgi unix//run/niknegar/php.sock"
        print "\t}"
        print "\t@nn_file {"
        print "\t\tnot path /"
        print "\t\tfile {"
        print "\t\t\troot /var/www/honeymo-blog"
        print "\t\t\ttry_files {path} {path}/index.html"
        print "\t\t}"
        print "\t}"
        print "\thandle @nn_file {"
        print "\t\troot * /var/www/honeymo-blog"
        print "\t\trewrite * {file_match.relative}"
        print "\t\tfile_server"
        print "\t}"
        print "\t# end nik-negar blog"
        print "\thandle {"
        print "\t\treverse_proxy web:80"
        print "\t}"
        done = 1; next
      }
      { print }' "$CF" > "$CF.nn" && cat "$CF.nn" > "$CF" && rm -f "$CF.nn"
    log "Caddyfile: blog routes added"; changed=1; recreate=1
  else
    log "Caddyfile: 'reverse_proxy web:80' line not found — not changed, check by hand"
  fi
fi

# 2. docker-compose mounts
DC=$DEPLOY/docker-compose.yml
if [ -f "$DC" ] && ! grep -q '/var/www/honeymo-blog' "$DC"; then
  if grep -qE '^\s*- caddy-config:/config\s*$' "$DC"; then
    backup "$DC"
    awk '{ print } /^[[:space:]]*- caddy-config:\/config[[:space:]]*$/ && !done { match($0, /^[[:space:]]*/); ind = substr($0, 1, RLENGTH);
           print ind "- /var/www/honeymo-blog:/var/www/honeymo-blog:ro   # nik-negar blog"; print ind "- /run/niknegar:/run/niknegar   # nik-negar panel (PHP)"; done = 1 }' "$DC" > "$DC.nn" && cat "$DC.nn" > "$DC" && rm -f "$DC.nn"
    log "docker-compose: blog mounts added"; changed=1; recreate=1
  else
    log "docker-compose: caddy volumes line not found — not changed"
  fi
fi

# validate before touching the running proxy
if [ $recreate = 1 ]; then
  if docker run --rm -e DOMAIN=honeymo.ir -e ACME_EMAIL=x@example.com -v "$CF":/etc/caddy/Caddyfile:ro caddy:2-alpine caddy validate --config /etc/caddy/Caddyfile >/tmp/nn-caddy-validate.txt 2>&1; then
    (cd "$DEPLOY" && docker compose up -d caddy >/tmp/nn-caddy-up.txt 2>&1) && log "caddy restarted with the blog routes" || log "caddy restart FAILED: $(tail -3 /tmp/nn-caddy-up.txt)"
  else
    log "Caddyfile did not validate, restoring backup: $(tail -3 /tmp/nn-caddy-validate.txt)"
    last=$(ls -t "$BK"/Caddyfile.* 2>/dev/null | head -1); [ -n "$last" ] && cat "$last" > "$CF"
  fi
fi

# 3 + 4. assets copied from the running app container (only when they changed)
if docker inspect "$WEB" >/dev/null 2>&1; then
  sw=$(docker exec "$WEB" cat $HTML/sw.js 2>/dev/null || true)
  if [ -n "$sw" ]; then
    patched=$(printf '%s' "$sw" | sed 's#denylist:\[/^\\/api\\//\]#denylist:[/^\\/api\\//,/^\\/blog/,/^\\/tg-/]#')
    if [ "$patched" != "$(cat "$PUB/sw.js" 2>/dev/null)" ]; then
      if [ "$patched" != "$sw" ]; then printf '%s' "$patched" > "$PUB/sw.js"; log "sw.js: /blog and panel excluded from the app shell"
      else log "sw.js: denylist pattern not found — not overriding"; fi
    fi
  fi
  css=$(docker exec "$WEB" sh -c "ls $HTML/assets/main-*.css 2>/dev/null | head -1")
  if [ -n "$css" ]; then
    docker exec "$WEB" cat "$css" > /tmp/nn-css && if ! cmp -s /tmp/nn-css "$PUB/blog/assets/honeymo.css"; then cp /tmp/nn-css "$PUB/blog/assets/honeymo.css"; log "stylesheet copied ($(basename "$css"))"; fi
  fi
  robots=$(docker exec "$WEB" cat $HTML/robots.txt 2>/dev/null || true)
  if [ -n "$robots" ]; then
    want=$(printf '%s\n' "$robots" | grep -v 'blog/sitemap-blog.xml'; printf 'Sitemap: https://honeymo.ir/blog/sitemap-blog.xml\n')
    [ "$want" != "$(cat "$PUB/robots.txt" 2>/dev/null)" ] && { printf '%s\n' "$want" > "$PUB/robots.txt"; log "robots.txt updated"; }
  fi
fi
# 5. the app's «مجله» tab: open the static magazine page with a normal page load instead of the app's own old list.
#    One line in apps/web/src/app/Link.tsx, then the web image is rebuilt in the background (the running site stays up meanwhile).
LINK=/srv/honeymo/apps/web/src/app/Link.tsx
need_build=0
if [ -f "$LINK" ] && ! grep -q 'nik-negar' "$LINK"; then
  if grep -q '^    e.preventDefault();$' "$LINK"; then
    backup "$LINK"
    php -r '$f=$argv[1]; $c=file_get_contents($f); $c=preg_replace("/^    e\.preventDefault\(\);$/m", "    // nik-negar: the magazine index is a static page now; open it with a normal page load\n    if (/^\\/blog\\/?$/.test(href)) return;\n    e.preventDefault();", $c, 1); file_put_contents($f, $c);' "$LINK"
    log "app Link.tsx: /blog opens the static magazine"; need_build=1
  else
    log "app Link.tsx: expected line not found — not changed"
  fi
fi
if [ -f "$LINK" ] && grep -q 'nik-negar' "$LINK" && docker inspect "$WEB" >/dev/null 2>&1; then
  if ! docker exec "$WEB" sh -c "grep -lF '^\/blog\/?\$/' $HTML/assets/*.js" >/dev/null 2>&1; then need_build=1; fi
fi
if [ $need_build = 1 ]; then
  last=$(cat /var/lib/niknegar-webbuild.last 2>/dev/null || echo 0)
  if [ -f /run/niknegar/webbuild.pid ] && kill -0 "$(cat /run/niknegar/webbuild.pid)" 2>/dev/null; then :
  elif [ $(( $(date +%s) - last )) -lt 21600 ] && [ "${1:-}" != "--build-now" ]; then log "web rebuild needed, but one was tried less than 6 h ago"
  else
    date +%s > /var/lib/niknegar-webbuild.last
    setsid nohup bash -c "echo \"=== \$(date -Is) build web\"; cd $DEPLOY && docker compose build web && docker compose up -d web && echo BUILD-OK || echo BUILD-FAILED" >> /var/log/niknegar-webbuild.log 2>&1 < /dev/null &
    echo $! > /run/niknegar/webbuild.pid
    log "web rebuild started in the background (log: /var/log/niknegar-webbuild.log)"
  fi
fi

chown -R www-data:www-data "$PUB" 2>/dev/null
exit 0
