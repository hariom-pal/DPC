# UAT Environment Configuration — CGPSC ODPC Portal

What goes into `<APP_DIR>/.env` on the UAT server.

The application reads its settings from:
- `config/*.php` (Laravel standard), and
- `config/dpc.php`, `config/features.php`, `config/audit.php` (application-specific).

Every variable not listed here keeps the default written in those files. **The
defaults are the business rules as built**, so leave them alone unless the
Commission asks for a change.

> `.env` holds secrets. It is listed in `.gitignore` and must **never** be committed,
> copied into a ticket, or pasted into chat. Owner `<DEPLOY_USER>`, group
> `<WEB_GROUP>`, mode `0640`.

---

## 1. The three decisions that matter most

### 1.1 `APP_ENV=uat` — not `production`, not `local`

| Value | What the code does | Use on UAT? |
|---|---|---|
| `local` | Every OTP is the fixed `DEMO_OTP` (`OtpService::generateCode()`), and demo tooling is unlocked | **No** — UAT would accept a known OTP for every account |
| `production` | Approving a Pre-DPC or DPC proposal is **refused** unless `FEATURE_CSC_DIGISIGN=true` (`DpcProposalService` / `PreDpcProposalService::assertSignatureAvailable()`), and no CSC DigiSign provider is implemented | **No** — the approval workflow cannot be tested |
| `uat` (any other name) | Real random OTPs; approvals are signed with the mock signature provider | **Yes** |

> **Danger: `demo:seed` and `dpc:reset-transactional-data` refuse to run only when
> `APP_ENV=production`.** On `APP_ENV=uat` they **will** run.
> - `php artisan demo:seed` executes `migrate:fresh` and **erases the database**.
> - `php artisan dpc:reset-transactional-data` truncates every workflow table.
>
> **Never run either on UAT.**

### 1.2 How testers receive OTPs

Login always asks for an OTP (`LoginController` → `OtpPurpose::Login`).

On UAT there is no SMS gateway (`FEATURE_SMS_GATEWAY=false` binds `LogSmsSender`), and
mail goes to the log (`MAIL_MAILER=log`). So **every OTP, every temporary password
and every notification is written to the Laravel log**, and only after the
**queue worker** has processed it (`SendOtpNotification` is a queued listener).

Pick one of these:

| Option | How | Trade-off |
|---|---|---|
| **A. Read the log** (recommended) | The queue worker must be running. Then read the OTP with: `grep -h "verification code is" <APP_DIR>/storage/logs/laravel*.log \| tail -4`. A code is issued on **both** email and mobile, so expect two lines per login. | Only server operators can read OTPs, so testers need an operator to hand them over |
| **B. Real SMTP** | Set the `MAIL_*` SMTP variables. Test accounts must have reachable email addresses | Testers receive their own OTPs and temporary passwords by email |
| **C. Fixed UAT OTP** | `DPC_OTP_FAKE_CODE=<6 digits>` | Every account on the UAT accepts the same OTP. Acceptable only if UAT is not reachable from the internet. **Your decision.** |

### 1.3 Redis, or database drivers

Two options:
- **As configured (recommended):** `.env.example` puts sessions, cache and the queue on Redis, and `/health` reports `redis` as a check.
- **Without Redis:** set `SESSION_DRIVER=database`, `CACHE_STORE=database` and `QUEUE_CONNECTION=database`. The `sessions`, `cache`, `cache_locks`, `jobs` and `failed_jobs` tables already exist from the base migrations. The only cost is that `/health` reports `redis: error`.

---

## 2. Complete UAT `.env` template

Copy this into `<APP_DIR>/.env` and replace every placeholder.

