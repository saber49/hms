# Clood-X / HMS - Agent Operating Guide

This is the CURRENT operational map for agents working in
`~/Desktop/CascadeProjects`. Read it before editing or publishing.
The former 885-line notebook is preserved at
`hms/docs/AGENTS-legacy-2026-11-25.md`. It contains useful domain history,
but dates, release claims, file paths and deploy commands there may be
obsolete. Verify them against code and live state; this guide and the
actual repository scripts take precedence.

## The three repositories (three independent Git histories)

| Local repo | GitHub | Owns | Production target |
| --- | --- | --- | --- |
| `hms/` | `saber49/hms` | Laravel backend, office frontend, worker PWA, terminal, deploy scripts | `/var/www/hms-backend`, `/var/www/hms-frontend` |
| `clood-x-1/` | `saber49/clood-x-1` | Canonical worker UI `www/`, Capacitor iOS/Android projects | Native app builds; NOT the live server by itself |
| `clood-x-desktop/` | `saber49/clood-x-desktop` | Electron wrapper, synced `renderer/`, Windows/macOS release | `/var/www/hms-frontend/desktop/` binary downloads |

Repository-prefixed paths below are relative to
`~/Desktop/CascadeProjects`; paths starting `docs/` are relative to `hms/`.
`hms` has `main` (production) and `staging` (staging); the platform apps
release from their own `main`. The three commits are NOT expected to have
the same SHA. "One release train" means tracking the exact source commit,
bundled assets, published artifacts and server state for EACH component.
Do not call a source commit a deployed mobile/desktop release.

## Source of truth and copies

- Office frontend/API: edit `hms/frontend/` and `hms/backend/` ONLY.
  Server live trees are deploy outputs, not editing targets.
- Worker native UI: edit `clood-x-1/www/` ONLY. `ios/` and `android/`
  bundle that directory during `npx cap sync`; the website cannot update
  the worker UI already bundled inside installed apps.
- Worker PWA: `hms/frontend/worker/index.php` is PWA-only; assets and
  `sw.js` are copies under `hms/frontend/worker/`. Terminal entry points
  are `hms/frontend/wachbuch/index.php` and `index.html`.
- Desktop: `clood-x-desktop/renderer/` must mirror `clood-x-1/www/`.
  Run `cd clood-x-desktop && npm run sync`, then
  `diff -rq renderer ../clood-x-1/www` from that repo. Codemagic builds
  the COMMITTED renderer without sync.
- IMPORTANT current exception: `hms/frontend/worker/assets/js/qm-wachbuch.js`
  has additional PWA forms not present in native `clood-x-1/www/assets/js/qm-wachbuch.js`.
  They are NOT byte-identical. Do not run `hms/scripts/sync-worker-ui.sh`
  blindly: it copies native JS over PWA JS and would drop those forms.
  Reconcile the intended features with backend validation first; then
  sync targeted assets, review the diff and explicitly test both UIs.
  Desktop sync is separate and must NOT modify the PWA.

## Before any change or release

1. Check `git status -sb`, `git log -1`, and
   `git ls-remote origin refs/heads/main` in EACH affected repo. Respect
   existing uncommitted work; never overwrite it without inspecting diffs.
2. For server changes, compare live deployed SHA in
   `/var/www/.hms-deployed-{prod,staging}` with local/GitHub and run the
   relevant `hms/deploy/deploy.sh <env> --dry`. A staging commit is not
   necessarily the production commit. Check for server drift; do not
   use `--force` to hide it.
3. For platform builds, check source files in `clood-x-1/www/` against
   `clood-x-desktop/renderer/`, and check manifest versions from the
   actual download server. GitHub HEAD, built artifact and installed
   application can be three different versions.
4. Fix the source, verify with appropriate tests, commit and push each
   affected repository. Then build/publish each affected target; verify
   public response CONTENT and manifests, not only HTTP 200.
5. Report a per-platform matrix: source commit, build version, deployed
   version, verified/pending. Never claim equality for an unverified
   App Store, TestFlight, Google Play or Codemagic build.

## HMS web/API deployment

- SSH: `ssh hms` (configured key; do not assume the old key paths in the
  historical notebook). Server Git mirror: `/var/www/hms-repo`; prod
  `main`, staging `staging`. Never edit `/var/www/hms-{backend,frontend}*`
  directly. `hms/deploy/deploy.sh staging|prod [--dry]` fetches GitHub,
  checks drift, backs up changes and deploys both trees with rsync.
- Recommended flow: commit/push to `hms/staging`, dry-run, deploy staging,
  test; merge/cherry-pick verified changes to `hms/main`, dry-run, deploy
  prod, test. Inspect the dry-run for unexpected removals before apply.
  Backend changes restart Octane/queue through the deploy script.
- Production backend `/var/www/hms-backend`, frontend
  `/var/www/hms-frontend`; staging uses `-staging` suffix. Runtime data
  (`.env`, `storage/`, `uploads/`, `desktop/` binaries, vendor) is excluded.
  A documentation-only commit does not require redeploying PHP/assets.
