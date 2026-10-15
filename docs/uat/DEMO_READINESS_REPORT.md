# Demo Readiness Report — e-DPC Portal (CGPSC)

**Verified on:** 2026-09-01
**Demo date:** 2026-09-02
**Branch:** `feature/m0-setup`
**Mode:** Demo Freeze — verification only. No feature, business rule, schema, or working-code change was made.

**Verdict: GO.** Zero server errors across every screen, every role, **in both languages**. Three defects were found and fixed; none of the fixes touched business behaviour. A second verification round additionally confirmed the system behaves correctly **on the demo date itself**, audited all 134 state-changing routes, and swept every screen in Hindi. Six items remain, all presenter-awareness rather than product defects — see [Remaining Issues](#remaining-issues) and [Demo Risk](#demo-risk).

> A complete chronological record of this UAT — every command, explanation, suggestion and decision — is kept in **`docs/uat/TERMINAL_TRANSCRIPT.md`**.

---

## 1. Pass

### 1.1 Quality gate

| Check | Result |
|---|---|
| `pint --test` (code style) | **PASS** — no violations |
| `phpstan analyse` (level 6, larastan) | **PASS** — no errors |
| `php artisan test` | **PASS** — 1,287 passed / 4,228 assertions / 0 failures |
| `php artisan security:audit` | **PASS** — no security advisories |
| `php artisan schema:verify-mysql` | **PASS** — 79 migrations build 78 tables on MySQL |

### 1.2 Environment

| Component | State |
|---|---|
| PHP | 8.4.24 |
| Laravel | 13.23.0 |
| MySQL | 8.0.46 — `dpc_dev`, 78 tables, **0 pending migrations** |
| Redis | reachable (`PONG`) — sessions, cache, queue |
| Assets | no build step required (no Vite/Mix); Bootstrap and all JS vendored in `public/` |

### 1.3 Routes

- **270 routes** registered; the router builds without error.
- **Every** route target resolves — all controller classes exist and all methods exist. 0 broken bindings.
- **Every** `route('name')` reference in Blade and PHP resolves to a registered name.
  *(An initial scan flagged 33 — all were `$request->route('param')` parameter reads, not the `route()` helper. Confirmed false positives.)*

### 1.4 Views

- Every `view()`, `@extends`, `@include`, `@includeIf`, `@includeWhen` target exists across **489 scanned files**.
- The one dynamic include — `@include('dpc-proposals.sections.'.$current['partial'])` — was resolved against its source: all 5 possible partials (`post-selection`, `committee`, `working-officers`, `seniority`, `evaluation`) are present.

### 1.5 Controllers, Services, Policies, Middleware

Exercised live rather than read: **136 GET routes × 6 identities (~950 requests)**, then re-run after the database rebuild across 5 authenticated roles.

**Zero HTTP 500s. Zero uncaught exceptions.** Final tally:

| Role | 200 | 302 | 403 | 404 |
|---|---|---|---|---|
| admin | 77 | 6 | 43 | 3 |
| chairman | 25 | 5 | 99 | 0 |
| member | 25 | 5 | 99 | 0 |
| authorized_officer | 28 | 6 | 95 | 0 |
| administrative_head | 23 | 6 | 100 | 0 |
| *(guest)* | 6 | 122 | 0 | 0 |

The large 403 counts are the authorisation model working: each role reaches its own surface and is refused everywhere else. Guests are redirected to login from all but 6 public pages.

The 3 admin 404s were each traced and are **correct behaviour**, not broken links:

| Route | Why 404 |
|---|---|
| `admin/recruitment-rules/{rule}/download` | `abort_if(go_document_path === null, 404)`. Both views guard the link with `@if ($rule->go_document_path)`, so it is never rendered without a file — unreachable from the UI. |
| `admin/audit-trail/{source}/{id}` | Probed with a synthetic `source`/`id` pair that does not exist. |
| `admin/department-posts/{post}/orders/{order}` | Scoped binding; the probe paired a post and an order that do not belong together. |

### 1.6 Permissions

- Spatie roles seed correctly: `admin`, `chairman`, `member`, `authorized_officer`, `administrative_head`, `promotion_candidate`.
- Cross-role isolation verified by the live sweep above and by the existing `AuthorizationCoverageTest`, `RoleBasedUiVisibilityTest` and `ScreenSmokeTest` suites (all green).
- CSRF is **enforced** — a scripted POST without a token is correctly rejected with 419.
- Security headers present on every response: enforced `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`.

### 1.7 Reports and downloads

Every export and stored document was downloaded and its bytes measured:

| Endpoint | Result |
|---|---|
| `/admin/audit-trail/export` | 200 — 45,229 B CSV |
| `/reports` (admin / AO) | 200 — renders |
| `/reports/export` | 200 — CSV, 8-stage pipeline summary |
| `/dpc-templates/currently_working_us` | 200 — 225 B CSV |
| `/dpc-templates/final_seniority_list_us` | 200 — 180 B CSV |
| `/dpc-templates/evaluation_chart_us` | 200 — 503 B CSV |
| `/dpc-templates/{unknown}` | 404 — correct |
| Proceedings documents (×6) | 200 — 4,336–9,782 B each |
| Circulation documents (×2) | 200 — 4,196 B and 5,760 B |

Storage integrity after the rebuild: **8/8 documents and 25/25 signature files present on disk.**

The evaluation-chart upload template returns 403 on approved/submitted proposals and 200 on the draft (`DPC-2026-SE-0004`). That is the intended rule — you only fetch an upload template for a proposal you can still edit — and a draft exists in the demo data, so the screen is demonstrable.

### 1.8 File upload

Exercised end to end through the real CSRF-guarded stack:

1. Template downloaded → 200.
2. Uploaded back → accepted, parsed, and rejected with a clear message: *"The file could not be read as a spreadsheet: The file has a header row but no data rows."*
3. Redirected to the correct section route.
4. Import preview screen → 200.
5. Import error report → 200, `text/csv`.

The commit happy path is covered by 25 existing tests in `DpcExcelImportTest` and `ImportTemplateTest`, all passing.

### 1.9 Translations

- **1,449 distinct keys** referenced across views and PHP.
- **Full en/hi parity** — all 13 language files match key-for-key in both directions, zero drift.
- All four dynamic key namespaces verified complete in both locales: 12 months, 9 working-officer summary rows, 2 vacancy grounds, 8 candidate timeline events (the timeline whitelist in `CandidateDpcController` is exactly those 8, so no unmapped key is reachable).

### 1.10 Assets and JavaScript

- Every `asset()` target exists in `public/`.
- All 3 logos resolve (real artwork present; `*.fallback.svg` never needed).
- All 13 JavaScript files pass `node --check` — no syntax errors.
- No `public/storage` symlink required — documents are served through controllers, and nothing uses `Storage::url()`.

### 1.11 Seed data and demo users

`php artisan demo:seed --force` runs clean today (exit 0, all 12 seeders DONE, no warnings).

**Password for every demo account: `Demo@2026`**

| Role | Login |
|---|---|
| admin | `admin@cgpsc.gov.in` / 9990000000 |
| chairman | `chairman@cgpsc.gov.in` / 9000000101 |
| member ×5 | `member1..5@cgpsc.gov.in` / 90000002 01–05 |
| authorized_officer (School Education) | `ao.schooleducation@cg.gov.in` / 9000001101 |
| administrative_head (School Education) | `ah.schooleducation@cg.gov.in` / 9000001102 |

Demo content: 9 departments · 25 users · 9 DPC proposals · 8 Pre-DPC proposals · 4 proceedings · 1 circulation · 6 allotments · 8 recruitment rules.

Pipeline spread (from `/reports/export`) gives a screen to show at every stage:

| Stage | Count |
|---|---|
| Drafted by the department (S12–S18) | 1 |
| Awaiting departmental approval (S19–S25) | 1 |
| Forwarded to the Commission (S25) | 7 |
| Allotted to a Member (S28) | 6 |
| Sitting opened (S31–S33) | 1 |
| Result approved (S34) | 3 |

---

## 1B. Second verification round

Added after the first pass, covering three areas it did not reach.

### 1B.1 Behaviour on the demo date itself

The defect in FIX-1 was caused by nothing more than the calendar advancing, which makes one question unavoidable: **does anything break when the date becomes 2026-09-02?**

Verified empirically rather than by inspection. The clock was frozen at `2026-09-02 10:00:00` in the base `TestCase::setUp()` and the **entire suite re-run as though it were demo day**:

- **1,287 passed / 0 failed**, exit 0.
- No test references 2026-09-02. The nearest hardcoded dates after today are 2026-09-10, 2026-09-12 and 2026-09-30 — beyond the demonstration.

The temporary harness change was backed up, labelled `TEMPORARY` in source, reverted immediately, and **the revert proven by MD5 checksum** (`tests/TestCase.php: OK`, zero `travelTo` occurrences remaining).

**No demonstration data expires or activates overnight.** Only three records fall anywhere near the boundary, all with `effective_from = 2026-09-01`, all simply staying active:

| Record | Date |
|---|---|
| `ao.schooleducation@cg.gov.in` — authorized_officer, School Education | 2026-09-01 |
| `ah.schooleducation@cg.gov.in` — administrative_head, School Education | 2026-09-01 |
| CGPSC Chairman — Dr. Ajay Kumar Shrivastava | 2026-09-01 |

### 1B.2 Bilingual operation

Every screen was rendered in **both English and Hindi** for all five authenticated roles, and the visible text scanned for translation keys leaking through as literal strings — the exact failure mode of FIX-2.

- **Identical status distributions in both locales**, route for route, for every role.
- **Zero HTTP 500s and zero exceptions in either language.**
- **Zero untranslated keys visible on any page.** The single regex hit, `cgpsc.gov.in`, is the demo accounts' e-mail domain rendered from the database, not a key.

The demonstration may be given in either language, and may switch between them.

### 1B.3 State-changing routes

The first pass swept GET routes only. All **134** POST/PUT/PATCH/DELETE routes were then audited for authentication.

Six carry no `auth` middleware, and each is correct:

| Route | Why it is public |
|---|---|
| `POST login` | the authentication flow — `throttle:login` |
| `POST register` | department self-registration (S6) |
| `POST verify` | OTP verification — `throttle:otp-verify` |
| `POST self-inspection/identify` | Self-Inspection is deliberately account-free (S49–S51) — `throttle:self-inspection` |
| `POST self-inspection/close` | ends an account-free Self-Inspection session |
| `PUT storage/{path}` | Laravel's own temporary-upload route — **not unprotected**: `ReceiveFile` calls `abort_unless($this->hasValidSignature($request), …)` |

The three highest-risk public endpoints are each rate-limited. **No unguarded mutation endpoint exists.**

---

## 2. Fail

**No outstanding failures.** All three defects found during verification were fixed and re-verified — see below.

---

## 3. Fixed Issues

### FIX-1 — Two failing tests, broken by the calendar reaching the demo date

**Severity:** Medium (build gate red on demo eve)
**File:** `tests/Feature/Admin/UserDepartmentAssignmentTest.php`

`php artisan test` failed 2 of 1,287. Both were the same root cause, and it was time-bombed to today.

The fixtures created an officer at *real* today, which posts them with `effective_from = today`. The tests then travelled to a hardcoded `2026-09-01` and transferred the officer. That worked for as long as real-today was before 2026-09-01 — and today **is** 2026-09-01, so the posting now begins on the very day of the transfer. Two correct business rules then fired:

- `DepartmentAssignmentService::retireIncumbent()` clamps the closing date with `->max($incumbent->effective_from)`, so a posting cannot end before it began. The expected `2026-08-31` became `2026-09-01`.
- The transfer form refuses a transfer dated on or before the current posting's start: *"This officer's current posting began on 01-09-2026. A transfer must take effect after that date."*

**Fix:** froze the clock to a fixed `2026-08-01` in `setUp()`, *before* the fixtures are built, so the interval between posting and transfer is explicit and the suite no longer depends on the day it runs.

**No business logic was touched.** The application behaved correctly throughout; the tests were asserting against a moving calendar. All 16 tests in the file now pass, and the full suite is green at 1,287.

> **Note for the record:** the failure surfaced as a confusing `Call to a member function all() on array` at the assertion line. That is a Laravel test-helper quirk, not an application fault — `TestResponseAssert::injectResponseContext()` reads `session('errors')` to enrich a failure message, and under the project's `'serialization' => 'json'` session setting the error bag has already been flattened to an array by `Store::prepareErrorBagForSerialization()`. **Production is unaffected:** `Store::marshalErrorBag()` rehydrates it into a `ViewErrorBag` on the next request. This was verified explicitly — a validation failure followed by a page render returns 200, and all 49 views using `$errors`/`@error` are safe.

### FIX-2 — Missing translation key `ui.user` rendered as a raw key on screen

**Severity:** Low (cosmetic, but on a demo screen)
**Files:** `lang/en/ui.php`, `lang/hi/ui.php`

`__('ui.user')` is used twice on the CGPSC Chairman screens (`admin/cgpsc-chairman/form.blade.php:68` and `index.blade.php:46`) but existed in neither locale. Laravel renders a missing key as the literal string, so both screens displayed **`ui.user`** where a field label belongs.

**Fix:** added `'user' => 'User'` and `'user' => 'उपयोगकर्ता'`, placed alphabetically alongside the existing `user_management` / `user_manual` / `user_roles` entries. This was the only genuinely missing key out of 1,449.

### FIX-3 — Every stored document downloaded as a blank page

**Severity:** **High — this was the one real demo blocker.**

All 8 document rows in `dpc_dev` pointed at files that were no longer on disk. `Storage::get()` returns `null` for a missing file, which the controller casts to `''`, so the endpoint returned **HTTP 200 with an empty body** — no error, no warning, just a blank page.

Measured before the fix:

| Document | Before |
|---|---|
| Proceedings × 2 (`01a03eee-ebe2…`, `01a03eee-ef2d…`) | 200, **0 bytes** |
| Promotion List × 2 (same proceedings) | 200, **0 bytes** |
| Proceedings + Promotion List (`01a03eee-e6e6…`) | 200, 2,443 bytes — **supersession banner only, no document body** |
| Circulation revised promotion list | file absent |

This affects the headline artefacts of the whole workflow: the DPC Proceedings and the Promotion List.

**Cause:** the files were already gone before this session — nothing run here deletes stored files, and every file under `storage/app/private` was dated 2026-08-31 or later with no `01a03eee-*` artefact present at all. The rows had outlived their files.

**Fix (approved before running, as it is destructive):** rebuilt the demo database with `php artisan demo:seed --force`. The seeder was first proven clean today against a throwaway database so the rebuild carried no risk.

Measured after the fix — all 8 serve real content:

| Document | After |
|---|---|
| Proceedings × 3 | 200 — 7,167 / 7,069 / 4,336 B |
| Promotion List × 3 | 200 — 8,787 / 9,782 / 5,786 B |
| Circulation note | 200 — 4,196 B |
| Circulation revised promotion list | 200 — 5,760 B |

Storage integrity now: **8/8 documents, 25/25 signatures present.** Orphaned files left behind by the throwaway verification database were removed after confirming no row references them.

---

## 4. Remaining Issues

None block the demo. All four are presenter-awareness items.

### REM-1 — Two accounts on the credentials sheet cannot be logged into

**Impact:** presenter confusion on stage.

`demo:seed` prints `ds.gad@cg.gov.in / 9000009001` and a second `9000009002` under *"Login (email or mobile)"* with role `—`. These are **not portal users**. They are external DPC committee members — the Deputy Secretary, General Administration Department, seeded in `DemoJourneySeeder::committee()` with `'user' => null`. A `User` row is created to hold the committee record, but it has no role, no department, and is unverified (`email_verified_at` and `mobile_verified_at` both null, `must_change_password` true).

Attempting to log in as either sends the presenter into OTP verification, and even past that they would reach nothing — the sweep confirms this identity gets 302 on 122 of 128 routes, the same surface as a guest.

**Recommendation:** do not attempt these two logins. Not changed here — altering the seeder or the cheat sheet would be a content change under freeze.

### REM-2 — Promotion candidates have blank login fields on the cheat sheet

14 `promotion_candidate` accounts print with an empty login column, because a Seniority List carries no email or mobile. Their only identifier is `employee_number`, which `LoginController` accepts as the third login field. This is by design; the cheat sheet simply cannot show it. If a candidate login is to be demonstrated, take the employee number from the Seniority List screen first.

### REM-3 — Three screens will demo with no data

| Table | Rows | Screen affected |
|---|---|---|
| `portal_notices` | 0 | Landing-page notice board renders empty |
| `import_batches` | 0 | Import preview/errors screens have no history to open |
| `dpc_evaluation_record_gaps` | 0 | Record-gap document route has nothing to show |

All three screens render correctly (200) — they are simply empty. The import screens can be populated live during the demo by uploading a template, which is a better demonstration anyway; that flow was verified working end to end.

### REM-4 — Date-sensitivity remains elsewhere in the test suite

FIX-1 removed one instance, but the same pattern — a hardcoded date compared against a fixture created at real-today — may exist in other test files. It affects the build gate only, never the running application, and the suite is green today and tomorrow. Worth a sweep after the demo, not before it.

### REM-5 — The Role Assignments transfer is refused *today*, and works on demo day

**Impact:** a rehearsal tonight will look like a broken screen.

Both lead demo officers hold postings with `effective_from = 2026-09-01` — today. The business rule requires a transfer to take effect *after* the current posting began, so:

| Effective from | Result |
|---|---|
| 2026-09-01 (today) | **Refused** — *"This officer's current posting began on 01-09-2026. A transfer must take effect after that date…"* |
| 2026-09-02 (demo day) | **Accepted** |

Verified by submitting the real POST through the CSRF-guarded stack inside a rolled-back transaction (demo database confirmed unmutated: 16 assignments before and after).

**This is correct behaviour and needs no fix.** If the screen is rehearsed today, expect the refusal and do not treat it as a defect.

### REM-6 — Only a role change can be shown, not a cross-department transfer

**Impact:** limits what the Role Assignments screen can demonstrate.

Only **1 of 9** departments has status `registered`, and `UserAssignmentController::index()` deliberately offers only registered departments — an officer posted to a department that has not completed S6 registration would hold a role over a department with no masters and nothing to do. The dropdown therefore contains one real choice: School Education, the officer's own department.

**What can be demonstrated:** a role change within School Education (Authorized Officer → Administrative Head), verified accepted.
**What cannot:** an inter-departmental transfer, as no second destination is offered.

Registering a second department beforehand would enable it, but that is a data change and was **not** made under Demo Freeze.

---

## 5. Demo Risk

**Overall risk: LOW.**

| Area | Risk | Basis |
|---|---|---|
| Server errors / crashes | **Very low** | 0 × 5xx and 0 uncaught exceptions across ~950 requests spanning every GET route and every role, run twice |
| Broken links / routes | **Very low** | All 270 route targets and every `route()` reference resolve; all view includes resolve |
| Reports and downloads | **Low** | Every export and all 8 stored documents verified byte-for-byte after the rebuild |
| File upload | **Low** | Full round trip verified through the CSRF-guarded stack; 25 existing import tests green |
| Missing translations | **Very low** | Full en/hi parity; the single gap is fixed |
| JavaScript / assets | **Low** | All files present, all JS syntax-clean, no build step, no CDN dependency |
| Permissions | **Very low** | Role isolation confirmed live and by three dedicated test suites |
| Data integrity | **Low** | Demo DB rebuilt today from a seeder proven clean; 8/8 documents and 25/25 signatures on disk |
| Bilingual operation | **Very low** | Every screen rendered in en and hi for all five roles — identical results, zero errors, no key leaks |
| Behaviour on demo day | **Very low** | Full suite re-run with the clock set to 2026-09-02 — 1,287 passed; no demo data expires or activates overnight |
| State-changing routes | **Very low** | All 134 audited; the six public ones are the auth flow, account-free Self-Inspection, and a signature-guarded upload route |
| Presenter error | **Medium** | The only real exposure — see REM-1, REM-2, REM-5 and REM-6 |

### Pre-demo checklist

1. Confirm MySQL and Redis are up (`php artisan db:show`, `redis-cli ping`).
2. Log in as `admin@cgpsc.gov.in` / `Demo@2026` and confirm the dashboard renders.
3. Open one Proceedings document and one Promotion List to confirm storage is intact.
4. Do **not** attempt the `ds.gad@cg.gov.in` login (REM-1).
5. Avoid the Portal Notices screen unless a notice is created first (REM-3).
6. On Role Assignments, demonstrate a **role change within School Education** — a cross-department transfer has no destination to offer (REM-6). If you rehearse **today**, the transfer will be refused; that is correct and it works tomorrow (REM-5).
7. If the database must be rebuilt on the day: `php artisan demo:seed --force` — verified working, takes about 35 seconds.

### Notes on configuration

- `APP_DEBUG=true` and `APP_ENV=local`. Fine for a controlled demo and useful if anything unexpected occurs, but it means a stack trace rather than a friendly error page would be shown. No errors were reachable in testing. Left unchanged deliberately — editing `.env` on demo eve carries more risk than it removes.
- `storage/logs/laravel.log` is 62 MB. Harmless, but worth rotating after the demo.
- A scratch schema `dpc_mig27` is left behind by `schema:verify-mysql`. Harmless; unrelated to `dpc_dev`.

---

## 6. Changes Made in This Session

Exactly three, all inside the permitted fix categories. No feature, business rule, schema, or working application code was altered.

| # | File | Change | Category |
|---|---|---|---|
| 1 | `tests/Feature/Admin/UserDepartmentAssignmentTest.php` | Froze the clock in `setUp()` so fixtures no longer depend on the real date | Test correctness |
| 2 | `lang/en/ui.php` | Added `'user' => 'User'` | Missing translation |
| 3 | `lang/hi/ui.php` | Added `'user' => 'उपयोगकर्ता'` | Missing translation |

Plus one approved data operation: `php artisan demo:seed --force`, rebuilding `dpc_dev` to restore the 8 documents whose files were missing (FIX-3).

**Post-change gate:** `pint` PASS · `phpstan` PASS · `php artisan test` 1,287 passed / 0 failed · `security:audit` PASS · `schema:verify-mysql` PASS.
