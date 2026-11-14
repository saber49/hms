#!/usr/bin/env bash
# Staging: lint, run tenant migration, reload, verify /workers exposes app_*_login and backfill works.
set -u
BE=/var/www/hms-backend-staging
chown -R www-data:www-data $BE/app $BE/database /var/www/hms-frontend-staging
for f in app/Http/Controllers/Api/WorkerController.php app/Http/Controllers/Api/WorkerAppAuthController.php app/Models/Tenant/Mitarbeiter.php database/migrations/tenant/2026_09_08_120000_add_app_login_tracking_to_mitarbeiter.php; do php -l $BE/$f; done
cd $BE
echo "--- tenant:migrate (staging) ---"
sudo -u www-data env HOME=$BE/storage/frankenphp php artisan tenant:migrate 2>&1 | grep -iE 'app_login|migrat|error|tenant' | head -n 20
sudo -u www-data env HOME=$BE/storage/frankenphp php artisan octane:reload >/dev/null 2>&1; sleep 2
BASE="https://staging.clood-x.com"
TOKEN=$(curl -s -X POST "$BASE/api/auth/demo-login" | sed -E 's/.*"token":"([^"]+)".*/\1/'); H="Authorization: Bearer $TOKEN"
echo "--- /workers fields ---"
curl -s "$BASE/api/workers?aktiv=1" -H "$H" | python3 -c "
import json,sys; d=json.load(sys.stdin)
print('workers:',len(d),'| has app_last_login key:', all('app_last_login' in w for w in d))
for w in d[:8]: print('  ',w['dienst_nr'],w['vorname'],w['name'],'| app_zugang',w.get('app_zugang'),'| first',w.get('app_first_login'),'| last',w.get('app_last_login'))
"
echo "--- worker-app login stamps the columns? (demo worker) ---"
ENV_PW=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'); ENV_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"')
DB=$(mysql -u"$ENV_USER" -p"$ENV_PW" -N -e "SELECT \`database\` FROM hms_central_staging.tenants WHERE slug LIKE '%demo%' OR name LIKE '%Demo%' LIMIT 1" 2>/dev/null)
echo "demo db: ${DB:-<none>}"
[ -n "$DB" ] && mysql -u"$ENV_USER" -p"$ENV_PW" "$DB" -e "SELECT dienst_nr, email, app_zugang, app_first_login, app_last_login FROM mitarbeiter WHERE app_zugang=1 LIMIT 5" 2>/dev/null
echo "--- ERRORs today ---"; grep -h "^\[$(date +%Y-%m-%d)" storage/logs/laravel.log 2>/dev/null | grep -c ERROR
