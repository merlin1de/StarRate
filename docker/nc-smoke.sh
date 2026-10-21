#!/bin/bash
# Runtime-Smoke für eine NC-Version (Regression / Forward-Kompat).
# Aufruf im Container als root:  bash /tmp/nc-smoke.sh
# Voraussetzung: /tmp/img/*.jpg (ein paar Testbilder), nc-verify.sh lief vorher
# (legt User srtest + Guest-Share SRREGRESS an).
set +e
OCC="runuser -u www-data -- php /var/www/html/occ"
USER=srtest
PASS='SrTest#2026'
BASE="http://localhost"
H="Host: localhost"
OCS="OCS-APIRequest: true"   # Basic-Auth ohne Session → sonst CSRF-Check 412
LOG=/var/www/html/data/nextcloud.log
FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  OK   $1 ($2)"; else echo "  FAIL $1 (got $2, want $3)"; FAIL=1; fi; }

LOGBASE=$(wc -l < "$LOG" 2>/dev/null || echo 0)
echo -n "NC: "; $OCC status | grep versionstring
echo -n "StarRate: "; $OCC config:app:get starrate installed_version

echo "=== Upload Testbilder → /SRSmoke ==="
curl -s -o /dev/null -u "$USER:$PASS" -H "$H" -X MKCOL "$BASE/remote.php/dav/files/$USER/SRSmoke"
for f in /tmp/img/*.jpg; do
  c=$(curl -s -o /dev/null -w "%{http_code}" -u "$USER:$PASS" -H "$H" -T "$f" "$BASE/remote.php/dav/files/$USER/SRSmoke/$(basename "$f")")
  echo "  PUT $(basename "$f") → $c"
done

echo "=== Owner: Gallery-Seite + Bilder-API ==="
c=$(curl -s -o /tmp/idx.html -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/")
check "GET /apps/starrate/" "$c" 200
c=$(curl -s -o /tmp/imgs.json -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/images?path=/SRSmoke")
check "GET /api/images?path=/SRSmoke" "$c" 200
N=$(ls /tmp/img/*.jpg | wc -l)
GOT=$(php -r '$d=json_decode(file_get_contents("/tmp/imgs.json"),true); echo count($d["images"] ?? []);')
check "Anzahl Bilder" "$GOT" "$N"
FID=$(php -r '$d=json_decode(file_get_contents("/tmp/imgs.json"),true); echo $d["images"][0]["id"] ?? "";')
echo "  fileId[0]=$FID"

echo "=== Rating setzen + lesen ==="
c=$(curl -s -o /tmp/set.json -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" -H "Content-Type: application/json" \
  -X POST -d '{"rating":4,"color":"Green","pick":"pick"}' "$BASE/apps/starrate/api/rating/$FID")
check "POST /api/rating/{id}" "$c" 200
curl -s -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/rating/$FID" > /tmp/get.json
R=$(php -r '$d=json_decode(file_get_contents("/tmp/get.json"),true); echo ($d["rating"]??"?")."/".($d["color"]??"?")."/".($d["pick"]??"?");')
check "GET /api/rating/{id}" "$R" "4/Green/pick"

echo "=== Thumbnail + Preview ==="
c=$(curl -s -o /tmp/t.jpg -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/thumbnail/$FID")
check "GET /api/thumbnail/{id}" "$c" 200
c=$(curl -s -o /tmp/p.jpg -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/preview/$FID")
check "GET /api/preview/{id}" "$c" 200
c=$(curl -s -o /tmp/d.jpg -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/download/$FID")
check "GET /api/download/{id}" "$c" 200

echo "=== Share-API (Owner) ==="
c=$(curl -s -o /tmp/sl.json -w "%{http_code}" -u "$USER:$PASS" -H "$H" -H "$OCS" "$BASE/apps/starrate/api/share")
check "GET /api/share" "$c" 200

echo "=== Gast: Seite + Bilder + Rating ==="
c=$(curl -s -o /tmp/gv.html -w "%{http_code}" -H "$H" "$BASE/apps/starrate/guest/SRREGRESS")
check "GET /guest/{token}" "$c" 200
c=$(curl -s -o /tmp/gi.json -w "%{http_code}" -H "$H" "$BASE/apps/starrate/api/guest/SRREGRESS/images?path=/SRSmoke")
check "GET /api/guest/{token}/images" "$c" 200
c=$(curl -s -o /tmp/gt.jpg -w "%{http_code}" -H "$H" "$BASE/apps/starrate/api/guest/SRREGRESS/thumbnail/$FID")
check "GET /api/guest/{token}/thumbnail/{id}" "$c" 200

echo "=== Neue Fehler im nextcloud.log (Level >= 3) ==="
tail -n +$((LOGBASE+1)) "$LOG" 2>/dev/null > /tmp/newlog.txt
ERR=$(php -r '$n=0; foreach (file("/tmp/newlog.txt") as $l) { $j=json_decode($l,true); if (($j["level"]??0)>=3) { $n++; echo "  ".substr(($j["app"]??"")." | ".($j["message"]??""),0,220)."\n"; } } fwrite(STDERR,$n);' 2>/tmp/errcount)
echo "$ERR"
check "Fehler im Log" "$(cat /tmp/errcount)" 0

echo "=== Ergebnis ==="
[ $FAIL = 0 ] && echo "SMOKE PASS" || echo "SMOKE FAIL"
