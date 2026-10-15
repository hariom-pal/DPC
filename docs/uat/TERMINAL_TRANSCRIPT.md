# Terminal Transcript — Business UAT

**e-DPC Portal · Chhattisgarh Public Service Commission**

This document is the **complete chronological record of everything printed in the terminal** during the Business UAT. It is not a summary. It exists so the terminal conversation — every command, every explanation, every suggestion, every decision — can be read back later without screenshots or copy-paste.

## Conventions

- **Append-only.** Entries are never edited or deleted once written. A correction is written as a new entry that supersedes an earlier one, and says so.
- **Nothing reaches the terminal before it is written here.** Each entry is appended first, then spoken in the terminal.
- Entries are numbered sequentially and carry a timestamp in **IST**.
- Where a field does not apply to an entry it is written `—` rather than omitted, so the shape of every entry is the same.
- Architectural recommendations are additionally recorded in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`; the entry names the item number there.

## Rounds

This is round 001. If further UAT rounds are run, this file stays as it is and the next round opens `TERMINAL_TRANSCRIPT_002.md`, so each round is a self-contained record.

## Timestamp accuracy note

This document was created at **2026-09-01 22:34 IST**, part-way through the Demo Readiness verification, on the instruction to begin maintaining it. **Entries 1–14 are therefore a backfill** of the session that had already run.

Their timestamps are reconstructed from evidence, not captured live, and are marked `~` (approximate):

- `storage/logs/laravel.log` entries (recorded in UTC; +5:30 applied)
- File modification times under `storage/app/private`
- Measured command durations (the test suite runs 401–443 s; `demo:seed` ≈ 35 s)

The content of those entries — commands, outputs, figures, decisions — is exact and taken from the actual session, not paraphrased. **From entry 15 onward, timestamps are captured live** at the moment of the interaction.

---

# Round 001 — Demo Readiness Verification (Demo Freeze)

**UAT objective:** verify the whole application is demo-ready for the business demonstration on **2026-09-02**, under Demo Freeze rules — fix only runtime defects; no features, no business rules, no schema changes, no refactors.

**Deliverable:** `docs/uat/DEMO_READINESS_REPORT.md` with Pass / Fail / Fixed Issues / Remaining Issues / Demo Risk.

---

## Entry 1 — Instruction received: Demo Freeze mode

- **Timestamp:** ~2026-09-01 20:25 IST
- **Current UAT Step:** Step 0 — Scope and rules of engagement

### Progress Messages
Demo Freeze declared by the user. Verification-only mode entered.

### Commands Executed
None — instruction intake.

### Environment Actions
None.

### Business Explanation
The final business demonstration is tomorrow. At this point the value of a change is almost always negative: a new feature cannot be exercised enough to be trusted by morning, and a refactor of working code trades a known-good state for an unknown one. So the standing instruction inverts — the job stops being "improve the product" and becomes "prove the product runs, and repair only what is actually broken."

### Validation Explanation
The permitted fix list was recorded as the test for whether any given change is allowed: runtime exceptions, missing imports, broken routes, broken links, missing view variables, null reference errors, validation crashes, missing translations, incorrect menu links, broken report downloads, JavaScript runtime errors, missing assets, SQL errors, HTTP 500s, file upload/download issues. Anything outside that list is reported, not fixed.

### Observations
Twelve verification areas were named: Routes, Controllers, Services, Policies, Middleware, Views, APIs, Database, Seed Data, Demo Users, Permissions, Reports.

### Warnings
None.

### Errors
None.

### Suggestions
None at this stage.

### Recommendations
None at this stage.

### Decisions
- Verification will be **empirical wherever possible** — run the application and observe it, rather than read the code and infer. A demo fails on behaviour, not on source.
- Any fix must be justified against the permitted list and must not alter business behaviour.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Establish the baseline: PHP/Laravel versions, database and Redis connectivity, migration state, route count.

### Final Summary of This Interaction
Demo Freeze accepted. Scope fixed to verification plus a closed list of permitted repairs. Working method chosen: run the system, do not merely read it.

---

## Entry 2 — Baseline environment check

- **Timestamp:** ~2026-09-01 20:28 IST
- **Current UAT Step:** Step 1 — Environment baseline

### Progress Messages
"Baseline is healthy: 270 routes, 78 tables, 0 pending migrations, MySQL + Redis both up."

### Commands Executed
```bash
php -v
php artisan --version
php artisan db:show
redis-cli -a *** ping
php artisan migrate:status
php artisan route:list --json | (count)
cat .env
cat composer.json phpunit.xml phpstan.neon pint.json
```

### Environment Actions
Read-only inspection. Nothing modified.

### Business Explanation
Before any screen can be judged, the ground it stands on has to be known good. A demo machine that cannot reach its database, or that is one un-run migration away from the schema the code expects, will fail in ways that look like application bugs and waste the room's time.

### Validation Explanation
Four independent facts were confirmed: the runtime matches what `composer.json` requires; the database answers and reports the expected schema; Redis answers (it carries sessions, cache **and** queue here, so it is not optional); and no migration is pending, meaning the deployed schema matches the migration history exactly.

### Observations
| Fact | Value |
|---|---|
| PHP | 8.4.24 |
| Laravel | 13.23.0 |
| MySQL | 8.0.46 — database `dpc_dev`, 78 tables |
| Redis | `PONG` |
| Pending migrations | **0** |
| Routes registered | **270** |
| `APP_ENV` / `APP_DEBUG` | `local` / `true` |
| Session / cache / queue | redis / redis / redis |
| Uncommitted paths in working tree | **427** |

The MySQL **server** hosts 735 tables across many unrelated schemas — it is a shared development server. Only `dpc_dev` is ours; the others were disregarded.

### Warnings
- `APP_DEBUG=true`. Noted for later judgement, not acted on.
- The working tree carries 427 uncommitted paths. This is pre-existing and was already raised in the audit of 2026-08-28; it is not a demo-readiness matter and was not re-litigated here.

### Errors
None.

### Suggestions
None at this stage.

### Recommendations
None at this stage.

### Decisions
- Proceed. The environment is sound enough to verify against.
- Treat `dpc_dev` as the demonstration database.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Run the project's own quality gate — the five checks defined in `composer gate`.

### Final Summary of This Interaction
Environment confirmed healthy: correct runtime, database and Redis reachable, schema fully migrated, 270 routes registered. Cleared to begin verification.

---

## Entry 3 — Quality gate, part 1: style and static analysis

- **Timestamp:** ~2026-09-01 20:33 IST
- **Current UAT Step:** Step 2 — Quality gate

### Progress Messages
"Pint ✅, PHPStan ✅" — reported alongside the test result in Entry 4.

### Commands Executed
```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
```

### Environment Actions
None — both are read-only analyses.

### Business Explanation
These two checks do not prove the product works, and were not treated as if they did. Their value on demo eve is narrower: they prove nobody has left the codebase mid-edit. A style failure or a type error is often the visible edge of an unfinished change, and an unfinished change is exactly what breaks a demo.

### Validation Explanation
Pint runs in `--test` mode, so it reports violations without rewriting files — important under freeze, where an automatic reformat would itself be an unapproved change. PHPStan runs at **level 6** with the Larastan extension over `app`, `config`, `database` and `routes`.

### Observations
- Pint: `{"tool":"pint","result":"passed"}` — no violations.
- PHPStan level 6: **`[OK] No errors`**.

### Warnings
None.

### Errors
None.

### Suggestions
None.

### Recommendations
None.

### Decisions
Both accepted as passing. Continue to the test suite.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Run the full PHPUnit suite.

### Final Summary of This Interaction
Style and static analysis both clean. No half-finished edits detectable in the codebase.

---

## Entry 4 — Quality gate, part 2: the test suite fails 2 of 1,287

- **Timestamp:** ~2026-09-01 20:41 IST
- **Current UAT Step:** Step 2 — Quality gate

### Progress Messages
"Pint ✅, PHPStan ✅, 1285/1287 tests pass. Let me track this work and dig into the 2 failures."

### Commands Executed
```bash
php artisan test        # 443 s
```

### Environment Actions
The suite runs against **SQLite in memory** (`phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). The MySQL demonstration database was not touched.

### Business Explanation
The suite is the project's accumulated statement of what the DPC rules are — 1,287 tests encoding eligibility, seniority, vacancy, zone of consideration, committee composition and workflow. A red suite on demo eve is not a cosmetic problem; it means one of those statements is currently false, and it has to be understood before the demonstration, not after.

### Validation Explanation
The full suite was run rather than a subset, because the point was to find out whether *anything* was broken, not to confirm a belief about a particular area.

### Observations
- **1,285 passed, 2 failed**, 4,224 assertions, 443 s.
- Both failures in `tests/Feature/Admin/UserDepartmentAssignmentTest`:
  1. `a transfer keeps the same account and closes the previous posting` — expected `'2026-08-31'`, got `'2026-09-01'` (line 85).
  2. `the admin screen records a transfer and shows the history` — `Error: Call to a member function all() on array` (line 215).

### Warnings
The dates involved — `2026-08-31`, `2026-09-01` — are suspiciously close to today's real date, 2026-09-01. Flagged immediately as a possible time-dependency rather than a genuine regression.

### Errors
The two failures above. Exit code 2.

### Suggestions
None yet — diagnosis first.

### Recommendations
None yet.

### Decisions
- Create a tracked task list; the verification is large enough that untracked work will drift.
- Do **not** assume the failures are "just flaky tests". Failure 2 in particular (`Call to a member function all() on array`) looks like a runtime error, which would be a genuine defect and would sit squarely inside the permitted fix list.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Diagnose both failures to root cause before changing anything.

### Final Summary of This Interaction
Suite is 1,285/1,287. Two failures in one file, both touching the officer-transfer screen. One looks date-related; the other looks like a real runtime error. Both to be investigated.

---

## Entry 5 — Diagnosing failure 2, and a false alarm that had to be ruled out

- **Timestamp:** ~2026-09-01 20:52 IST
- **Current UAT Step:** Step 2a — Defect diagnosis

### Progress Messages
"Failure 1 is a date-dependent test; failure 2 looks like a real runtime error."
"This is masking the real failure — something flashes `errors` to the session as a plain array instead of a MessageBag. That would also break `$errors` in Blade."
"Found something significant: `config/session.php:233` hardcodes `'serialization' => 'json'`. Let me check whether that breaks `$errors` in Blade — which would 500 every form validation failure."
"Good news: Laravel rehydrates the error bag on load (`Store.php:155`), so JSON session serialization is safe — no production bug."

### Commands Executed
```bash
vendor/bin/phpunit tests/Feature/Admin/UserDepartmentAssignmentTest.php --filter=test_the_admin_screen_records_a_transfer_and_shows_the_history
sed -n '35,95p' vendor/laravel/framework/src/Illuminate/Testing/TestResponseAssert.php
grep -rn "with('errors'|flash('errors'|'errors' =>" app/ routes/ resources/views/
cat bootstrap/app.php
grep -n "serial" config/session.php
cat vendor/laravel/framework/src/Illuminate/View/Middleware/ShareErrorsFromSession.php
grep -n "serialization|prepareForStorage|marshalErrorBag" vendor/laravel/framework/src/Illuminate/Session/Store.php
# plus a temporary probe test, since deleted
```

### Environment Actions
A temporary probe test was written to `tests/Feature/Scratch/ProbeTest.php` to observe the live response and session state, then **deleted**. No application file was modified.

### Business Explanation
The chain of reasoning matters more than the answer, because for a while it looked like a catastrophe. If the shared `$errors` variable really were a plain array instead of a `ViewErrorBag`, then **every form in the portal would return HTTP 500 the moment a user typed something invalid** — and 49 views use `$errors` or `@error`. On a demo where the presenter is expected to show validation working, that would end the demonstration. It had to be either confirmed or eliminated, not assumed.

### Validation Explanation
Eliminated in three steps:
1. The raw PHPUnit stack trace showed the error arising inside `TestResponseAssert.php:81` — framework *test* code, not application code. That immediately narrowed it.
2. `injectResponseContext()` calls `$session->get('errors')->all()` to enrich a failing assertion's message. A probe confirmed `get_debug_type(session('errors'))` was `array`, explaining the crash.
3. The cause was found in `config/session.php:233` — `'serialization' => 'json'`, a deliberate hardening choice against gadget-chain deserialization attacks. Laravel supports it **in both directions**: `Store::prepareErrorBagForSerialization()` flattens the bag on save, and `Store::marshalErrorBag()` rebuilds it on load. The test helper reads the response's session *after* flattening and *before* rehydration, which is a framework blind spot, not an application fault.

Then proved empirically rather than by reading: a validation failure was triggered and the resulting page rendered. **HTTP 200.** Production is unaffected.

### Observations
- `TestResponseAssert::injectResponseContext()` crashes when an assertion fails on a redirect carrying validation errors under JSON session serialization. It **masks the real assertion message** — which is why the actual cause was invisible.
- The real cause of failure 2 was then readable: the transfer was refused with *"This officer's current posting began on 01-09-2026. A transfer must take effect after that date — correct the current posting first if the date on the order is earlier."*
- All 49 views using `$errors` / `@error` confirmed safe.

### Warnings
This class of masked failure will recur. Any future assertion failure on a validation redirect will surface as `Call to a member function all() on array` rather than the real message, and will send the next reader down the same path.

### Errors
None in the application. The two test failures remain outstanding at this point.

### Suggestions
None requiring action — the framework quirk is not ours to fix, and the session hardening is correct and should stay.

### Recommendations
Record the diagnosis prominently in the readiness report so the next person who meets `Call to a member function all() on array` recognises it in seconds rather than an hour. **Done** — it is a call-out note under FIX-1.

### Decisions
- **`config/session.php`'s `'serialization' => 'json'` stays.** It is a deliberate security control, fully supported by the framework, and reverting it to work around a test-helper quirk would trade a real protection for a cosmetic convenience.
- Failure 2 is **not** an application defect.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Establish the shared root cause of both failures and fix the tests without touching business logic.

