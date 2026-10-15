# UAT Troubleshooting — CGPSC ODPC Portal

Start every investigation with the logs.

```bash
cd <APP_DIR>
tail -n 100 storage/logs/laravel-$(date +%F).log         # LOG_STACK=daily; with "single" it is storage/logs/laravel.log
sudo tail -n 100 /var/log/nginx/odpc-uat.error.log        # Apache: /var/log/apache2/odpc-uat.error.log (RHEL /var/log/httpd/)
sudo journalctl -u php8.4-fpm -n 100 --no-pager          # RHEL: -u php-fpm
sudo journalctl -u odpc-queue -n 100 --no-pager
curl -sS https://<UAT_DOMAIN>/health                     # database / redis / cache
```

> Never "fix" UAT with `migrate:fresh`, `db:wipe`, `demo:seed`,
> `dpc:reset-transactional-data`, `git reset --hard`, `git clean` or `chmod -R 777`.

---

## HTTP 500 — Server Error

| Look for in `laravel-*.log` | Cause | Fix |
|---|---|---|
| `No application encryption key has been specified` | `APP_KEY` empty | New UAT: `php artisan key:generate --force`. Existing UAT: restore its old key (env doc §3). Then `config:cache`. |
| `SQLSTATE[HY000] [2002]` / `[1045] Access denied` | Database unreachable or wrong credentials | See "Database connection failures" |
| `Connection refused [tcp://127.0.0.1:6379]` | Redis down, with sessions/cache on Redis | `sudo systemctl start redis-server` (RHEL `redis`), or switch the drivers to `database` (env doc §1.3) |
| `Table '...' doesn't exist` / `Unknown column` | Migrations not run | `php artisan migrate:status`, then the migration doc §4 |
| `file_put_contents(...storage/framework/...): Permission denied` | Storage not writable by the web group | "Storage permission failures" |
| `View [...] not found` / stale behaviour after deploy | Old caches | `php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache`, then reload PHP-FPM |
| `Target class [...] does not exist` | Old optimized autoloader | `composer install --no-dev --optimize-autoloader` (as the deploy user), then `optimize:clear` |
| `Digital signing is unavailable` | `APP_ENV=production` with DigiSign off | Use `APP_ENV=uat` (env doc §1.1) |
| `no SMS gateway provider is implemented` | `FEATURE_SMS_GATEWAY=true` | Set it to `false` |
| Nothing in the log | `APP_DEBUG=false` hides details and logging failed | Check the PHP-FPM journal. Temporarily `LOG_LEVEL=debug`, then `config:cache` |

Never set `APP_DEBUG=true` on a UAT reachable by testers. Read the log instead.

## HTTP 403 — Forbidden

