#!/usr/bin/env bash
# Verify the Objekt-Kontakt autocomplete endpoint on staging.
set -u
BE=/var/www/hms-backend-staging
chown -R www-data:www-data $BE/app $BE/routes /var/www/hms-frontend-staging
php -l $BE/app/Http/Controllers/Api/ObjectController.php
php -l $BE/routes/api.php
cd $BE && sudo -u www-data env HOME=$BE/storage/frankenphp php artisan octane:reload >/dev/null 2>&1; sleep 2
sudo -u www-data env HOME=$BE/storage/frankenphp php artisan route:list --path=objects 2>&1 | grep -E 'objects'
BASE="https://staging.clood-x.com"
TOKEN=$(curl -s -X POST "$BASE/api/auth/demo-login" | sed -E 's/.*"token":"([^"]+)".*/\1/')
H="Authorization: Bearer $TOKEN"
q() { printf '%-60s -> ' "$1"; curl -s -o /tmp/r.json -w '%{http_code} ' "$BASE$1" -H "$H"; head -c 300 /tmp/r.json; echo; }
q "/api/objects/contacts"
q "/api/objects/contacts?q=so"
q "/api/objects/contacts?q=zzzz_nomatch"
KID=$(curl -s "$BASE/api/objects?aktiv=1" -H "$H" | python3 -c "import json,sys;d=json.load(sys.stdin);print(d[0]['kunden_id'] if d else '')")
[ -n "$KID" ] && q "/api/objects/contacts?kunden_id=$KID&limit=3"
q "/api/objects/O001"
echo "--- new ERRORs ---"; grep -h "$(date +%Y-%m-%d)" $BE/storage/logs/laravel.log 2>/dev/null | grep -c ERROR
