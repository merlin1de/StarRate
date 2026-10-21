#!/bin/bash
# Verifiziert den NC34-Magic-Getter-Fix per Guest-Link.
# Aufruf im Container als root:  bash /tmp/nc-verify.sh [deploy|testonly]
# - deploy:   lokalen /tmp/starrate.tar.gz einspielen, dann testen
# - testonly: nur testen (für Baseline mit bereits installierter Version)
set +e
MODE="${1:-deploy}"
OCC="runuser -u www-data -- php /var/www/html/occ"
APPDIR=/var/www/html/custom_apps/starrate
USER=srtest
TOKEN=SRREGRESS

if [ "$MODE" = "deploy" ]; then
  echo "=== Deploy lokaler Build ==="
  rm -rf "$APPDIR"
  tar xzf /tmp/starrate.tar.gz -C /var/www/html/custom_apps/
  chown -R www-data:www-data "$APPDIR"
  $OCC upgrade >/dev/null 2>&1
  $OCC maintenance:mode --off >/dev/null 2>&1
fi

echo -n "installed_version: "; $OCC config:app:get starrate installed_version

echo "=== Setup: trusted domain + user + seeded share ==="
$OCC config:system:set trusted_domains 9 --value=localhost >/dev/null 2>&1
if ! $OCC user:info "$USER" >/dev/null 2>&1; then
  OC_PASS='SrTest#2026' $OCC user:add --password-from-env --display-name "SR Test" "$USER" >/dev/null 2>&1
fi
SHARE='{"'$TOKEN'":{"token":"'$TOKEN'","owner_id":"'$USER'","nc_path":"/","password_hash":null,"expires_at":null,"min_rating":0,"permissions":"view","active":true,"created_at":1700000000,"allow_download":false}}'
$OCC user:setting "$USER" starrate starrate_shares "$SHARE" >/dev/null 2>&1

echo "=== Test 1: guestView page  (getShare DB-Getter + guest.php Nonce-Getter) ==="
code=$(curl -s -o /tmp/gv.html -w "%{http_code}" -H "Host: localhost" "http://localhost/apps/starrate/guest/$TOKEN")
marker=$(grep -cE 'starrate://guest|intent://guest' /tmp/gv.html)
echo "  http=$code   guest-template-marker(starrate:// oder intent://guest)=$marker"

echo "=== Test 2: guestImages API (getShare + Session-Getter) ==="
code2=$(curl -s -o /tmp/gi.json -w "%{http_code}" -H "Host: localhost" "http://localhost/apps/starrate/api/guest/$TOKEN/images")
echo "  http=$code2"
echo -n "  body: "; head -c 200 /tmp/gi.json; echo

echo "=== Ergebnis ==="
if [ "$code" = "200" ] && [ "$marker" -ge 1 ] && [ "$code2" = "200" ]; then
  echo "PASS: beide Guest-Routen 200, Guest-Template gerendert (Nonce-Getter ok)"
else
  echo "FAIL: code=$code marker=$marker code2=$code2 (500 = Magic-Getter-Fatal)"
fi
