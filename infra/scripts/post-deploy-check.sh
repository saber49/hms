#!/usr/bin/env bash
# Post-deploy health check for PRODUCTION after publishing the Controlling release.
# (demo-login is staging-only, so prod API calls are expected to answer 401 â€”
#  that still proves routing + auth middleware work.)
set -u
echo "--- services ---"; systemctl is-active hms-octane.service hms-queue.service
echo "--- demo-login on prod (expected: disabled) ---"; curl -s -X POST https://clood-x.com/api/auth/demo-login | head -c 120; echo
echo "--- unauthenticated probe of new routes (expect 401, NOT 404/500) ---"
for p in /api/controlling/dashboard /api/controlling/mitarbeiter-options /api/controlling/mitarbeiter/1 /api/controlling/drilldown/kunde/X; do
  printf '%-45s -> ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://clood-x.com$p"
done
echo "--- prod route table ---"
cd /var/www/hms-backend && sudo -u www-data env HOME=/var/www/hms-backend/storage/frankenphp php artisan route:list --path=controlling 2>&1 | grep -cE 'controlling/'
echo "--- frontend: script tags in prod index.php ---"
grep -oE 'controlling[^"]*\?v=[a-z0-9]+' /var/www/hms-frontend/index.php | sort -u
echo "--- static JS reachable over HTTPS ---"
for f in controlling-core controlling-widgets controlling-drilldown controlling-worker; do
  printf '%-22s -> ' "$f"; curl -s -o /dev/null -w '%{http_code} %{size_download}B\n' "https://clood-x.com/assets/js/controlling/$f.js?v=20260908drill1"
done
printf '%-22s -> ' "controlling.css"; curl -s -o /dev/null -w '%{http_code} %{size_download}B\n' "https://clood-x.com/assets/css/controlling.css?v=20260908drill1"
echo "--- prod laravel.log: ERROR lines from today ---"
grep -h "$(date +%Y-%m-%d)" /var/www/hms-backend/storage/logs/laravel.log 2>/dev/null | grep -c ERROR
echo "--- octane.err.log tail ---"; tail -n 3 /var/www/hms-backend/storage/logs/octane.err.log 2>/dev/null
