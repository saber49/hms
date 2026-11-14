#!/usr/bin/env bash
# Publish staging -> prod (code only) and verify the Controlling assets are live.
set -u
echo "=== DRY-RUN ==="; bash /var/www/publish-staging-to-prod.sh 2>&1 | grep -E '^>f'
echo "=== APPLY ===";   bash /var/www/publish-staging-to-prod.sh --apply 2>&1 | grep -E '^>f|PUBLISHED|Reloading|ERROR|error'
echo "=== prod verify ==="
V=$(grep -oE 'controlling-drilldown\.js\?v=[a-z0-9]+' /var/www/hms-frontend/index.php | sed 's/.*v=//')
echo "index.php cache-buster: $V"
curl -s -o /dev/null -w "drilldown.js over HTTPS: %{http_code}\n" "https://clood-x.com/assets/js/controlling/controlling-drilldown.js?v=$V"
echo -n "drilldown.js mounts on <body>: "; curl -s "https://clood-x.com/assets/js/controlling/controlling-drilldown.js?v=$V" | grep -c 'insertAdjacentHTML'
echo -n "octane: "; systemctl is-active hms-octane.service
bash /var/www/md5-controlling-files.sh prod