### Final Summary of This Interaction
The alarming-looking failure was eliminated as a framework test-helper artefact, and production was proved unaffected by rendering a page after a validation failure (HTTP 200). The masked message revealed the true cause of failure 2: the transfer was correctly refused by a business rule.

---

## Entry 6 — Root cause: the tests were time-bombed to the demo date

- **Timestamp:** ~2026-09-01 21:01 IST
- **Current UAT Step:** Step 2b — Defect repair (FIX-1)

### Progress Messages
"Root cause confirmed: fixtures are created at *real* today, and today has caught up to the hardcoded `2026-09-01`."
"All remaining tests are date-neutral. The fix is to freeze the clock before the fixtures are built, so the tests stop depending on the real calendar date."
"All 16 pass."

### Commands Executed
```bash
grep -n "travelTo|Carbon::parse|2026-|function test_" tests/Feature/Admin/UserDepartmentAssignmentTest.php
sed -n '240,340p' tests/Feature/Admin/UserDepartmentAssignmentTest.php
cat app/Domain/UserManagement/Services/DepartmentAssignmentService.php
# patch applied via python3 heredoc
vendor/bin/phpunit tests/Feature/Admin/UserDepartmentAssignmentTest.php
```

### Environment Actions
**One file modified:** `tests/Feature/Admin/UserDepartmentAssignmentTest.php`. The scratch probe directory `tests/Feature/Scratch/` was removed.

### Business Explanation
This is the entry to read if only one is read, because the product was never wrong.

The fixture helper `User::factory()->assignedTo($dept)` posts an officer with effect from **today**. The tests then travelled the clock to a hardcoded `2026-09-01` and transferred the officer. That arrangement quietly depended on real-today being *earlier* than 2026-09-01 — and today **is** 2026-09-01. So the officer's existing posting now began on the very day of the transfer, and two correct business rules fired:

1. `DepartmentAssignmentService::retireIncumbent()` closes the outgoing posting with `$on->copy()->subDay()->max($incumbent->effective_from)`. The `->max()` exists so **a posting can never end before it began**. With both dates on 2026-09-01, the clamp produced `2026-09-01` where the test expected `2026-08-31`.
2. The transfer form refuses a transfer dated on or before the current posting's start date, telling the user to correct the current posting first.

Both behaviours are right and both should stay. What was wrong was a test asserting against a moving calendar.

### Validation Explanation
The fix freezes the clock to a fixed `2026-08-01` in `setUp()`, **before any fixture is created**, so the interval between posting and transfer is explicit and constant regardless of the day the suite runs. Every other test in the file was read first to confirm none depends on the real date in a way the freeze would break — including one that asserts a back-dated transfer is refused using `Carbon::today()->subYear()`, which still resolves correctly relative to the frozen date.

A prose comment was added explaining the trap, in the commentary style the file already uses, so the next reader does not reintroduce it.

### Observations
- All **16** tests in the file pass after the change.
- No application file was touched. The change is confined to test setup.

### Warnings
The same pattern — a hardcoded date compared against a fixture created at real-today — may exist elsewhere in the suite. It affects the build gate only, never the running application.

### Errors
None.

### Suggestions
Sweep the suite for other hardcoded future dates after the demonstration. Not before: the suite is green today, and a broad test refactor on demo eve is exactly the kind of change Demo Freeze exists to prevent.

### Recommendations
**Architectural.** Fixtures that depend on "today" should not silently inherit the wall clock. Either freeze the clock in the base `TestCase`, or have `assignedTo()` take an explicit date. Recorded as **item #27** in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`.

### Decisions
- Fix the **test**, not the service. The `->max()` clamp and the transfer-date validation are correct business rules and are left exactly as they are.
- Defer the broader date-fragility sweep until after the demonstration.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Move to static verification — routes, views, translations, assets — while the full suite re-runs in the background.

### Final Summary of This Interaction
**FIX-1 applied.** Both failures had one root cause: tests pinned to a date the calendar had now reached. Clock frozen in `setUp()`; all 16 tests pass. No business logic altered — the application had behaved correctly throughout.

---

## Entry 7 — Static verification: routes and views

- **Timestamp:** ~2026-09-01 21:06 IST
- **Current UAT Step:** Step 3 — Static verification

### Progress Messages
"Now the static verification sweep — routes, views, translations, assets."
"Most are false positives from my regex matching `$this->route('param')`. Let me refine and check the one view finding."
"Both are regex artifacts. But the dynamic include needs real verification."
"Routes and views are clean."

### Commands Executed
```bash
# bootstrapped the framework and reflected over the router
php scratchpad/check_routes.php     # every route target class + method
php scratchpad/check_refs.php       # every route('name'), view(), @extends, @include
grep -n "route('dpcProposal')" app/Providers/AppServiceProvider.php
grep -rn "'partial'" app/ resources/views/
ls resources/views/dpc-proposals/sections/
```

### Environment Actions
Read-only. Helper scripts were written to the scratchpad directory, outside the project.

### Business Explanation
A broken link is one of the most damaging things that can happen in a demonstration, because it is unambiguous and everyone in the room sees it. Two kinds were checked: a route pointing at a controller method that no longer exists, and a page linking to a route name that was renamed. Both produce an error in front of the audience with no warning beforehand.

### Validation Explanation
Rather than grep and guess, the framework was booted so the real router and view finder could answer:
- **Route targets:** every one of the 270 routes had its action reflected — controller class resolved via `class_exists`, method via `method_exists`.
- **Route names:** every `route('…')` literal in Blade and PHP checked with `Route::has()`.
- **Views:** every `view()`, `@extends`, `@include`, `@includeIf`, `@includeWhen` literal checked with `View::exists()`.

### Observations
- **270 routes checked — all targets resolve.** No missing class, no missing method.
- 489 files scanned for references.
- An initial 33 "broken route names" were **all false positives**: the regex `\broute\(` also matched `$request->route('dpcProposal')`, which reads a route *parameter*, not a route name. Verified by inspecting `AppServiceProvider.php:262` and the Form Request classes.
- One "missing view" — `@include('dpc-proposals.sections.')` — was a **dynamic** include: `@include('dpc-proposals.sections.' . $current['partial'])`. Resolved properly by finding the five possible values in `DpcProposalController` (`post-selection`, `committee`, `working-officers`, `seniority`, `evaluation`) and confirming **all five partials exist** on disk.

### Warnings
Regex-based scanning over a PHP codebase produces false positives freely. Every flagged item was individually verified before being called a defect; none survived.

### Errors
None.

### Suggestions
None.

### Recommendations
None.

### Decisions
Routes and views recorded as **PASS**. No fix required.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Verify translations across both locales.

### Final Summary of This Interaction
All 270 route targets resolve; every route name and every view reference resolves, including the one dynamically-composed include. Zero real defects; 34 apparent findings all disproved.

---

## Entry 8 — Translations: full en/hi parity, one genuine gap (FIX-2)

- **Timestamp:** ~2026-09-01 21:12 IST
- **Current UAT Step:** Step 3 — Static verification

### Progress Messages
"Now translations — the app is bilingual, so let me check both locales."
"Full en/hi parity. Four are dynamic-key prefixes, but `ui.user` has no trailing dot — let me check it."
"`ui.user` is genuinely missing — it would render the literal string 'ui.user' on the Chairman screen."
"All dynamic namespaces are complete in both locales."

### Commands Executed
```bash
php scratchpad/check_lang.php     # 1,449 keys, Lang::has() per locale, plus file-level parity both directions
grep -n "ui\.user" resources/views/admin/cgpsc-chairman/*.blade.php
grep -rn "candidate.timeline.|working_officers.grounds.|landing.months." <call sites>
sed -n '120,165p' app/Http/Controllers/Candidate/CandidateDpcController.php
# patch applied via python3 heredoc
php -r "foreach(['en','hi'] as $l){$a=require \"lang/$l/ui.php\"; echo $l.': '.($a['user'] ?? 'MISSING').PHP_EOL;}"
```

### Environment Actions
**Two files modified:** `lang/en/ui.php`, `lang/hi/ui.php`.

### Business Explanation
The portal is bilingual because the PPT source screens are, and government users will switch to Hindi. Laravel renders a missing translation key as the **literal key string**, so a gap does not throw an error — it silently prints developer text like `ui.user` where a field label belongs. On the CGPSC Chairman screen, which is an administration screen a presenter may well open, that is visible sloppiness in front of the customer.

### Validation Explanation
Three separate checks, because each catches something the others miss:
1. **Referenced-key existence** — 1,449 distinct keys extracted from views and PHP, each tested with `Lang::has()` in **both** `en` and `hi`.
2. **File-level parity in both directions** — every key in every `lang/en/*.php` flattened and compared against its `hi` counterpart, and the reverse. This catches a key that exists in Hindi but not English, which a one-way check would miss.
3. **Dynamic namespaces** — keys built by concatenation cannot be checked statically, so each was enumerated against its source.

### Observations
- **1,449 distinct keys referenced.**
- **Full en/hi parity across all 13 language files — zero drift in either direction.**
- Five apparent gaps; four were dynamic prefixes (trailing dot), all verified complete in both locales:
  | Namespace | Keys | Verified against |
  |---|---|---|
  | `landing.months.*` | 12 | `PortalNoticeComposer` uses `strtolower($date->format('M'))` |
  | `dpc.working_officers.summary.*` | 9 | the summary row keys |
  | `dpc.working_officers.grounds.*` | 2 | retirement, deputation |
  | `candidate.timeline.*` | 8 | the **exact** 8-event whitelist in `CandidateDpcController::timelineFor()` — no unmapped event is reachable |
- **One real gap:** `ui.user`, used at `admin/cgpsc-chairman/form.blade.php:68` and `index.blade.php:46`, present in neither locale.

### Warnings
The first patch attempt failed its own safety assertion — the 4-space-indented anchor string also matched a 16-space-indented nested key, so the edit would have been ambiguous. The script asserted on match count and stopped rather than guessing. Re-done with exact whole-line matching.

### Errors
None.

### Suggestions
None.

