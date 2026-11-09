#!/usr/bin/env bash
# Read-only: bundles the HoneyMo source files about products, prices and orders into one text file
# in the private content repo (server-report/shop-src.txt) so the back office can be designed against the real code.
set -uo pipefail
REPO=mnazif1992/honeymo-blog; SRC=/srv/honeymo; R=/tmp/nn-shop-src.txt
cd $SRC || exit 1
{
  echo "### tree"; find . \( -name node_modules -o -name dist -o -name .git -o -name dev-dist \) -prune -o -type f -print | grep -v '\.\(png\|jpg\|webp\|woff2\|ico\)$' | sort
  echo; echo "### grep products.json / price / stock"
  grep -rn --include='*.ts' --include='*.tsx' --include='*.mjs' --include='*.js' --include='*.json' -l 'products.json\|price\|stock' apps packages scripts 2>/dev/null | grep -v node_modules | grep -v dist/ | head -60
  for f in package.json scripts/* packages/shared/package.json $(find packages/shared/src -type f | sort) \
           $(grep -rln --include='*.ts' 'product\|order\|price' apps/api/src 2>/dev/null | grep -v test | sort) \
           apps/api/package.json apps/web/src/features/shop/content.ts apps/web/src/features/shop/ProductPage.tsx apps/web/src/features/shop/ProductPhoto.tsx \
           apps/web/src/ui/Photo.tsx apps/web/src/generated/products.json apps/web/index.html $(ls apps/web/scripts/* 2>/dev/null) $(ls content 2>/dev/null | head -0); do
    [ -f "$f" ] || continue
    sz=$(wc -c < "$f"); [ "$sz" -gt 120000 ] && { echo; echo "### FILE $f (skipped, $sz bytes)"; continue; }
    echo; echo "### FILE $f"; cat "$f"
  done
  echo; echo "### public dirs"; ls -la apps/web/public apps/web/public/shop 2>/dev/null | head -80
  echo; echo "### content dirs"; ls -la content apps/web/content 2>/dev/null | head -40
} > $R 2>&1
GT=$(php -r 'require "/opt/niknegar/config.php"; echo GH_TOKEN;')
p=server-report/shop-src.txt
curl -s -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$p" -o /tmp/nn-get.json
sha=$(grep -m1 '"sha"' /tmp/nn-get.json | cut -d'"' -f4)
printf '{"message":"shop source for review","content":"%s"%s}' "$(base64 -w0 $R)" "${sha:+,\"sha\":\"$sha\"}" > /tmp/nn-put.json
curl -s -o /dev/null -w "shop-src ($(wc -c < $R) bytes) -> %{http_code}\n" -X PUT -H "Authorization: Bearer $GT" "https://api.github.com/repos/$REPO/contents/$p" --data @/tmp/nn-put.json
rm -f /tmp/nn-put.json /tmp/nn-get.json
echo done
