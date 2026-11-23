#!/usr/bin/env bash
# HMS deploy via git monorepo.
#
#   deploy/deploy.sh staging|prod [--force] [--dry]
#
# Flow (all on the server via `ssh hms`):
#   1. /var/www/hms-repo: git fetch + checkout origin/<branch>
#   2. DRIFT CHECK: live dirs are rsync-compared (checksum, dry-run) against the
#      LAST DEPLOYED commit — any file changed/added on the server outside git
#      aborts the deploy (pass --force to override after reviewing the list).
#   3. Backup of every replaced/deleted file to /root/deploy_backup_<env>_<ts>
#   4. rsync repo → /var/www/hms-{backend,frontend}(-staging) (checksum, --delete,
#      runtime paths excluded, ownership www-data)
#   5. php -l on changed PHP files, tenant:migrate on new migrations,
#      restart hms-octane(+hms-queue), record deployed sha.
set -euo pipefail
cd "$(dirname "$0")/.."

ENV="${1:-}"; shift || true
FORCE=0; DRY=0
for a in "$@"; do case "$a" in --force) FORCE=1;; --dry) DRY=1;; *) echo "unknown arg: $a"; exit 2;; esac; done
[ "$ENV" = staging ] || [ "$ENV" = prod ] || { echo "usage: $0 staging|prod [--force] [--dry]"; exit 2; }

ssh hms "ENV=$ENV FORCE=$FORCE DRY=$DRY bash -s" <<'REMOTE'
set -euo pipefail
REPO=/var/www/hms-repo
if [ "$ENV" = staging ]; then
  BRANCH=staging; SUF="-staging"; SVC="hms-octane-staging"; Q="hms-queue-staging"
else
  BRANCH=main;    SUF="";        SVC="hms-octane";          Q="hms-queue"
fi
BE=/var/www/hms-backend$SUF
FE=/var/www/hms-frontend$SUF
SHA=/var/www/.hms-deployed-$ENV
TS=$(date -u +%Y%m%d_%H%M%S)
BK=/root/deploy_backup_${ENV}_$TS

BE_X=(--exclude .env --exclude '.env.*' --exclude 'vendor/' --exclude 'node_modules/'
      --exclude 'storage/' --exclude 'bootstrap/cache/' --exclude public/storage
      --exclude frankenphp --exclude frankenphp-worker.php --exclude auth.json
      --exclude .phpunit.result.cache --exclude '*.log'
      --exclude '._*' --exclude .DS_Store --exclude '*.bak.*')
FE_X=(--exclude 'uploads/' --exclude 'desktop/' --exclude 'phpmyadmin/'
      --exclude '._*' --exclude .DS_Store --exclude '*.bak.*')

cd "$REPO"
git fetch -q origin
NEW=$(git rev-parse "origin/$BRANCH")
OLD=$(cat "$SHA" 2>/dev/null || git rev-parse HEAD)
echo "== $ENV: baseline ${OLD:0:8} -> deploy ${NEW:0:8} (origin/$BRANCH)"
# Move the mirror worktree to the target commit (read-only mirror — exact match)
git checkout -qB "$BRANCH" "origin/$BRANCH"

# --- 1) drift check: live vs last-deployed baseline --------------------------
BASE=$(mktemp -d /tmp/hms-base.XXXXXX)
LINT=$(mktemp /tmp/hms-lint.XXXXXX)
trap 'rm -rf "$BASE" "$LINT"' EXIT
git archive "$OLD" | tar -x -C "$BASE"

DRIFT=$( { rsync -rcn --delete -i "${BE_X[@]}" "$BASE/backend/"  "$BE/" || true; \
           rsync -rcn --delete -i "${FE_X[@]}" "$BASE/frontend/" "$FE/" || true; } )
if [ -n "$DRIFT" ]; then
  echo "!! DRIFT vs baseline $OLD (files changed on server outside git):"
  echo "$DRIFT" | head -60
  [ "$FORCE" = 1 ] || { echo "aborting (review, commit the drift, or rerun with --force)"; exit 1; }
  echo "--force: continuing anyway"
fi

# --- 2) preview of what this deploy changes ----------------------------------
CH_BE=$(rsync -rcn --delete -i "${BE_X[@]}" "$REPO/backend/"  "$BE/" || true)
CH_FE=$(rsync -rcn --delete -i "${FE_X[@]}" "$REPO/frontend/" "$FE/" || true)
N_BE=$(echo "$CH_BE" | grep -c . || true); N_FE=$(echo "$CH_FE" | grep -c . || true)
echo "== changes: $N_BE backend, $N_FE frontend"
echo "$CH_BE" | head -20; echo "$CH_FE" | head -20
if [ -z "$CH_BE$CH_FE" ]; then echo "nothing to deploy"; exit 0; fi
[ "$DRY" = 1 ] && { echo "--dry: stopping before apply"; exit 0; }

# --- 3) apply: backup replaced/deleted files, rsync with delete --------------
git checkout -qf "$NEW"
mkdir -p "$BK/be" "$BK/fe"
echo "$CH_BE" | awk '{sub(/^[^ ]+ +/,""); print}' | grep '\.php$' > "$LINT" || true
rsync -rc --delete --backup --backup-dir="$BK/be/" --chown=www-data:www-data "${BE_X[@]}" "$REPO/backend/"  "$BE/"
rsync -rc --delete --backup --backup-dir="$BK/fe/" --chown=www-data:www-data "${FE_X[@]}" "$REPO/frontend/" "$FE/"

# --- 4) lint + migrate + restart ----------------------------------------------
if [ -n "$CH_BE" ]; then
  while IFS= read -r f; do
    [ -f "$BE/$f" ] && php -l "$BE/$f" >/dev/null || { [ ! -f "$BE/$f" ] || { echo "LINT FAIL $f"; exit 1; }; }
  done < "$LINT"
  echo "php lint ok"
  if echo "$CH_BE" | grep -q 'database/migrations'; then
    (cd "$BE" && sudo -u www-data env HOME="$BE/storage/frankenphp" php artisan tenant:migrate 2>&1 | tail -15)
  fi
  if echo "$CH_BE" | grep -qE 'composer\.(json|lock)'; then
    echo "!! composer.json/lock changed — run composer install on the server as www-data"
  fi
  systemctl restart "$SVC" "$Q"
  sleep 3; systemctl is-active "$SVC" "$Q"
else
  echo "frontend-only deploy (no restart)"
fi
echo "$NEW" > "$SHA"
echo "== done. backup: $BK"
REMOTE