| Situation | Explanation |
|---|---|
| AO / AH opening `/admin/...` (including Vacancy Master) | **Correct.** Admin screens are `role:admin` only |
| A user with several roles sees 403 on a screen they should reach | The session acts as one role at a time: switch the active role (top bar) |
| Every page 403 from the web server (not Laravel's page) | Document root not readable, or wrong `root` / `DocumentRoot`. It must be `<APP_DIR>/public`. On RHEL, run `restorecon` (server doc §3.7). |
| Downloading a document as another department | Correct — documents are authorised per proposal / department |

## HTTP 404 — Not Found

| Situation | Fix |
|---|---|
| Every URL except `/` gives 404 (NGINX) | `try_files $uri $uri/ /index.php?$query_string;` missing |
| Every URL except `/` gives 404 (Apache) | `AllowOverride All` missing, or `mod_rewrite` not enabled (`sudo a2enmod rewrite`) |
| A newly deployed route 404s | Stale route cache: `php artisan route:clear && php artisan route:cache` |
| A Vacancy Master post or document 404 | The post / entry / document belongs to another department or year (deliberate) |

## HTTP 419 — Page Expired (CSRF / session)

| Cause | Fix |
|---|---|
| Session cookie not sent back: `SESSION_SECURE_COOKIE=true` while the site is served over plain HTTP | Serve over HTTPS, or set `SESSION_SECURE_COOKIE=false` on a temporary HTTP-only UAT; then `config:cache` |
| `APP_URL` / `SESSION_DOMAIN` do not match the host in the browser | `APP_URL=https://<UAT_DOMAIN>`, `SESSION_DOMAIN=null` |
| Session store unavailable (Redis down) or `storage/framework/sessions` not writable | Start Redis / fix permissions |
| TLS terminated on a separate load balancer | The app does not configure trusted proxies (`bootstrap/app.php`), so Laravel sees HTTP. Terminate TLS on the app server, or add a trusted-proxy configuration in code (**a code change — needs approval**). |
| The tester left the form open longer than `SESSION_LIFETIME` (120 min) | Reload and resubmit |

## HTTP 502 / 504 — Bad Gateway / Timeout

| Cause | Fix |
|---|---|
| PHP-FPM not running | `sudo systemctl status php8.4-fpm` → start it |
| Socket path mismatch | NGINX `fastcgi_pass` / Apache `SetHandler` must match the pool's `listen` (`/run/php/php8.4-fpm.sock` on Ubuntu, `/run/php-fpm/www.sock` on RHEL) |
| Socket permission | Pool `listen.owner` / `listen.group` must be the web server user |
| All workers busy (slow pages, then 502/504) | `server reached pm.max_children` in the FPM log → raise `pm.max_children` (server doc §5.3) |
| A long calculation or import | `fastcgi_read_timeout 120s` / `ProxyTimeout 120` and `request_terminate_timeout = 120` |

## Login never completes / no OTP

1. The queue worker must be running:
   ```bash
   systemctl is-active odpc-queue
   ```
   If it is not, `sudo systemctl restart odpc-queue`.
2. Look for the code in the log:
   ```bash
   grep -h "verification code is" storage/logs/laravel*.log | tail -4
   ```
3. Look for failed jobs, retry them, and read the reason in the worker journal:
   ```bash
   php artisan queue:failed
   php artisan queue:retry all
   ```
4. Too many attempts: `DPC_RATE_LIMIT_LOGIN_*` and `DPC_RATE_LIMIT_OTP_VERIFY_*` throttle. Wait for the decay period (default 60 s).
5. After changing `.env`, the worker still holds the old config: `php artisan config:cache && sudo systemctl restart odpc-queue`.

## Database connection failures

```bash
mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME> -e "SELECT 1"
php artisan db:show
php artisan tinker --execute='dump(config("database.connections.mysql.host"), config("database.connections.mysql.database"));'
```

| Error | Fix |
|---|---|
| `[2002] Connection refused` | MySQL down, or listening on another host/port; firewall between hosts |
| `[1045] Access denied` | Wrong user/password, or the account's host part does not match the app server |
| `[1044] Access denied to database` | Grants missing (migration doc §2) |
| Config shows old values | Cached config: `php artisan config:clear && php artisan config:cache` |

## Missing PHP extensions

```bash
composer check-platform-reqs --no-dev
php -m
php-fpm8.4 -m           # FPM can differ from the CLI
```

Install the missing `php8.4-<ext>` (Ubuntu) or `php-<ext>` (RHEL), then
`systemctl reload php8.4-fpm`.

Typical errors and their extension:
- `could not find driver` → `pdo_mysql`
- `Class "ZipArchive" not found` → `zip` (Excel import)
- `imagecreatetruecolor` → `gd`
- `mb_*` → `mbstring`
- `Normalizer` / `Collator` → `intl`

## Missing frontend assets (unstyled pages, broken buttons)

The pages use only files committed under `public/`. There is no build step.

```bash
ls -l public/css/portal.css public/js/portal.js public/vendor/bootstrap/css/bootstrap.min.css
curl -sS -o /dev/null -w '%{http_code}\n' https://<UAT_DOMAIN>/css/portal.css
```

- Missing files → the release is incomplete; redeploy the tag.
- 403 on assets → `public/` is not readable by the web server user.
- Assets load but scripts do not run → the browser console shows a CSP violation. A browser extension or a proxy is injecting scripts. The application's own pages are CSP-clean. As an emergency fall-back only, `DPC_CSP_ENFORCE=false` switches CSP to report-only.

## File upload failures

| Symptom | Cause | Fix |
|---|---|---|
| `413 Request Entity Too Large` | Web server limit | NGINX `client_max_body_size 16m;` / Apache `LimitRequestBody 16777216` |
| Upload silently empty, or "The document failed to upload" | PHP limits | `upload_max_filesize = 12M`, `post_max_size = 16M` in **both** FPM and CLI ini (server doc §5.1); reload FPM |
| "must be a file of type: pdf" | Not a PDF, or `fileinfo` missing | Upload a real PDF; check `php -m \| grep fileinfo` |
| "may not be greater than 10240 kilobytes" | Application limit | `DPC_DOCUMENTS_MAX_KB` (documents), `DPC_IMPORT_MAX_FILE_KB` (imports) |
| `The document could not be stored.` | Storage not writable | Next section |

## Storage permission failures

```bash
sudo -u <WEB_GROUP> test -w <APP_DIR>/storage/app/private && echo writable || echo NOT-writable
namei -l <APP_DIR>/storage/app/private
getfacl <APP_DIR>/storage | head
```

Fix with the commands in `UAT-APPLICATION-DEPLOYMENT.md` §6 (ownership, `2770`
directories, default ACLs). On RHEL also run `restorecon -Rv <APP_DIR>`.

Files the queue worker created as `<DEPLOY_USER>` must stay writable by PHP-FPM. The
default ACL and `UMask=0007` in the worker unit handle that. **Never `chmod -R 777`.**

## Missing environment variables

```bash
php artisan about --only=environment
php artisan tinker --execute='dump(config("app.env"), config("app.url"), config("queue.default"), config("session.driver"), config("mail.default"));'
```

- A value in `.env` that is not reflected: the config is cached. `php artisan config:clear && php artisan config:cache`.
- A key with an empty value (`KEY=`) is **not** the same as a missing key. `env('KEY', default)` returns `''` for an empty key, not the default. Remove lines you do not mean to set.

## Scheduler not running

```bash
sudo -u <DEPLOY_USER> php <APP_DIR>/artisan schedule:list
grep odpc /etc/cron.d/odpc-scheduler
sudo -u <DEPLOY_USER> php <APP_DIR>/artisan schedule:run          # runs whatever is due now
sudo -u <DEPLOY_USER> php <APP_DIR>/artisan dpc:return-expired-allotments --help
```

`cron` (Ubuntu) / `crond` (RHEL) must be active. The job is due at 00:15 UTC.

## Cache and sessions

```bash
php artisan tinker --execute='Cache::put("uat:probe","ok",30); dump(Cache::get("uat:probe"));'   # "ok"
redis-cli ping                                                                                    # PONG (when on Redis)
```

## Vacancy Master specific

| Symptom | Explanation |
|---|---|
| A post shows *Not mapped on 01-01-YYYY* | No Department Post Mapping is in force on 1 January of that year (its effective-from date is later). Expected; choose a later year or correct the mapping's effective date. |
| *No active Recruitment Rule is in force* | Create or activate a rule for the post with effective-from on or before 1 January |
| Finalize disabled | Every item listed under *Before this position can be finalized* must be resolved, including pending entries |
| DPC proposal blocked with *Vacancy Master reconciliation* | Sanctioned or working figures differ from the finalized position. Correct the chart, or revise the position. With `DPC_VACANCY_MASTER_REQUIRED=true`, a post without a finalized position blocks submission. |
| Draft DPC proposals now show a different vacancy split after the 2026-10-15 release | Expected: the vacancy year now ends 31 December and rounding is half-up (env doc §4) |
