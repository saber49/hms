#!/bin/bash
# ============================================================
# Deploy Clood-X Desktop installer to production
# ------------------------------------------------------------
# Uploads first (from the dev machine):
#   scp dist/Clood-X-Setup-*.exe dist/Clood-X-Setup-*.blockmap dist/latest.yml root@SERVER:/tmp/
# Then on the server:
#   bash /tmp/deploy-desktop.sh
#
# Installs into /var/www/hms-frontend/desktop/ → served at
# https://clood-x.com/desktop/ (see the /desktop/ location in
# /etc/nginx/sites-available/clood-x.com).
#
# Files:
#   Clood-X-Setup-<ver>.exe        versioned installer (auto-update target)
#   Clood-X-Setup-<ver>.exe.blockmap  differential-update map
#   latest.yml                     update manifest (electron-updater)
#   Clood-X-Setup.exe              STABLE filename copy → the website
#                                  download link never breaks on updates
# ============================================================
set -euo pipefail

DST=/var/www/hms-frontend/desktop

install -d -o www-data -g www-data -m 755 "$DST"

# ---- Windows artifacts ----
exe=$(ls /tmp/Clood-X-Setup-*.exe 2>/dev/null | grep -v '\.blockmap' | head -1)
if [ -n "$exe" ]; then
    # Remove previous versioned installers (keep the directory tidy);
    # latest.yml always points at the new one.
    rm -f "$DST"/Clood-X-Setup-*.exe "$DST"/Clood-X-Setup-*.blockmap

    install -o www-data -g www-data -m 644 "$exe" "$DST/$(basename "$exe")"
    [ -f "$exe.blockmap" ] && install -o www-data -g www-data -m 644 "$exe.blockmap" "$DST/$(basename "$exe").blockmap"
    install -o www-data -g www-data -m 644 /tmp/latest.yml "$DST/latest.yml"

    # Stable download name for the website link
    install -o www-data -g www-data -m 644 "$exe" "$DST/Clood-X-Setup.exe"

    rm -f /tmp/Clood-X-Setup-*.exe /tmp/Clood-X-Setup-*.blockmap /tmp/latest.yml
    echo "--- windows deployed: $(basename "$exe")"
fi

# ---- macOS artifacts ----
dmg=$(ls /tmp/Clood-X-*.dmg 2>/dev/null | head -1)
zip=$(ls /tmp/Clood-X-*.zip 2>/dev/null | head -1)
if [ -n "$dmg" ] || [ -n "$zip" ]; then
    rm -f "$DST"/Clood-X-*.dmg "$DST"/Clood-X-*.zip "$DST"/Clood-X-*.blockmap

    for f in /tmp/Clood-X-*.dmg /tmp/Clood-X-*.zip /tmp/Clood-X-*.dmg.blockmap /tmp/Clood-X-*.zip.blockmap; do
        [ -f "$f" ] || continue
        install -o www-data -g www-data -m 644 "$f" "$DST/$(basename "$f")"
    done
    [ -f /tmp/latest-mac.yml ] && install -o www-data -g www-data -m 644 /tmp/latest-mac.yml "$DST/latest-mac.yml"

    # Stable download name for the website link (universal dmg)
    [ -n "$dmg" ] && install -o www-data -g www-data -m 644 "$dmg" "$DST/Clood-X.dmg"

    rm -f /tmp/Clood-X-*.dmg /tmp/Clood-X-*.zip /tmp/Clood-X-*.blockmap /tmp/latest-mac.yml
    echo "--- mac deployed: $(basename "${dmg:-$zip}")"
fi

if [ -z "${exe:-}" ] && [ -z "${dmg:-}" ] && [ -z "${zip:-}" ]; then
    echo "ERROR: no artifacts found in /tmp (expected Clood-X-Setup-*.exe or Clood-X-*.dmg/.zip)"
    exit 1
fi

echo "=== deployed ==="
ls -la "$DST"
echo ""
echo "=== verify ==="
curl -sI "https://clood-x.com/desktop/latest.yml" | head -3
curl -sI "https://clood-x.com/desktop/Clood-X-Setup.exe" | head -3
[ -f "$DST/latest-mac.yml" ] && curl -sI "https://clood-x.com/desktop/latest-mac.yml" | head -3
[ -f "$DST/Clood-X.dmg" ] && curl -sI "https://clood-x.com/desktop/Clood-X.dmg" | head -3
