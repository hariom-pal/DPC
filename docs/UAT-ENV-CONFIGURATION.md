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
| `local` | Demo tooling is unlocked | **No** — use `uat`; the fixed OTP (§1.2, option C) works on `uat` too |
| `production` | The fixed OTP is ignored. The DigiLocker and eSign **simulators are refused**: Department Admin registration needs `FEATURE_DIGILOCKER=true` with real credentials, and Pre-DPC submission or approval needs `FEATURE_CSC_DIGISIGN=true`. **Neither real provider has been verified yet (§7)** | **No** — registration and the approval workflow cannot be tested |
| `uat` | Fixed OTP allowed (§1.2). DigiLocker and eSign run on their simulators unless the real providers are switched on (§7) | **Yes** |
| any other name (`staging`, …) | Like `uat` for the simulators, but the fixed OTP is **ignored** (allowed only on `local`, `testing`, `uat`) | Only with real SMS + SMTP |

> **Danger: `demo:seed` and `dpc:reset-transactional-data` refuse to run only when
> `APP_ENV=production`.** On `APP_ENV=uat` they **will** run.
> - `php artisan demo:seed` executes `migrate:fresh` and **erases the database**.
> - `php artisan dpc:reset-transactional-data` truncates every workflow table.
>
> **Never run either on UAT.**

### 1.2 How testers receive OTPs

Since 2026-10-28 the OTPs are:

| Moment | Codes asked |
|---|---|
| Every sign-in (second factor) | **Mobile OTP only.** An account without a deliverable mobile cannot sign in (Promotion Candidates excepted) |
| Department Admin registration (invitation link) | E-mail OTP **and** mobile OTP, both mandatory — there is no waiver |
| Department Admin adding a new AO/AH inline | E-mail OTP **and** mobile OTP of the new officer |

On UAT there is no SMS gateway (`FEATURE_SMS_GATEWAY=false`), and mail may go to the
log (`MAIL_MAILER=log`). Consequences:
- **An SMS OTP is never sent and never written to the log.** Without a gateway the
  mobile code can be proved **only** with the fixed code (option C with
  `DPC_OTP_FIXED_MOBILE=true`). **Without option C nobody can sign in on UAT** —
  option A or B alone is not enough any more.
- **An e-mail OTP is sent synchronously.** No queue worker is needed for it. Under
  `MAIL_MAILER=log`, the message, code included, is written to the log.
- **Temporary passwords are shown once on screen** and never mailed or logged.

Pick one of these:

| Option | How | Trade-off |
|---|---|---|
| **A. Read the log** (e-mail codes and invitation links only) | With `MAIL_MAILER=log`, read the e-mail OTP with: `grep -h "verification code is" <APP_DIR>/storage/logs/laravel*.log \| tail -2`. Combine with C for the mobile code | Only server operators can read OTPs, so testers need an operator to hand them over |
| **B. Real SMTP** (e-mail codes and invitation links only) | Set the `MAIL_*` SMTP variables. Combine with C for the mobile code | Testers receive their own e-mail OTPs and invitation links. **The seeded Department Master e-mails (`<code>.dpc@cg.gov.in`) are placeholders** — correct them in Department Master before inviting, or mail goes to addresses nobody reads (or to a real mailbox that is not the tester's) |
| **C. Fixed UAT OTP** (chosen 2026-10-26; **required** while there is no SMS gateway) | `DPC_OTP_FIXED_ENABLED=true`, `DPC_OTP_FIXED_CODE=123456`, and `DPC_OTP_FIXED_MOBILE=true` for the mobile code too. Default off. Honoured only on `APP_ENV` `local`, `testing` or `uat` — **never `production`**, whatever `.env` says | Every account on the UAT accepts the same OTP, so anyone who knows a password can sign in. **Allowed only on a UAT that is not publicly reachable** (VPN, IP allow-list at the web server, or HTTP basic auth in front of the site) |

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

# ─── Integrations — keep all false on UAT (see §7) ──────────────────────────
FEATURE_HRMS=false
# false = eSign SIMULATOR (UAT only). true = the CSC DigiSign provider, which is a
# stub that throws until the CSC contract is implemented and verified.
FEATURE_CSC_DIGISIGN=false
# true here throws: "no SMS gateway provider is implemented"
FEATURE_SMS_GATEWAY=false
FEATURE_EMAIL_GATEWAY=false
FEATURE_S3_STORAGE=false
FEATURE_LGD_SYNC=false
FEATURE_NOTIFICATIONS=true
FEATURE_UIDAI_AADHAAR_OTP=false

# ─── DigiLocker — Department Admin identity at registration (see §7) ───────
# false = DigiLocker SIMULATOR (refused on APP_ENV=production).
FEATURE_DIGILOCKER=false
DPC_IDENTITY_VERIFICATION_REQUIRED=true
# Real provider only — leave unset while FEATURE_DIGILOCKER=false:
#DIGILOCKER_AUTHORIZE_URL=<from DigiLocker onboarding>
#DIGILOCKER_TOKEN_URL=<from DigiLocker onboarding>
#DIGILOCKER_CLIENT_ID=<from DigiLocker onboarding>
#DIGILOCKER_CLIENT_SECRET=<from DigiLocker onboarding>
#DIGILOCKER_REDIRECT_URI=https://<UAT_DOMAIN>/sign-in/identity/callback
#DIGILOCKER_TIMEOUT=15

# ─── Department Admin invitation (see §7.3) ────────────────────────────────
DPC_INVITATION_TTL_HOURS=72
DPC_TEMPORARY_PASSWORD_LENGTH=16
DPC_PASSWORD_RESET_LIMIT_ACTOR=10
DPC_PASSWORD_RESET_LIMIT_TARGET=3
# Promotion Candidates sign in without an OTP only when true — keep false until the Commission approves it.
DPC_CANDIDATE_PASSWORD_ONLY=false

# ─── Security headers (App\Http\Middleware\SecurityHeaders) ─────────────────
DPC_SECURITY_HEADERS=true
DPC_CSP_ENFORCE=true
# HSTS is only sent on HTTPS requests; the default is max-age=31536000; includeSubDomains
#DPC_HSTS=

# ─── OTP (see §1.2) ─────────────────────────────────────────────────────────
DPC_OTP_LENGTH=6
DPC_OTP_TTL=300
DPC_OTP_MAX_ATTEMPTS=3
DPC_OTP_RESEND_COOLDOWN=60
DPC_OTP_MAX_SENDS_PER_HOUR=5
# Option C — REQUIRED on UAT while there is no SMS gateway (sign-in is mobile-OTP only).
# Ignored on APP_ENV=production. Only on a UAT that is not publicly reachable (§1.2).
DPC_OTP_FIXED_ENABLED=true
DPC_OTP_FIXED_CODE=123456
DPC_OTP_FIXED_MOBILE=true

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
| `FEATURE_DIGILOCKER=true` without verified `DIGILOCKER_*` credentials | Department Admin registration stops at the identity step |
| `FEATURE_S3_STORAGE=true` | Unless an S3 bucket and credentials are really provided |
| `DEMO_OTP`, `DPC_OTP_FAKE_CODE` | Removed (2026-10-26); read by nothing. Use `DPC_OTP_FIXED_*` (§1.2, option C) |

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

---

## 7. Identity, invitation and eSign (added 2026-10-28)

### 7.1 Simulator or real provider

| Purpose | Simulator (UAT) | Real provider | Status of the real provider |
|---|---|---|---|
| Department Admin identity at registration (name, date of birth, gender) | `FEATURE_DIGILOCKER=false`. A simulator page (`/sign-in/identity/simulator`) asks the tester for the identity DigiLocker would return | `FEATURE_DIGILOCKER=true` + every `DIGILOCKER_*` key. The redirect URI registered with DigiLocker must equal `DIGILOCKER_REDIRECT_URI` exactly | OAuth client written, **never run against DigiLocker** — needs onboarding credentials and a sandbox test |
| AO/AH eSign before Pre-DPC "Submit for approval" and before every Pre-DPC/DPC approval | `FEATURE_CSC_DIGISIGN=false`. A simulator page (`/esign/simulator`) lets the tester type the name the provider "asserts", so a name mismatch can be tested | `FEATURE_CSC_DIGISIGN=true` | **Not implemented** — `CscDigiSignProvider` throws until the CSC eSign API contract and credentials are provided |
| Mobile OTP | `DPC_OTP_FIXED_*` (§1.2 option C) | `FEATURE_SMS_GATEWAY=true` | **Not implemented** |
| E-mail OTP and invitation link | `MAIL_MAILER=log` | `MAIL_MAILER=smtp` + `MAIL_*` | Standard Laravel mail — works with any SMTP server |

Both simulators answer **only when `APP_ENV` is not `production`**, and only while their
feature flag is false. On `production` they return 404 and the binding refuses to
build them, so a misconfigured production host fails closed instead of accepting a
typed-in identity or signature.

> **A UAT running simulators must not be publicly reachable.** Anyone who can reach the
> simulator pages can assert any name. Put the UAT behind a VPN, an IP allow-list or
> HTTP basic auth at the web server.

### 7.2 What the eSign check enforces (no configuration)

Every eSign, on the simulator and on the real provider alike:
- the provider must report success, echo the request reference, and return the digest of the document that was sent;
- a provider transaction reference is accepted once (`signatures_provider_txn_unique`);
- the name the provider asserts must match the officer's portal name (normalised: case, spacing, honorifics);
- the signer must still be the currently allotted AO/AH.

On a mismatch the Pre-DPC/DPC is **not** forwarded. The attempt is recorded in
`signature_attempts`, and a notice appears on the proposal and the Department Admin's
listing. The Department Admin then either allots another officer or corrects the
officer's details, with a reason. The correction is audited as an identity correction
at stage `esign_correction`.

### 7.3 Department Admin invitation, replacement and AO/AH allotment

None of these rules has a switch; only the invitation lifetime is configurable.

| Rule | Configuration |
|---|---|
| The CGPSC Super Admin chooses a department and enters **that department's own e-mail from Department Master**. Another department's e-mail, or a personal address, is refused before anything is sent | Department Master → department e-mail (**correct the seeded placeholders first**) |
| The link is single-use and expires | `DPC_INVITATION_TTL_HOURS` (default `72`) |
| Registration: mobile → e-mail OTP + mobile OTP → DigiLocker (name, date of birth, gender) → password | §1.2, §7.1 |
| **One Department Admin per department.** A new registration ends the previous admin's posting and revokes their sessions; an account left without any posting is deactivated | none |
| **Replacing the Department Admin** (different person, or corrected details): the CGPSC Super Admin re-invites the same department e-mail. The previous identity is kept on the audit trail as an identity correction at stage `handover` | none |
| The Department Admin opens a Pre-DPC (year + post), completes it, and **allots the AO/AH as the last step**. An officer is found by e-mail, or added inline with e-mail and mobile OTPs. The admin can reallot at any time before a final decision, except that while the proposal is submitted the AO who signed it stays; an AH who has not signed can be replaced | §1.2 (OTPs) |
| AO/AH see their profile at every sign-in and must confirm it; only the department can be changed there | none |

There is no separate "DPC Admin" role any more. Administration is per department: the
Department Admin above.

### 7.4 Moving from simulators to real providers (production checklist)

Before `APP_ENV=production`:
1. `DPC_OTP_FIXED_ENABLED=false` (it is ignored on production anyway; set it so the file says so), and implement and verify an SMS gateway, since sign-in is mobile-OTP only.
2. `FEATURE_DIGILOCKER=true` with the onboarding credentials, verified end-to-end on the DigiLocker sandbox.
3. `FEATURE_CSC_DIGISIGN=true` only after the CSC provider is implemented and a real signature has been verified, including the name the provider asserts.
4. Remove `DPC_ADMIN_PASSWORD` from `.env`.
