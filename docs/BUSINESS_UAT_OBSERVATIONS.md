# Business UAT Observations

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Phase:** Business User Acceptance Testing · **Opened:** 2026-08-28
**Preceding audit:** `docs/ARCHITECT_REVIEW.md` (2026-08-28)

---

## Standing rule for this file

**Every entry is OBSERVED ONLY. Nothing is implemented on the strength of this file.**
No observation becomes work until the business owner marks its **Status** as `Approved`. Entries are **appended, never overwritten and never renumbered** — an ID once issued is never reused, even if the observation is later rejected.

## Fields recorded for every observation

| Field | Notes |
|---|---|
| **Observation ID** | `UAT-OBS-nnn`, issued in the order found |
| **Module** | One of the twenty modules in the agreed test order |
| **Screen** | The business name of the screen |
| **Screen / Page URL** | The exact route as typed in the browser, e.g. `/admin/qualifications/{id}/edit` |
| **Evidence** | The file, class or method that produces the behaviour — and the screenshot filename where one was taken. This is what makes the observation traceable and implementable later |
| **Current Behaviour** | What the application does today, stated as verified fact |
| **Expected Behaviour** | What the business expects instead |
| **Business Reason** | Why the expectation is what it is |
| **Impact** | Who is affected and what goes wrong if left alone |
| **Severity** | `Critical` / `High` / `Medium` / `Low` |
| **Suggested Change** | The smallest change that would meet the expectation — a proposal, not a decision |
| **Status** | `Open` / `Approved` / `Rejected` / `Fixed` |

**Severity guide.** `Critical` — a DPC cannot be completed, or a wrong promotion could be published. `High` — a wrong or unexplainable outcome reaches an officer. `Medium` — correct outcome, materially poor experience or a missing control. `Low` — cosmetic or convenience.

**Evidence convention.** Screenshots are named `UAT-OBS-nnn-<short-slug>.png`. Where no screenshot exists, the evidence field carries the file and line, or the class and method, that was read to confirm the behaviour.

---

## Module coverage

| # | Module | Tested on | Observations | Status |
|---|---|---|---|---|
| 1 | Masters | 2026-08-31 | 4 | In progress — screen 1 of 16 |
| 2 | Department | 2026-08-31 | 3 | **E2E Step 2 executed — Pass with observations** |
| — | Cross-cutting (Authentication / Environment) | 2026-08-31 | 1 | E2E Step 1 complete |
| 3 | Department Post Mapping | 2026-08-31 | 2 | **E2E Step 3 executed — Pass with observations** |
| 4 | Recruitment Rules | 2026-08-31 | 4 | **E2E Step 4 + 4A executed — Pass with observations** |
| 5 | Users & Department Assignment | 2026-08-31 | 3 | **E2E Step 5 executed — Pass with observations** |
| 6 | Pre-DPC | — | — | Not started |
| 7 | Vacancy | — | — | Not started |
| 8 | Working Officers | — | — | Not started |
| 9 | Seniority List | — | — | Not started |
| 10 | Evaluation / Tabulation Chart | — | — | Not started |
| 11 | Merit Calculation | — | — | Not started |
| 12 | DPC | — | — | Not started |
| 13 | Committee | — | — | Not started |
| 14 | Proceedings | — | — | Not started |
| 15 | Review DPC | — | — | Not started |
| 16 | Circulation DPC | — | — | Not started |
| 17 | Reports | — | — | Not started |
| 18 | Candidate Portal | — | — | Not started |
| 19 | Notifications | — | — | Not started |
| 20 | Audit Trail | — | — | Not started |

---

## Observations

### UAT-OBS-001 · District Master ships the pre-2022 district list; five districts are missing

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-001 |
| **Module** | 1 — Masters |
| **Screen** | District Master |
| **Screen / Page URL** | `/admin/districts` |
| **Evidence** | `app/Integrations/Mock/SeedDirectoryProvider::districts()` lines 19-50; `database/seeders/DistrictSeeder.php`; verified live in `dpc_dev.districts` — **28 rows, all `active`, all `lgd_code = NULL`**. Consumer that breaks: `app/Http/Controllers/Auth/RegistrationController.php:36` |
| **Current Behaviour** | The master holds the 28 districts of Chhattisgarh as constituted between February 2020 and September 2022. The five districts created in September 2022 — **Manendragarh-Chirmiri-Bharatpur, Mohla-Manpur-Ambagarh Chowki, Sarangarh-Bilaigarh, Sakti and Khairagarh-Chhuikhadan-Gandai** — are absent |
| **Expected Behaviour** | The master carries all 33 districts currently notified by the State |
| **Business Reason** | The district dropdown on department registration is filtered to `status = active` and reads this table. A department headquartered in one of the five newer districts has no district to select, and `district_id` is `required` on `RegisterDepartmentRequest` |
| **Impact** | Departments in five districts cannot complete registration and therefore cannot raise a DPC at all. An Administrator can add the rows by hand, but only if somebody notices |
| **Severity** | High |
| **Suggested Change** | Confirm the authoritative list with the Commission, then extend the seeder array or enter the five districts on the screen. Already proposed as **Suggestion 22** — this entry records it as a defect against registration, which the suggestion did not |
| **Status** | Open |

---

### UAT-OBS-002 · Deleting a district that is in use silently blanks the department that used it

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-002 |
| **Module** | 1 — Masters |
| **Screen** | District Master — the Delete button on each row |
| **Screen / Page URL** | `/admin/districts` |
| **Evidence** | `app/Domain/Master/Services/MasterCrudService::delete()` — no in-use check; `app/Domain/Master/Concerns/IsMaster` applies `SoftDeletes`; FK `departments.district_id` is declared `nullOnDelete()` in `2026_07_30_090010_link_departments_and_users_to_masters.php:21`; `Department::district()` (`app/Domain/Department/Models/Department.php:97`) carries no `withTrashed()`. 1 department currently holds a `district_id` |
| **Current Behaviour** | Delete performs a **soft** delete with no check of whether the district is referenced. Because no row is physically removed, the `SET NULL` rule never fires; the department keeps a `district_id` pointing at a soft-deleted row, and the `SoftDeletes` global scope then resolves `$department->district` to **null**. The department's district goes blank on every screen that shows it, with no error and no trace visible to the user |
| **Expected Behaviour** | The delete is refused and names what is referencing the record — *"This district cannot be deleted: 3 departments are located in it. Set it to Inactive instead."* |
| **Business Reason** | `Inactive` already means *not choosable on new work, undisturbed on old work*, which is what a business user means by "remove this district". Delete is a different act and must not damage settled records |
| **Impact** | A single click can strip the district from registered departments. The Department Master, by contrast, does guard its own delete (`DepartmentController::hasIssuedDpcIds()`), so the inconsistency is within Module 1 itself. The same unguarded delete path is shared by Designation, Pay Scale, Reservation Category, Vacancy Type, DPC Type, Committee Type, Review Reason, Record Reason and Qualification |
| **Severity** | High |
| **Suggested Change** | One in-use guard in the shared `MasterCrudService::delete()`, applied to every master. Already proposed as **Suggestion 23** — recorded here as a defect because it corrupts existing data rather than merely omitting a convenience |
| **Status** | Open |

