#!/usr/bin/env bash
# Diagnose nginx 404s hit by the worker app (iPhone PWA / Safari).
set -u
echo "=== nginx vhosts ==="; ls /etc/nginx/sites-enabled/
echo; echo "=== prod vhost: server_name / root / worker-app locations ==="
for f in /etc/nginx/sites-enabled/*; do echo "--- $f"; grep -nE 'server_name|root |location|try_files|return|rewrite' "$f" | grep -viE 'staging' | head -n 60; done
echo; echo "=== worker-app files on disk ==="
ls -la /var/www/hms-frontend/ | head -n 40
ls -la /var/www/hms-frontend/worker-app 2>/dev/null | head -n 30
ls -la /var/www/worker-app 2>/dev/null | head -n 20
echo; echo "=== last 404s in nginx access log (last 24h, top URIs) ==="
for log in /var/log/nginx/access.log /var/log/nginx/*access*.log; do [ -f "$log" ] || continue; echo "--- $log"; grep -h ' 404 ' "$log" | grep -E "$(date +%d/%b/%Y)|$(date -d yesterday +%d/%b/%Y)" | awk '{print $7}' | sort | uniq -c | sort -rn | head -n 25; done
echo; echo "=== 404s from iPhone UA (last 400 lines) ==="
grep -h ' 404 ' /var/log/nginx/access.log 2>/dev/null | grep -i iphone | tail -n 15 | cut -c1-260
echo; echo "=== nginx error.log tail ==="; tail -n 10 /var/log/nginx/error.log 2>/dev/null | cut -c1-300
