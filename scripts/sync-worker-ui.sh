#!/usr/bin/env bash
# ============================================================
# sync-worker-ui.sh — push the worker web UI from the single
# source of truth (clood-x-1/www) to every deployment target:
#
#   1. frontend/worker/    → the PWA at clood-x.com/worker/
#   2. clood-x-desktop/renderer/ → the Electron app content (sibling repo)
#
# The Capacitor apps (iOS + Android) consume clood-x-1/www
# directly as webDir — no copy needed there. The old
# hms/worker-app clone (PowerShell sync) was removed 2026-11-08.
#
# Workflow after editing app.js / app.css / etc. in
# ../clood-x-1/www:
#   1. bump CACHE_NAME in clood-x-1/www/sw.js (v73 → v74)
#   2. bash scripts/sync-worker-ui.sh
#   3. in frontend/worker/index.php bump ?v= for
#      app.js/app.css AND register('sw.js?v=N') — keep
#      N == CACHE_NAME number (script warns on mismatch)
#   4. deploy frontend/worker/ to the server
#   5. native builds pick changes up via `npx cap sync` /
#      Codemagic; desktop via `npm run dist` (runs this first)
# ============================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"                                   # hms-repo/
SOT="${WORKER_SOT:-$(cd "$ROOT/.." && pwd)/clood-x-1/www}"       # clood-x-1/www
PWA="$ROOT/frontend/worker"
DESK="${WORKER_DESK:-$(cd "$ROOT/.." && pwd)/clood-x-desktop/renderer}"

[ -d "$SOT" ] || { echo "source of truth missing: $SOT" >&2; exit 1; }

SHARED_ASSETS=(js/app.js js/call.js js/storage.js js/capacitor-bridge.js \
               js/qm-wachbuch.js js/qm-live.js js/checklisten.js \
               css/qm-wachbuch.css css/checklisten.css \
               css/app.css css/md3-tokens.css css/ms-icons.css \
               fonts/material-symbols-rounded.woff2 \
               icons/clood-x-logo.png icons/icon-192.png icons/icon-512.png)

copy_file() { # $1 rel path under www, $2 target root
    local src="$SOT/$1" dst="$2/$1"
    [ -f "$src" ] || { echo "  !! missing in source: $1" >&2; return 1; }
    mkdir -p "$(dirname "$dst")"
    cp -f "$src" "$dst"
}

echo "Source of truth : $SOT"
echo

# ── Target 1: PWA (frontend/worker) ──────────────────────
# PWA entry is index.php — index.html belongs to the native/
# desktop shells and is NOT synced here.
echo "→ $PWA"
for f in "${SHARED_ASSETS[@]}"; do copy_file "assets/$f" "$PWA"; done
copy_file sw.js "$PWA"
copy_file login.html "$PWA"
echo "  PWA: assets/ + sw.js + login.html synced"

# ── Target 2: Electron renderer (clood-x-desktop/renderer) ───────
echo "→ $DESK"
for f in "${SHARED_ASSETS[@]}"; do copy_file "assets/$f" "$DESK"; done
for f in sw.js login.html index.html; do copy_file "$f" "$DESK"; done
echo "  renderer: full www tree synced"

# ── Consistency check: sw.js?v=N must equal CACHE_NAME ───────
echo
cn="$(sed -n "s/.*CACHE_NAME = '[^0-9]*\([0-9]*\)'.*/\1/p" "$SOT/sw.js" | head -1)"
rv="$(sed -n "s/.*register('sw\.js?v=\([0-9]*\)'.*/\1/p" "$PWA/index.php" 2>/dev/null | head -1)"
echo "CACHE_NAME = v${cn:-?} · index.php registers sw.js?v=${rv:-?}"
if [ -n "$cn" ] && [ -n "$rv" ] && [ "$cn" != "$rv" ]; then
    echo "  !! MISMATCH — Cloudflare pins /worker/sw.js?v=$rv: bump the" >&2
    echo "     register('sw.js?v=N') in worker/index.php to v$cn" >&2
fi

echo
echo "Done. Reminders: bump ?v= in worker/index.php · deploy worker/ · rebuild native+desktop apps"