---

### UAT-OBS-003 · A deleted district's name is reserved for ever, and the record cannot be restored

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-003 |
| **Module** | 1 — Masters |
| **Screen** | District Master — New District |
| **Screen / Page URL** | `/admin/districts/create` |
| **Evidence** | `app/Domain/Master/Requests/DistrictRequest::rules()` — `Rule::unique('districts', 'name_en')->ignore($id)`, which queries the table with no soft-delete condition; `php artisan route:list --path=admin/districts` returns **6 routes** with no `restore`. Verified by code reading — reproduction steps below are for confirmation during UAT |
| **Current Behaviour** | Deleting a district hides it from the list but leaves the row in the table. The uniqueness rule still sees it, so re-creating a district of the same name is rejected with *"The name en has already been taken"* while the list shows no such district. There is no restore route and no way to view deleted records, so the name is unusable and the record unreachable from the application for good |
| **Expected Behaviour** | Either the uniqueness check ignores soft-deleted rows so the name can be re-used, or the screen offers to restore the deleted district by name and explains that it exists but is deleted |
| **Business Reason** | An Administrator who deletes a district in error has no route back, and the error message actively misleads — it points at a record they cannot see |
| **Impact** | Administrator only; no effect on a published promotion. Recovery today requires a database intervention, which is outside the application's audit trail and contrary to the audit design |
| **Severity** | Medium |
| **Suggested Change** | Scope the uniqueness rule with `whereNull('deleted_at')`, or surface deleted records with a restore action. Not to be actioned until the delete question in **UAT-OBS-002** is settled, since refusing an in-use delete changes what this rule needs to cover |
| **Status** | Open |

**Reproduction for UAT.** On `/admin/districts`, delete a district that no department uses (for example *Sukma*). Confirm it disappears from the list. Then press **New District**, enter the same name, and save. Expected in UAT: the save is rejected for a duplicate name that is nowhere on screen.

---

### UAT-OBS-004 · State LGD Code is free text and is not constrained to Chhattisgarh

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-004 |
| **Module** | 1 — Masters |
| **Screen** | District Master — New / Edit District |
| **Screen / Page URL** | `/admin/districts/create`, `/admin/districts/{id}/edit` |
| **Evidence** | `DistrictRequest::rules()` — `'state_lgd_code' => ['required', 'string', 'max:20']`; `resources/views/admin/districts/form.blade.php` renders it as an open text input pre-filled with `22`; all 28 live rows hold `22` |
| **Current Behaviour** | The field is pre-filled with `22` (Chhattisgarh) but remains editable and accepts any string up to 20 characters — letters included. Nothing ties a district to the State the portal serves |
| **Expected Behaviour** | The State LGD code is fixed at `22`, shown read-only or as settled configuration rather than as an editable field on every district |
| **Business Reason** | The portal serves one State. A per-district State code is a field with exactly one correct value, presented as though it were a choice |
| **Impact** | Low in practice — a mistyped code corrupts no calculation and blocks no workflow today. It would surface at the point of LGD reconciliation, where the district would fail to match |
| **Severity** | Low |
| **Suggested Change** | Render the field read-only from configuration, or constrain it to the configured State code in the request rules |
| **Status** | Open |

---

### UAT-OBS-005 · The demonstration credentials document names two accounts that do not exist

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-005 |
| **Module** | 2 — Department *(raised during End-to-End Step 2)* |
| **Screen** | Demonstration credentials artefact / Login |
| **Screen / Page URL** | `docs/demo/DEMO_LOGIN_CREDENTIALS.md`; affects `/login` |
| **Evidence** | Document generated 2026-08-26 08:52 by `demo:credentials` (`app/Console/Commands/DemoCredentialsCommand.php`). Verified live in `dpc_dev`: `SELECT * FROM users WHERE email IN ('ao.he@cg.gov.in','ah.he@cg.gov.in')` returns **no rows**. Role counts in the database are `authorized_officer = 1`, `administrative_head = 1`; the document claims **2 of each**. The document omits `ds.gad@cg.gov.in`, which does exist as user #10 |
| **Current Behaviour** | The document lists `ao.he@cg.gov.in` and `ah.he@cg.gov.in` as Higher Education's Authorized Officer and Administrative Head, each with an *"Active assignment … from 25-08-2026"*. Neither account exists. Anyone following the document to demonstrate a second department's workflow is unable to sign in. The stated total of 25 accounts is coincidentally correct because the two phantom entries are offset by two undocumented role-less accounts |
| **Expected Behaviour** | The credentials document reflects the accounts actually present, or the seeder provisions the accounts the document promises |
| **Business Reason** | This artefact is the entry point for every demonstration and every UAT session. A login that fails at the first attempt costs credibility before any feature is seen |
| **Impact** | Demonstration and UAT execution. No effect on any calculation, workflow or published document. Discovered because the End-to-End scenario selected Higher Education precisely on the strength of these two accounts |
| **Severity** | Medium |
| **Suggested Change** | Regenerate the document with `php artisan demo:credentials` after every seed, or have the demonstration seeder provision Higher Education's two officers. Note that the End-to-End walkthrough now **creates** these two accounts at Step 2 through the registration screen, which would reconcile the document by itself |
| **Status** | Open |

---

