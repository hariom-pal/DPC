# Worklog — 2026-07-30 — Milestone 0: Project Foundation

## Task
Stand up the Laravel 13 project foundation and quality gate for the ODPC Portal. Setup only — no business logic.

## Decisions applied
- **Laravel 13.x** (user chose latest stable over the "Laravel 12" label; installed 13.23.0) on PHP 8.4.
- Redis for **cache, queue and sessions** (predis client — phpredis extension not loaded).
- MySQL dev DB `dpc_dev` (root/root); Redis password provided.
- Bootstrap 5.3 **vendored** (no Node/Vite build step — the stack excludes Node).

## What was done
- Installed Laravel 13.23.0 at the repo root (alongside `docs/`, `design/`); removed the empty `dpc-api`/`dpc-web` dirs.
- `.env` / `.env.example` wired to MySQL (`dpc_dev`) and Redis (predis) for cache/queue/sessions; AWS region `ap-south-1` placeholder for S3.
- Installed `predis/predis` and `larastan/larastan`; resynced composer lock (removed an unused `laravel/pao` dev dep and the Pest plugin).
- **Bootstrap 5.3.3** CSS/JS downloaded to `public/vendor/bootstrap/` (no CDN, no build step).
- **Bilingual shell (EN/HI):** `lang/en/messages.php`, `lang/hi/messages.php`, `App\Http\Middleware\SetLocale` (registered on the web group), `config('app.supported_locales')`, and a `/lang/{locale}` switch route.
- **Base Blade layout** (`layouts/app.blade.php`) + landing page (`welcome.blade.php`) with the language toggle.
- **Health endpoint** `GET /health` (`HealthController`) probing database, Redis and cache → JSON, HTTP 200 when healthy / 503 otherwise.
- **Tooling:** `phpstan.neon` (larastan, level 6), `pint.json` (Laravel preset + `declare_strict_types`), composer scripts `test`, `pint`, `pint:test`, `stan`, `gate`. Dropped the Node/npm/Vite composer scripts.
- **Tests:** `tests/Feature/HealthCheckTest.php` (health OK, home renders, language switch, unsupported-locale ignored) + the two stock example tests.

## Quality gate — all green
- `vendor/bin/pint --test` → passed
- `vendor/bin/phpstan analyse` (level 6) → no errors
- `php artisan test` / `composer test` → **6 passed (11 assertions)**
- `php artisan migrate` → applied default tables to `dpc_dev`
- `php artisan optimize` → config/routes/views/events cached OK (then cleared for dev)
- **Live HTTP check:** `/health` returned `{"status":"ok", database:ok, redis:ok, cache:ok}` against real MySQL + Redis; home page rendered with EN/HI toggle.

## Notes / follow-ups
- Fixed two PHPStan findings at source: removed an always-true `getPdo() !== null` comparison; dropped the opt-in `checkModelProperties` flag (fought stock `UserFactory` before models carry property annotations — will reintroduce once models are annotated).
- Stock Node/Vite files (`package.json`, `vite.config.js`, `.npmrc`, `resources/js`, `resources/css`) remain but are unused; can be pruned later.
- Default `create_cache_table` / `create_jobs_table` migrations remain (harmless; we use Redis for both).
- Committed on branch `feature/m0-setup` (commit `8951c30`); working tree clean.

## Next
Milestone 1 — Auth, RBAC & Department Registration (blocked items already ruled: Q1 email/mobile+password+OTP, Q2 CGPSC Admin role). Remaining M1 choices: Q14 RBAC package (lean Spatie), Q15 audit package.
