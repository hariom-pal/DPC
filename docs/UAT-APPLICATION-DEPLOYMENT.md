# UAT Application Deployment — CGPSC ODPC Portal

Deploys the application onto a server prepared with `UAT-SERVER-INSTALLATION.md`. It
covers a **new** UAT and an **existing** UAT that already holds data, and ends with
the redeployment and rollback procedure.

Placeholders are listed in `UAT-SERVER-INSTALLATION.md`. Extra ones used here:

| Placeholder | Meaning |
|---|---|
| `<REPO_URL>` | The Git remote UAT clones from. The development checkout's `origin` is `git@github.com:hariom-pal/DPC.git` — **confirm** this is the repository UAT should use and that the server has read access (deploy key) |
| `<RELEASE_TAG>` | The approved, immutable tag to deploy, e.g. `uat-2026-10-16.1` |
| `<RELEASE_BRANCH>` | The branch the tag was cut from, e.g. `release/uat-2026-10-16` |
| `<BACKUP_DIR>` | Directory for database and document backups, outside `<APP_DIR>` |

> **Hard rules for every step below**
> - Never: `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `demo:seed`, `dpc:reset-transactional-data`, `php artisan test`, `composer update`, `chmod -R 777`.
> - Never `git pull` into a working tree with local changes. Never `git reset --hard` / `git clean` on the server.
> - Run Composer and Artisan as `<DEPLOY_USER>`, not root.

---

## 0. Before anything reaches UAT — prepare a deployable revision

This is done **on the development machine**, not on the server, and **only after you
approve it**.

### 0.1 Where the code stands today (inspected 2026-10-15)

| Fact | Value |
|---|---|
| Branch | `feature/m0-setup` |
| `HEAD` | `71761a7` (2026-08-22) |
| Unpushed commits | **32** ahead of `origin/feature/m0-setup` |
| Working tree | ~528 entries. 217 untracked (incl. **32 migrations**, 70 `app/Domain` files, the Vacancy Master and Rule 1.1 code), ~279 modified, staged renames (`Cadre` → `DepartmentPost`, …), 5 staged doc deletions, 7 unstaged deletions of obsolete files |
| Merge conflicts | none |
| Test suite on this tree | 1,507 passed, 2 skipped, 0 failed |
| MySQL schema build | 86 migrations, 83 tables, on MySQL 8.0.46 (`schema:verify-mysql`) |

**A clone of the remote today would not contain the application as tested.** Most of
it exists only in this working tree. A release has to be committed and pushed first.

### 0.2 Secrets check before committing

Nothing secret is untracked today. `.env`, `vendor/`, `node_modules/`, `storage/*`
contents, `public/storage` and `*.log` are ignored by `.gitignore`. Re-check on the
day:

```bash
cd /var/www/html/DPC
git status --short --ignored | grep -E '^!!' | head -50          # what stays out (should include .env, vendor/, storage contents)
git ls-files --others --exclude-standard | grep -iE '\.env|\.pem|\.key$|id_rsa|\.sqlite|\.sql$|\.log$|^storage/|backup|secret|credential'
```

The second command should list only:
- `app/Console/Commands/DemoCredentialsCommand.php` (code), and
- `docs/demo/DEMO_LOGIN_CREDENTIALS.md` — a demonstration document whose shared password `Demo@2026` exists only on a database built with `demo:seed`. **Decide whether it goes into the release** (see the final report).

### 0.3 Recommended release procedure

These commands create a branch from the current working tree **without discarding
anything**. Run them only after review. They are listed here, not executed.

```bash
cd /var/www/html/DPC
git switch -c <RELEASE_BRANCH>                 # new branch at HEAD; the working tree and index stay as they are
git add -A                                     # stages modified, untracked (not ignored) and deleted files
git status                                     # REVIEW: every file listed is going into the release
git diff --cached --stat | tail -5
git commit -m "UAT release: <summary>"
git tag -a <RELEASE_TAG> -m "UAT release <RELEASE_TAG>"
git push -u origin <RELEASE_BRANCH>
git push origin <RELEASE_TAG>
```

- Push failing with "Permission denied / correct access rights" while the key is fine is usually outbound port 22 being blocked. Use GitHub's SSH-over-443 endpoint:
  ```bash
  git remote set-url --push origin ssh://git@ssh.github.com:443/hariom-pal/DPC.git
  ```
- Merging `<RELEASE_BRANCH>` into `main` is a separate decision. Deploy by **tag**, so a later commit can never slip into UAT unnoticed.

---

## 1. Directories and the deployment user (server, as a sudo user)

```bash
sudo useradd --system --create-home --shell /bin/bash <DEPLOY_USER>     # skip if it exists
sudo usermod -aG <WEB_GROUP> <DEPLOY_USER>
sudo mkdir -p <APP_DIR> <BACKUP_DIR>
sudo chown <DEPLOY_USER>:<WEB_GROUP> <APP_DIR>
sudo chown <DEPLOY_USER>:<DEPLOY_USER> <BACKUP_DIR> && sudo chmod 0750 <BACKUP_DIR>
```

Read access to the repository (deploy key):

```bash
sudo -u <DEPLOY_USER> ssh-keygen -t ed25519 -N "" -f /home/<DEPLOY_USER>/.ssh/id_ed25519 -C "odpc-uat-deploy"
sudo cat /home/<DEPLOY_USER>/.ssh/id_ed25519.pub
```

Add the printed key as a **read-only deploy key** on the repository, then:

```bash
sudo -u <DEPLOY_USER> ssh -T git@github.com
# The expected answer is "successfully authenticated". If port 22 is blocked:
sudo -u <DEPLOY_USER> ssh -T -p 443 git@ssh.github.com
```

---

## 2. Get the code

### 2.1 New UAT

```bash
sudo -u <DEPLOY_USER> git clone <REPO_URL> <APP_DIR>
cd <APP_DIR>
sudo -u <DEPLOY_USER> git fetch --tags
sudo -u <DEPLOY_USER> git checkout --detach <RELEASE_TAG>
sudo -u <DEPLOY_USER> git log -1 --oneline                 # record this in the UAT log
```

### 2.2 Existing UAT

Go to section 9 (redeployment). It has a pre-flight check and a backup step that a
first install does not need.

---

## 3. PHP dependencies

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u <DEPLOY_USER> composer check-platform-reqs --no-dev   # every line must say "success"
```

`composer install` reads `composer.lock`. **Never run `composer update` on UAT.** Dev
packages (PHPUnit, Larastan, Pint, Pail, Collision) are not installed. `laravel/tinker`
is a runtime dependency, so `php artisan tinker` remains available.

## 4. Frontend assets

**Nothing to build.** No view uses Vite (`@vite` appears nowhere). The CSS and JS the
pages load are committed under:
- `public/css/portal.css`
- `public/js/*.js`
- `public/vendor/bootstrap/*`

`package.json` has no lockfile and its Vite/Tailwind output is not referenced.
**Do not run `npm install` / `npm ci` / `npm run build`.**

Check that the assets are present:

```bash
ls <APP_DIR>/public/css/portal.css <APP_DIR>/public/vendor/bootstrap/css/bootstrap.min.css <APP_DIR>/public/js/portal.js
```

## 5. Environment file

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> cp .env.example .env              # NEW UAT only; for an existing UAT keep its .env
sudo -u <DEPLOY_USER> editor .env                      # fill it in from UAT-ENV-CONFIGURATION.md §2
sudo chown <DEPLOY_USER>:<WEB_GROUP> .env && sudo chmod 0640 .env
sudo -u <DEPLOY_USER> php artisan key:generate --force  # NEW UAT ONLY — never on an existing UAT
```

## 6. Storage and permissions

Every uploaded document is written under `storage/app/private/private/...` on the
`local` disk:
- department-post orders
- record-gap evidence
- Rule 1.1 minutes
- Vacancy Master orders
- proceedings
- circulations
- signatures
- imports

The `local` disk root is `storage/app/private`, and the code adds `private/<area>/`
under it. None of it is under `public/`, and no web-server rule may point at it.

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> mkdir -p storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs bootstrap/cache

# Code: owned by the deploy user, readable by the web group, not writable by it.
sudo chown -R <DEPLOY_USER>:<WEB_GROUP> <APP_DIR>
sudo find <APP_DIR> -type d -exec chmod 0750 {} \;
sudo find <APP_DIR> -type f -exec chmod 0640 {} \;
sudo chmod 0750 <APP_DIR>/artisan

# Writable runtime paths: group-writable, and setgid so new files keep the group.
sudo find <APP_DIR>/storage <APP_DIR>/bootstrap/cache -type d -exec chmod 2770 {} \;
sudo find <APP_DIR>/storage <APP_DIR>/bootstrap/cache -type f -exec chmod 0660 {} \;

# Files created by PHP-FPM (web group) must stay writable by the deploy user and the queue worker:
sudo setfacl -R  -m g:<WEB_GROUP>:rwX -m u:<DEPLOY_USER>:rwX <APP_DIR>/storage <APP_DIR>/bootstrap/cache
sudo setfacl -dR -m g:<WEB_GROUP>:rwX -m u:<DEPLOY_USER>:rwX <APP_DIR>/storage <APP_DIR>/bootstrap/cache
```

The web server itself (nginx/apache) must be able to read `public/`. If nginx is not
in `<WEB_GROUP>`:

```bash
sudo usermod -aG <WEB_GROUP> nginx      # RHEL with nginx; on Ubuntu nginx runs as www-data
```

On RHEL with SELinux, label the writable paths (see the server doc §3.7):

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t "<APP_DIR>/storage(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "<APP_DIR>/bootstrap/cache(/.*)?"
sudo restorecon -Rv <APP_DIR>
```

**Storage link (OPTIONAL).** No feature stores files on the `public` disk. The link
only exposes `storage/app/public`, never `storage/app/private`. Creating it is
harmless:

```bash
sudo -u <DEPLOY_USER> php artisan storage:link
```

## 7. Database

1. Create the database and user (`UAT-MIGRATION-AND-ROLLBACK.md` §2).
2. **Existing UAT:** take the pre-migration backup (`UAT-MIGRATION-AND-ROLLBACK.md` §3).
3. Check, then migrate:

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan config:clear
sudo -u <DEPLOY_USER> php artisan migrate:status
sudo -u <DEPLOY_USER> php artisan migrate --pretend         # review the SQL before running it
sudo -u <DEPLOY_USER> php artisan migrate --force           # additive; never fresh/refresh
sudo -u <DEPLOY_USER> php artisan migrate:status | grep -c Pending   # must print 0
```

4. Print the evidence-table grants and give them to the DBA to apply. The command only
prints SQL, it changes nothing:

```bash
sudo -u <DEPLOY_USER> php artisan security:evidence-grants --user=<DB_USER> --host=<DB_CLIENT_HOST_OR_%> --database=<DB_NAME>
```

After the DBA applies them, verify (the probes match no rows):

```bash
sudo -u <DEPLOY_USER> php artisan security:evidence-grants --verify
```

## 8. Reference data and the first CGPSC Administrator

The seeders are idempotent by key, but **two of them overwrite existing rows**:

| Seeder | Safe on an EXISTING UAT? | Why |
|---|---|---|
| `RolesAndPermissionsSeeder` | **Yes** | `findOrCreate` on the seven roles |
| `AdminUserSeeder` | **Only if the admin email does not exist yet** | `updateOrCreate` by email **resets that account's password** to `DPC_ADMIN_PASSWORD` |
| `DepartmentMasterSeeder` | **No** | `updateOrCreate` by code sets `status = Unregistered`, which un-registers registered departments |
| `LookupMasterSeeder`, `DistrictSeeder`, `DesignationSeeder`, `PayScaleSeeder`, `QualificationSeeder`, `CommitteeMasterSeeder` | Not without review | They re-write names/labels of existing codes back to the shipped values, undoing Administrator edits |
| `DepartmentPostSeeder` | No | Sample posts for the `HE` department |
| `DatabaseSeeder` (all of the above) | **New UAT only, once** | — |
| `DemoSeeder` / `php artisan demo:seed` | **Never** | `demo:seed` runs `migrate:fresh` |

### 8.1 New UAT (empty database)

```bash
cd <APP_DIR>
# .env must hold DPC_ADMIN_NAME/EMAIL/MOBILE/PASSWORD (a strong temporary password).
sudo -u <DEPLOY_USER> php artisan config:clear
sudo -u <DEPLOY_USER> php artisan db:seed --force
```

Then remove `DPC_ADMIN_PASSWORD` from `.env`, log in, and change the password at
`https://<UAT_DOMAIN>/password/change`.

### 8.2 Existing UAT

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan db:seed --class=RolesAndPermissionsSeeder --force
# Is there already an Administrator with the intended email?
sudo -u <DEPLOY_USER> php artisan tinker --execute="dump(App\Domain\Authentication\Models\User::role('admin')->pluck('email')->all());"
```

Run `AdminUserSeeder` **only** if the list printed is empty, or does not contain
`DPC_ADMIN_EMAIL`:

```bash
sudo -u <DEPLOY_USER> php artisan db:seed --class=AdminUserSeeder --force
```

## 9. Optimise and cache

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan optimize:clear
sudo -u <DEPLOY_USER> php artisan config:cache
sudo -u <DEPLOY_USER> php artisan route:cache       # if this fails, run `php artisan route:clear` and continue without it
sudo -u <DEPLOY_USER> php artisan view:cache
sudo -u <DEPLOY_USER> php artisan event:cache
```

> After any later `.env` edit: `config:cache` again, then restart the queue worker and
> reload PHP-FPM.

## 10. Queue worker (REQUIRED) — systemd

Without it, OTPs, temporary passwords and notifications are never written, and **login
is impossible**.

Create `/etc/systemd/system/odpc-queue.service`:

```ini
[Unit]
Description=ODPC Portal queue worker (UAT)
After=network.target redis-server.service mysql.service

[Service]
User=<DEPLOY_USER>
Group=<WEB_GROUP>
UMask=0007
WorkingDirectory=<APP_DIR>
ExecStart=/usr/bin/php <APP_DIR>/artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600 --timeout=90
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

On RHEL, write `After=` with `redis.service mysqld.service`.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now odpc-queue
systemctl status odpc-queue --no-pager
journalctl -u odpc-queue -n 50 --no-pager
```

`--max-time=3600` makes the worker exit hourly and systemd starts a fresh one. This
releases memory and picks up new code.

## 11. Scheduler (REQUIRED) — cron

`routes/console.php` schedules `dpc:return-expired-allotments` daily at **00:15
application time (UTC) = 05:45 IST**. It returns allotments that pass their completion
date to the Chairman.

```bash
echo '* * * * * <DEPLOY_USER> cd <APP_DIR> && /usr/bin/php artisan schedule:run >> /dev/null 2>&1' \
  | sudo tee /etc/cron.d/odpc-scheduler
sudo chmod 0644 /etc/cron.d/odpc-scheduler
sudo -u <DEPLOY_USER> php <APP_DIR>/artisan schedule:list
```

## 12. Web server

Enable the virtual host from `UAT-SERVER-INSTALLATION.md` §6 and reload:

```bash
sudo nginx -t && sudo systemctl reload nginx           # or: sudo apachectl configtest && sudo systemctl reload apache2|httpd
sudo systemctl reload php8.4-fpm                       # RHEL: php-fpm
```

## 13. Validation

Run these after every deployment.

**Platform**

```bash
php -v | head -1
cd <APP_DIR>
sudo -u <DEPLOY_USER> composer check-platform-reqs --no-dev | grep -v success || echo "platform OK"
sudo -u <DEPLOY_USER> php artisan about
```

**Application**

```bash
sudo -u <DEPLOY_USER> php artisan migrate:status | grep -c Pending          # 0
sudo -u <DEPLOY_USER> php artisan route:list --name=vacancy-master | head   # Vacancy Master routes present
sudo -u <DEPLOY_USER> php artisan route:list | tail -1                       # "Showing [296] routes"
sudo -u <DEPLOY_USER> php artisan schedule:list
sudo -u <DEPLOY_USER> php artisan lang:check                                 # every English key has a Hindi one
```

**Services**

```bash
systemctl is-active odpc-queue php8.4-fpm nginx redis-server                # RHEL: php-fpm, redis
```

**HTTP**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<UAT_DOMAIN>/up            # 200
curl -sS https://<UAT_DOMAIN>/health                                         # {"status":"ok",...,"checks":{"database":"ok","redis":"ok","cache":"ok"}}
curl -sSI https://<UAT_DOMAIN>/login | grep -iE '^HTTP|content-security-policy|x-frame-options|strict-transport'
curl -sS -o /dev/null -w '%{http_code}\n' https://<UAT_DOMAIN>/.env          # 403 or 404, never 200
curl -sS -o /dev/null -w '%{http_code}\n' https://<UAT_DOMAIN>/css/portal.css # 200
```

**Queue end to end (the login OTP path)**

Request a login on the site. The worker should handle the job within seconds, and the
code should appear in the log:

```bash
grep -h "verification code is" <APP_DIR>/storage/logs/laravel*.log | tail -2
sudo -u <DEPLOY_USER> php artisan queue:failed                              # should be empty
```

**Storage is writable by the web group**

```bash
sudo -u <WEB_GROUP> test -w <APP_DIR>/storage/app/private && echo writable
```

**Logs**

```bash
tail -n 50 <APP_DIR>/storage/logs/laravel-$(date +%F).log
sudo tail -n 50 /var/log/nginx/odpc-uat.error.log
```

If anything fails, see `UAT-TROUBLESHOOTING.md`.

---

## 14. Redeploying a new release to an existing UAT

### 14.1 Pre-flight

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> git status --porcelain       # MUST be empty. If not, stop and find out why — never reset it away.
sudo -u <DEPLOY_USER> git describe --tags --always # the release currently deployed (note it as <PREVIOUS_TAG>)
sudo -u <DEPLOY_USER> php artisan migrate:status | grep -c Pending   # expected 0 before the new code
```

### 14.2 Back up (always)

Database and documents: `UAT-MIGRATION-AND-ROLLBACK.md` §3.

### 14.3 Deploy

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan down --retry=60                  # maintenance mode
sudo -u <DEPLOY_USER> git fetch --tags origin
sudo -u <DEPLOY_USER> git checkout --detach <RELEASE_TAG>
sudo -u <DEPLOY_USER> composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u <DEPLOY_USER> php artisan config:clear
sudo -u <DEPLOY_USER> php artisan migrate:status                    # review what is pending
sudo -u <DEPLOY_USER> php artisan migrate --pretend
sudo -u <DEPLOY_USER> php artisan migrate --force
sudo -u <DEPLOY_USER> php artisan optimize:clear
sudo -u <DEPLOY_USER> php artisan config:cache
sudo -u <DEPLOY_USER> php artisan route:cache
sudo -u <DEPLOY_USER> php artisan view:cache
sudo -u <DEPLOY_USER> php artisan event:cache
sudo -u <DEPLOY_USER> php artisan queue:restart                    # workers finish their job, then exit; systemd restarts them
sudo systemctl reload php8.4-fpm                                    # RHEL: php-fpm (clears OPcache)
sudo -u <DEPLOY_USER> php artisan up
```

Then run section 13.

### 14.4 Rollback

**Code only** (the release added no migration, or its migrations are compatible with
the old code):

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan down --retry=60
sudo -u <DEPLOY_USER> git checkout --detach <PREVIOUS_TAG>
sudo -u <DEPLOY_USER> composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u <DEPLOY_USER> php artisan optimize:clear && sudo -u <DEPLOY_USER> php artisan config:cache && sudo -u <DEPLOY_USER> php artisan route:cache && sudo -u <DEPLOY_USER> php artisan view:cache
sudo -u <DEPLOY_USER> php artisan queue:restart && sudo systemctl reload php8.4-fpm
sudo -u <DEPLOY_USER> php artisan up
```

**Code and schema:** the database rollback has real limits. Read
`UAT-MIGRATION-AND-ROLLBACK.md` §6 before rolling back any migration. Restoring the
pre-deployment backup is the reliable path.