### UAT-OBS-006 · Registration cannot be completed without a queue worker, and no document says one is needed

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-006 |
| **Module** | 2 — Department *(raised during End-to-End Step 2)* |
| **Screen** | Department Registration → OTP Verification |
| **Screen / Page URL** | `/register` → `/verify` |
| **Evidence** | `.env` — `QUEUE_CONNECTION=redis`. `app/Domain/Authentication/Listeners/SendOtpNotification.php` and `SendCredentialNotification.php` both `implements ShouldQueue`. `app/Domain/Authentication/Services/OtpService::issue()` stores `code_hash` only — the plain code is never persisted. Verified live: no `queue:work` or `horizon` process is running, and `queues:default` already holds **34 pending jobs**. `grep -i "queue:work"` over `README.md`, `CGPSC_DPC_DEVELOPER_HANDBOOK.md`, `CGPSC_DPC_USER_GUIDE.md` and `DEMO_LOGIN_CREDENTIALS.md` returns **nothing** |
| **Current Behaviour** | Registration submits successfully and redirects to `/verify`, but the OTP and the officers' temporary passwords are queued and never delivered. Because the OTP is stored only as a hash, there is no way to read it back. The newly registered department is left with two accounts nobody can sign in to, and no error is shown — the screen simply waits for a code that will never arrive |
| **Expected Behaviour** | Either the run-book states that a queue worker is required before registration or OTP login is exercised, or the local/demonstration configuration falls back to a synchronous driver so the codes reach the log immediately |
| **Business Reason** | An OTP that is generated but never delivered is indistinguishable, to the user, from an OTP that was never generated. The failure is silent and terminal |
| **Impact** | **Blocks End-to-End Step 2 today.** Would block any customer demonstration that registers a department or exercises OTP login. It does **not** affect the seeded accounts, because the demonstration seeder pre-verifies both channels and disables the first-login password change — which is precisely why the defect was invisible until a genuinely new account was created. Not a production defect: a deployed installation would run a worker |
| **Severity** | Medium |
| **Suggested Change** | Add the queue-worker prerequisite to the README and the demonstration run-book. Separately consider whether `local` should default to `QUEUE_CONNECTION=sync`, so that a developer or tester who has not started a worker still receives OTPs. No application code needs to change |
| **Status** | Open |

---

### UAT-OBS-007 · A fixed OTP code can be switched on by environment variable, with no production guard, no documentation and no audit check

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-007 |
| **Module** | Cross-cutting — Authentication *(raised during End-to-End Step 1, Environment Verification)* |
| **Screen** | OTP Verification; every screen that issues an OTP |
| **Screen / Page URL** | `/verify`, and OTP issuance from `/register` and Self-Inspection |
| **Evidence** | `app/Domain/Authentication/Services/OtpService.php:70` — `generateCode()` reads `$fake = config('dpc.otp.fake_code')` and returns it verbatim when non-empty, **with no environment check**. `config/dpc.php:25` — `'fake_code' => env('DPC_OTP_FAKE_CODE')`. Verified absent from `.env.example`, `README.md`, `CGPSC_DPC_DEVELOPER_HANDBOOK.md`, `CGPSC_DPC_USER_GUIDE.md` and `CGPSC_DPC_ARCHITECTURE_HANDBOOK.md` (grep for `fake_code` / `FAKE_CODE` across `docs/` and `README.md` returns nothing). `app/Console/Commands/SecurityAuditCommand.php` runs `composer audit` only and performs no configuration check |
| **Current Behaviour** | Setting `DPC_OTP_FAKE_CODE` in the environment makes **every OTP the portal issues** that fixed value — for department registration, for OTP login and for Self-Inspection access. Nothing refuses it in production, nothing warns, nothing logs that a fixed code is in force, and the variable is documented nowhere, so a configuration review would not know to look for it. `php artisan security:audit` would report a clean gate |
| **Expected Behaviour** | Consistent with the project's own convention for dangerous switches: refuse in production. `OtpService::generateCode()` should ignore `fake_code` when `app()->environment('production')`, or the application should refuse to boot with it set in production. The variable should additionally appear in `.env.example` commented out with a warning, and `security:audit` should fail if it is set |
| **Business Reason** | The OTP is the portal's only second factor. A known constant OTP means any person who can reach the verification screen with a valid identifier can complete verification. This is inconsistent with the discipline applied everywhere else in the codebase: `demo:seed`, `demo:credentials` and `data:reset` all call `app()->environment('production')` and refuse, and both `DpcProposalService:680` and `PreDpcProposalService:460` guard mock-signing in production. The same rule was not applied to the one-time password |
| **Impact** | **Latent, not active.** `fake_code` is unset in this environment, so OTPs are genuinely random and the UAT is unaffected. The exposure is a production one: a value set for local testing and carried into a deployed `.env` would silently disable the second factor with no signal anywhere. The undocumented nature is what makes it dangerous — nobody reviewing configuration would know the switch exists |
| **Severity** | **High** — recorded against the security axis. Note that the severity guide at the head of this register is written for business outcomes (*"a wrong promotion could be published"*) and has no security axis; `High` is assigned on the basis that an authentication control can be silently disabled, while `Critical` is withheld because the defect is latent and requires a misconfiguration to become live |
| **Suggested Change** | Three parts. **(1)** Guard `generateCode()` so `fake_code` is ignored outside local and testing environments — the smallest change, and it matches five existing precedents in this codebase. **(2)** Add `DPC_OTP_FAKE_CODE` to `.env.example`, commented out, with a one-line warning. **(3)** Extend `SecurityAuditCommand` beyond `composer audit` to assert production configuration invariants — `fake_code` unset, `SESSION_ENCRYPT=true`, `APP_DEBUG=false`. Part 3 would also have caught the `SESSION_ENCRYPT=false` item already recorded in the implementation audit at §7.9 |
| **Status** | Open |

---

