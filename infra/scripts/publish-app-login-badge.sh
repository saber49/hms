#!/usr/bin/env bash
# Publish the app-login badge release + run the new tenant migration on PROD.
set -u
echo "=== DRY-RUN ==="; bash /var/www/publish-staging-to-prod.sh 2>&1 | grep -E '^>f'
echo "=== APPLY ===";   bash /var/www/publish-staging-to-prod.sh --apply 2>&1 | grep -E '^>f|PUBLISHED|Reloading|ERROR'
BE=/var/www/hms-backend
echo "=== prod tenant:migrate ==="
cd $BE && sudo -u www-data env HOME=$BE/storage/frankenphp php artisan tenant:migrate 2>&1 | grep -iE 'app_login|Migrating|Nothing|error|complete' | head -n 40
sudo -u www-data env HOME=$BE/storage/frankenphp php artisan octane:reload >/dev/null 2>&1
echo "=== prod verify ==="
echo -n "octane: "; systemctl is-active hms-octane.service
curl -s -o /dev/null -w "workers.js: %{http_code}\n" "https://clood-x.com/assets/js/workers.js?v=20260908applogin1"
echo -n "badge code live: "; curl -s "https://clood-x.com/assets/js/workers.js?v=20260908applogin1" | grep -c 'nie angemeldet'
ENV_PW=$(grep -E '^DB_PASSWORD=' $BE/.env | cut -d= -f2- | tr -d '"'); ENV_USER=$(grep -E '^DB_USERNAME=' $BE/.env | cut -d= -f2- | tr -d '"')
echo "=== column present on every prod tenant? ==="
mysql -u"$ENV_USER" -p"$ENV_PW" -N -e "SELECT \`database\` FROM hms_central.tenants WHERE aktiv=1" 2>/dev/null | while read db; do
  c=$(mysql -u"$ENV_USER" -p"$ENV_PW" -N "$db" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$db' AND table_name='mitarbeiter' AND column_name='app_last_login'" 2>/dev/null)
  echo "  $db: app_last_login column = ${c:-?}"
done
echo "--- prod ERRORs today ---"; grep -h "^\[$(date +%Y-%m-%d)" $BE/storage/logs/laravel.log 2>/dev/null | grep -c ERROR
