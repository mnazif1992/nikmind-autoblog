#!/usr/bin/env bash
# HoneyMo — makes the private GitHub repo mnazif1992/honeymo-app the home of the app source.
# Pushes the current code in /srv/honeymo as the first commit (no secrets: .env files and build output are ignored),
# then keeps /srv/honeymo as a git checkout of it. Nothing in the running site changes.
set -uo pipefail
SRC=/srv/honeymo
REPO=mnazif1992/honeymo-app
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
AUTH="Authorization: Basic $(printf 'x-access-token:%s' "$GT" | base64 -w0)"
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $GT" https://api.github.com/repos/$REPO)
[ "$code" = 200 ] || { echo "The token cannot see $REPO yet (HTTP $code). Add honeymo-app to the token, then run this again."; exit 1; }
cd $SRC || exit 1
git config --global --get-all safe.directory | grep -qx "$SRC" || git config --global --add safe.directory "$SRC"
cp -a $SRC/deploy/.env /root/honeymo.env.bak 2>/dev/null
cat > .gitignore.nn <<'IGN'
node_modules/
dist/
dev-dist/
*.tsbuildinfo
.env
deploy/.env
apps/api/.env
deploy/queue/
.npm-cache/
.cache/
.DS_Store
._*
IGN
if [ -f .gitignore ]; then cat .gitignore.nn >> .gitignore; sort -u .gitignore -o .gitignore; rm .gitignore.nn; else mv .gitignore.nn .gitignore; fi
if [ ! -d .git ]; then
  git init -q -b main
  git config user.name "HoneyMo server"; git config user.email "server@honeymo.ir"
fi
git add -A
git -c core.quotepath=off status --short | head -20
echo "files: $(git ls-files | wc -l)  — any .env tracked: $(git ls-files | grep -c '\.env$')"
git ls-files | grep -q '\.env$' && { echo "STOP: an .env file would be committed"; exit 1; }
git commit -qm "HoneyMo app as running on the server (first commit)" || true
git remote remove origin 2>/dev/null; git remote add origin https://github.com/$REPO.git
git -c http.extraHeader="$AUTH" push -u origin main 2>&1 | tail -3
git -c http.extraHeader="$AUTH" ls-remote origin 2>/dev/null | head -2
echo done