### UAT-OBS-008 · Six of thirteen registration fields reject input with no message at all

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-008 |
| **Module** | 2 — Department *(raised during End-to-End Step 2, executed)* |
| **Screen** | Department Registration |
| **Screen / Page URL** | `/register` |
| **Evidence** | `resources/views/auth/register.blade.php` renders `@error(...)` for **8 fields only** — `department_id`, `district_id`, `department_email` (twice), `ao_designation_id`, `ao_email`, `ah_designation_id`, `ah_email`. It renders **no** `@error` for `pin`, `place`, `ao_name`, `ah_name`, `ao_mobile`, `ah_mobile`, and contains **no error-summary block** (`$errors->any()` / `$errors->all()` appear nowhere). Confirmed by live HTTP test against `http://127.0.0.1:8001/register` on 2026-08-31: case **N3** (`pin=4920`), case **N5** (`ao_mobile=12345`) and case **N6** (`ao_mobile=9000001101`, already held by School Education's AO) each returned the form with `is-invalid` count **0** and no message text. Nothing was created in any case — department #3 remained `unregistered` and the user count remained 25, proving the rules fired |
| **Current Behaviour** | The Form Request enforces every rule correctly, but for six of the thirteen fields the rejection is **invisible**. The user submits, the page returns, and there is no red border, no message and no summary — nothing distinguishes a rejected submission from a page that simply reloaded. The most likely real-world trigger is `ao_mobile` or `ah_mobile` uniqueness: an officer whose mobile is already on the portal, or a second registration attempt after a partial one. A six-digit PIN typo produces the same silence |
| **Expected Behaviour** | Every field that can fail validation displays its own message, as the other seven fields already do; and the page carries an error summary at the top so that no failure can ever be silent, including failures on fields added later |
| **Business Reason** | Registration is the first screen a department ever uses and it is unattended — there is no official on hand to explain. A form that refuses without saying why is not a validation problem, it is an adoption problem: the department cannot get in and cannot find out why |
| **Impact** | **A department can be permanently unable to register with no diagnosis available to it.** Six fields affected, two of them (`ao_mobile`, `ah_mobile`) carrying uniqueness rules that will fire in ordinary use. No effect on any calculation or published document. Recovery today requires someone to read `storage/logs` or the validation rules in source — neither available to a departmental user |
| **Severity** | **High** |
| **Suggested Change** | Two parts, both small. **(1)** Add the six missing `@error` blocks, matching the pattern already used for the other seven fields. **(2)** Add an error-summary partial at the top of the form, so a future field cannot reintroduce the same silence. The second is the part that prevents recurrence and would be worth applying to every form in the portal, not only this one |
| **Status** | Open |

**Not asserted, needs a browser check.** Case **N2** (`ao_email` equal to `ah_email`) returned `is-invalid` present but no message text parsed. `ao_email` **does** carry an `@error` block, so this is most likely a limitation of the HTTP-response parsing used in this test — the same weakness initially made case N4 appear silent when it was not. It is recorded here for completeness and deliberately **not** counted as part of this observation.

---

### UAT-OBS-009 · Sanctioned Strength drives the vacancy calculation but has no data-entry screen

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-009 |
| **Module** | 3 — Department Post Mapping *(raised during End-to-End Step 3, executed)* |
| **Screen** | Department Post Mapping — New / Edit |
| **Screen / Page URL** | `/admin/department-posts/create`, `/admin/department-posts/{id}/edit` |
| **Evidence** | `department_posts.sanctioned_strength` exists (migration `2026_08_27_410001`) and is `$fillable` with an `integer` cast on `app/Domain/Master/Models/DepartmentPost.php:73`. It is **absent from `app/Domain/Master/Requests/DepartmentPostRequest::rules()`** and **absent from `resources/views/admin/department-posts/form.blade.php`** — the live form posts only `department_id`, `name`, `class_level`, `pay_scale_id`, `promotion_duration_years`, `status`, `effective_from` and the `orders[]` repeater. `DepartmentPostController::store()`/`update()` persist `$request->safe()->except(...)`, and `safe()` returns validated data only, so an unvalidated field can never be written. Consumers: `VacancyEngine.php:77` (`$toPost?->sanctioned_strength`), `VacancyCalculationService.php:112`, `SaveWorkingOfficersRequest.php:111`. Only writers anywhere: `database/seeders/Demo/DemoDpcBuilder.php:88` (`forceFill`) and a one-off backfill inside migration `410001` lines 120–121. Verified live: both Higher Education mappings created through the UI carry `sanctioned_strength = NULL` |
| **Current Behaviour** | The Sanctioned Strength of a post — what a Government Order says the establishment is — cannot be entered or corrected anywhere in the portal. Departments onboarded through the UI have `NULL`, and `VacancyCalculationService` silently falls back to `$proposal->sanctioned_post_count`. `resources/views/dpc-proposals/working-officers.blade.php:62` already carries a `no_sanctioned_strength` message, so the interface anticipates the absence while offering no way to remedy it. The demonstration data appears correct only because the seeder wrote the values directly |
| **Expected Behaviour** | Sanctioned Strength is enterable and editable on the Department Post Mapping form, validated as a non-negative integer, and — being an establishment figure authorised by a Government Order — carried forward correctly when a mapping is superseded |
| **Business Reason** | Sanctioned Strength is the establishment figure every vacancy calculation starts from. A portal that computes vacancies but cannot be told the sanctioned strength is asking the department to trust a figure it was never allowed to supply |
| **Impact** | **Every department onboarded through the portal rather than seeded.** The vacancy figure falls back to a different input (`sanctioned_post_count` on the proposal) without saying so on screen, so the number a department sees may not be derived from the establishment it believes is in force. Will materially affect **Step 8 (Vacancy Calculation)** in this walkthrough, where both posts carry `NULL`. No effect on eligibility or merit scoring |
| **Severity** | **CRITICAL** — escalated 2026-08-31 from High, see amendment below |
| **Suggested Change** | Add `sanctioned_strength` to `DepartmentPostRequest::rules()` as `['nullable','integer','min:0']` and render it on the form beside Promotion Duration. Separately, decide whether the fallback in `VacancyCalculationService:112` should remain silent or should state on screen which input it used |
| **Status** | Open |


**AMENDMENT — 2026-08-31, End-to-End Step 8. Escalated High → CRITICAL.**

Originally recorded as a missing field affecting the vacancy figure. Execution proved it is worse: **it makes a DPC uncompletable.**

With `sanctioned_strength = NULL` on Higher Education post #11, the engine returned `baseVacancy = null`, `totalVacancy = null`, `promotionVacancy = null`, `directRecruitmentVacancy = null`, `zone = null`, `requiredSeniorityCount = null`. The Seniority List is gated on the zone; the Evaluation Chart is projected from the Seniority List; Merit Calculation reads the chart. **Steps 7 and 9 to 26 of the End-to-End UAT are unreachable.** That is the register's own Critical bar — *"a DPC cannot be completed"*.

Every possible entry route was checked and none exists: the Department Post Mapping form and request omit it; the Working Officers form had `sanctioned_post_count` **deliberately removed** (change register 2026-08-27, item 1, on the grounds that it *"comes from the Department Post Mapping"* — which cannot supply it); the DPC edit screen exposes only `dpc_type_id` and `legal_stay`; `SaveDpcProposalRequest` has no rule for it; and `dpc_proposals.sanctioned_post_count` is written only from `$breakdown->sanctionedStrength` at `DpcProposalService:295`, i.e. derived from the very field that cannot be set. The only writers in the codebase remain the demonstration seeder and a one-off migration backfill.

**A second, independent consequence.** `SaveWorkingOfficersRequest::assertVacancyArithmetic()` returns early when sanctioned strength is null — *"A master-data gap, not a validation failure."* So the vacancy-arithmetic invariant, which stops a department recording more vacancies than there are posts, is **silently skipped**. Since the value can never be set through the UI, that invariant never executes for any department onboarded through the portal. The early return is reasonable in itself; the defect is that the condition it waits for is unsatisfiable.

**Working strength is unaffected and correct** — it is counted from the Working Officer List (6 officers), so only the sanctioned side of the subtraction is missing.

**Discrepancy against the implementation audit, recorded because it bears on the audit's reliability rather than on the code.** `docs/ARCHITECT_REVIEW.md` §1.2 records requirement **R18** — *"Department Post Mapping: Department → Post → Pay Scale → Class → Promotion Duration → Sanctioned Strength → Active"* — as **✅ complete**, citing `department_posts` + `sanctioned_strength` (migration `410001`), `DepartmentPostRequest` and `DepartmentPostController`. The column exists, but the Form Request cited as evidence does not contain the field and the screen does not offer it. The requirement is **partially** met: the schema is there, the data entry is not.

---

### UAT-OBS-010 · One validation message on the Post Mapping form exposes an internal field path

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-010 |
| **Module** | 3 — Department Post Mapping *(raised during End-to-End Step 3, executed)* |
| **Screen** | Department Post Mapping — New / Edit, Government Orders repeater |
| **Screen / Page URL** | `/admin/department-posts/create` |
| **Evidence** | Live test case V1 (`orders[0][government_order_date] = 2027-01-01`) rendered: *"The orders.0.government_order_date field must be a date before or equal to today."* `DepartmentPostRequest::messages()` supplies hand-written messages for `orders.*.government_order_number.required_with`, `orders.*.government_order_date.required_with`, and all four `orders.*.document` failure modes, but **none for `before_or_equal`** |
| **Current Behaviour** | A future-dated Government Order is correctly refused, but the message shows the internal array path `orders.0.government_order_date` — the only message on this form that does so. Every other rule here reads as plain English |
| **Expected Behaviour** | A message in the form's own voice, e.g. *"A Government Order cannot be dated in the future."* |
| **Business Reason** | The rest of this form is written to a notably high standard — the duplicate-name message even explains why the same name in another department is legitimate. This one message breaks that voice and reads as a system error rather than guidance |
| **Impact** | Cosmetic. The rule enforces correctly and the administrator can still tell which field is at fault |
| **Severity** | Low |
| **Suggested Change** | Add one entry to `DepartmentPostRequest::messages()` for `orders.*.government_order_date.before_or_equal` |
| **Status** | Open |

---

### UAT-OBS-011 · The Government Order PDF on a Recruitment Rule cannot be downloaded — HTTP 500 whenever the order number contains a slash

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-011 |
| **Module** | 4 — Recruitment Rules *(raised during End-to-End Step 4, executed)* |
| **Screen** | Recruitment Rules — the Government Order download link |
| **Screen / Page URL** | `/admin/recruitment-rules/{id}/download` |
| **Evidence** | `app/Http/Controllers/Admin/RecruitmentRuleController.php:102–108` — `download()` returns `Storage::disk(...)->download($path, 'GO-'.$recruitmentRule->government_order_number.'.pdf')`. Verified live 2026-08-31: `GET /admin/recruitment-rules/9/download` returned **HTTP 500**. Logged exception: `InvalidArgumentException: The filename and the fallback cannot contain the "/" and "\\" characters.` at `vendor/symfony/http-foundation/HeaderUtils.php:187`, `{"userId":1}`. The rule's order number is `F-2-17/2026/38-2`. Queried across the whole table: **10 of 10 recruitment rules carry a `/` in `government_order_number`**, including all eight seeded demonstration rules |
| **Current Behaviour** | The Government Order PDF is uploaded, stored and hashed correctly, and the record is sound — but the download route builds the served filename out of the order number, and Symfony refuses any `Content-Disposition` filename containing `/` or `\`. The result is a hard 500 error page, not a graceful failure. Slash-delimited numbering (`F-2-17/2026/38-2`) is the standard Chhattisgarh / Government of India order format, so effectively **no realistically-numbered Government Order can be retrieved** |
| **Expected Behaviour** | The order downloads. The served filename is sanitised — slashes replaced — or the stored `original_name` is served instead, which is the pattern already used elsewhere in this codebase |
| **Business Reason** | The Government Order is the legal authority for the Recruitment Rule. A rule whose authorising order cannot be produced is a rule that cannot be defended if the promotion it governed is challenged. The evidence chain is intact in the database and broken at the only route that exposes it |
| **Impact** | **Every Recruitment Rule that has a document attached.** Currently one — the Higher Education rule created during this UAT — because the eight seeded rules carry no PDF, which is the only reason this has stayed invisible: the route has never before been exercised with a real file. Any department that attaches an order will meet a 500. No effect on eligibility, vacancy or merit computation; the rule itself functions normally |
| **Severity** | **High** |
| **Suggested Change** | Serve the stored `original_name`, exactly as `DepartmentPostController::downloadDocument()` already does, or sanitise the constructed name (e.g. replace `/` with `-`). **The correct pattern already exists in this codebase**, which makes this an inconsistency between two download routes rather than a missing capability. Consider also asserting the same `Content-Type` header the Department Post route sets |
| **Status** | Open |

---

### UAT-OBS-012 · The English help text on the recruitment split renders a raw translation placeholder

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-012 |
| **Module** | 4 — Recruitment Rules *(raised during End-to-End Step 4, executed)* |
| **Screen** | Recruitment Rule — New / Edit |
| **Screen / Page URL** | `/admin/recruitment-rules/create` |
| **Evidence** | Rendered live in the page: `<div class="form-text"> The two shares must total :total. </div>`. `lang/en/admin.php:395` — `'hint_split_total' => 'The two shares must total :total.'`. `resources/views/admin/recruitment-rules/form.blade.php:137` calls `{!! __('admin.recruitment_rules.form.hint_split_total') !!}` with **no replacement parameters**. `lang/hi/admin.php:395` at the same key holds a complete sentence with no placeholder |
| **Current Behaviour** | An English-language administrator sees the literal text *"The two shares must total :total."* on a live form. A Hindi-language administrator sees a correct, fully-worded sentence explaining that DR + PR must equal exactly 100% |
| **Expected Behaviour** | The English string states the rule in full, as the Hindi one does — or the view passes `['total' => '100%']` |
| **Business Reason** | The portal is bilingual and the two locales must say the same thing. Here the Hindi is correct and the English is broken, which is the reverse of the usual direction and so is unlikely to be caught by a Hindi-speaking reviewer |
| **Impact** | Cosmetic, but on an Administrator-facing form in a government portal. The rule it describes is enforced correctly regardless, and the error message shown on failure is well-written |
| **Severity** | Low |
| **Suggested Change** | Replace the English string with the full sentence, mirroring the Hindi. Note that `lang:check` (`CheckLanguageCatalogueCommand`) compares key coverage between locales, so it cannot detect this — the key exists in both. Consider whether that command should also flag an unreplaced `:placeholder` at a call site that passes no parameters |
| **Status** | Open |

---

### UAT-OBS-013 · The Recruitment Rule feeder row carries the qualifying service but has no field-level audit

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-013 |
| **Module** | 4 — Recruitment Rules *(raised during End-to-End Step 4, executed)* |
| **Screen** | Recruitment Rule — feeder grid |
| **Screen / Page URL** | `/admin/recruitment-rules/{id}/edit` |
| **Evidence** | `app/Domain/Master/Models/RecruitmentRuleSource.php` does **not** implement `OwenIt\Auditing\Contracts\Auditable` and uses neither `IsMaster` nor `AuditableTrait`. Verified live: after creating the rule and cloning a version, `audits` grouped by type showed `RecruitmentRule = 1` and **no `RecruitmentRuleSource` rows** — while `DepartmentPostOrder`, a comparable child record, showed 3. `RecruitmentRuleService::updateDraft()` performs `$rule->promotionSources()->delete()` then re-creates the rows |
| **Current Behaviour** | The feeder row holds `promotion_percentage`, `min_experience_years`, `min_experience_unit` and — through `rr_source_qualifications` — the accepted degrees. None of it is audited field-by-field. While a rule is Draft, an administrator may change the qualifying service from 5 years to 3 and the only record is an Activity Timeline line reading *"Recruitment rule v1 updated"* |
| **Expected Behaviour** | The feeder row is `Auditable`, as its sibling child record `DepartmentPostOrder` already is, so a change to qualifying service or accepted qualifications is attributable |
| **Business Reason** | Minimum qualifying service is a direct input to the eligibility verdict on every officer. The portal's stated discipline is that anything a promotion turns on is auditable |
| **Impact** | **Limited by versioning, which is the real control.** An Active rule's feeder cannot be edited at all — `isEditable()` refuses, verified live — and a new version is a new row, so the rule a DPC actually cited is immutable and reconstructible. The residual exposure is Draft-stage editing only. Because `updateDraft()` deletes and re-creates the rows, even an added `Auditable` trait would record deletes and creates rather than field diffs unless the sync were changed to update in place |
| **Severity** | Low |
| **Suggested Change** | Make `RecruitmentRuleSource` `Auditable`. This is an instance of the class of gap the implementation audit already records as **S-2** (*"Officer evaluation/seniority/working/vacancy/result rows are not Auditable"*) and is best addressed in the same pass, together with the volume decision that finding calls for. If field-level diffs are wanted rather than delete/create pairs, `syncFeeders()` would need to update in place |
| **Status** | Open |

---

### UAT-OBS-014 · A Recruitment Rule's effective dates are recorded but ignored when the rule is selected for a DPC, and two rules may be in force at once

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-014 |
| **Module** | 4 — Recruitment Rules *(raised during End-to-End Step 4A addendum)* |
| **Screen** | Not a screen defect — it is in rule selection. Surfaces at DPC Creation |
| **Screen / Page URL** | Consequence appears at `/dpc-proposals/create` (Step 13) and on the Recruitment Rule list |
| **Evidence** | **Two divergent resolvers.** `app/Domain/Dpc/Services/VacancyEngine.php:232` filters `status = Active` **and** the effective window (`effective_from <= $on`, `effective_to` null or `>= $on`, where `$on = $proposal->proposal_date ?? today`), ordering `effective_from DESC, version DESC`. `app/Domain/Dpc/Services/DpcProposalService.php:253` `currentRuleFor()` filters `status = Active` only, ordering `version DESC` — **no date filter of any kind**. `currentRuleFor()` is called from `ruleInForce()` (line 220), which supplies `recruitment_rule_id` when a DPC proposal is created from a Pre-DPC, and from `recruitmentRuleSuperseded()` (line 245), which drives the "rule has changed" warning. `VacancyEngine` short-circuits at line 234 when the proposal already carries a `recruitment_rule_id`, so a wrong selection is not corrected downstream. **No overlap constraint exists**: `RecruitmentRuleRequest` validates only `effective_to >= effective_from` within one row, and the sole unique index is `(post_id, version)` — verified against the live schema |
| **Current Behaviour** | `effective_from` and `effective_to` are captured, validated within a row and displayed, but are **not consulted at the moment that decides which rule a promotion runs under**. A DPC cites whichever Active rule has the highest version number. Consequently: a **future-dated** Active rule is selected for a DPC raised today; an **expired** Active rule stays "current" if it is the highest version; and where two Active rules **overlap**, the selector and the vacancy engine may name **different rules for the same proposal**. Nothing prevents two Active rules covering the same day |
| **Expected Behaviour** | The rule selected for a DPC is the one in force on the relevant date — the same filter `VacancyEngine::recruitmentRule()` already applies. Separately, two Active rules for one post should not be allowed to cover the same day, as the Department Post Mapping already ensures for itself |
| **Business Reason** | A DPC must be judged under the rule in force for its year. That principle is **stated in this code's own documentation and not implemented**: the selector is literally named `ruleInForce()` and its docblock reads *"The recruitment-rule version in force for the Promotion To Post"*, while applying no date filter. "In force" is a statement about dates, and dates are exactly what it does not read |
| **Impact** | The cited rule determines the promotion/direct quota split, the minimum qualifying service and the accepted qualifications — that is, **how many vacancies exist and who is eligible for them**. A DPC run under a not-yet-in-force or lapsed rule would be defensibly wrong. Requires an administrator to have created such a rule, so the defect is conditional rather than always-on — but nothing prevents it, and versioning positively invites preparing next year's rule in advance, which is the exact shape that triggers it. **This walkthrough is currently safe**: the only other rule against post #11 is v2 in **Draft**, and both resolvers filter on `Active`. Activating v2 would cause the Step 13 DPC to cite it regardless of its effective date |
| **Severity** | **High** |
| **Suggested Change** | Have `currentRuleFor()` apply the same effective-window filter `VacancyEngine::recruitmentRule()` already uses, and pass the relevant date (the Pre-DPC's DPC year or the proposal date) rather than defaulting to today — the two resolvers should be one method, not two. Separately, refuse a second Active rule whose effective period overlaps an existing one for the same post, following `DepartmentPostMappingService::supersede()`, which already refuses this for mappings with the reasoning *"Two mappings cannot both be in force on the same day."* **Both patterns already exist in this codebase**; this is an inconsistency to reconcile, not a capability to invent |
| **Status** | Open |


**AMENDMENT — 2026-08-31, End-to-End Step 13. The trigger is the normal flow, not misuse.**

This observation originally recorded the overlap as *"conditional rather than always-on… requires an administrator to have created such a rule."* **That understated it.** Verified live at Step 13: **approving a Pre-DPC automatically mints a new Active recruitment rule version.** `app/Domain/PreDpc/Services/PreDpcProposalService.php:576` calls `RecruitmentRuleService::create([... 'status' => MasterStatus::Active->value ...])` for the promotion-to post, and **does not supersede the existing rule** — the earlier row's `effective_to` stays NULL.

Observed on Higher Education post #11: rule #9 (v1, Active, from 2026-04-01, open-ended) and rule #11 (v3, Active, from 2026-04-01, open-ended) both in force on the same day, the second created at the exact AH-approval timestamp. `currentRuleFor()` selected v3 on version number alone, and the DPC cites it.

**Consequence.** Any post whose Pre-DPC is approved more than once — the ordinary multi-year case, a 2026 exercise followed by a 2027 one — accumulates Active, open-ended, overlapping rules. A DPC raised for an earlier year, or any retrospective or out-of-order exercise, will cite the highest-numbered rule regardless of the year it governs. Where the rules differ in quota split, qualifying service or accepted qualifications, that produces the wrong eligibility verdicts and the wrong vacancy count for that year.

**Severity recommendation revised.** Recorded as `High`; on this evidence it meets the register's `Critical` bar — *"a wrong promotion could be published"* — because the defect now arises from the standard flow rather than from misuse. **Not re-rated unilaterally**; the business owner is asked to confirm. Note also that the auto-minted rule carries **no Government Order document**, so UAT-OBS-011 cannot arise on it — but neither can its authority be produced.

**Note on scope.** Whether a *superseded* rule should block submission is already named in the code as an open business question, and the code deliberately declines to decide it. That is the correct posture and is not part of this observation — this observation concerns the selection being date-blind, which is a defect regardless of how that policy question is answered.

---

### UAT-OBS-015 · Provisioning any departmental account from Admin → Users fails with HTTP 500

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-015 |
| **Module** | 5 — Users & Department Assignment *(raised during End-to-End Step 5, executed)* |
| **Screen** | User Management — New / Edit Account |
| **Screen / Page URL** | `POST /admin/users`, `PUT /admin/users/{uuid}` |
| **Evidence** | `app/Domain/UserManagement/Services/UserProvisioningService.php:56` — `provision()` calls `$this->grant($user, Role::from($data['role']), $data['department_id'] ?? null)`, and line 78 in `update()` does the same. `grant()` declares `?int $departmentId`; the file carries `declare(strict_types=1)`. `$data` is `$request->validated()`, and `StoreUserRequest` validates `department_id` with `Rule::requiredIf(...)`, `prohibited_unless`, `nullable` and `Rule::exists` — **no integer cast and no `prepareForValidation()`** — so an HTML form delivers the string `"2"`. Logged live 2026-08-31: `TypeError: UserProvisioningService::grant(): Argument #3 ($departmentId) must be of type ?int, string given, called in .../UserProvisioningService.php on line 56`, `{"userId":1}`. Verified by contrast over the same route: `role=member` with no department → **HTTP 302, account created (#29)**; `role=authorized_officer` with `department_id=2` → **HTTP 500, nothing created** |
| **Current Behaviour** | The CGPSC Administrator cannot provision an Authorized Officer, Administrative Head or Promotion Candidate from the User Management screen. The request 500s. The surrounding transaction rolls back cleanly, so no orphan account is left — the failure is total but not corrupting. Provisioning a Commission role (Administrator, Chairman, Member) works, because that branch passes `null` and never reaches the type error |
| **Expected Behaviour** | The account is provisioned and posted to the chosen department in one action, as the screen offers and as change item 1 of 2026-08-24 specifies |
| **Business Reason** | Change item 1 deliberately widened this screen so the Administrator could post an Authorized Officer or Administrative Head **directly**, rather than only through S6 department registration. That capability is documented in the service's own docblock and does not function |
| **Impact** | The only remaining routes to a departmental account are S6 self-registration (which creates exactly one AO and one AH, at registration time) and the Role Assignments screen (which posts an account that already exists). **A department that needs a second Authorized Officer, or a replacement after a release, cannot be given one through this screen.** Not a barrier to completing a DPC, since registration still provisions the two statutory officers. Three of the five roles the dropdown offers are unusable |
| **Severity** | **High** |
| **Suggested Change** | Cast at the boundary — either `$request->integer('department_id')` in the controller, as `UserAssignmentController` already does correctly on line 57, or a `prepareForValidation()` cast in `StoreUserRequest` and `UpdateUserRequest`. **The correct pattern already exists one controller away** |
| **Status** | Open |

**Why 1287 green tests do not catch this.** `tests/Feature/Admin/UserManagementTest.php` exercises this route only with `Role::Member` and **never posts `department_id` at all**, so every test takes the `null` branch. The broken branch has no coverage. This is the same class of gap the implementation audit warns about at §9.4 — a suite that passes against inputs the application never actually receives. A test that posted a departmental role would have failed on the first run.

---

### UAT-OBS-016 · A department that has completed verification can no longer receive a posting or a transfer

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-016 |
| **Module** | 5 — Users & Department Assignment *(raised during End-to-End Step 5, executed)* |
| **Screen** | Role Assignments (postings / transfers) |
| **Screen / Page URL** | `/admin/users/{uuid}/assignments` |
| **Evidence** | `app/Enums/DepartmentStatus.php` has **three** cases — `unregistered`, `registered`, `active`. `RegistrationService.php:53` sets `registered` on form submission; `app/Http/Controllers/Auth/VerificationController.php:93–94` promotes it to **`active`** once the Authorized Officer verifies both OTPs. The two places that gate postings both filter on `Registered` **alone**: `UserAssignmentController.php:48` (`->where('status', DepartmentStatus::Registered->value)`) and `AssignUserRequest.php:32` (`Rule::exists('departments','id')->where('status', DepartmentStatus::Registered->value)`). Verified live: Higher Education (`status = active`, taken through the real registration and verification flow at Step 2) is **absent from the department dropdown** — only School Education is offered — and test **Y1** posting `department_id=3` was refused with *"The selected department id is invalid."* |
| **Current Behaviour** | The moment a department finishes onboarding it becomes ineligible to receive officers. The dropdown offers only departments still in the intermediate `registered` state, and the validation rejects any other id as invalid. The message gives no hint that the department exists and is fully operational — it reads as though the department were unknown |
| **Expected Behaviour** | Both `registered` **and** `active` departments may receive a posting. Only `unregistered` should be excluded |
| **Business Reason** | The controller's own comment states the intent: *"Only a registered department can receive a posting: an officer posted to a department that has not completed S6 registration would hold a role over a department with no masters, no posts and nothing to do."* The intent is **"must have completed registration"**. The implementation reads **"must be exactly in the `registered` state"**, which excludes the one state that proves registration completed |
| **Impact** | **Every department onboarded through the portal.** No transfer into it, no additional Authorized Officer, no replacement after a release. Higher Education — the department this UAT is built around — is already in this state. Seeded departments are unaffected because the demonstration seeder leaves them at `registered` and never runs OTP verification, which is why this has stayed invisible. Does not block a DPC: the two officers created at registration keep their postings, and closing or transferring an officer *out* is unaffected |
| **Severity** | **High** |
| **Suggested Change** | Accept both states in both places — e.g. `whereIn('status', [DepartmentStatus::Registered->value, DepartmentStatus::Active->value])` and the matching `Rule::exists(...)->whereIn(...)`. Worth a wider check at the same time: any other query filtering on `Registered` alone will have inherited the same assumption |
| **Status** | Open |

---

### UAT-OBS-017 · Releasing an officer overwrites the closed posting's remark

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-017 |
| **Module** | 5 — Users & Department Assignment *(raised during End-to-End Step 5, executed)* |
| **Screen** | Role Assignments — Release |
| **Screen / Page URL** | `DELETE /admin/users/{uuid}/assignments` |
| **Evidence** | `DepartmentAssignmentService::retireIncumbent()` — `if ($remarks !== null) { $incumbent->remarks = $remarks; }`. `release()` passes `'Released by the CGPSC Administrator.'`. Verified live on user #10: the row created with remark *"UAT promotion to AH"* read *"Released by the CGPSC Administrator."* after release |
| **Current Behaviour** | The remark explaining why a posting **began** is replaced by the reason it **ended**, on the row describing the posting itself. The original text is not preserved anywhere on the record |
| **Expected Behaviour** | The posting keeps the remark it was created with. A release reason, if recorded, belongs in a separate field or on the Activity Timeline entry — where it is in fact already captured: *"Released from School Education Department with effect from 2026-08-30."* |
| **Business Reason** | This table is explicitly the history — the controller's docblock says it *"never edits a past row, because a past posting is a fact about a period that has closed."* Overwriting the remark edits a past row, in the one field that carries the human explanation |
| **Impact** | Minor information loss on an audit-bearing table. Mitigated substantially by the field-level audit: `UserDepartmentAssignment` is `Auditable`, so the old remark remains recoverable from `audits.old_values`. The Activity Timeline also records the release independently. So nothing is truly lost — it is simply no longer visible on the screen that shows the history |
| **Severity** | Low |
| **Suggested Change** | Do not overwrite `remarks` on retirement. If the release reason should live on the row, add a distinct column for it |
| **Status** | Open |

---

### UAT-OBS-018 · The credential message runs a full stop against the password, and the password may contain punctuation

| Field | Value |
|---|---|
| **Observation ID** | UAT-OBS-018 |
| **Module** | Cross-cutting — Authentication *(raised during End-to-End Step 9 preliminaries)* |
| **Screen** | Credential SMS / e-mail; first login |
| **Screen / Page URL** | `/login` |
| **Evidence** | `app/Domain/Authentication/Listeners/SendCredentialNotification.php` — `"ODPC Portal account created. Login ID: {$loginId}. Temporary password: {$event->temporaryPassword}. You must change your password on first login after OTP verification."` Passwords come from `Str::password(12)`, whose character set includes punctuation. Observed live: `Temporary password: ^z~0HQ^&MD[*. You must change…` — the password ends in `*` and is immediately followed by the sentence's full stop. **The first login attempt during this UAT failed** because the full stop was read as the twelfth character |
| **Current Behaviour** | Password and sentence punctuation are visually indistinguishable. A recipient reading this from an SMS cannot tell whether the trailing `.` belongs to the password, and a copy-paste of the visible token fails authentication with a generic error |
| **Expected Behaviour** | The password is unambiguously delimited — on its own line, in quotes, or as the final token with no trailing punctuation |
| **Business Reason** | This is the first interaction every officer in the state has with the portal. A failed first login on an unattended channel produces a support call the portal cannot answer, and the officer has no way to know the message was the problem |
| **Impact** | Every provisioned account. Recoverable — the Administrator can re-issue — but the recovery costs a call and the cause is invisible to the caller. Higher likelihood than it appears: `Str::password()` includes symbols by default, so a trailing `*`, `!` or `.` is common |
| **Severity** | Low |
| **Suggested Change** | Put the password on its own line, or wrap it, or end the sentence before it. The same message is used by `UserProvisioningService` for both provisioning and password reset, so one change covers all three paths |
| **Status** | Open |

---

## Running summary

| Severity | Open | Approved | Rejected | Fixed | Total |
|---|---:|---:|---:|---:|---:|
| Critical | 1 | 0 | 0 | 0 | 1 |
| High | 8 | 0 | 0 | 0 | 8 |
| Medium | 3 | 0 | 0 | 0 | 3 |
| Low | 6 | 0 | 0 | 0 | 6 |
| **Total** | **18** | **0** | **0** | **0** | **18** |

**Critical:** UAT-OBS-009 (escalated 2026-08-31 — blocks the End-to-End UAT at Step 8). **UAT-OBS-014** is recorded as High with a standing recommendation that the business consider it Critical.

**Note on OBS-002 severity.** The Module 1 closure gate established that UAT-OBS-002 meets the `Critical` bar; the table above still counts it as `High` because the register is not edited without the business owner's approval. The correction is pending.