```dotenv
# ─── Application ────────────────────────────────────────────────────────────
APP_NAME="ODPC Portal"
APP_ENV=uat
# New UAT: leave empty, then run `php artisan key:generate`. Existing UAT: KEEP the existing key (see §3).
APP_KEY=
APP_DEBUG=false
APP_URL=https://<UAT_DOMAIN>

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

# ─── Logging ────────────────────────────────────────────────────────────────
LOG_CHANNEL=stack
# daily = one file per day, kept LOG_DAILY_DAYS days (laravel-YYYY-MM-DD.log)
LOG_STACK=daily
LOG_DAILY_DAYS=30
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

# ─── Database (MySQL 8.0) ───────────────────────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=<DB_HOST>
DB_PORT=3306
DB_DATABASE=<DB_NAME>
DB_USERNAME=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
# Defaults: utf8mb4 / utf8mb4_unicode_ci (config/database.php)

# ─── Sessions / cache / queue (Redis; see §1.3 for the database alternative) ─
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
# true when the site is served over HTTPS (it must be, see the server doc); false only for a temporary HTTP-only UAT.
SESSION_SECURE_COOKIE=true

CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<REDIS_PASSWORD_OR_null>
REDIS_PORT=6379

# ─── Mail (see §1.2) ────────────────────────────────────────────────────────
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="no-reply@cgpsc.gov.in"
MAIL_FROM_NAME="${APP_NAME}"
# For real SMTP instead:
#   MAIL_MAILER=smtp
#   MAIL_SCHEME=<smtp|smtps>
#   MAIL_HOST=<SMTP_HOST>
#   MAIL_PORT=<SMTP_PORT>
#   MAIL_USERNAME=<SMTP_USER>
#   MAIL_PASSWORD=<SMTP_PASSWORD>

# ─── Object storage (not used on UAT) ───────────────────────────────────────
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# ─── Integrations — no real provider is implemented; keep all false ─────────
FEATURE_HRMS=false
FEATURE_CSC_DIGISIGN=false
# true here throws: "no SMS gateway provider is implemented"
FEATURE_SMS_GATEWAY=false
FEATURE_EMAIL_GATEWAY=false
FEATURE_S3_STORAGE=false
FEATURE_LGD_SYNC=false
FEATURE_NOTIFICATIONS=true

# ─── Security headers (App\Http\Middleware\SecurityHeaders) ─────────────────
DPC_SECURITY_HEADERS=true
DPC_CSP_ENFORCE=true
# HSTS is only sent on HTTPS requests; the default is max-age=31536000; includeSubDomains
#DPC_HSTS=

# ─── OTP (see §1.2) ─────────────────────────────────────────────────────────
DPC_OTP_LENGTH=6
DPC_OTP_TTL=600
DPC_OTP_MAX_ATTEMPTS=5
# Option C only — leave unset otherwise:
#DPC_OTP_FAKE_CODE=<6_DIGITS>

# ─── First CGPSC Administrator (used ONLY by AdminUserSeeder; see deployment doc §8) ─
# Remove DPC_ADMIN_PASSWORD from this file once the account exists.
DPC_ADMIN_NAME="CGPSC Administrator"
DPC_ADMIN_EMAIL=<ADMIN_EMAIL>
DPC_ADMIN_MOBILE=<ADMIN_10_DIGIT_MOBILE>
DPC_ADMIN_PASSWORD=<STRONG_TEMPORARY_PASSWORD>

# ─── Business-rule switches added October 2026 (defaults shown) ────────────
# Rule 1.1 pre-submission verification required at DPC submission:
DPC_PROPOSAL_VERIFICATION_ENFORCED=true
# Vacancy Master — see §4:
DPC_VACANCY_MASTER_REQUIRED=false
DPC_VACANCY_MASTER_EARLIEST_YEAR=2019
DPC_VACANCY_MASTER_MAX_ENTRY_COUNT=500
# Vacancy year 1 January – 31 December (rule 1.5) and rounding half-up (rule 1.3):
DPC_VACANCY_PERIOD_FROM_MONTH=1
DPC_VACANCY_PERIOD_FROM_DAY=1
DPC_VACANCY_PERIOD_TO_MONTH=12
DPC_VACANCY_PERIOD_TO_DAY=31
DPC_VACANCY_PROMOTION_ROUNDING=round

# ─── Uploads (match php.ini and the web server; see the server doc) ─────────
DPC_DOCUMENTS_MAX_KB=10240
DPC_IMPORT_MAX_FILE_KB=5120

# DEMO_OTP has no effect unless APP_ENV=local. Do not set it on UAT.
```

---

## 3. `APP_KEY`

`APP_KEY` encrypts sessions, signed URLs and any encrypted attribute. Changing it logs
everybody out and makes anything encrypted with the old key unreadable.

