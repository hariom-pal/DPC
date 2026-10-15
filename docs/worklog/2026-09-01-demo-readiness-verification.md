# 2026-09-01 — Demo Readiness Verification (Demo Freeze)

**Task:** Under Demo Freeze rules, verify the whole application is demo-ready for the business demonstration on 2026-09-02. Fix only runtime defects — no features, no business rules, no schema, no refactors.

**Deliverable:** `docs/uat/DEMO_READINESS_REPORT.md` — Pass / Fail / Fixed Issues / Remaining Issues / Demo Risk.

**Verdict: GO.** Zero server errors on every screen for every role. Three defects found and fixed, one of them a genuine demo blocker.

## What was verified, and how

| Area | Method |
|---|---|
| Quality gate | Pint **pass**, PHPStan L6 **0 errors**, PHPUnit **1,287 passed / 4,228 assertions**, `security:audit` **clean**, `schema:verify-mysql` **79 migrations / 78 tables** — re-run after every change |
| Routes | Reflected all 270 route targets: every controller class and method exists. Every `route('name')` in Blade and PHP resolves |
| Views | All `view()`, `@extends`, `@include*` targets across 489 files; resolved the one dynamic include against its 5 possible partials |
| Controllers / policies / middleware | **Live sweep — 136 GET routes × 6 identities (~950 requests)**, re-run after the DB rebuild. Real MySQL, full middleware stack |
| Reports & downloads | Downloaded every export and all 8 stored documents; measured bytes |
| File upload | Full round trip through the CSRF-guarded stack: template → upload → validate → preview → error CSV |
| Translations | 1,449 keys; en/hi parity both directions; all 4 dynamic namespaces enumerated |
| Assets / JS | Every `asset()` target on disk; all 13 JS files `node --check` clean |
| Seed data | `demo:seed` proven clean **in a throwaway database first**, so the real rebuild carried no risk |

## Headline result

**0 × HTTP 500 and 0 uncaught exceptions across ~950 authenticated requests.** The 3 admin 404s were each traced to correct `abort_if` guards or synthetic probe parameters — no broken links.

## The three fixes

1. **Two failing tests, time-bombed to the demo date.** Fixtures were created at *real* today and then compared against a hardcoded `2026-09-01` — and today became 2026-09-01, so the posting began on the very day of the transfer. Two correct business rules then fired (the `->max(effective_from)` clamp, and the "transfer must take effect after the current posting" validation). **The application was right; the tests were asserting against a moving calendar.** Froze the clock to `2026-08-01` in `setUp()` before fixtures are built. No business logic touched.

2. **`ui.user` missing in both locales** — rendered as the literal string `ui.user` on the CGPSC Chairman form and index. The only genuine gap out of 1,449 keys.

3. **Every stored document downloaded as a blank page — the one real blocker.** All 8 document rows in `dpc_dev` pointed at files no longer on disk; `Storage::get()` returns `null`, cast to `''`, so the endpoint returned **200 with an empty body** — no error, just blank. This hit the Proceedings and Promotion List, the headline artefacts of the workflow. Files were already gone before the session. Fixed by rebuilding the demo DB (approved first, as it is destructive). All 8 now serve 4,196–9,782 bytes; 25/25 signatures present.

## A false alarm worth recording

The test failure surfaced as `Call to a member function all() on array`, which looked like `$errors` being broken application-wide — every validation failure 500ing. It is not. It is a Laravel *test-helper* quirk: `TestResponseAssert::injectResponseContext()` reads `session('errors')` after `Store::prepareErrorBagForSerialization()` has flattened it under the project's `'serialization' => 'json'` setting. Production rehydrates it via `Store::marshalErrorBag()`. Verified explicitly — validation failure then page render returns 200, and all 49 views using `$errors`/`@error` are safe.

## Remaining — presenter awareness, not defects

- **`ds.gad@cg.gov.in` cannot be logged into.** It is an *external committee member* (Deputy Secretary, GAD) seeded with `'user' => null`, not a portal user: no role, unverified, must-change-password. The cheat sheet prints it under "Login" with role `—`, which invites a dead-end on stage. Not changed — that would be a content change under freeze.
- 14 promotion candidates print blank logins; their only identifier is `employee_number`, which the login form does accept.
- `portal_notices`, `import_batches` and `dpc_evaluation_record_gaps` are empty — those screens render fine but with no data. The import screens are better demonstrated live anyway, and that flow is verified.
- The date-fragility pattern behind fix 1 may exist in other test files. Build gate only; sweep after the demo.

## Changes made

Three files, all inside the permitted categories — plus one approved `demo:seed --force`.

| File | Change |
|---|---|
| `tests/Feature/Admin/UserDepartmentAssignmentTest.php` | Froze the clock in `setUp()` |
| `lang/en/ui.php` | Added `'user' => 'User'` |
| `lang/hi/ui.php` | Added `'user' => 'उपयोगकर्ता'` |

No feature, business rule, schema, UI or working application code was altered.

**Post-change gate: Pint pass · PHPStan 0 errors · 1,287 tests passed / 0 failed · security:audit clean · schema:verify-mysql pass.**
