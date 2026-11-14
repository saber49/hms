# hms — Clood-X production monorepo

Single source of truth for the code running on VPS `87.106.142.253`.
**The repository is the deploy unit — never edit files on the server.**

## Layout

| Path         | Deployed to                                            |
|--------------|--------------------------------------------------------|
| `backend/`   | `/var/www/hms-backend` (prod) / `-staging` — Laravel Octane/FrankenPHP |
| `frontend/`  | `/var/www/hms-frontend` (prod) / `-staging` — PHP admin portal |
| `deploy/`    | deploy tooling                                         |

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