- HTTP edge is FrankenPHP, NOT nginx: `hms-edge-prod` uses
  `/etc/frankenphp/Caddyfile.prod`. Octane uses FrankenPHP worker mode;
  preserve `admin off` on edge config (port 2019 conflict). Prefer a
  service restart over `octane:reload`. Caddy config is outside the Git
  deploy; validate separately and do not alter it incidentally.
- Cloudflare caches static URLs. For worker asset changes, use a NEW
  query version in `worker/index.php`, terminal links, and the SW
  precache list; bump `CACHE_NAME` in both native and PWA SW and the
  PWA `register('sw.js?v=N')` to the same N. Never reuse a `?v=` value.
  Verify public asset bytes after deploy. Keep special PWA forms intact.

## Native mobile releases

- Repo `clood-x-1/`; `www/` is bundled into iOS/Android. Bump SW cache
  before building; `npm run cap:sync` refreshes local platform assets.
  iOS dependency sync needs Xcode/CocoaPods locally, but Codemagic's
  `ios-release` workflow runs `npx cap sync ios` on its Mac builder.
- iOS marketing version lives in `clood-x-1/codemagic.yaml` as
  `APP_VERSION`; Codemagic may submit to TestFlight AND App Store review.
  Verify the build/artifact/review status in Codemagic/App Store Connect;
  a push to GitHub alone is not an installed iOS update.
- Android `versionCode` / `versionName` live in
  `clood-x-1/android/app/build.gradle`. Build a signed APK/AAB and verify
  the version and bundled assets before distributing manually or via
  Google Play. A local Gradle build alone is not a user update.

## Electron desktop releases

- Repo `clood-x-desktop/`. `npm run sync` copies ONLY the native worker UI
  to `renderer/`; never run a script that also rewrites the PWA as a side
  effect. Bump native SW cache for ANY renderer asset change. Set the
  release version in BOTH `package.json` and `package-lock.json`.
- Windows: `env -u ELECTRON_RUN_AS_NODE npm run dist` makes the installer,
  blockmap and `latest.yml` under `dist/`. Check the bundled renderer in
  `dist/win-unpacked/resources/app.asar`. Upload these plus
  `clood-x-desktop/deploy-desktop-safe.sh` to server `/tmp`, then execute
  `bash /tmp/deploy-desktop-safe.sh win`. Confirm the public `latest.yml`
  and the installer hash. Do not run the older server
  `/var/www/deploy-desktop.sh`: it removes prior versioned artifacts.
- macOS: signed/notarized builds ONLY via Codemagic workflow
  `mac-desktop-release`, triggered by a `desktop-v<version>` tag pushed
  to the desktop repo. The workflow publishes DMG/ZIP through
  `deploy-desktop-safe.sh mac` ONLY if `HMS_SSH_KEY` is configured;
  otherwise upload artifacts manually. Verify signing, notarization,
  public `latest-mac.yml` and DMG/ZIP before calling it live.
- Production binaries are NOT part of the HMS Git deploy: active files
  are `/var/www/hms-frontend/desktop/latest.yml` (Windows),
  `latest-mac.yml` (macOS), `Clood-X-Setup.exe` and `Clood-X.dmg`.
  `deploy-desktop-safe.sh` backs up stable files and retains versioned
  installers; do not delete old artifacts without explicit approval.
  If Windows/macOS manifest versions differ, label the release PARTIAL,
  not a completed unified desktop rollout.

## Domain and security invariants

- Every tenant read/write must use `TenantService` and fail closed without
  an active tenant. Never set the tenant DB directly; Octane workers
  persist across requests. Apply `BereichScope` to tenant queries before
  grouping and to single-record reads/writes; include scope in cache keys.
- Confirmed worker/NU billing data comes from `AbrechnungDataService`.
  Keep customer-billable and paid-worker hours separate; respect
  `kunden_freigabe` / `zeit_freigabe` gates. Check the relevant archived
  domain note and CURRENT code before changing accounting logic.
- AI write tools must use preview -> pending action -> confirm; read
  tools cannot become raw SQL. New office UI uses the shared MD3 components
  and tokens (`docs/MD3_ADOPTION.md`), not new one-off controls.
- Historical implementation details (WebView safe areas, Caddy pitfalls,
  PDF, QM, workforce, finance) remain searchable in
  `docs/AGENTS-legacy-2026-11-25.md`. Treat historical "LIVE", "TODO",
  local paths and dates as observations at the time, not instructions
  to overwrite current deployments.

## Status snapshot (2026-11-25; re-check, never assume current)

- HMS production: `c041c35`; staging: `147e98e`; worker PWA SW v93.
- Native source: `03fdb43`; iOS source version 1.4.16, Android source
  7.13 (versionCode 39). Distribution/installed versions NOT verified.
- Desktop source: `5458f1a` (`desktop-v1.0.11`); Windows manifest live
  1.0.11, macOS manifest last observed 1.0.10 pending Codemagic.
  Do not call this desktop release complete until macOS is verified.
