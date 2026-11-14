#!/usr/bin/env bash
# Print md5 of every file touched by the Controlling refactor (staging or prod root).
# Usage: bash md5-controlling-files.sh [staging|prod]
SUF=""; [ "${1:-staging}" = "staging" ] && SUF="-staging"
BE=/var/www/hms-backend$SUF; FE=/var/www/hms-frontend$SUF
cd /var/www
md5sum \
  $BE/app/Services/ControllingAggregationService.php \
  $BE/app/Http/Controllers/Api/ControllingController.php \
  $BE/app/Http/Controllers/Api/ControllingDrilldownController.php \
  $BE/app/Http/Controllers/Api/TenantDashboardController.php \
  $BE/app/Http/Controllers/Api/ReportController.php \
  $BE/routes/api.php \
  $FE/index.php \
  $FE/assets/css/controlling.css \
  $FE/assets/js/controlling.js \
  $FE/assets/js/controlling/*.js 2>&1 | sed "s#$BE/#BE/#; s#$FE/#FE/#"