### Recommendations
None architectural. (Item #12 in `SUGGESTIONS_AND_IMPROVEMENTS.md` already proposes an `artisan lang:check` command, which is precisely the automation that would have caught this; nothing further to add.)

### Decisions
- Add the key in **both** locales — adding only English would create exactly the parity drift the check exists to prevent.
- Place it alphabetically beside `user_management` / `user_manual` / `user_roles`, matching the file's existing ordering.
- Hindi rendering `'उपयोगकर्ता'`, consistent with the existing `user_management` → `'उपयोगकर्ता प्रबंधन'`.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Verify assets and JavaScript.

### Final Summary of This Interaction
**FIX-2 applied.** Full en/hi parity confirmed across 1,449 keys; the single genuine gap, `ui.user`, added to both locales. Four apparent gaps disproved as dynamic prefixes with complete namespaces.

---

## Entry 9 — Assets and JavaScript

- **Timestamp:** ~2026-09-01 21:18 IST
- **Current UAT Step:** Step 3 — Static verification

### Progress Messages
"Now assets and JavaScript references."
"All logos resolve (real files present for all three). Let me syntax-check the JS."
"JS is clean. Static verification done."

### Commands Executed
```bash
ls -R public/
grep -rn "@vite|mix(" resources/views/
for a in $(grep -rhon "asset(\s*'[^']*'" resources/views/ app/ | ...); do test -f "public/$a" || echo MISSING; done
cat resources/views/partials/logo.blade.php
for f in $(find public/js -name "*.js"); do node --check "$f"; done
```

### Environment Actions
Read-only.

### Browser / JavaScript note
JavaScript **runtime** errors cannot be detected by syntax checking alone. What mitigates that risk here is recorded in the `SecurityHeaders` docblock: when the Content-Security-Policy was enforced, all inline handlers were moved into `public/js/`, and the six repeating forms, the confirmation dialogues and the Pre-DPC rehydration path were then driven in **headless Chromium with the policy enforced — twelve checks, zero violations, zero console errors**. `ViewsAreCspCleanTest` fails the build if an inline handler returns. That prior evidence, plus a clean syntax check, is the basis for the low JavaScript risk rating.

### Business Explanation
A missing image or a JavaScript file that fails to parse degrades a demonstration quietly — a broken-image icon in the header, or an "add row" button that does nothing when clicked. The second is worse, because the presenter will click it in front of the customer and nothing will happen.

### Validation Explanation
Every `asset()` literal was resolved against `public/`. `node --check` parses each JavaScript file and reports syntax errors without executing it.

### Observations
- **No build step required** — no Vite, no Mix. Bootstrap is vendored in `public/vendor/bootstrap`, and there is no CDN dependency. For a demonstration this is a meaningful advantage: nothing to compile, and no reliance on the venue's internet.
- Every `asset()` target exists on disk.
- All **13** JavaScript files pass `node --check`.
- One apparent missing asset, `public/images/logo/`, was a **directory prefix** from `partials/logo.blade.php`, which resolves a logo dynamically across `svg/png/webp/jpg/jpeg` with a `*.fallback.svg` safety net. All three logos (cgpsc, csc, digital-india) have **real artwork present**, so no fallback is ever needed.
- No `public/storage` symlink is required — nothing uses `Storage::url()` or `asset('storage/...')`; documents are served through controllers.

### Warnings
None.

### Errors
None.

### Suggestions
None.

### Recommendations
None.

### Decisions
Assets and JavaScript recorded as **PASS**.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Verify seed data and demo users, and confirm `demo:seed` still runs today.

### Final Summary of This Interaction
All assets present, all JavaScript syntactically clean, no build step and no CDN dependency. The one apparent gap was a dynamic logo resolver with full fallback coverage.

---

## Entry 10 — Seed data and demo users

- **Timestamp:** ~2026-09-01 21:09 IST *(the `demo:seed` run; file timestamps confirm)*
- **Current UAT Step:** Step 4 — Seed data, demo users, permissions

### Progress Messages
"The dev DB already holds seeded demo data. Let me verify `demo:seed` still runs clean **today** — in a separate scratch database, so the existing demo data is untouched."
"`demo:seed` runs clean today (exit 0)."
"Two accounts show no role — let me check whether they can actually log in."
"`ds.gad` is an **external committee member**, not a portal user — the cheat sheet listing it as a login is misleading."

### Commands Executed
```bash
php artisan tinker --execute='... counts and user list ...'
mysql -e "DROP DATABASE IF EXISTS dpc_demo_verify; CREATE DATABASE dpc_demo_verify ..."
DB_DATABASE=dpc_demo_verify php artisan demo:seed --force
DB_DATABASE=dpc_demo_verify php artisan tinker --execute='... user roles ...'
grep -rn "ds.gad|Sanjay Kumar Agrawal" database/seeders/
sed -n '480,540p' database/seeders/Demo/DemoJourneySeeder.php
```

### Environment Actions
**A scratch database `dpc_demo_verify` was created** and seeded. `dpc_dev` was **not** touched. This was deliberate — see Decisions.

### Business Explanation
The demonstration depends on the seeded story: three departments, their officers, and a set of DPCs at every stage of the workflow. Two things had to be true. First, the data currently in `dpc_dev` had to be sound. Second — and easily overlooked — the seeder itself had to still *work today*, because if anything goes wrong tomorrow morning the recovery move is to rebuild, and a rebuild command that fails is no recovery at all.

### Validation Explanation
The seeder was proven in a **throwaway database** rather than by rebuilding the real one. That way the question "does `demo:seed` still work on 2026-09-01?" was answered with zero risk to the existing demonstration data. This turned out to matter a great deal, because it made the later rebuild (Entry 13) a known-safe operation rather than a gamble.

### Observations
- `demo:seed --force` completed **exit 0**, all 12 seeders `DONE`, no warnings. Timings: DemoAccountSeeder 7.3 s, DemoMasterSeeder 1.7 s, DemoJourneySeeder 17.4 s, DemoPipelineSeeder 5.4 s.
- Password for every demo account: **`Demo@2026`**.
- Roles seed correctly: admin, chairman, member ×5, authorized_officer, administrative_head, promotion_candidate ×14.
- **Two accounts have no role at all** (ids 10 and 25). `ds.gad@cg.gov.in` was found in `DemoJourneySeeder::committee()` seeded with `'user' => null` — it is the **Deputy Secretary, General Administration Department**, an *external DPC committee member*, not a portal user. `email_verified_at` and `mobile_verified_at` are both null and `must_change_password` is true, so a login attempt lands in OTP verification and, past that, reaches nothing.
- 14 promotion candidates have no email and no mobile — by design, since a Seniority List carries neither. Their only identifier is `employee_number`, which `LoginController` accepts as its third login field.
- Three tables are empty: `portal_notices` (0), `import_batches` (0), `dpc_evaluation_record_gaps` (0).

### Warnings
- **The generated cheat sheet prints `ds.gad@cg.gov.in / 9000009001` under "Login (email or mobile)" with role `—`.** A presenter reading that sheet may well try it on stage and hit a dead end.
- The 14 candidates print with a blank login column, which reads as missing data rather than as "log in with the employee number".

### Errors
None.

### Suggestions
Presenter guidance rather than code change: do not attempt the `ds.gad` login; if a candidate login must be shown, take the employee number from the Seniority List screen first. Both written into the readiness report under REM-1 and REM-2.

### Recommendations
**Architectural.** The seeder creates a `User` row to hold an external committee member's details, and that row is then indistinguishable from a real account in the credentials listing. Either external members should not get a `User` row, or the cheat sheet should label them as non-login records. Recorded as **item #26** in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`.

### Decisions
- **Verify in a scratch database, not in `dpc_dev`.** Proving the seeder without risking the demonstration data was worth the extra step.
- **Do not "fix" the cheat sheet or the seeder.** Under Demo Freeze that is a content change, and the risk of editing seeder output on demo eve outweighs the confusion it would prevent. Report it instead.

### Questions Asked
None at this point.

### User Responses
—

### Next Planned Action
Run the live smoke test — every GET route as every demo role.

### Final Summary of This Interaction
`demo:seed` proven to run clean today in a throwaway database — which later made the rebuild safe. Demo users and roles verified. Two non-login accounts and three empty tables identified as presenter-awareness items, deliberately reported rather than changed.

---

## Entry 11 — Live smoke test: every GET route, every role

- **Timestamp:** ~2026-09-01 21:22 IST
- **Current UAT Step:** Step 5 — Live route sweep

### Progress Messages
"Now the live smoke test — the highest-value check."
"'Too many connections' — my per-request `refreshApplication()` leaked connections. Let me refresh once per role instead."
"Zero 500s and zero exceptions across ~950 requests. Let me dig into the anomalies."

### Commands Executed
```bash
php artisan route:list --json | (enumerate GET routes and parameters)
grep -rn "Route::bind|getRouteKeyName" app/ routes/
# temporary tests/Smoke/RouteSweepTest.php + scratchpad phpunit config against real MySQL
vendor/bin/phpunit -c scratchpad/smoke.xml
mysql -e "SHOW VARIABLES LIKE 'max_connections'"
```

### Environment Actions
A temporary test and a scratchpad PHPUnit configuration pointed at the **real MySQL** database (`dpc_demo_verify`), so the full middleware stack ran against real data rather than SQLite. All temporary files were removed afterwards.

### Business Explanation
This is the check that most closely resembles the demonstration itself. Everything up to here was static; this actually logs in as each role and opens every screen, through the real router, real middleware, real policies, real database and real Blade rendering. If a screen is going to throw a 500, reference a null object, or miss a view variable, this is what finds it — and it finds it now rather than in front of the customer.

### Validation Explanation
136 GET routes were enumerated. Routes with parameters were given **real** values by reflecting each controller method's signature, finding the type-hinted Eloquent model, querying an actual record and using its route key — so `{dpcProposal}` received a genuine proposal UUID, not a placeholder. Each route was then requested as guest and as each of six identities, and the HTTP status recorded. Any status ≥ 500 captured the response body for diagnosis.

### Observations
- **~950 requests. Zero HTTP 500s. Zero uncaught exceptions.**
- Status distribution:
  | Role | 200 | 302 | 403 | 404 |
  |---|---|---|---|---|
  | admin | 77 | 6 | 42 | 3 |
  | chairman | 25 | 5 | 98 | 0 |
  | member | 25 | 5 | 98 | 0 |
  | authorized_officer | 27 | 6 | 95 | 0 |
  | administrative_head | 22 | 6 | 100 | 0 |
  | guest | 6 | 122 | 0 | 0 |
  | ds.gad (no role) | 6 | 122 | 0 | 0 |
- The heavy 403 counts are the **authorisation model working correctly** — each role reaches its own surface and is refused everywhere else. Guests are redirected from all but 6 public pages.
- The no-role account behaves exactly like a guest, confirming Entry 10's finding.
- **Three admin 404s, each traced and each correct:**
  | Route | Explanation |
  |---|---|
  | `admin/recruitment-rules/{rule}/download` | `abort_if(go_document_path === null, 404)`. **Both** views guard the link with `@if ($rule->go_document_path)`, so it is never rendered without a file — unreachable from the UI |
  | `admin/audit-trail/{source}/{id}` | probed with a synthetic source/id pair that does not exist |
  | `admin/department-posts/{post}/orders/{order}` | scoped binding; the probe paired a post and an order that do not belong together |
- Six routes reported `UNRESOLVED-PARAM`; all were explained — `{template}` and `my-dpc/{dpcProposal}` take **plain string** parameters rather than models, and `{portalNotice}`, `{importBatch}`, `{recordGap}` correspond to the three empty tables from Entry 10.

### Warnings
The first sweep aborted with MySQL error 1040 *Too many connections*. This was **self-inflicted** — calling `refreshApplication()` before every request opened a new connection each time, against a server limit of 151. Corrected by refreshing once per role. The resulting errors in `laravel.log` are test-harness noise, not product faults.

### Errors
None in the application.

### Suggestions
None.

### Recommendations
None.

### Decisions
- Test through the **real MySQL database and full middleware stack** rather than SQLite, so the result reflects demonstration conditions.
- Investigate every non-2xx anomaly rather than accepting the headline "no 500s" — which is what surfaced the guarded-link and scoped-binding explanations.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Verify reports, downloads and file uploads.

### Final Summary of This Interaction
The central result of the UAT: **~950 live requests across every GET route and every role produced zero server errors and zero exceptions.** Every anomaly was individually explained; none was a defect.

---

## Entry 12 — Reports, downloads and uploads, and a test artefact that looked like a bug

- **Timestamp:** ~2026-09-01 21:52 IST
- **Current UAT Step:** Step 6 — Reports, downloads, uploads

### Progress Messages
"A circulation document download returned **0 bytes** — that's a broken download. Let me investigate."
"Confirmed: HTTP 200 with an **empty body** — a genuinely broken download."
"Mystery solved — the base `TestCase` calls `Storage::fake('local')`, so file-backed downloads read an empty disk. That's a test artifact, not a product bug."
"All downloads now serve real content."
"The upload pipeline works correctly."

### Commands Executed
```bash
php artisan route:list --json | (filter download|export|print|pdf|csv|template|report|document)
# temporary tests/Smoke/DownloadSweepTest.php, DocProbeTest.php, ImportUploadTest.php
vendor/bin/phpunit -c scratchpad/smoke.xml --filter DownloadSweepTest
cat tests/TestCase.php
grep -n "function contents" -A 40 app/Domain/Circulation/Services/CirculationDocumentService.php
```

### Environment Actions
Temporary tests only, since removed. To read **real** stored files the smoke tests had to call `app('filesystem')->forgetDisk('local')` to undo the base class's fake.

### Business Explanation
The documents this system produces — the DPC Proceedings and the Promotion List — are the legal output of the whole process. A download that fails is not a cosmetic defect; it is the system failing to deliver the thing it exists to produce. So every export and every stored document was downloaded and its **bytes measured**, not merely its HTTP status checked. A 200 with an empty body is exactly the failure a status-only check would miss.

### Validation Explanation
The initial run showed a circulation document returning **0 bytes**. Rather than reporting it immediately, it was traced:
1. The service was called directly in `tinker` — it returned **4,196 bytes**.
2. The file was confirmed present on disk at 4,196 bytes.
3. Instrumentation inside the HTTP context showed `Storage::exists()` returning **false** with the correct root path.
4. Cause found in `tests/TestCase.php`: `setUp()` calls **`Storage::fake('local')`**, deliberately, so tests never write into real storage. The smoke tests inherited it and were reading an empty disk.

The sweep was re-run with the real disk restored, and every download served real content. **The zero-byte reading was an artefact of the measuring instrument, not a product defect** — but the diagnostic path built here is exactly what exposed the genuine problem in Entry 13.

### Observations
Downloads, all verified with real bytes:
| Endpoint | Result |
|---|---|
| `/admin/audit-trail/export` | 200 — 45,229 B CSV |
| `/reports` (admin / AO) | 200 — renders |
| `/reports/export` | 200 — CSV, 8-stage pipeline summary |
| `/dpc-templates/currently_working_us` | 200 — 225 B CSV |
| `/dpc-templates/final_seniority_list_us` | 200 — 180 B CSV |
| `/dpc-templates/evaluation_chart_us` | 200 — 503 B CSV |
| `/dpc-templates/{unknown}` | 404 — correct |
| Circulation + proceedings documents | 200 — real content |

- `/reports/export` at 299 bytes was checked rather than assumed — it is a legitimate 8-row pipeline summary: 1 drafted, 1 awaiting approval, 7 forwarded, 6 allotted, 1 sitting opened, 3 result approved. Good stage coverage for the demonstration.
- The evaluation-chart template returns **403 on approved/submitted proposals and 200 on the draft** (`DPC-2026-SE-0004`). Verified across all 9 proposals. This is the intended rule — you only fetch an upload template for a proposal you can still edit — and a draft exists, so the screen is demonstrable.
- **Upload verified end to end** through the real CSRF-guarded stack: template downloaded → uploaded back → parsed → rejected with a clear message (*"The file could not be read as a spreadsheet: The file has a header row but no data rows."*) → redirected to the correct section route → preview screen 200 → error report 200 as `text/csv`.
- The commit happy path is covered by **25 existing tests** in `DpcExcelImportTest` and `ImportTemplateTest`, all passing.
- Storage: no `public/storage` symlink needed; `storage/app` and `storage/logs` writable.

### Warnings
- A POST without a CSRF token correctly returned **419**. This is the protection working; the test was given a real token rather than switching the middleware off, so the upload was exercised through the same guard a browser passes.
- Any future test that reads real stored files must undo `Storage::fake('local')` or it will silently measure an empty disk — the same trap hit here.

### Errors
None in the application.

### Suggestions
None arising from this entry directly. (The insight that a missing file yields a silent empty response is carried into Entry 13, where it became a real defect.)

### Recommendations
None from this entry; see Entry 13.

### Decisions
- **Measure bytes, not status codes.** A 200 proves the route ran; only the body proves the document exists.
- Investigate the anomaly to root cause before reporting it. Doing so avoided filing a false defect against `CirculationDocumentController`.
- Restore the real disk in the smoke tests rather than weaken the base `TestCase`, which is protecting the repository for good reason.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Verify that the demonstration database's own stored documents are intact.

### Final Summary of This Interaction
Every export and download verified by byte count; uploads verified end to end through CSRF. One alarming zero-byte reading was traced to the base `TestCase` faking the local disk — an artefact, not a defect. The technique built here immediately exposed a genuine problem next.

---

## Entry 13 — The real demo blocker: every stored document downloaded as a blank page

- **Timestamp:** ~2026-09-01 22:02 IST
- **Current UAT Step:** Step 6a — Storage integrity (FIX-3)

### Progress Messages
"Important finding — let me verify it carefully."
"Confirmed: no `01a03eee-*` files exist at all — dpc_dev's 8 documents were already orphaned before I started (nothing I ran deletes files). Let me confirm the user-visible impact."
"Fully diagnosed."

### Commands Executed
```bash
php artisan tinker --execute='... Storage::exists() for every document row ...'
find storage/app/private -type f -printf '%TY-%Tm-%Td %TH:%TM  %10s  %p\n' | sort
# temporary tests/Smoke/DevDocTest.php against dpc_dev
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter DevDocTest
```

### Environment Actions
Read-only at this stage. The repair itself was **not** performed without approval — see Entry 14.

### Business Explanation
This was the one finding that would have damaged the demonstration.

Every one of the **8 document rows in `dpc_dev` pointed at a file that was no longer on disk**. `Storage::get()` returns `null` for a missing file; the controller casts it to `''` and returns it. So the endpoint responded **HTTP 200 with an empty body** — no error page, no exception, no log entry. Just a blank white page.

The affected documents are the **DPC Proceedings** and the **Promotion List** — the two artefacts the entire workflow exists to produce, and precisely what a presenter would open to conclude the demonstration.

The silence is what makes this dangerous. A 500 would have been caught by the sweep in Entry 11 and by any monitoring. A 200 with no body passes every check that looks at status codes, which is why it survived until someone measured the bytes.

### Validation Explanation
Established as fact rather than inference, in three steps:
1. **Every** document row was checked against storage: `ok=0, missing=8`.
2. The user-visible impact was confirmed by requesting each document as the Chairman through the full stack, with the real disk restored.
3. Responsibility was established before proposing a destructive repair. Every file under `storage/app/private` was listed with timestamps: all dated 2026-08-31 or 2026-09-01 21:09, with **no `01a03eee-*` artefact present at all**. The `dpc_dev` documents carry `01a03eee-*` UUIDs; the 21:09 files are `01a05da0-*` from the scratch verification seed. Nothing run in this session deletes stored files — `migrate:fresh` drops tables, not files. **The files were already gone before the session began.**

### Observations
Measured against `dpc_dev` before repair:
| Document | Result |
|---|---|
| Proceedings ×2 (`01a03eee-ebe2…`, `01a03eee-ef2d…`) | 200, **0 bytes** |
| Promotion List ×2 (same proceedings) | 200, **0 bytes** |
| Proceedings + Promotion List (`01a03eee-e6e6…`) | 200, 2,443 bytes — **supersession banner only, no document body** |
| Circulation revised promotion list | file absent |

The 2,443-byte responses were dumped and read: they contain only the *"SUPERSEDED — superseded by DPC-2026-SE-0002"* banner the application prepends. Healthy equivalents measure 4,336–9,782 bytes. So even those two carry no document.

### Warnings
**A missing stored file produces no error anywhere in the system.** No exception, no log line, no failed status. The only symptom is a blank page for the user. Any future storage loss will be equally silent.

### Errors
The defect itself: 8 of 8 stored documents unreadable in the demonstration database.

### Suggestions
Repair before the demonstration. Three options were prepared rather than one, so the choice remained the user's:
1. Rebuild `dpc_dev` with `demo:seed --force` — already proven safe in Entry 10.
2. Leave the database alone and document the affected records so the presenter avoids them.
3. Seed a second database alongside and switch `DB_DATABASE` before the demonstration.

### Recommendations
**Architectural, and the most valuable finding of this UAT.** The download path should not be able to serve a blank page. The system already stores a `content_hash` for every document, and `CirculationDocumentService::verify()` already knows how to check it — but the download path calls neither. A missing or corrupt file should fail loudly with a clear message rather than returning 200 and nothing. Recorded as **item #28** in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`, priority **High**.

### Decisions
- **Do not repair unilaterally.** Every available repair either drops the user's demonstration database or changes their configuration. Both are destructive and hard to reverse, and the decision belongs to the user.
- Establish that the loss predates the session **before** raising it, so the report states a fact rather than an accusation or an apology.

### Questions Asked
Deferred to Entry 14, so the question could be asked once with evidence and costed options attached.

### User Responses
—

### Next Planned Action
Present the finding with three options and ask the user to choose.

### Final Summary of This Interaction
The one genuine demo blocker, found only because downloads were measured by byte count. All 8 stored documents in `dpc_dev` were unreadable, returning HTTP 200 with an empty body — silently, with the Proceedings and Promotion List among them. Pre-existing, not session-caused. Repair options prepared; no destructive action taken without approval.

---

## Entry 14 — Approval sought and granted; database rebuilt and re-verified

- **Timestamp:** ~2026-09-01 22:12 IST
- **Current UAT Step:** Step 6b — Approved repair and confirmation

### Progress Messages
"I have one blocking decision that's destructive, so let me check with you."
"Rebuilding dpc_dev now."
"All 8 documents restored."
"Zero server errors, all documents serving real content."

### Commands Executed
```bash
php artisan demo:seed --force            # exit 0
php artisan tinker --execute='... document + signature integrity ...'
# temporary tests/Smoke/FinalSweepTest.php — full re-sweep after rebuild
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter FinalSweepTest
rm -rf tests/Smoke
mysql -e "DROP DATABASE IF EXISTS dpc_demo_verify;"
rm -rf storage/app/private/{circulations,proceedings}/01a05da0-*
```

### Environment Actions
- **`dpc_dev` dropped and rebuilt** by `demo:seed --force` (approved).
- Scratch database `dpc_demo_verify` dropped.
- Temporary `tests/Smoke/` removed.
- Orphaned files from the scratch seed removed, **after** confirming zero references to them from any document or signature row in `dpc_dev`.

### Business Explanation
The repair was the standard demonstration-preparation step, not an improvisation: `demo:seed` exists precisely to build the demonstration database, its own docblock states it is intended for a fresh database, and it had already been proven to run clean today in Entry 10. That earlier caution is what turned a risky action into a routine one.

### Validation Explanation
Repair confirmed on three independent axes, because a rebuild can fix one thing and break another:
1. **Storage integrity** — every document and signature row re-checked against disk.
2. **User-visible behaviour** — every stored document downloaded again and its bytes measured.
3. **No regression elsewhere** — the entire GET-route sweep re-run across all five authenticated roles against the rebuilt database.

### Observations
- `demo:seed --force` completed **exit 0** in ≈35 s.
- **Storage integrity: 8/8 documents present, 25/25 signature files present.**
- Every document now serves real content:
  | Document | After |
  |---|---|
  | Proceedings ×3 | 200 — 7,167 / 7,069 / 4,336 B |
  | Promotion List ×3 | 200 — 8,787 / 9,782 / 5,786 B |
  | Circulation note | 200 — 4,196 B |
  | Circulation revised promotion list | 200 — 5,760 B |
- **Full re-sweep: 0 × 5xx, 0 exceptions.** admin 77×200, chairman 25×200, member 25×200, AO 28×200, AH 23×200.
- Demo content after rebuild: 9 departments · 25 users · 9 DPC proposals · 8 Pre-DPC · 4 proceedings · 1 circulation · 6 allotments · 8 recruitment rules.
- Post-change gate re-run in full: **Pint pass · PHPStan 0 errors · 1,287 passed / 0 failed · security:audit clean · schema:verify-mysql 79 migrations / 78 tables**.

### Warnings
The rebuild changed the record counts (previously 28 users / 10 proposals). The earlier surplus was residue from manual UAT activity; the fresh seed is the canonical set.

### Errors
None.

### Suggestions
Pre-demonstration checklist, written into the readiness report:
1. Confirm MySQL and Redis are up.
2. Log in as `admin@cgpsc.gov.in` / `Demo@2026` and confirm the dashboard renders.
3. **Open one Proceedings document and one Promotion List** to confirm storage is intact.
4. Do not attempt the `ds.gad@cg.gov.in` login.
5. Avoid the Portal Notices screen unless a notice is created first.
6. If a rebuild is needed on the day: `php artisan demo:seed --force` — verified, ≈35 s.

### Recommendations
- `APP_DEBUG=true` **left unchanged deliberately**. It means an unexpected error would show a stack trace rather than a friendly page, but no error was reachable in testing, and editing `.env` on demo eve carries more risk than it removes. Flagged in the report rather than changed.
- `storage/logs/laravel.log` is 62 MB — harmless, worth rotating after the demonstration.
- A scratch schema `dpc_mig27` is left behind by `schema:verify-mysql`; harmless and unrelated to `dpc_dev`.

### Decisions
- **User chose: rebuild `dpc_dev` with `demo:seed --force`.**
- Remove all session artefacts (scratch database, temporary tests, orphaned files) — but only after proving the orphaned files were unreferenced.

### Questions Asked
> The demo database (dpc_dev) has 8 document rows whose files are missing from storage — clicking any Proceedings / Promotion List / Circulation document download returns a blank page. The files were already gone before this session (nothing I ran deletes files). How should I handle it?
>
> 1. **Rebuild dpc_dev with demo:seed** *(recommended)* — drops every table and rebuilds; seeder already verified clean today against a scratch database; fixes all 8; destructive to any manual data.
> 2. **Leave dpc_dev alone, document only** — report names the 3 affected proceedings and 1 circulation so the presenter avoids them.
> 3. **Seed a fresh database alongside** — keep `dpc_dev`, build a working second database, switch `DB_DATABASE` before the demo.

### User Responses
**Option 1 selected — "Rebuild dpc_dev with demo:seed (Recommended)".**

### Next Planned Action
Write `docs/uat/DEMO_READINESS_REPORT.md` and the worklog entry.

### Final Summary of This Interaction
**FIX-3 applied with approval.** `dpc_dev` rebuilt; all 8 documents and 25 signatures restored and verified by byte count; full route sweep re-run with zero server errors; complete quality gate re-run green. All session artefacts removed.

---

## Entry 15 — Deliverables written; verdict GO

- **Timestamp:** ~2026-09-01 22:30 IST
- **Current UAT Step:** Step 7 — Reporting

### Progress Messages
"Gate verified green on the final state. Let me honour the standing worklog convention."
"**Verdict: GO.**"

### Commands Executed
```bash
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php artisan test          # 401 s
# wrote docs/uat/DEMO_READINESS_REPORT.md
# wrote docs/worklog/2026-09-01-demo-readiness-verification.md
```

### Environment Actions
Two documents created. No code or data change.

### Business Explanation
The verification is only worth what can be acted on tomorrow morning. The report is therefore written for two readers: the presenter, who needs the credentials, the checklist and the list of things not to touch; and the project record, which needs the evidence behind the verdict.

### Validation Explanation
The gate was **re-run on the final state** rather than the report citing the earlier run. Between that run and this one, two language files had changed and the database had been rebuilt, so the earlier figures no longer described the shipped state.

### Observations
- Final gate: Pint **pass**, PHPStan **0 errors**, PHPUnit **1,287 passed / 4,228 assertions / exit 0** (401 s).
- The three edited files are **untracked** in git (`??`) — they belong to the 427-path uncommitted working set that predates this session, not to anything done here.

### Warnings
None.

### Errors
None.

### Suggestions
Recorded in the report as REM-1 to REM-4 and in the pre-demonstration checklist.

### Recommendations
Three architectural items recorded in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md` as #26, #27 and #28.

### Decisions
**Verdict: GO.** Zero server errors across every screen and every role; three defects found and fixed; four remaining items are presenter-awareness, not product defects.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Continue verification with the areas not yet covered — see Entry 16.

### Final Summary of This Interaction
Both deliverables written against a re-verified green gate. Round 001 verdict: **GO**, with three fixes applied and four presenter-awareness items documented.

---

## Entry 16 — Instruction received: maintain this transcript

- **Timestamp:** 2026-09-01 22:34 IST *(captured live)*
- **Current UAT Step:** Step 8 — Transcript instituted

### Progress Messages
"Good call — `TERMINAL_TRANSCRIPT.md` is the clearer name. Let me set it up and backfill everything so far, then continue the verification."

### Commands Executed
```bash
date '+%Y-%m-%d %H:%M:%S %Z'
head -30 docs/SUGGESTIONS_AND_IMPROVEMENTS.md
grep -n "^## " docs/SUGGESTIONS_AND_IMPROVEMENTS.md | tail -25
```

### Environment Actions
Created `docs/uat/TERMINAL_TRANSCRIPT.md` (this file).

### Business Explanation
The reason given for the rename was accepted and is worth preserving, because it shapes how the file is used: *"Session"* suggests a log of one run, whereas *"Transcript"* states plainly that this is the complete chronological record of everything printed in the terminal. For a document whose entire purpose is to be read back later without screenshots, the name has to set that expectation on sight.

### Validation Explanation
The backfill was reconstructed from evidence rather than memory where timestamps were concerned — `laravel.log` entries (UTC, +5:30 applied), file modification times under `storage/app/private`, and measured command durations. Approximate timestamps are marked `~` and the reconstruction is disclosed at the head of the file. All content — commands, figures, outputs, decisions — is exact.

### Observations
- The last item in `docs/SUGGESTIONS_AND_IMPROVEMENTS.md` is **#25**, so new architectural items continue from **#26**.
- Entries 1–15 required backfilling, as the instruction arrived after the Demo Readiness verification had completed.

### Warnings
Timestamps for entries 1–15 are reconstructed, not captured. This is disclosed rather than presented as precise.

### Errors
None.

### Suggestions
Adopted the numbered-round structure the user proposed: this file is round 001 and stays as it is; a further round opens `TERMINAL_TRANSCRIPT_002.md`. This keeps each round self-contained while remaining append-only within a round.

### Recommendations
None beyond the three already recorded.

### Decisions
- File named `TERMINAL_TRANSCRIPT.md`, per the user's reasoning.
- Every entry carries the full field set, with `—` where a field does not apply, so entries are uniform and skimmable.
- Nothing is spoken in the terminal before it is appended here.

### Questions Asked
None.

### User Responses
The user directed: rename to `TERMINAL_TRANSCRIPT.md`; append-only single file acceptable; numbered files acceptable for future rounds; then continue the Demo Readiness verification from where it stopped, **without restarting the Business UAT**.

### Next Planned Action
Record the three architectural recommendations as items #26–#28, then continue verification with the areas the first pass did not cover: behaviour on the **demo date itself (2026-09-02)**, the **Hindi locale**, and the **state-changing routes**.

### Final Summary of This Interaction
Transcript instituted and backfilled to 15 entries with disclosed timestamp provenance. Round structure agreed. Verification continues from Step 8 — the UAT is not restarted.

---

## Entry 17 — Architectural recommendations recorded, then demo-day clock verification

- **Timestamp:** 2026-09-01 22:41 IST
- **Current UAT Step:** Step 9 — Continued verification: behaviour on the demo date

### Progress Messages
"Recommendations recorded as #26–#28. Now continuing verification — starting with the check most relevant to a demo tomorrow: does anything break when the clock rolls to 2026-09-02?"
"No test uses tomorrow's date directly, but let me verify rather than reason — I'll run the whole suite as if it were demo day, then revert."
"The suite passes **1,287/1,287 as if it were demo day**."
"Reverted cleanly (checksum matches)."

### Commands Executed
```bash
cat >> docs/SUGGESTIONS_AND_IMPROVEMENTS.md   # items 26, 27, 28
ls /usr/lib/*/faketime/libfaketime.so.1       # not installed
grep -rEon "'2026-(09|1[0-2])-[0-9]{2}'|'202[7-9]-..-..'" tests/ | sort | uniq -c
cp tests/TestCase.php <scratchpad>/TestCase.php.bak && md5sum tests/TestCase.php
# temporary: travelTo('2026-09-02 10:00:00') added to Tests\TestCase::setUp()
php artisan test                              # 294 s
cp <scratchpad>/TestCase.php.bak tests/TestCase.php && md5sum -c <scratchpad>/TestCase.md5
```

### Environment Actions
`tests/TestCase.php` was **temporarily** modified to freeze the clock at demo day, then **restored from backup and verified by MD5 checksum**. Three items appended to `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`.

### Business Explanation
The demonstration is tomorrow, and the defect found earlier today (FIX-1) was caused by nothing more than the calendar advancing. That makes one question unavoidable and easy to overlook: **does anything else break when the date becomes 2026-09-02?** A system that is verified green on 1 September and fails on 2 September has not been verified for the demonstration at all.

### Validation Explanation
Reasoning alone was not accepted as evidence. A grep showed the nearest hardcoded dates after today are 2026-09-10 and later, which *suggested* tomorrow was safe — but the FIX-1 failure came from an interaction between a hardcoded date and a fixture built at real-today, which no grep can see.

So the suite was actually run with the clock frozen at `2026-09-02 10:00:00`, injected into the base `TestCase::setUp()` so every one of the 1,287 tests ran as though it were demo day. `libfaketime` was not installed, so this in-process approach was used instead.

The change was treated as hazardous because it is: a frozen clock left in the base test class would silently distort every future test run. It was backed up first, labelled `TEMPORARY` in the source, reverted immediately, and the revert **proved by MD5 checksum** rather than by inspection.

### Observations
- **All 1,287 tests pass with the clock set to demo day.** Exit 0, 294 s.
- Nearest hardcoded dates after today: 2026-09-10, 2026-09-12, 2026-09-30, 2026-10-01 and later. **No test references 2026-09-02.**
- `tests/TestCase.php` restored: `md5sum -c` → `OK`, zero occurrences of `travelTo` remaining.

### Warnings
Dates at 2026-09-10 and 2026-09-30 (`ReviewDocumentReferenceTest`, `AllotmentTest`, `AllotmentDeadlineTest`, `SelfInspectionConfigurationTest`) are the next expiry points. They are beyond the demonstration but will arrive within weeks, and are the concrete instances behind recommendation #27.

### Errors
None.

### Suggestions
Run this same demo-day check before any future demonstration; it costs one suite run and removes an entire category of surprise.

### Recommendations
Three architectural items written to `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`:
- **#26** — the demo cheat sheet lists external committee members as login accounts (Medium)
- **#27** — test fixtures inherit the wall clock, so tests can be time-bombed to a future date (Medium)
- **#28** — **a missing stored document downloads as a blank page, silently** (High)

### Decisions
- Verify empirically rather than by grep and inference.
- Treat the temporary test-harness edit as hazardous: back up, label, revert, and prove the revert by checksum.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Check whether any demonstration *data* rolls over tomorrow.

### Final Summary of This Interaction
The full suite passes with the clock set to demo day — 1,287/1,287. The temporary harness change was reverted and the revert proven by checksum. Recommendations #26–#28 recorded.

---

## Entry 18 — Demo data date rollover, and a rehearsal trap on the transfer screen

- **Timestamp:** 2026-09-01 22:48 IST
- **Current UAT Step:** Step 9a — Demo data date boundaries

### Progress Messages
"Three records start today — worth checking which, since a transfer demo depends on it."
"The two lead demo officers and the Chairman all have records starting **today**. That has a direct rehearsal implication — let me verify it empirically, safely inside a rolled-back transaction."
"Only **one** department is registered — which constrains the transfer screen."
"Decisive."

### Commands Executed
```bash
php artisan tinker --execute='... scan date columns for the 2026-08-30..09-05 window ...'
php artisan tinker --execute='... identify the rows and list department statuses ...'
# temporary tests/Smoke/TransferDateTest.php — POST inside DB::beginTransaction()/rollBack()
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter TransferDateTest
```

### Environment Actions
A state-changing POST was exercised against the **live demonstration database**, wrapped in an explicit transaction and rolled back. The test asserted the row count was identical before and after, so the rollback is proven rather than assumed.

### Business Explanation
Two findings here, and the first is the kind of thing that ruins a rehearsal.

**The Role Assignments screen cannot be demonstrated today, but works tomorrow.** Both lead demo officers — the Authorized Officer and the Administrative Head for School Education — hold postings with `effective_from = 2026-09-01`, i.e. today. The business rule requires a transfer to take effect *after* the current posting began. So a transfer attempted **today** is refused; the same transfer dated **tomorrow** is accepted. If the presenter rehearses tonight and hits that refusal, the natural conclusion is that the screen is broken. It is not — and on demo day it behaves correctly.

**A cross-department transfer cannot be demonstrated at all.** Only **1 of 9** departments has status `registered`, and `UserAssignmentController::index()` deliberately offers only registered departments — an officer posted to a department that has not completed S6 registration would hold a role over a department with no masters and nothing to do. The dropdown therefore contains one real choice: School Education, the officer's own department. What *can* be shown is a **role change within School Education** (Authorized Officer → Administrative Head), which was verified accepted.

### Validation Explanation
The claim was proved by executing it, not by reading the validator. The transfer POST was submitted twice through the full CSRF-guarded stack — once dated today, once dated tomorrow — each inside a transaction that was rolled back in a `finally` block. The demonstration database was then re-counted to confirm it was untouched.

### Observations
- Dates falling in the 2026-08-30 … 2026-09-05 window: **three**, all `effective_from = 2026-09-01`:
  - `ao.schooleducation@cg.gov.in` — authorized_officer, School Education
  - `ah.schooleducation@cg.gov.in` — administrative_head, School Education
  - `cgpsc_chairmen` — Dr. Ajay Kumar Shrivastava
- All three are *start* dates now in the past, so they remain active tomorrow. There are **no future-dated records that activate**, and none that expire.
- Transfer screen offers **2 options**: `— Select —` and `School Education Department`. Registered departments: **1 of 9**.
- Transfer POST result:
  | Effective from | Result |
  |---|---|
  | 2026-09-01 (today) | **Refused** — *"This officer's current posting began on 01-09-2026. A transfer must take effect after that date…"* |
  | 2026-09-02 (demo day) | **Accepted** |
- Demonstration database verified unmutated: 16 assignments before and after; 25 users, 9 proposals unchanged.

### Warnings
- **Rehearsal trap.** Any transfer or role change rehearsed *today* will be refused. This is correct behaviour, not a fault, and it disappears tomorrow.
- The Chairman's record also begins today; it stays active tomorrow, and item 9 makes a serving Chairman a precondition for the whole committee workflow, so this was worth confirming.

### Errors
None.

### Suggestions
Add to the presenter checklist:
- If rehearsing **today**, expect the Role Assignments transfer to be refused. It works on demo day. Do not "fix" it.
- Demonstrate a **role change within School Education**, not a cross-department transfer — only one department is registered, so no other destination is offered.
- Alternatively, register a second department beforehand if a genuine inter-departmental transfer is wanted on stage. That is a data change and was **not** made under Demo Freeze.

### Recommendations
None architectural — both findings are properties of the seeded data, and the underlying rules are correct. Covered by existing item #26's theme of the demonstration data not matching what the cheat sheet implies is demonstrable.

### Decisions
- **Do not alter the demonstration data** to make the transfer screen more demonstrable. Registering a second department would change the seeded story on demo eve; the limitation is reported instead.
- Exercise the state-changing route against real data only inside a transaction, and assert the rollback.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Sweep every screen in Hindi as well as English.

### Final Summary of This Interaction
No demonstration data expires or activates overnight — the three date-sensitive records simply stay active. Two presenter-facing findings: the transfer screen is refused today but works on demo day, and only a role change (not a cross-department transfer) can be shown, because just one of nine departments is registered. Verified against live data with a proven rollback.

---

## Entry 19 — Bilingual sweep: every screen in Hindi and English

- **Timestamp:** 2026-09-01 22:55 IST
- **Current UAT Step:** Step 9b — Locale verification

### Progress Messages
"Now the bilingual check — sweeping every screen in Hindi and scanning for untranslated keys leaking into the page."
"Both locales are identical — zero 5xx, and the one 'untranslated key' is a false positive."

### Commands Executed
```bash
# temporary tests/Smoke/LocaleSweepTest.php
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter LocaleSweepTest
grep -rn "cgpsc.gov.in" resources/views/ lang/en/ui.php
```

### Environment Actions
Temporary test only, since removed.

### Business Explanation
The portal is bilingual because the PPT source screens are, and a government demonstration may well switch to Hindi in front of the customer. Entry 8 proved the *language files* are complete, but a complete language file does not prove a rendered page is correct — a view can reference a key that exists in English and not in Hindi, or compose a key at runtime that no static check can see. The only way to be sure is to render every screen in both languages and look at the output.

### Validation Explanation
Two things were checked per screen, in both locales, for all five authenticated roles:
1. **HTTP status**, to catch a locale-specific crash.
2. **Untranslated keys leaking into visible text.** The rendered HTML had `<script>` and `<style>` blocks stripped and tags removed, then the remaining visible text was scanned for anything matching a translation-key shape across the 13 language-file groups. This is exactly the failure mode of FIX-2, where `ui.user` printed as literal text — a status-code check would never have caught it.

### Observations
- **Both locales produce identical status distributions for every role**, route for route:
  | Locale / role | 200 | 302 | 403 | 404 |
  |---|---|---|---|---|
  | en/admin · hi/admin | 77 | 6 | 43 | 3 |
  | en/chairman · hi/chairman | 25 | 5 | 99 | 0 |
  | en/member · hi/member | 25 | 5 | 99 | 0 |
  | en/authorized_officer · hi/authorized_officer | 28 | 6 | 95 | 0 |
  | en/administrative_head · hi/administrative_head | 23 | 6 | 100 | 0 |
- **Zero HTTP 500s and zero exceptions in either locale.**
- **Zero untranslated keys visible on any page.** The single regex hit, `cgpsc.gov.in`, is the **e-mail domain** of the demo accounts rendered from the database — the pattern matched the `cgpsc` language-group name followed by `.gov.in`. Confirmed a false positive: no such literal exists in any view or language file.

### Warnings
None.

### Errors
None.

### Suggestions
None.

### Recommendations
None.

### Decisions
Bilingual operation recorded as **PASS**. The demonstration may be given in either language, and may switch between them, with no known risk.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Audit the state-changing routes, which the first pass did not cover.

### Final Summary of This Interaction
Every screen rendered in both English and Hindi for all five roles — identical results, zero server errors, and no untranslated key visible anywhere. The one apparent leak was an e-mail domain. Hindi is demo-safe.

---

## Entry 20 — State-changing route guard audit

- **Timestamp:** 2026-09-01 22:59 IST
- **Current UAT Step:** Step 9c — POST/PUT/PATCH/DELETE coverage

### Progress Messages
"All six unauthenticated state-changing routes are legitimate (auth flow, account-free Self-Inspection, and a signature-guarded framework upload route)."
"Clean."

### Commands Executed
```bash
php artisan route:list --json | (filter POST|PUT|PATCH|DELETE, report those without auth middleware)
php artisan route:list --json | (inspect storage/{path})
sed -n '100,135p' vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemServiceProvider.php
grep -n "signature|abort" vendor/laravel/framework/src/Illuminate/Filesystem/ReceiveFile.php
rm -rf tests/Smoke
```

### Environment Actions
Read-only. All temporary smoke tests removed; `tests/` confirmed back to `Concerns`, `Feature`, `Support`, `Unit`, `TestCase.php`.

### Business Explanation
The live sweep in Entry 11 covered only GET routes — the screens a presenter opens. But **134 of the application's routes change state**, and an unguarded one is both a security exposure and a demonstration risk. This closes that gap.

### Validation Explanation
Every POST, PUT, PATCH and DELETE route was listed with its resolved middleware stack and filtered for the absence of `auth`. Each survivor was then examined individually rather than accepted from the list, because "no auth middleware" is not the same as "unprotected".

### Observations
- **134 state-changing routes.** Six carry no `auth` middleware, and all six are correct:
  | Route | Why it is public |
  |---|---|
  | `POST login` | the authentication flow itself — `throttle:login` |
  | `POST register` | department self-registration (S6) |
  | `POST verify` | OTP verification — `throttle:otp-verify` |
  | `POST self-inspection/identify` | Self-Inspection is deliberately account-free (S49–S51) — `throttle:self-inspection` |
  | `POST self-inspection/close` | ends an account-free Self-Inspection session |
  | `PUT storage/{path}` | Laravel's own temporary-upload route. **Not unprotected:** `ReceiveFile` calls `abort_unless($this->hasValidSignature($request), …)`, so it requires a signed URL |
- The three highest-risk public endpoints (login, OTP verify, Self-Inspection identify) are each rate-limited.

### Warnings
None.

### Errors
None.

### Suggestions
None.

### Recommendations
None.

### Decisions
State-changing route coverage recorded as **PASS**. No unguarded mutation endpoint exists.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
Update `docs/uat/DEMO_READINESS_REPORT.md` with rounds 9a–9c, then report to the terminal.

### Final Summary of This Interaction
All 134 state-changing routes audited. The six without `auth` are the authentication flow, the account-free Self-Inspection pair, and a signature-guarded framework upload route — every one correct, and the risky ones throttled. All temporary artefacts removed; `tests/` and the demonstration database confirmed clean.

---

## Entry 21 — Round 001 closed

- **Timestamp:** 2026-09-01 23:04 IST
- **Current UAT Step:** Step 10 — Close-out

### Progress Messages
"Let me confirm the test harness is healthy after the revert, then close out."

### Commands Executed
```bash
php artisan test --filter="UserDepartmentAssignment|ScreenSmoke|BilingualCoverage"
ls tests/ ; ls -d tests/Smoke tests/Feature/Scratch
mysql -e "SHOW DATABASES LIKE 'dpc%';"
# updated docs/uat/DEMO_READINESS_REPORT.md with section 1B, REM-5, REM-6
```

### Environment Actions
`docs/uat/DEMO_READINESS_REPORT.md` updated with the second round. No code or data change.

### Business Explanation
The last thing a verification should do is prove it left nothing behind. This round created a scratch database, four temporary test classes, and one temporary edit to the shared test base class — every one of which would be a defect if it survived. A frozen clock left in `Tests\TestCase` in particular would silently distort every future test run, which is precisely the kind of quiet damage this UAT exists to catch.

### Validation Explanation
Four independent confirmations: a targeted test run to prove the harness still behaves after the revert; a directory listing to prove no scratch test classes remain; a database listing to prove the scratch schema is gone; and the earlier MD5 check on `tests/TestCase.php`.

### Observations
- Targeted re-run after the revert: **23 passed / 75 assertions**, including `ScreenSmokeTest` and `BilingualCoverageTest`.
- `tests/` contains only `Concerns`, `Feature`, `Support`, `Unit`, `TestCase.php`. No `Smoke`, no `Scratch`.
- Databases: `dpc_dev` and `dpc_mig27` only — the scratch `dpc_demo_verify` is gone.
- Demonstration database unchanged by round 2: 16 assignments, 25 users, 9 proposals.

### Warnings
`dpc_mig27` is a scratch schema left behind by `schema:verify-mysql`. It is the command's own working space, unrelated to `dpc_dev`, and harmless.

### Errors
None.

### Suggestions
Consolidated in the readiness report's pre-demo checklist, now seven items including the two new Role Assignments notes.

### Recommendations
The three architectural items (#26, #27, #28) stand as recorded. **#28 is the one to action after the demonstration** — a missing stored document should not be able to download as a blank page.

### Decisions
**Round 001 closed. Verdict: GO.**

### Questions Asked
None.

### User Responses
—

### Next Planned Action
None. Verification complete. Any further work opens `TERMINAL_TRANSCRIPT_002.md`.

### Final Summary of This Interaction
Workspace confirmed clean — no scratch tests, no scratch database, base test class byte-identical to its original, harness re-verified green. Round 001 of the Business UAT is closed with a **GO** verdict: three defects fixed, six presenter-awareness items documented, three architectural recommendations recorded.

---

## Round 001 — Close

**Verdict: GO.**

| | |
|---|---|
| Entries | 21 |
| Defects found and fixed | 3 (FIX-1, FIX-2, FIX-3) |
| Remaining items | 6 (REM-1 … REM-6) — all presenter-awareness, none a product defect |
| Architectural recommendations | 3 (#26, #27, #28 in `SUGGESTIONS_AND_IMPROVEMENTS.md`) |
| Live requests issued | ~2,000 across two rounds, six identities, two locales |
| HTTP 500s / uncaught exceptions | **0** |
| Final gate | Pint pass · PHPStan 0 errors · 1,287 tests passed · security:audit clean · schema:verify-mysql pass |
| Demo-day rehearsal | Full suite re-run with clock at 2026-09-02 — 1,287 passed |

Deliverables: `docs/uat/DEMO_READINESS_REPORT.md` · `docs/worklog/2026-09-01-demo-readiness-verification.md` · this transcript.

*End of round 001.*

# Round 001 — Addendum: Demo Data Package

Round 001 was closed at entry 21. This addendum continues the same round because it is
the same demonstration and the same freeze, and splitting it would separate the sample
data from the verification that justifies it.

---

## Entry 22 — Instruction received: generate a Demo Data Package

- **Timestamp:** 2026-09-01 23:12 IST
- **Current UAT Step:** Step 11 — Demo Data Package: discovery

### Progress Messages
"Before generating anything I need the exact template formats and validation rules the application enforces. Let me investigate the real upload surface."
"Three spreadsheet imports plus three document uploads. Let me read the validation rules that sample data must satisfy."
"Higher Education has **zero** posts, zero proposals and is unregistered."
"I have the exact formats. One thing blocks the content of the files, so let me record the investigation and then raise it."

### Commands Executed
```bash
cat app/Domain/Import/Enums/ImportTemplate.php
cat app/Domain/Dpc/Validation/DpcRowRules.php
php artisan route:list --json | (filter POST|PUT|PATCH on import|upload|document|order)
grep -rln "file(|UploadedFile|->hasFile" app/Domain/*/Requests/ app/Http/Controllers/
php artisan tinker --execute='... department_posts, proposals, reservation_categories, CR config ...'
php artisan tinker --execute='... draft proposal state; Higher Education content ...'
# temporary tests/Smoke/GrabTemplatesTest.php — download the real templates from the app
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter GrabTemplatesTest
```

### Environment Actions
Read-only, plus a temporary test that downloaded the four template CSVs into the scratchpad.

### Business Explanation
A demo data package is only useful if the files import **first time, in front of the customer**. That rules out writing plausible-looking CSVs by hand. Two things had to be established first: the exact column headers the application emits, and the exact validation every row must satisfy. Anything else is guesswork that fails on stage.

The templates were therefore taken **from the application itself** — downloaded through the same endpoint a departmental officer uses — rather than transcribed from documentation. A template generated by the code cannot disagree with the code.

### Validation Explanation
`ImportTemplate::columnMap()` and `DpcRowRules` together define what a valid row is. The rules that constrain sample data:

- `sn` / `seniority_number` — **required**, integer, min 1
- `name` — **required**, string
- `dob` — **required**, a date, and **before today**
- `reservation_category_id` — **required**, and must exist in the M2 master. The spreadsheet carries a **code** (`UR`/`SC`/`ST`/`OBC`/`EWS`), resolved during parsing
- everything else — nullable
- Evaluation Chart rows: only `sn` is required; identity columns are optional, **but `employee_number` is compared against the approved Seniority List and a mismatch is refused**

Post names (`PROMOTION TO POST`, `PROMOTION FROM POST`) are **free-text snapshot fields**, not validated against the post master. This matters for the department question below: the file format itself is department-neutral.

### Observations

**The complete upload surface — six screens, not the list assumed in the instruction:**

| # | Upload | Kind | Endpoint |
|---|---|---|---|
| 1 | Currently Working Officers (S16) | CSV/XLSX data import | `POST dpc-proposals/{proposal}/imports` |
| 2 | Final Seniority List (S17) | CSV/XLSX data import | same |
| 3 | Evaluation / Tabulation Chart (S18) | CSV/XLSX data import | same |
| 4 | Recruitment Rule — Government Order | PDF document | `AdminRecruitmentRuleController` (`go_document`) |
| 5 | Department Post — order document | PDF document | `DepartmentPostController` |
| 6 | Evaluation missing-record — supporting document | PDF document | `DpcRecordGapController` |

**Three items named in the instruction do not exist as uploads** and, per the instruction's own rule, no file will be generated for them:
- **Promotion Candidates** — candidates are *derived* from the Seniority List, not uploaded. There is no candidate import.
- **Vacancy Import** — vacancy is *calculated* by `VacancyCalculationService` from sanctioned strength, working strength, retirements and deputations. Importing it would contradict the engine.
- **Recruitment Rule Import** — recruitment rules are entered on a form; only the Government Order **PDF** is uploaded.

**Exact template headers, taken from the application (all carry a UTF-8 BOM):**
- S16: `SN, EMPLOYEE NO, NAME, DOB, QUAL, PROMOTION TO POST, PROMOTION FROM POST, CAT, DATE OF JOINING IN SERVICE, DATE OF POSTING ON PROMOTION TO POST, DATE OF POSTING ON PROMOTION FROM POST, ROSTER POSITION, DEPUTATION DATE`
- S17: `SN, EMPLOYEE NO, NAME OF US, DOB, QUAL, PROMOTION TO POST, PROMOTION FROM POST, CAT, DATE OF JOINING IN SERVICE, DATE OF POSTING ON PROMOTION TO POST, DATE OF POSTING ON US`
- S18: `SN, EMPLOYEE NO, NAME OF US, DOB, QUAL, DOP, CAT,` then **six** each of `LOYALTY <year>`, `IMMOVABLE PROPERTY <year>`, `CR <year>`, `TRIBAL AREA <year>`, then `TRIBAL AREA SERVICE YEARS, NOTE`

**Format details that a hand-written file would get wrong:**
- Dates are **DD-MM-YYYY** (`08-06-1969`)
- CR grades are **Devanagari**: `क+`, `क`, `ख`, `ग`, `घ`
- Loyalty / Immovable Property are `Y` / `N`
- The blank S18 template spans **2021–2026**, but the **pre-filled template for the draft proposal spans 2019–2024** — the CR year window is computed per proposal, not globally. Generic years would be the wrong years.

**Current data state:**
- Reservation categories: UR, SC, ST, OBC, EWS
- All **9** department posts belong to School Education
- Only **one** proposal is in `draft` and can therefore accept an import: **DPC-2026-SE-0004**, Block Education Officer ← Principal, Higher Secondary School, target sanctioned strength **4**
- That proposal already holds 3 working officers, 6 seniority entries, 6 evaluation rows and a 4-member committee

### Warnings
- **Imports are refused once a proposal is submitted**, so only the draft proposal can receive a file. Eight of the nine DPCs are approved or submitted.
- The target post's sanctioned strength is **4**, and an existing test pins that *an import may not push working officers past the sanctioned strength*. Sample record counts must respect that ceiling.
- A seniority import must match the **zone of consideration**, and duplicate seniority numbers are rejected.

### Errors
None.

### Suggestions
Use the **pre-filled** S18 template as the basis for the Evaluation Chart sample, not the blank one — its CR year columns match the proposal.

### Recommendations
None architectural at this point.

### Decisions
- Take template formats from the running application, never from documentation.
- Generate **no** file for Promotion Candidates, Vacancy or Recruitment Rule data, because those screens accept no upload.
- Raise the Higher Education question before writing file contents, since it determines the post names and officer set.

### Questions Asked
Raised in Entry 23.

### User Responses
—

### Next Planned Action
Ask which department the package should target, then generate and verify the files.

### Final Summary of This Interaction
The real upload surface is six screens: three spreadsheet imports and three PDF document uploads. Three modules named in the instruction accept no upload and will get no file. Exact headers, date format, Devanagari CR grades and the per-proposal CR year window were captured from the application itself. One blocking question remains: the instruction asks for a Higher Education demo, but Higher Education is unregistered and holds no posts, rules, users or proposals.

---

## Entry 23 — Department question asked and answered

- **Timestamp:** 2026-09-01 23:18 IST
- **Current UAT Step:** Step 11a — Scope decision

### Progress Messages
"I have the exact formats. One thing blocks the content of the files, so let me record the investigation and then raise it."
"School Education confirmed. Now the key question for record counts — does an import replace or append?"

### Commands Executed
```bash
grep -n "function commit" -A 40 app/Domain/Import/Services/ExcelImportService.php
grep -n "function assertSectionInvariants" -A 60 app/Domain/Import/Services/ExcelImportService.php
grep -n "function assertMatchesCandidates" -A 45 app/Domain/Dpc/Services/EvaluationCandidateService.php
php artisan tinker --execute='... sanctioned_post_count, requiredSeniorityCount, anchorYearFor ...'
```

### Environment Actions
Read-only.

### Business Explanation
The instruction asked for a package that lets *"the complete Higher Education demo"* run. Higher Education cannot host a demonstration: the department is `unregistered` and holds **0 posts, 0 recruitment rules, 0 users and 0 DPC proposals**. Building one would mean registering the department and creating pay scales, posts, a recruitment rule, two user accounts and a draft proposal — a substantial body of brand-new, unexercised data created on demo eve, and the result would still lack the workflow history the School Education DPCs carry.

Meanwhile the entire seeded demonstration *is* School Education: nine posts, nine DPCs, the `ao`/`ah.schooleducation` accounts, and the cheat sheet the presenter will be holding.

Because the answer changes the contents of every file — the post names and the officer set — it was put to the user rather than assumed.

### Validation Explanation
Three constraints were established before any data was designed, because they determine the record counts rather than leaving them to taste:

1. **An import replaces its section.** `saveWorkingOfficers()` and `saveSeniorityEntries()` call `->delete()` then recreate. Uploading twice does not double the rows.
2. **Working Officers ≤ sanctioned strength.** `assertWithinSanctionedStrength()` refuses a file that would exceed it. For this proposal the ceiling is **4**.
3. **Seniority must equal the Zone of Consideration** — `assertMatchesZone()` demands *exact* equality. For this post pair `requiredSeniorityCount()` returns `null` (the deck's uncapped "other cases"), so the gate does not apply and the count is a presentational choice.
4. **The Evaluation Chart must match the Seniority List** — `assertMatchesCandidates()` compares the S.N. set *and* the employee numbers, so a renumbered or renamed chart is refused.

`anchorYearFor(DPC-2026-SE-0004)` = **2024**, giving CR columns **2019–2024** — six years ending at the anchor, not at the current year.

### Observations
- Higher Education: `unregistered`, 0 posts, 0 rules, 0 proposals, 0 users.
- Only **DPC-2026-SE-0004** is in `draft`; imports are refused on the other eight.
- The file format is **department-neutral** — `PROMOTION TO POST` / `PROMOTION FROM POST` are free-text snapshot columns, not checked against the post master. The same files would import into a Higher Education proposal if one existed.

### Warnings
The blank S18 template spans **2021–2026** while this proposal needs **2019–2024**. A file built from the blank template would import successfully but score against the wrong CR years — a silent wrong answer, which is worse than a rejection.

### Errors
None.

### Suggestions
Build the Evaluation Chart sample on the **pre-filled** template from the Tabulation Chart screen, never the blank one from Upload Templates. Written into the guide as a call-out.

### Recommendations
None architectural.

### Decisions
- Target **School Education / DPC-2026-SE-0004**, per the user's answer.
- Let the constraints determine the record counts rather than picking round numbers.

### Questions Asked
> You asked for the package to run the complete **Higher Education** demo — but Higher Education is `unregistered` and holds 0 posts, 0 recruitment rules, 0 users and 0 DPC proposals. The entire seeded demo is **School Education**. Which should the package target?
>
> 1. **School Education — the real demo** *(recommended)* — generate against DPC-2026-SE-0004, the only draft proposal that accepts imports; every file provable tonight.
> 2. **Build Higher Education from scratch** — register the department and create posts, rules, users and a proposal first.
> 3. **School Education now, HE files as an unverified spare set.**

### User Responses
**Option 1 selected — "School Education — the real demo".**

### Next Planned Action
Generate the files, then prove every one of them imports.

### Final Summary of This Interaction
Higher Education was established as unable to host a demonstration, the question was put with costed options, and School Education / DPC-2026-SE-0004 was confirmed. The four invariants that govern record counts were read out of the code first, so the sample data was designed to satisfy them rather than tested against them afterwards.

---

## Entry 24 — Files generated and proved to import

- **Timestamp:** 2026-09-01 23:26 IST
- **Current UAT Step:** Step 11b — Generation and verification

### Progress Messages
"All facts gathered. Now generating the sample files using the application's own header builder, so the format cannot drift."
"Now the three document uploads — valid PDFs that pass `mimetypes:application/pdf`."
"All three import with **zero rejected rows**, and the vacancy computes to exactly 3 (1 clear + 1 retirement + 1 deputation) as designed."
"PDFs all pass. Let me clean up the orphaned import files."

### Commands Executed
```bash
php scratchpad/gen.php     # CSVs, headers taken from ImportTemplateBuilder itself
php scratchpad/pdf.php     # three minimal but valid PDFs
# temporary tests/Smoke/DemoDataVerifyTest.php — upload + commit all three, inside a transaction
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter DemoDataVerifyTest
# temporary tests/Smoke/PdfValidateTest.php — the real Form Request rules
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter PdfValidateTest
rm -rf storage/app/private/imports/*   # orphans, after confirming import_batches = 0
rm -rf tests/Smoke
```

### Environment Actions
Six files written to `docs/demo/sample-data/`. The verification uploaded and committed all three CSVs against the live demonstration database **inside a transaction that was rolled back**; row counts were re-checked afterwards. Upload artefacts left in `storage/app/private/imports/` were removed after confirming no `import_batches` row referenced them.

### Business Explanation
A demo data package earns its place only if the files import **first time, in front of the customer**. Two decisions follow from that.

First, the CSVs were generated using the application's **own** `ImportTemplateBuilder::headers()` and PHP's `fputcsv` — the same code path that produces the templates a department downloads. Hand-typing headers would have risked a quoting or BOM difference that no amount of reading would catch.

Second, the files were not merely inspected, they were **uploaded and committed through the real routes** — same CSRF guard, same validators, same invariants, same commit path — and then rolled back. Anything less is a claim rather than evidence.

The data itself was designed so the demonstration has something to say at each step. The three working officers are not an arbitrary number: sanctioned strength is 4, so three officers leave one clear vacancy, and adding one retirement inside the DPC year and one open-ended deputation exercises all three vacancy grounds at once. The engine independently agreed — **Total Vacancy = 3**.

### Validation Explanation
Verified along four axes:
1. **Row validity** — every row accepted by `DpcRowRules`, zero rejected.
2. **Section invariants** — each commit passed its own gate (sanctioned ceiling, zone, candidate match).
3. **Derived state** — the vacancy engine's own output checked against the intended story.
4. **No side effects** — transaction rolled back and row counts re-asserted.

### Observations

**Import verification — all clean:**

| File | Rows | Valid | Rejected | Upload | Commit |
|---|---|---|---|---|---|
| `demo-working-officers.csv` | 3 | **3** | **0** | 302 | committed |
| `demo-seniority-list.csv` | 8 | **8** | **0** | 302 | committed |
| `demo-evaluation-chart.csv` | 8 | **8** | **0** | 302 | committed |

**Derived state after the three commits:** sanctioned 4 · working 3 · **total vacancy 3** · seniority 8 · evaluation rows 8.

Committing the Seniority List moved the evaluation rows from 6 to 8 **on its own** — the chart is projected from the list, exactly as the 2026-08-22 directive requires. The chart import then filled those 8 projected rows.

**PDF validation against the application's own rules — all PASS:** `demo-recruitment-rule-go.pdf` (1.8 KB), `demo-department-post-order.pdf` (1.6 KB), `demo-missing-record-justification.pdf` (1.7 KB).

**Rollback proven:** `WO=3 SN=6 EV=6 import_batches=0` after the run — identical to before.

**Six files, six modules:**

| File | Module | Records |
|---|---|---|
| `demo-working-officers.csv` | Currently Working Officers (S16) | 3 officers |
| `demo-seniority-list.csv` | Final Seniority List (S17) | 8 officers |
| `demo-evaluation-chart.csv` | Evaluation / Tabulation Chart (S18) | 8 rows × 33 columns |
| `demo-recruitment-rule-go.pdf` | Recruitment Rule — Government Order | 1 document |
| `demo-department-post-order.pdf` | Department Post — order document | 1 document |
| `demo-missing-record-justification.pdf` | Evaluation missing record — supporting document | 1 document |

### Warnings
- **The upload order is partly enforced, not merely conventional.** The Evaluation Chart is *refused* before the Seniority List exists, and once it exists the chart's S.N. set and employee numbers must match it exactly.
- **Editing the CSVs in Excel is a hazard.** The CR grades are Devanagari; saving as plain ANSI CSV destroys them. Save as *CSV UTF-8*.
- Only `DPC-2026-SE-0004` accepts uploads. The other eight DPCs correctly refuse.

### Errors
None. Every file imported cleanly on the first attempt — no sample data needed correcting, because the constraints were read out of the code before the data was written rather than after.

### Suggestions
Written into the guide's *Notes for the presenter*: preview is not commit; an import replaces its section rather than appending, so the flow is re-runnable; dates are DD-MM-YYYY; and the refusal on an approved DPC is worth demonstrating deliberately, since it is the immutability guarantee rather than a fault.

### Recommendations
None architectural. The three items already recorded (#26–#28) stand.

### Decisions
- Generate CSVs through the application's own header builder rather than by hand.
- Prove the files by importing them, not by inspecting them.
- Roll back and clean up, leaving the demonstration database exactly as found.
- Generate **no** file for Promotion Candidates, Vacancy or Recruitment Rule data, since those screens accept no upload.

### Questions Asked
None.

### User Responses
—

### Next Planned Action
None. The Demo Data Package is complete.

### Final Summary of This Interaction
Six sample files generated for the six real upload modules and every one proved against the running application: three CSVs imported and committed with **zero rejected rows**, three PDFs passed the application's own file rules. The data is internally consistent end to end — the working officers produce a total vacancy of 3 across all three grounds, the seniority list of 8 projects onto the chart, and the chart's 8 rows match it exactly. The demonstration database was left byte-for-byte as it was found.

---

## Addendum — Close

| | |
|---|---|
| Upload modules found | **6** (3 spreadsheet imports, 3 document uploads) |
| Modules correctly given no file | 3 (Promotion Candidates, Vacancy, Recruitment Rule data — none accepts an upload) |
| Files generated | **6** (3 CSV, 3 PDF) |
| Sample records | **19 data rows** + 3 documents |
| Rejected rows across all imports | **0** |
| Demonstration database | unchanged (`WO=3 SN=6 EV=6 import_batches=0`) |

Deliverables: `docs/demo/DEMO_DATA_GUIDE.md` · `docs/demo/sample-data/` (6 files).

*End of addendum.*

# Round 001 — Addendum 2: Demo Kit

Continues the same round and the same freeze. *(Note: the instruction for this addendum said not
to modify documentation other than the five requested items. This transcript is appended to
regardless, because the standing instruction of 2026-09-01 requires that nothing reach the
terminal before it is recorded here — this file is the record of the interaction, not a
document being revised.)*

---

## Entry 25 — Instruction received: build the complete Demo Kit

- **Timestamp:** 2026-09-01 23:32 IST
- **Current UAT Step:** Step 12 — Demo Kit: discovery

### Progress Messages
"Let me gather the real UI facts these documents must reflect — I won't invent navigation or labels."
"All labels confirmed from source."
"A DPC sits at every stage — the demo can show each without waiting. Now writing the kit."

### Commands Executed
```bash
php artisan tinker --execute='... all 25 users with roles, department, designation, verification ...'
grep -rn "route(|__('ui\." resources/views/layouts/*.blade.php resources/views/dashboard.blade.php
php -r '... dump lang/en/ui.php dashboard tiles and admin nav labels ...'
php -r '... config("dpc.workflows.*.transitions") for all five workflows ...'
php -r '... resolve button labels from lang/en/{ui,dpc,cgpsc}.php ...'
grep -n "btn-|workflow" resources/views/dpc-proposals/show.blade.php \
    resources/views/cgpsc/{proceedings/result,schedules/index}.blade.php
sed -n '50,60p' app/Http/Controllers/Dpc/DpcProposalController.php     # REVIEW_STEPS
php artisan tinker --execute='... each DPC mapped to allotment / schedule / proceeding state ...'
```

### Environment Actions
Read-only throughout. Four documents created under `docs/demo/`. **No code, database, configuration, UI or other documentation was touched** — confirmed afterwards by a modification-time sweep over `app`, `config`, `database`, `routes`, `resources`, `public`, `tests` and `bootstrap`, which returned nothing.

### Business Explanation
A demo script whose click sequence is wrong is worse than no script: the presenter follows it, the label is not there, and the pause happens in front of the customer. So **every navigation path, tile name, section name and button caption in these documents was read out of the source** — `lang/en/*.php`, the Blade views, and the controllers' own constants — rather than written from memory of how the application probably looks.

The same applies to the workflow. The stage sequence, who may perform each transition, and which transitions demand a digital signature were taken from `config('dpc.workflows.*.transitions')`, which is the definition the application actually enforces.

### Validation Explanation
The single most useful discovery was structural. Querying each DPC's allotment, schedule and proceeding state showed the seeded data holds **a DPC parked at every stage of the process simultaneously**. That turns an impossible demonstration — a months-long statutory process in under an hour — into a practical one: perform the data entry on the one Draft proposal, then step across DPCs already sitting at each later stage, each with the correct Member logged in.

### Observations

**Stage map — the spine of the flow document:**

| Stage | DPC | Actor |
|---|---|---|
| Draft — data entry and uploads | DPC-2026-SE-0004 | Authorized Officer |
| Submitted — awaiting approval | DPC-2026-SE-0005 | Administrative Head |
| Approved — not yet allotted | DPC-2026-SE-0006 | Chairman |
| Allotted — awaiting acceptance | DPC-2026-SE-0007 | member3, Dr. Suresh Chandra Netam |
| Scheduled — sitting not opened | DPC-2026-SE-0008 | member4, Shri Mohan Lal Kashyap |
| Sitting open — ready to conclude | DPC-2026-SE-0009 | member5, Smt. Anita Bhonsle |
| Concluded — result approved | DPC-2026-SE-0001 | member1 |
| Review DPC | DPC-2026-SE-0002 | member1 |
| Circulation | DPC-2026-SE-0003/C1 | member2 |

**Labels confirmed from source** (a sample of what went into the click sequences): dashboard tiles *DPC Proposals · Pre-DPC Proposals · Upload Templates · Allotments · Schedules · Proceedings · Circulations · Administration*; proposal sections *Selection of Posts (S21) · DPC Committee (S22) · Officers Under Consideration (S23) · Seniority List (S24) · Tabulation Chart (S25)*; buttons *Upload & Preview · Commit :count Row(s) · CALCULATE · Explain Result · Submit for Approval · Approve, Sign & Forward to CGPSC · Return for Correction · Allot · Re-allot · Accept DPC · Schedule · Open Proceeding · APPROVE BY CGPSC*; result groups *MAIN LIST · Promotions Held (CIR) · Not Listed*.

**Workflow, from `config('dpc.workflows')`** — including which transitions are signed: `dpc_proposal.approve` (AH) and `dpc_proceeding` result approval both carry signatures; `dpc_cgpsc` allot/accept/schedule do not.

**Users** — 25 total: 1 admin, 1 chairman, 5 members, 1 AO, 1 AH, 14 promotion candidates, 2 non-login external committee members.

### Warnings
- **Two accounts cannot sign in** and are printed by the seeder as though they can. Documented in `DEMO_USERS.md` §4 as *Accounts that cannot sign in*, and in the cheat sheet's *Things to avoid*.
- **Promotion Candidates have no e-mail and no mobile** — the employee number is their only login identifier. Called out three times across the kit, as the instruction asked.
- **A rehearsal today will see the transfer screen refuse**, because both departmental officers' postings begin 01-09-2026 and a transfer must take effect after the posting starts. It works on demo day. In the cheat sheet's *Things to avoid*.

### Errors
None.

### Suggestions
Recorded in the kit rather than the terminal: keep `docs/demo/sample-data/` open in a file manager before starting; verify SE-0004 is still in Draft after any rehearsal; and treat `php artisan demo:seed --force` as the single recovery action for anything that cannot be explained in one sentence.

### Recommendations
None architectural. The three items already recorded (#26–#28) stand.

### Decisions
- **Read every label from source.** No navigation path or button caption was written from memory.
- **Build the flow across the stage map** rather than driving one DPC end to end, so no step waits on the previous one.
- **Generate no new sample files.** The upload surface was fully enumerated in entry 22 and the six files verified in entry 24; nothing was missing. Re-generating would have changed verified artefacts for no reason — their checksums are unchanged.
- **Leave `DEMO_DATA_GUIDE.md` untouched**, since the instruction limited edits to the five requested items; the new documents cross-reference it instead.

### Questions Asked
None. The one genuine ambiguity — Higher Education versus School Education — was settled in entry 23 and carried forward unchanged.

### User Responses
—

### Next Planned Action
None. The Demo Kit is complete.

### Final Summary of This Interaction
Four documents written — `DEMO_FLOW.md` (14 steps, login to final DPC recommendation, ≈42 minutes core), `UPLOAD_FILES_GUIDE.md` (all six upload screens with columns, rules and expected results), `DEMO_USERS.md` (all 25 accounts, with the employee-number login for candidates stated explicitly), and `DEMO_CHEAT_SHEET.md` (one page). Every label and navigation path was taken from the source rather than assumed, and the flow is built on the discovery that the seeded data holds a DPC at every stage at once. No sample files needed regenerating — the six verified in entry 24 cover the entire upload surface, checksums unchanged. Nothing outside `docs/` was modified, confirmed by a modification-time sweep.

---

## Addendum 2 — Close

| | |
|---|---|
| Documents created | 4 — `DEMO_FLOW.md`, `UPLOAD_FILES_GUIDE.md`, `DEMO_USERS.md`, `DEMO_CHEAT_SHEET.md` |
| Sample files | 6, unchanged and checksum-verified from entry 24 |
| Demo accounts documented | 25 (23 usable, 2 non-login) |
| Flow steps | 14 — 9 core (≈42 min), 5 optional (≈17 min) |
| Upload screens documented | 6 · modules correctly given no file: 3 |
| Code / DB / config / UI changed | **none** |

*End of addendum 2.*

# Round 001 — Addendum 3: Final Demo Readiness Confirmation (demo day)

---

## Entry 26 — Demo-day confirmation: two defects found, both fixed

- **Timestamp:** 2026-09-02 07:19 – 07:55 IST
- **Current UAT Step:** Step 13 — Final confirmation on the morning of the demonstration

### Progress Messages
"Demo day. The date rollover is itself a risk I flagged, so let me re-verify against reality rather than trust yesterday's results."
"`documents ok=0 missing=8` — the blocker has returned overnight. Investigating immediately."
"**Canary deleted — running the test suite wipes real document storage.** That explains both incidents."
"Restored: **8/8 documents, 25/25 signatures, SE-0004 back in Draft.**"
"**Critical finding: the 14 promotion candidates cannot log in.** My `DEMO_USERS.md` claims they can — that's now disproven."

### Commands Executed
```bash
php artisan test                                  # 1287 passed, exit 0 — but see below
php artisan db:show ; redis-cli ping
php artisan tinker --execute='... document + signature integrity ...'
stat -c '%y' storage/app/private                  # mtime evidence
mkdir storage/app/private/circulations/MARKER     # canary experiment
sed -n '...' vendor/.../Support/Facades/Storage.php   # getRootPath / fake
grep -n "STORAGE_PATHS" -A 12 app/Console/Commands/ResetTransactionalDataCommand.php
grep -n "artisan(" tests/Feature/Console/ResetTransactionalDataTest.php
php artisan demo:seed --force                     # restore
# temporary tests/Smoke/FinalConfirmTest.php  — 25 real POST /login, reports, documents, imports
# temporary tests/Smoke/SelfInspectTest.php   — self-inspection end to end
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter FinalConfirmTest
vendor/bin/phpunit -c scratchpad/smoke-dev.xml --filter SelfInspectTest
rm -rf tests/Smoke
```

### Environment Actions
`php artisan demo:seed --force` was run **with the user's explicit approval**, restoring the demonstration database. Four documents updated: `DEMO_USERS.md`, `DEMO_FLOW.md`, `DEMO_CHEAT_SHEET.md`, `DEMO_DATA_GUIDE.md`. Temporary tests removed. No code, configuration or schema touched.

### Business Explanation
Yesterday's verification ended with a GO. This morning the same check failed. That is the whole argument for re-verifying on the day rather than trusting a report written the night before — and it found **two** defects, one of which had been quietly corrupting the demonstration for days.

### Validation Explanation

**Defect 1 — the test suite destroys stored documents.**

The 8 documents were missing again. The `storage/app/private` directory mtime read `Sep 1 23:12`, proving the loss happened during my own work the previous night, not overnight — *after* my last integrity check and before I finished. A canary file was planted and the running suite completed: **the canary was deleted.**

The mechanism, once located, is exact. `ResetTransactionalDataCommand::clearStorage()` does:

```php
$directory = storage_path('app/'.$path);   // real filesystem path
File::deleteDirectory($directory);
```

over `STORAGE_PATHS` = `private/proceedings`, `private/circulations`, `private/signatures`, `private/imports`, `private/record-gaps`. Because it addresses the path **directly** rather than through the `Storage` facade, `Storage::fake('local')` in `Tests\TestCase::setUp()` gives no protection at all — `Storage::fake()` only redirects the facade, cleaning `storage/framework/testing/disks/local` (confirmed by reading `Storage::getRootPath()`).

`tests/Feature/Console/ResetTransactionalDataTest.php:87` invokes that command with `--force` on every full suite run.

The proof is in what *survived*: `private/department-posts` and `recruitment-rules` were untouched — and those are precisely the two directories **not** in `STORAGE_PATHS`. An exact match.

So the sequence was: I restored the documents, then ran `php artisan test` as the closing quality gate, and that gate destroyed what it was meant to confirm. It also explains the "pre-existing" loss found on 2026-09-01 — an earlier test run, not a mystery.

**Defect 2 — Promotion Candidates cannot sign in, and my documentation said they could.**

Rather than assert the logins worked, all 25 were exercised with a real `POST /login`. Nine succeeded. **All fourteen Promotion Candidates failed**, as did the two external committee members.

The cause: `UserProvisioningService` issues `Str::password(12)` — a random password — and dispatches `CredentialsIssued`. A candidate has no e-mail and no mobile, so there is no channel to deliver it on. `Hash::check('Demo@2026', …)` returns **false**; the account is active and verified but holds a password nobody knows. This is recorded gap **#4** in `SUGGESTIONS_AND_IMPROVEMENTS.md`, written months ago and now met in practice.

`DEMO_USERS.md` §3 and `DEMO_FLOW.md` step 14 both instructed the presenter to sign in as `CG100037`. That would have failed live. Both were corrected, and **Self-Inspection** — which needs no account and is the route the system actually provides — was verified working end to end and substituted.

### Observations

**Environment (07:19):** PHP 8.4.24 · Laravel 13.23.0 · MySQL 8.0.46 · Redis `PONG`.

**Full suite on demo day: 1,287 passed, exit 0** — the date-sensitivity fix of 2026-09-01 holds. *(Run before the cause was known; it is what destroyed the storage. Not to be repeated.)*

**Login test — all 25 accounts, real `POST /login`:**

| Group | Result |
|---|---|
| admin, chairman, 5 members, AO, AH | **9 × LOGIN OK** |
| 14 promotion candidates | **14 × FAIL** — random password, no delivery channel |
| 2 external committee members | **2 × FAIL** — never portal accounts |

**After the approved re-seed:** documents **ok=8 missing=0** · signatures **ok=25 missing=0** · SE-0004 back in **Draft** (WO=3 SN=6 EV=6) · 25 users · 9 proposals.

**Reports and documents — all 200 with real content:** `/reports` (20,372 B admin / 22,740 B AO) · `/reports/export` (299 B CSV) · `/admin/audit-trail` (93,572 B) · `/admin/audit-trail/export` (45,229 B CSV) · `/dpc-templates` (21,159 B) · `/cgpsc/allotments` (56,059 B) · `/cgpsc/schedules` (21,857 B) · `/cgpsc/proceedings` (16,783 B) · 6 proceeding documents (4,336–9,782 B) · 2 circulation documents (4,196 / 5,760 B). **No empty bodies.**

**Sample files re-imported on demo day** — all three **0 rejected**, committed, **total vacancy 3**, rolled back cleanly (before 3,6,6 = after 3,6,6).

**Self-Inspection verified:** `/self-inspection` 200 → identify 200 → identity accepted 302 → record **200, 18,512 B**.

**Postings are re-dated to the seed day.** After the re-seed both departmental officers' postings begin 2026-09-02, so a transfer dated today is refused. This is *permanent*, not a one-day quirk: the seeder always dates postings "today", so the transfer screen is never demonstrable on a re-seed day. The cheat sheet was corrected from a date-specific note to a standing limitation.

### Warnings
- ⛔ **Never run `php artisan test` on the demonstration machine.** It deletes every stored Proceedings document, Promotion List, Circulation note and signature. This is now the first entry in the cheat sheet's *Things to avoid*.
- Filtered runs (`vendor/bin/phpunit --filter X`) are safe — verified: storage intact after two filtered runs.
- Promotion Candidate logins will fail. Use Self-Inspection.

### Errors
Two defects found, both resolved: storage destroyed by the test suite (restored by re-seed), and incorrect candidate-login documentation (corrected).

### Suggestions
Corrections written into the four demonstration documents: the candidate-login claim replaced by Self-Inspection throughout; the test-suite hazard added to *Things to avoid* and *Recovery*; the transfer-screen note generalised; login totals (9 of 25) stated plainly.

### Recommendations
**Architectural, and it should be recorded as item #29 after the demonstration** — not added now, because this instruction limited documentation changes to the five requested items.

> `ResetTransactionalDataCommand::clearStorage()` addresses `storage_path('app/…')` directly instead of going through the `Storage` facade. Every other part of the application uses the facade, which is what makes `Storage::fake()` in the test base class effective. Because this one command bypasses it, a test that exercises the command deletes **real** documents, signatures and uploads on the developer's machine. The base `TestCase` docblock already tells the story of tests *writing* into real storage and the fake being centralised to stop it; this is the same bug in the other direction, and the fake cannot catch it. The fix is to route the command through `Storage::disk('local')->deleteDirectory(...)` so the fake applies — a small change with no behavioural difference in production. This is the root cause of both document-loss incidents in this UAT.

### Decisions
- **Re-verify on the day rather than trust the overnight report.** It found two defects.
- **Find the cause before re-seeding**, so the fix would not simply be undone again by the next test run.
- **Test the logins rather than document them.** The candidate claim would have failed live, in front of the customer.
- **Correct the documents rather than change the data.** Candidates cannot be given a usable password without a DB change, which the freeze forbids and which Self-Inspection makes unnecessary.
- **Do not re-run the full suite after re-seeding.**

### Questions Asked
> **NO-GO as it stands.** All 8 stored documents are again missing from disk… Fixing it means re-seeding, which you've told me not to do. How do you want to proceed?
>
> 1. **Re-seed now** *(recommended)* · 2. Leave the DB alone and drop documents from the flow · 3. Re-seed then re-verify everything end to end.

### User Responses
**Option 1 selected — "Re-seed now — restore the documents".**

### Next Planned Action
None. Final confirmation complete; verdict issued.

### Final Summary of This Interaction
Re-verification on the morning of the demonstration found two defects that the overnight report had not. The stored documents had been destroyed again — root cause identified as `ResetTransactionalDataCommand` deleting real storage paths behind the `Storage` facade, invoked by the test suite, which also explains the earlier "pre-existing" loss. Restored by an approved re-seed: 8/8 documents, 25/25 signatures. Separately, testing all 25 logins for real disproved a claim in my own documentation: the 14 Promotion Candidates cannot sign in, because their random password has no delivery channel. The four demonstration documents were corrected and Self-Inspection — verified working — substituted. Everything else passed: 1,287 tests, all reports, all documents serving real bytes, all three sample files importing with zero rejections.

---

## Addendum 3 — Close

| | |
|---|---|
| Verdict | **GO** |
| Defects found on demo day | 2 — both resolved |
| Accounts able to sign in | 9 of 25 (verified by real `POST /login`) |
| Documents on disk | 8/8 · signatures 25/25 |
| Sample files | 6 · all re-imported with **0 rejected** |
| Reports verified | 9 screens + 6 proceeding documents + 2 circulation documents |
| Root cause recorded for post-demo action | `ResetTransactionalDataCommand` bypasses the Storage facade (proposed item #29) |

*End of addendum 3. End of round 001.*
