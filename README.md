# hms — Clood-X production monorepo

Single source of truth for the code running on VPS `87.106.142.253`.
**The repository is the deploy unit — never edit files on the server.**

## Layout

| Path         | Deployed to                                            |
|--------------|--------------------------------------------------------|
| `backend/`   | `/var/www/hms-backend` (prod) / `-staging` — Laravel Octane/FrankenPHP |
| `frontend/`  | `/var/www/hms-frontend` (prod) / `-staging` — PHP admin portal + PWA `worker/` |
| `deploy/`    | deploy tooling                                         |
| `scripts/`   | dev tooling (e.g. `sync-worker-ui.sh`)                 |

## Platform apps (sibling repos — not in this monorepo)

- `../clood-x-1` (`saber49/clood-x-1`) — **source of truth for the worker
  web UI** (`www/`) + Capacitor iOS/Android apps. After editing `www/`:
  bump `CACHE_NAME` in `www/sw.js`, run `bash scripts/sync-worker-ui.sh`
  (copies assets into `frontend/worker/` + `../clood-x-desktop/renderer`),
  bump `?v=` in `frontend/worker/index.php` keeping it equal to
  `CACHE_NAME`, commit, deploy via `deploy.sh`. Native builds pick changes
  up via `npx cap sync` / Codemagic.
- `../clood-x-desktop` (`saber49/clood-x-desktop`) — Electron wrapper;
  `npm run dist` syncs the renderer first. Publish binaries with
  `deploy-desktop.sh` on the server (installs to `frontend/desktop/`,
  which is excluded from git).

## Branches

- `main` → production
- `staging` → staging environment
- feature branches → merge into `staging`, verify on `staging.clood-x.com`, merge `staging`→`main` (or cherry-pick) for prod.

## Deploying

From a local clone:

```bash
deploy/deploy.sh staging [--dry]     # preview first if unsure
deploy/deploy.sh prod
```

On the server the script (in `/var/www/hms-repo`):

1. `git fetch` + checkouts `origin/<branch>`
2. refuses if any live file drifted from the last deployed commit (`--force` overrides after review)
3. backs up replaced/deleted files to `/root/deploy_backup_<env>_<ts>/`
4. rsyncs `backend/` and `frontend/` into place (`--chown=www-data`, checksums)
5. `php -l` on changed PHP, `tenant:migrate` on new migrations,
   restarts `hms-octane(-staging)` + `hms-queue(-staging)`

Rollback: `git checkout <old-sha>` on the server mirror + rerun the rsync step
(or restore files from the printed backup dir).

## Never in git

`.env`, `vendor/`, `storage/`, `uploads/`, `desktop/` binaries,
`frankenphp` binary, `*.bak.*` live backups — excluded by `.gitignore`
and by the deploy rsync excludes. Keep it that way.

## Server setup (one-time)

- Mirror clone: `/var/www/hms-repo` (read-only GitHub deploy key `/root/.ssh/hms_github_deploy`)
- Last deployed sha per env: `/var/www/.hms-deployed-{prod,staging}`
- SSH from local: `ssh hms` (see `~/.ssh/config`)
