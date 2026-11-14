#!/usr/bin/env bash
# =============================================================================
#  Publish CODE changes from STAGING -> PRODUCTION  (HMS / clood-x.com)
# -----------------------------------------------------------------------------
#  SAFE BY DEFAULT: runs a DRY-RUN unless you pass --apply.
#  Never touches: prod .env, prod storage/ (uploads, logs), bootstrap/cache,
#  public/storage symlink, .git.  (Code only.)
#
#  Usage:
#    bash publish-staging-to-prod.sh            # dry-run (preview changes)
#    bash publish-staging-to-prod.sh --apply    # sync code + reload Octane
#    bash publish-staging-to-prod.sh --apply --delete   # also remove files
#                                                         deleted in staging
#
#  After --apply, if you added migrations, run them explicitly (see end).
# =============================================================================
set -euo pipefail

SRC_BE=/var/www/hms-backend-staging
SRC_FE=/var/www/hms-frontend-staging
DST_BE=/var/www/hms-backend
DST_FE=/var/www/hms-frontend

APPLY=0
DELETE=0
for a in "$@"; do
  case "$a" in
    --apply)  APPLY=1 ;;
    --delete) DELETE=1 ;;
    *) echo "Unknown arg: $a"; exit 2 ;;
  esac
done

DRY="--dry-run"
[ "$APPLY" = "1" ] && DRY=""
DEL=""
[ "$DELETE" = "1" ] && DEL="--delete"

BE_EXCLUDES=(--exclude=.env --exclude=storage/ --exclude=bootstrap/cache/ --exclude=public/storage --exclude=.git)
FE_EXCLUDES=(--exclude=.git --exclude=uploads/)

echo "############################################################"
if [ "$APPLY" = "1" ]; then
  echo "#  APPLYING staging -> production  (delete=${DELETE})"
else
  echo "#  DRY-RUN (no changes). Add --apply to publish."
fi
echo "############################################################"

echo
echo "=== Backend code: ${SRC_BE} -> ${DST_BE} ==="
rsync -a --no-owner --no-group --omit-dir-times $DRY $DEL --itemize-changes "${BE_EXCLUDES[@]}" "$SRC_BE/" "$DST_BE/"

echo
echo "=== Frontend code: ${SRC_FE} -> ${DST_FE} ==="
rsync -a --no-owner --no-group --omit-dir-times $DRY $DEL --itemize-changes "${FE_EXCLUDES[@]}" "$SRC_FE/" "$DST_FE/"

if [ "$APPLY" != "1" ]; then
  echo
  echo ">>> DRY-RUN complete. Review the lines above (>f = file change)."
  echo ">>> To publish:  bash $0 --apply"
  exit 0
fi

echo
echo "=== Fix ownership ==="
chown -R www-data:www-data "$DST_BE" "$DST_FE"

echo "=== Clear prod caches (safe; rebuilt on boot) ==="
cd "$DST_BE"
sudo -u www-data env HOME="$DST_BE/storage/frankenphp" php artisan config:clear || true
sudo -u www-data env HOME="$DST_BE/storage/frankenphp" php artisan route:clear  || true
sudo -u www-data env HOME="$DST_BE/storage/frankenphp" php artisan view:clear   || true

echo "=== Graceful Octane reload (no downtime) ==="
sudo -u www-data env HOME="$DST_BE/storage/frankenphp" php artisan octane:reload \
  || systemctl reload hms-octane.service

cat <<'NOTE'

=== PUBLISHED. ===

If this release added DB migrations, run them now (they are NOT automatic):

  # Central DB (hms_central):
  cd /var/www/hms-backend && sudo -u www-data php artisan migrate --force

  # ALL active tenant DBs (database/migrations/tenant):
  cd /var/www/hms-backend && sudo -u www-data php artisan tenant:migrate

Tip: test the exact same migrations on staging first:
  cd /var/www/hms-backend-staging && sudo -u www-data php artisan migrate --force
  cd /var/www/hms-backend-staging && sudo -u www-data php artisan tenant:migrate
NOTE
