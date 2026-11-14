#!/usr/bin/env bash
# Verify the new einnahmen_offen / *_erwartet KPI fields on staging.
set -u
BE=/var/www/hms-backend-staging
chown -R www-data:www-data $BE/app $BE/routes /var/www/hms-frontend-staging
php -l $BE/app/Http/Controllers/Api/ControllingController.php
php -l $BE/app/Http/Controllers/Api/ControllingDrilldownController.php
php -l $BE/app/Services/ControllingAggregationService.php
cd $BE && sudo -u www-data env HOME=$BE/storage/frankenphp php artisan octane:reload >/dev/null 2>&1; sleep 2
BASE="https://staging.clood-x.com"
TOKEN=$(curl -s -X POST "$BASE/api/auth/demo-login" | sed -E 's/.*"token":"([^"]+)".*/\1/'); H="Authorization: Bearer $TOKEN"
curl -s "$BASE/api/controlling/kpi?period=month" -H "$H" | python3 -c "import json,sys;k=json.load(sys.stdin)['kpi'];print('KPI month:',{x:k.get(x) for x in ('einsaetze_total','bestaetigt_count','ausstehend_count','einnahmen','gewinn','einnahmen_offen','kosten_offen','einnahmen_erwartet','gewinn_erwartet')})"
KID=$(curl -s "$BASE/api/controlling/breakdown/customers?period=year&limit=1" -H "$H" | python3 -c "import json,sys;d=json.load(sys.stdin);print(d['rows'][0]['dim_id'])")
curl -s "$BASE/api/controlling/drilldown/kunde/$KID?period=year" -H "$H" | python3 -c "import json,sys;d=json.load(sys.stdin);k=d['kpi'];print('DRILL',d['entity']['name'],':',{x:k.get(x) for x in ('einsaetze_total','bestaetigt_count','ausstehend_count','einnahmen','gewinn','einnahmen_offen','einnahmen_erwartet','gewinn_erwartet')})"