### 3.1 New UAT (empty database, no previous `.env`)

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan key:generate --force
grep -c '^APP_KEY=base64:' .env      # must print 1
```

### 3.2 Existing UAT

**Keep the key it already has.** Copy the existing value into the new `.env` before
the first request reaches the new code:

```bash
sudo grep '^APP_KEY=' <OLD_ENV_FILE>        # read the existing value on the server; do not print it anywhere else
```

Never run `key:generate` on an existing UAT. If the key has to be rotated, move the
old key into `APP_PREVIOUS_KEYS` and generate a new one. Do that only with the
Commission's agreement.

---

## 4. Vacancy Master and the October 2026 rule changes

| Variable | Default | Effect |
|---|---|---|
| `DPC_VACANCY_MASTER_REQUIRED` | `false` | **`false`:** a DPC proposal is reconciled against the Vacancy Master **only when a finalized position exists** for its department + Promotion To Post + DPC year. Proposals of posts without one submit as before. **`true`:** every DPC proposal must have a finalized Vacancy Master position before it can be submitted. Proposals already in Draft/Returned for posts without a position will be **blocked** at submission. Turn it on only after the Administrator has finalized positions for every post being processed. |
| `DPC_VACANCY_MASTER_EARLIEST_YEAR` | `2019` | First selectable Vacancy Year (rule 1.4 excludes pre-2019 proposals) |
| `DPC_VACANCY_MASTER_MAX_ENTRY_COUNT` | `500` | Sanity limit on one reason-wise entry's vacancy count |
| `DPC_VACANCY_PERIOD_TO_MONTH` / `_DAY` | `12` / `31` | Vacancy year end. **Changed from 30 November on 2026-10-15** (rule 1.5). It affects Vacancy Master, draft DPC proposals and the retirement-date validation on the Working Officers chart. Calculations already recorded keep their stored period. |
| `DPC_VACANCY_PROMOTION_ROUNDING` | `round` | `round` = half-up (rule 1.3); `floor`, `ceil` also accepted. **Changed from `floor` on 2026-10-15.** It affects the promotion/direct split of every *new* calculation. |
| `DPC_PROPOSAL_VERIFICATION_ENFORCED` | `true` | Rule 1.1 confirmations required before a DPC proposal is submitted |

To compare UAT with the pre-October behaviour, set
`DPC_VACANCY_PERIOD_TO_MONTH=11`, `DPC_VACANCY_PERIOD_TO_DAY=30` and
`DPC_VACANCY_PROMOTION_ROUNDING=floor`, then `php artisan config:cache`.

---

## 5. Variables that must NOT be set on UAT

| Variable | Why |
|---|---|
| `APP_DEBUG=true` | Exposes stack traces, environment values and queries to testers |
| `APP_ENV=local` or `production` | See §1.1 |
| `FEATURE_SMS_GATEWAY=true`, `FEATURE_CSC_DIGISIGN=true` | No provider is implemented; the binding throws and approval or login breaks |
| `FEATURE_S3_STORAGE=true` | Unless an S3 bucket and credentials are really provided |
| `DEMO_OTP` | Ignored outside `local`; leaving it suggests it works |

---

## 6. After editing `.env`

Configuration is cached in production mode, and **a cached config ignores `.env`
completely**. Every `.env` change needs:

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan config:clear
sudo -u <DEPLOY_USER> php artisan config:cache
sudo systemctl restart odpc-queue                  # the worker holds the old config in memory
sudo systemctl reload php8.4-fpm                   # RHEL: php-fpm
```

Confirm what the application actually sees — this prints names and values, so run it
privately:

```bash
sudo -u <DEPLOY_USER> php artisan about --only=environment
sudo -u <DEPLOY_USER> php artisan tinker --execute="dump(app()->environment(), config('app.debug'), config('queue.default'), config('session.driver'), config('dpc.vacancy.period'), config('dpc.vacancy.promotion_rounding'), config('dpc.vacancy_master'));"
```

> **Never run `php artisan test` on the UAT server.** `phpunit.xml` points the test
> suite at an in-memory SQLite database, but a *cached* configuration overrides it.
> On a server with `config:cache` the tests would run against the UAT database and
> erase it.
