# End-to-End Business UAT — Execution Log

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Document type:** Permanent execution journal / meeting minute
**Opened:** 2026-08-31 10:33 IST
**Phase:** End-to-End Workflow Verification (supersedes module-wise UAT, which was halted after Module 1)
**Test Lead role:** Functional Test Lead · Business UAT Lead · Solution Architect · Business Analyst

---

## Standing rules for this document

1. **This is a transcript, not a summary.** Every explanation, question, expected result, business rule, validation, calculation, observation, warning, recommendation, pass/fail verdict and next action that appears in the terminal is reproduced here in full.
2. **Append only. Never overwrite.** A step once written is never edited except to append the user's answer beneath a question already recorded.
3. **Questions are recorded verbatim.** Where a question was put to the user in the terminal, the exact question appears here. The user's answer is appended beneath it when given. Until then it reads `AWAITING RESPONSE`.
4. **The terminal is now a pointer only.** From Step 2 onward the terminal shows a short completion line; this document carries the detail.
5. **Nothing here is implemented.** Findings are recorded, never fixed. Defects go to `BUSINESS_UAT_OBSERVATIONS.md`, unclear rules to `PENDING_BUSINESS_QUESTIONS.md`, improvements to `SUGGESTIONS_AND_IMPROVEMENTS.md`.

## Related registers

| Register | Role |
|---|---|
| `docs/BUSINESS_UAT_OBSERVATIONS.md` | Defects — UAT-OBS-nnn |
| `docs/PENDING_BUSINESS_QUESTIONS.md` | UAT-phase business questions — UAT-Q-nnn |
| `docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` | Construction-phase decisions — BD-2…BD-49 |
| `docs/SUGGESTIONS_AND_IMPROVEMENTS.md` | Improvement proposals — 1…24 |
| `docs/ARCHITECT_REVIEW.md` | Implementation audit baseline (2026-08-28) |

---

## Session context

### Environment verified at session open

| Item | Value |
|---|---|
| Framework | Laravel 13.23.0 |
| Database | MySQL, `dpc_dev` |
| `APP_ENV` | `local` |
| Quality gate re-run 2026-08-31 | Pint **passed** · PHPStan level 6 **no errors** · PHPUnit **1287 passed, 4228 assertions** · `composer audit` **no advisories** |
| Working tree | **426 changed paths, 151 untracked, last commit 2026-08-22** — uncommitted, no rollback point |

### The sample scenario chosen for this walkthrough

> **Higher Education Department promotes Assistant Professor → Associate Professor for DPC Year 2026.**

**Rationale.** School Education (department #2) already holds 9 posts, 8 approved Pre-DPC proposals and 9 DPC proposals; walking it would verify nothing because the work is already done. Higher Education (department #3) is `status = unregistered` with no posts, no recruitment rules, no Pre-DPC and no DPC, but does have staged accounts. Verified in code: `RegisterDepartmentRequest` accepts a department **only** when its status is `Unregistered`, so Higher Education is deliberately staged as the intended registration target.

### Accounts used in this walkthrough

Password for every account: `Demo@2026`

| Role | Login ID |
|---|---|
| CGPSC Administrator | `admin@cgpsc.gov.in` |
| Authorized Officer, Higher Education | `ao.he@cg.gov.in` |
| Administrative Head, Higher Education | `ah.he@cg.gov.in` |
| Chairman, CGPSC | `chairman@cgpsc.gov.in` |
| Member, CGPSC ×5 | `member1@cgpsc.gov.in` … `member5@cgpsc.gov.in` |

### The 26 steps to be verified

1. Master Data Verification · 2. Department Creation · 3. Department Post Mapping · 4. Recruitment Rule · 5. User & Department Assignment · 6. Working Officers · 7. Seniority List Upload · 8. Vacancy Calculation · 9. Pre-DPC Creation · 10. AO Submission · 11. AH Approval · 12. Pre-DPC Activation · 13. DPC Creation · 14. Chairman Assignment · 15. Committee Member Selection · 16. Committee Validation · 17. Evaluation / Tabulation · 18. Merit Calculation · 19. Recommendation · 20. Proceedings Generation · 21. Approval Workflow · 22. Final Result Publication · 23. Candidate Login · 24. Candidate Status Verification · 25. Reports · 26. Audit Trail

---
---

====================================================

## Step Number

**1**

## Step Name

**Master Data Verification**

## Timestamp

**2026-08-31 10:33 IST**

## Purpose

To confirm that every controlled list the promotion pipeline draws from is present and `Active` before any transactional work begins.

## Business Explanation

Every later step of a DPC selects from a master rather than accepting typed text — the district a department sits in, the pay scale a post carries, the reservation category an officer belongs to, the class pair that decides the zone of consideration. If a master row is missing, or exists but is not `Active`, the failure does not surface at the master screen. It surfaces three steps later as an empty dropdown, or as a value that cannot be chosen, with nothing on screen explaining why. Verifying the masters first is therefore not a formality; it is the only point at which this class of failure is cheap to find.

## Questions Asked To User

**Question 1.1** — *"The Higher Education scenario is acceptable?"*

> **USER ANSWER (2026-08-31, 10:41 IST):** *"Yes, I approve the selected End-to-End Business UAT scenario. I confirm that the Higher Education Department scenario (Assistant Professor → Associate Professor for DPC Year 2026) is acceptable for this End-to-End Business UAT."*

**Question 1.2** — *"You are content for Steps 2–22 to create real data in `dpc_dev`?"*

Context given to the user in the terminal: *"Steps 2–22 require you to enter data through the UI, which will create real rows in `dpc_dev`. That is inherent to end-to-end testing — I read the instruction 'do not change the database' as binding on me, not on your clicks. `APP_ENV=local`, so this is the right place to do it."*

> **USER ANSWER (2026-08-31, 10:41 IST):** *"I approve the creation of real test data in the local development database (dpc_dev) through the application UI for Steps 2–22. This is a local development environment and the generated data will be used only for Business UAT and verification."*

## Expected User Action

**Nothing is entered at this step.** This is a read-only readiness check. The user signs in as `admin@cgpsc.gov.in` and confirms each list under `/admin/…`.

## Expected Result

Every master populated and `Active`. Verified live against `dpc_dev` on 2026-08-31:

| Master | Rows | Active | Verdict |
|---|---:|---:|---|
| District | 28 | 28 | ⚠ incomplete — see Observations |
| Designation | 23 | 23 | PASS |
| Pay Scale | 6 | 6 | PASS |
| Class | 3 | 3 | PASS — `I`, `II`, `III` |
| Reservation Category | 5 | 5 | PASS — UR, SC, ST, OBC, EWS |
| Qualification | 17 | 17 | PASS |
| Vacancy Type | 4 | 4 | PASS — clear, from_promotion, from_retirement, from_deputation |
| DPC Type | 3 | 3 | PASS — fresh, review, circulation |
| Committee Type | 2 | 2 | PASS |
| Committee Configuration | 2 | 2 | PASS — one item to verify at Step 16 |
| Review Reason | 6 | 6 | PASS |
| Record Reason | 7 | 7 | PASS |
| Reservation Policy | 5 | 5 | PASS |
| Reservation Roster | 2 | 2 | PASS |
| Reservation Roster Point | 200 | 200 | PASS |
| CGPSC Chairman | 1 | 1 | PASS — Dr. Ajay Kumar Shrivastava |
| Portal Notice | 0 | 0 | PASS — not a gap; `PortalNoticeComposer` carries a catalogue fallback (audit R28) |

**Conclusion: every master the pipeline needs is populated and Active.**

## Business Rules

1. **Only `Active` rows appear downstream.** Confirmed in code at `app/Http/Controllers/Auth/RegistrationController.php:36` and `app/Http/Controllers/Admin/DepartmentController.php:114` (`districts()`), both filtering `status = active`.
2. **Class codes `I` / `II` / `III` are deliberately not editable** (BD-26). The merit engine keys its zone-of-consideration rules on class *pairs*; a code invented at runtime is a class the engine has no rule for and would fall through to the uncapped default without saying so.
3. **The four-state master lifecycle** is `Draft`, `Active`, `Inactive`, `Archived`. Only `Active` is selectable anywhere; the other three are indistinguishable to downstream consumers.

## Validations

- `status` — required, must be one of the four `MasterStatus` enum values.
- Name uniqueness — enforced per master on the English name.
- Access — every master screen is behind `role:admin`. An Authorized Officer receives HTTP 403, pinned by test `test_non_admin_cannot_access_districts`.

## Calculations

**None.** Masters are inputs only; no arithmetic occurs at this step.

## Database Impact

**None.** This step is read-only. No row was created, updated or deleted.

## Audit Trail

**No entries generated**, because nothing was changed. Had a master been edited, two independent records would have been written: field-level old/new values via `owen-it/auditing`, and an Activity Timeline entry (`master.created` / `master.updated` / `master.deleted`) via `MasterCrudService`.

## Downstream Impact

- **Reservation Category** is the highest-consequence master in this set. It is referenced by four officer-level tables — `dpc_evaluation_rows`, `dpc_seniority_entries`, `dpc_vacancies`, `dpc_working_officers` — and is read by the merit engine through `OfficerRecord`.
- **Class** drives the zone of consideration at Step 17.
- **District** is required at Step 2; see Observations.
- **Committee Configuration** governs Step 16.

## Observations

**No new observations were raised at this step.** Two carry-forward items were stated to the user:

**Carry-forward 1 — UAT-OBS-001 becomes live at Step 2.**
The District Master holds 28 of the State's 33 districts. Department registration requires a district. If Higher Education's headquarters is Raipur the walkthrough is unaffected, but Step 2 is where this gap becomes real. Already recorded as `UAT-OBS-001` (Severity: High, Status: Open).

**Carry-forward 2 — one item to verify at Step 16, not yet a finding.**
Committee Configuration record #1 has an **empty `effective_from`**, while record #2 carries `2026-01-01`. On an effective-dated master, a null start date may legitimately mean "always in force", or it may be a gap. This was **not** recorded as an observation, because it has not yet been resolved against `CommitteeRuleResolver`. It will be settled when the walkthrough reaches Committee Validation rather than guessed at now.

## Business Questions

**None raised at this step.** Three UAT-phase questions remain open from Module 1 (`UAT-Q-001` master lifecycle, `UAT-Q-002` district bifurcation, `UAT-Q-003` LGD codes). None of them blocks Step 1.

## Suggestions

**None raised at this step.**

## Pass/Fail

**✓ PASS**

Every master required by the pipeline is populated and `Active`. The one qualification — the incomplete district list — is an already-recorded observation, not a new failure, and does not prevent Step 1 from passing.

## User Confirmation

**CONFIRMED — 2026-08-31, 10:41 IST.** The user approved the scenario and the creation of test data in `dpc_dev`, and instructed the walkthrough to continue to Step 26, pausing at every major business milestone. The user additionally directed that no fixes be implemented during UAT, that defects, unclear rules and recommendations be routed to their respective registers, and that each step also verify the implementation against the approved business rules, architecture and implementation audit.

**Milestones at which the user requires a pause:** Department Registration · Department Post Mapping · Recruitment Rule · Working Officers · Seniority List · Vacancy Calculation · Pre-DPC Creation · AO Submission · AH Approval · Pre-DPC Activation · DPC Creation · Chairman Assignment · Committee Formation & Validation · Evaluation / Tabulation · Merit Calculation · Recommendation · Proceedings · Approval · Result Publication · Candidate Login · Reports · Audit Trail.

## Next Step

**Step 2 — Department Creation**, to begin once the user confirms the scenario and consents to data creation in `dpc_dev`.

Warning carried into Step 2: the District Master gap (`UAT-OBS-001`) is exercised for the first time at that step.

====================================================

====================================================

## Step Number

**2**

## Step Name

**Department Creation (Department Registration)** — *major business milestone*

## Timestamp

**2026-08-31 10:41 IST** — prepared. **Execution not yet performed: blocked, see Observations.**

## Purpose

To bring Higher Education Department into the portal as a registered entity, and to provision its two statutory accounts — the Authorized Officer and the Administrative Head — so that departmental work can begin.

## Business Explanation

A department can do nothing in the portal until it is registered (PPT S5–S6). Registration is **self-service and public**: it happens at `/register` with nobody signed in, because at that moment the department has no accounts to sign in with. The act does three things at once — it completes the department's own record (district, place, PIN, official e-mail), it flips the department from `unregistered` to `registered`, and it creates the two officer accounts that will run every DPC thereafter.

The **two-tier separation** is the essential control and everything later depends on it. The **Authorized Officer (AO)** prepares work — Pre-DPC proposals, working officers, seniority lists, DPC proposals. The **Administrative Head (AH)** approves it. One person cannot do both, and that is deliberate: it is the departmental equivalent of maker–checker. Registration is the only point at which both accounts are created together.

Note on architecture, verified against the code: **the posting is an assignment, not a column on the user.** `RegistrationService::createOfficer()` calls `DepartmentAssignmentService::assign()`, and that call is what both posts the officer to Higher Education *and* grants the role — nothing calls `assignRole()` directly. A later transfer is the same operation through the same service. This matches the architecture handbook and the 2026-08-24 change item 2.

## Questions Asked To User

**Question 2.1** — *"Step 2 cannot be executed in this environment as it stands. No queue worker is running, and both the OTP and the Authorized Officer's temporary password are delivered through queued listeners. Do you want me to start a queue worker (`php artisan queue:work`) in the background so registration can be completed, or would you rather start it yourself?"*

> **USER ANSWER (2026-08-31, 10:58 IST):** *"I will start the queue service myself. Do NOT start any background process automatically. Wait until I confirm it is running."*
>
> **USER CORRECTION issued with the same message:** *"Never ask an environment-related question (queue, Redis, mail, storage, scheduler, Horizon, Supervisor, etc.) without first inspecting the project configuration and documentation. Always verify before asking."*
>
> **Test Lead acknowledgement.** The correction is accepted and correct. Question 2.1 named `php artisan queue:work` before establishing whether this project defines a queue runner at all. That verification has now been carried out in full and is recorded as **Step 2E** below. The command is not restated to the user until that inspection is complete.

**Question 2.2** — *"The scenario is Assistant Professor → Associate Professor, but the Designation Master holds no such designations — it carries secretariat and School Education designations only. Should the missing Higher Education designations be added as part of Step 3, or should the scenario switch to a post pair that already exists?"*

> **USER ANSWER (2026-08-31, 10:58 IST):** *"Do NOT change the Higher Education scenario. The purpose of this End-to-End Business UAT is to validate the complete Higher Education promotion workflow. If the required Higher Education Posts are not yet available, create them during the appropriate Department Post Mapping / Master setup step according to the approved business model. Do not switch to School Education or any other department."*
>
> **Consequence recorded.** The scenario stands unchanged. **Step 3 scope is therefore widened**: before the Department Post Mapping can be created, the Designation Master must gain *Assistant Professor* and *Associate Professor*, entered through `/admin/designations` as the CGPSC Administrator. This is master-data entry through the UI, which the user authorised at Step 1, Question 1.2. It is **not** a code or schema change.

## Expected User Action

**Do not sign in.** Registration is a public screen.

1. Open `/register`.
2. Complete the form with the following values:

| Field | Value to enter |
|---|---|
| Department | **Higher Education Department** |
| District | **Raipur** *(district #24)* |
| Place | `Raipur` |
| PIN | `492001` |
| Department e-mail | `he@cg.gov.in` |
| **AO** Name | `Shri Ramesh Kumar Sinha` |
| **AO** Designation | **Deputy Secretary** *(#4)* |
| **AO** Mobile | `9000100001` |
| **AO** E-mail | `ao.he@cg.gov.in` |
| **AH** Name | `Smt. Vandana Shukla` |
| **AH** Designation | **Joint Secretary** *(#3)* |
| **AH** Mobile | `9000100002` |
| **AH** E-mail | `ah.he@cg.gov.in` |

3. Submit. Expect a redirect to `/verify`.
4. Retrieve the two OTPs and the two temporary passwords from `storage/logs/laravel.log` — **this requires a running queue worker**.
5. Enter both OTPs at `/verify`.

**Note on the chosen values.** The mobiles and e-mails above are exactly those the demonstration credentials document claims already exist. They do **not** exist (see Observations), so they are free to use and re-establish the documented state.

## Expected Result

1. The department dropdown at `/register` lists **8 departments** — every one except School Education, which is already registered. Verified: `RegistrationController::create()` filters `status = unregistered`.
2. On submit, redirect to `/verify` with the message *"Registration submitted. Credentials have been sent. Please verify the OTPs sent to your department email and Authorized Officer mobile."*
3. Higher Education becomes `registered` with `registered_at` stamped.
4. Two new users exist, each with `must_change_password = true` and a random 12-character password.
5. Two active assignments exist against department #3 — one `authorized_officer`, one `administrative_head`.
6. Four OTP rows are written (e-mail + SMS for the AO; the service issues on every channel the user holds).
7. After correct OTPs, the AO's `email_verified_at` and `mobile_verified_at` are stamped.

## Business Rules

1. **Only an unregistered department may be registered.** `RegisterDepartmentRequest` enforces `Rule::exists('departments','id')->where('status','unregistered')`. A second registration of the same department is impossible through this screen.
2. **All three official e-mail addresses must be `.gov.in`.** Enforced by regex `/@([A-Za-z0-9\-]+\.)*gov\.in$/i` on the department, AO and AH e-mails, each with its own message.
3. **The AO and the AH must be two different people.** `ao_mobile` carries `different:ah_mobile` and `ao_email` carries `different:ah_email`. This is the maker–checker separation expressed as validation.
4. **An e-mail or mobile may identify only one account portal-wide.** `Rule::unique('users','email')` and `Rule::unique('users','mobile')` on both officers.
5. **Both officers must change the issued password at first login** — `must_change_password = true`.
6. **Verification is all-or-nothing.** `VerificationService::confirm()` marks both channels verified only if every supplied code is valid; one wrong code verifies nothing.
7. **The role is granted by the posting, not separately.** Confirmed in `RegistrationService::createOfficer()`.
8. **The whole registration is one transaction.** `DB::transaction()` wraps the department update and both officer provisions, so a failure on the AH leaves no half-registered department.

## Validations

Complete list from `RegisterDepartmentRequest::rules()`:

| Field | Rules |
|---|---|
| `department_id` | required; must exist **and** be `unregistered` |
| `district_id` | required; must exist in `districts` |
| `place` | required; string; max 255 |
| `pin` | required; **exactly 6 digits** |
| `department_email` | required; e-mail; max 255; **`.gov.in`** |
| `ao_name` | required; string; max 255 |
| `ao_designation_id` | required; must exist in `designations` |
| `ao_mobile` | required; **exactly 10 digits**; different from `ah_mobile`; unique in `users` |
| `ao_email` | required; e-mail; max 255; `.gov.in`; different from `ah_email`; unique in `users` |
| `ah_name` | required; string; max 255 |
| `ah_designation_id` | required; must exist in `designations` |
| `ah_mobile` | required; 10 digits; unique in `users` |
| `ah_email` | required; e-mail; max 255; `.gov.in`; unique in `users` |

**Implementation-vs-audit check performed at this step.** Two asymmetries were noted and are **not** defects, but are recorded for completeness: `district_id` is validated with `Rule::exists('districts','id')` **without** a `status = active` filter, whereas the dropdown that feeds it *is* filtered to Active — so a hand-crafted POST could name a non-Active district. And `ah_mobile`/`ah_email` carry no `different:` rule of their own; the separation is asserted only from the AO's side, which is sufficient because both directions of the same pair are covered.

## Calculations

**None.** Registration performs no arithmetic. The only generated values are two random 12-character passwords (`Str::password(12)`) and the OTP codes.

## Database Impact

| Table | Effect |
|---|---|
| `departments` | Row #3 updated via `forceFill` — `district_id`, `place`, `pin`, `email`, `status = registered`, `registered_at = now()` |
| `users` | **2 rows inserted** — AO and AH, each `is_active = true`, `must_change_password = true`, hashed random password |
| `user_department_assignments` | **2 rows inserted** — `department_id = 3`, roles `authorized_officer` and `administrative_head`, `active_flag = 1`, remarks *"Provisioned by department registration (S6)."* |
| `model_has_roles` | 2 rows, granted through the assignment |
| `otp_verifications` | Up to 4 rows; `code_hash` only — **the code itself is never stored** |

All of it inside one transaction.

## Audit Trail

- `Department` is `Auditable` + blameable, so the status change to `registered` is captured with old and new values.
- Each officer's assignment is recorded on the Activity Timeline with the remark *"Provisioned by department registration (S6)."*
- `CredentialsIssued` is dispatched per officer; the temporary password is **not** persisted anywhere.
- OTP rows record the identifier, channel, purpose, hash, expiry and later `consumed_at`.
- **Note:** `forceFill` bypasses mass-assignment protection but not the auditing observer, so the audit record is still written.

## Downstream Impact

- Until Higher Education is `registered`, **nothing else in the walkthrough can proceed** — Steps 3 to 22 all act as or on behalf of this department.
- The AO account becomes the actor for Steps 3, 4, 6, 7, 9, 10 and 13.
- The AH account becomes the actor for Step 11.
- The department's district (Raipur) appears on every document the department later produces.
- The department gains `code = HE`, which is what makes the DPC codes read `DPC-2026-HE-0001`, mirroring School Education's existing `DPC-2026-SE-000n` series.

## Observations

Two new observations were raised at this step and appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.

**UAT-OBS-005 — the demonstration credentials document names two accounts that do not exist.** `docs/demo/DEMO_LOGIN_CREDENTIALS.md` (generated 2026-08-26 08:52) lists `ao.he@cg.gov.in` and `ah.he@cg.gov.in` as Higher Education's Authorized Officer and Administrative Head, each with an *"Active assignment … from 25-08-2026"*. Verified against `dpc_dev`: **neither user exists.** The document also omits `ds.gad@cg.gov.in`, which does exist. Severity: **Medium**.

**UAT-OBS-006 — registration cannot be completed without a queue worker, and no document says so.** `QUEUE_CONNECTION=redis`; both `SendOtpNotification` and `SendCredentialNotification` implement `ShouldQueue`. No worker process is running and `queues:default` already holds **34 pending jobs**. The OTP is stored only as a hash, so a blocked queue leaves no way to obtain it. `queue:work` appears nowhere in the README, Developer Handbook, User Guide or demonstration credentials document. Severity: **Medium** — it is a run-book gap rather than a product defect, since production would run a worker, but it **blocks this step today and would block a customer demonstration**. This is why seeded accounts work and a newly registered one does not: the seeder pre-verifies channels and disables the first-login password change.

**Carry-forward to Step 3 — the Designation Master has no Higher Education academic designations.** It holds 23 rows, all secretariat or School Education. *Assistant Professor* and *Associate Professor* are absent. Recorded here, raised to the user as Question 2.2, and not entered as an observation until the user rules on whether the masters should be extended or the scenario adjusted.

## Business Questions

**None raised at this step.** Question 2.2 above is a scenario question for the user, not a business rule ambiguity, and will be recorded in `PENDING_BUSINESS_QUESTIONS.md` only if the answer reveals a genuine policy gap.

## Suggestions

**None recorded yet.** Should the user confirm that a queue worker is simply undocumented rather than unintended, the run-book gap in UAT-OBS-006 becomes a documentation suggestion; that will be raised after the answer to Question 2.1.

## Pass/Fail

**⏸ BLOCKED — not executed.**

Step 2 was fully prepared and its expected behaviour verified against the code, but it **cannot be executed** until a queue worker is running (UAT-OBS-006). No pass or fail verdict is recorded, because the step was not run. Nothing has been entered into the application.

## User Confirmation

**ANSWERED — 2026-08-31, 10:58 IST.** Both questions answered; the answers are recorded verbatim beneath each question above. The user will start the queue service personally and has forbidden any automatic background process. The Higher Education scenario is confirmed unchanged, with the missing designations to be created during master setup.

## Next Step

**Step 2E — Queue Implementation Verification** (inserted at the user's instruction, before Step 2 is executed). Thereafter Step 2 execution, then **Step 3 — Department Post Mapping**, whose scope now includes creating the two missing Higher Education designations.

====================================================

====================================================

## SYNCHRONIZATION ENTRY — 2026-08-31, 10:58 IST

Carried out on the user's instruction: *"The execution log must be the SINGLE SOURCE OF TRUTH. Review every terminal output since the last synchronized entry. Append EVERYTHING that is missing."*

### Terminal output not previously captured

**Terminal progress message printed after the Step 2 entry was written (2026-08-31, ~10:45 IST), reproduced verbatim:**

> *"**Step 2 prepared but BLOCKED — cannot execute yet.** Full details in `docs/uat/END_TO_END_UAT_EXECUTION_LOG.md`.*
>
> *Two new defects recorded in `docs/BUSINESS_UAT_OBSERVATIONS.md` (now 6 total):*
>
> - ***UAT-OBS-005** (Medium) — `ao.he@cg.gov.in` and `ah.he@cg.gov.in` do not exist, despite the demo credentials document listing both with active assignments. The scenario was chosen partly on their strength.*
> - ***UAT-OBS-006** (Medium) — **this is what blocks Step 2.** `QUEUE_CONNECTION=redis`, both the OTP and password listeners are `ShouldQueue`, no worker is running, 34 jobs already queued. The OTP is stored as a hash only, so it cannot be read back. `queue:work` is documented nowhere.*
>
> *Two answers needed before I can proceed (both logged): [Questions 2.1 and 2.2 as recorded above]."*

**Terminal progress message printed after the log was created (2026-08-31, ~10:36 IST), reproduced verbatim:**

> *"Execution journal created. Step 1 has been back-filled in full — including both questions put to you, which are recorded as `AWAITING RESPONSE` and will have your answers appended beneath them. Full details: `docs/uat/END_TO_END_UAT_EXECUTION_LOG.md`. Two answers still needed before Step 2 (both already logged): is the Higher Education scenario acceptable, and are you content for Steps 2–22 to create real data in `dpc_dev`?"*

### Nothing else outstanding

Every question, answer, warning, blocker, observation and recommendation raised in the terminal during the End-to-End phase is now present in this document. The verification of that claim is recorded under **Log Completeness Verification** below.

====================================================

## Step Number

**2E** *(inserted on user instruction, before Step 2 execution)*

## Step Name

**Queue Implementation Verification — how does this project intend queues to run?**

## Timestamp

**2026-08-31 11:02 IST**

## Purpose

To determine, by inspection rather than assumption, which queue runner this project defines — so that the command given to the user is the project's own and not a generic Laravel default.

## Business Explanation

Step 2 is blocked because the OTP and the officers' temporary passwords are delivered by queued listeners (UAT-OBS-006). Unblocking it requires running whatever this project uses to consume its queue. Naming the wrong mechanism would be worse than naming none: a Horizon project driven by `queue:work` silently bypasses its own tags, retry and balance configuration, and a Supervisor-managed installation driven by hand acquires a second uncoordinated consumer.

## Questions Asked To User

**Question 2E.1** — *"The project defines no queue runner anywhere. Per your instruction not to assume where none is defined: shall I proceed on the standard Laravel command that matches this project's configuration — `php artisan queue:work` — and record the absence of a defined runner as an observation?"*

> AWAITING RESPONSE

## Expected User Action

None at this step. This is an inspection. The user's action follows once the command is agreed.

## Expected Result

A single, named command that the project itself sanctions.

## Findings — every source inspected

| Source | Inspected | Result |
|---|---|---|
| `composer.json` → `require` | Yes | `laravel/framework`, `laravel/tinker`, `maatwebsite/excel`, `owen-it/laravel-auditing`, **`predis/predis`**, `spatie/laravel-permission`. **`laravel/horizon` is absent** |
| `composer.json` → `require-dev` | Yes | faker, larastan, pail, pint, mockery, collision, phpunit. No queue tooling |
| `composer.json` → `scripts` | Yes | `dev` = **`php artisan serve` only** — no worker, no `concurrently`, no `queue:listen`. `setup`, `test`, `pint`, `stan`, `gate`, `audit:security`, `schema:mysql` defined; **none starts a queue** |
| `config/queue.php` | Yes | Stock Laravel. `default` = `env('QUEUE_CONNECTION','database')`. Connections: `sync`, `database`, `beanstalkd`, `sqs`, `redis`. Redis connection reads `REDIS_QUEUE` (unset → `default`), `retry_after` 90s, `after_commit` false |
| `config/horizon.php` | Yes | **Does not exist** |
| Supervisor configuration | Yes | **None in the repository** — no `*.conf`, no `supervisor*` path |
| Docker / Sail | Yes | **None** — no `Dockerfile`, no `docker-compose*`, no Sail dependency |
| `Procfile` / CI / deployment scripts | Yes | **None** — no `Procfile`, no `.github/`, no deploy script |
| `routes/console.php` | Yes | Only the stock `inspire` command. **No scheduler entries**, so no `schedule:work` is implicated |
| `README.md` → *Local setup* | Yes | `composer install` → … → `php artisan serve`. States *"Requires MySQL (`dpc_dev`) and Redis; both are load-bearing"* and, at line 34, *"Redis (cache, queue, sessions)"* — **but never starts a worker** |
| `docs/CGPSC_DPC_DEVELOPER_HANDBOOK.md` | Yes | **Zero occurrences of the word "queue"** |
| `docs/CGPSC_DPC_USER_GUIDE.md` | Yes | No queue guidance |
| `docs/archive/**` (archived deployment runbooks) | Yes | Only `deployment/EVIDENCE-TABLE-PERMISSIONS.md`, which concerns database grants. The archived design README states the target platform: *"AWS EC2 + S3 · Nginx · PHP-FPM · Ubuntu LTS. No Docker/Kubernetes"* — implying Supervisor-managed workers in production, **but no Supervisor configuration was ever committed** |
| `.env.example` | Yes | Ships **`QUEUE_CONNECTION=redis`**, `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `REDIS_CLIENT=predis` |
| `.env` (working) | Yes | Identical queue configuration; differs from the example only by `REDIS_PASSWORD` being set |

### Conclusion

**The project defines no queue runner.** Redis is the intended queue transport — `.env.example` ships it, so this is the designed configuration and not local drift — yet **nothing in the repository, the README, the Developer Handbook, the User Guide or the archived runbooks starts a consumer.** Of the four possibilities the user listed:

1. `php artisan queue:work` — **not defined anywhere, but it is the only mechanism consistent with the configuration present**
2. `php artisan horizon` — **ruled out**: package absent, config absent
3. Supervisor-managed workers — **ruled out for this repository**: implied by the archived production platform note, never committed
4. Another queue implementation — **none found**

## Business Rules

None. This step concerns environment operation, not business policy.

## Validations

Not applicable.

## Calculations

Not applicable.

## Database Impact

**None.** This step was pure inspection; no row was read for change and nothing was written.

## Audit Trail

No application audit entries. The inspection itself is recorded in this document.

## Downstream Impact

Every queued behaviour in the portal depends on the answer: OTP delivery at Steps 2 and 23, credential delivery at Steps 2 and 5, and the notification pipeline at Step 19 onward. If no worker runs for the remainder of the UAT, each of those steps will appear to succeed while delivering nothing — the same silent failure already recorded as UAT-OBS-006.

## Warnings

**Warning 1 — 34 stale jobs will execute the instant a worker starts.** `queues:default` already holds 34 pending jobs accumulated before this session. Starting a worker will process all of them immediately, producing a burst of log entries for events long past, and some may fail into `failed_jobs`. This is expected and harmless, but it means the log will contain OTPs and credentials belonging to earlier seeded activity. **When retrieving the Step 2 OTP, read the end of the log, not the first match.**

**Warning 2 — the queue must remain running for the rest of the UAT.** If the worker is stopped between steps, later notification steps will silently under-deliver.

## Observations

**No new observation raised.** The absence of a defined queue runner is already the substance of **UAT-OBS-006**, whose evidence field is now confirmed far more strongly than when it was written: it is not merely that `queue:work` is undocumented, but that **no queue runner of any kind is defined by the project**, while `.env.example` ships a Redis queue as the intended configuration. The observation stands at **Medium** and is not re-severitised without the business owner's approval.

## Business Questions

None. This is an engineering and run-book matter, not a business rule.

## Suggestions

**Suggestion candidate recorded, not yet written to the register** — pending the user's answer to Question 2E.1, since it would be premature to propose a run-book addition before the user confirms the command. The proposal would be: add a *Running the queue* section to `README.md` and the Developer Handbook naming the command and the fact that OTP and credential delivery depend on it; and commit a Supervisor unit for the EC2 target named in the archived design note.

## Pass/Fail

**✓ PASS — inspection complete and conclusive.** The question *"how does this project intend queues to run?"* is answered: **it does not say.** That is itself the finding, and it is recorded.

## User Confirmation

**AWAITING RESPONSE** to Question 2E.1.

## Next Step

Await the user's confirmation of the command, then await the user's confirmation that the queue service is running. **No background process will be started by the Test Lead**, per the user's explicit instruction of 2026-08-31 10:58 IST. Thereafter: **Step 2 execution**.

====================================================

## STANDING RULE ADDED — 2026-08-31, 11:02 IST

On the user's instruction, two rules are added to this document's standing rules and take effect immediately.

**Rule 6 — Log before terminal.** Before any message is printed in the terminal, it is first appended to this document. Only after the write succeeds is it displayed. This applies to questions, answers, errors, warnings, observations, suggestions, progress messages, decisions, business rules, validation messages and summaries. Should this document and the terminal ever diverge again, the UAT **stops**, this document is synchronized first, and only then does the UAT continue.

**Rule 7 — Verify before asking about the environment.** No environment-related question — queue, Redis, mail, storage, scheduler, Horizon, Supervisor or any other — is put to the user until the project's configuration and documentation have first been inspected. Always verify before asking. This rule was issued after Question 2.1 named a command before establishing whether the project defined one.

====================================================

## LOG COMPLETENESS VERIFICATION — 2026-08-31, 11:02 IST

Performed on the user's instruction, against the six checks the user specified.

| Check | Result | Evidence |
|---|---|---|
| ✓ Every terminal question exists in the log | **Pass** | Questions 1.1, 1.2, 2.1, 2.2, 2E.1 — five questions asked in the terminal, five recorded |
| ✓ Every user answer exists in the log | **Pass** | Answers to 1.1 and 1.2 recorded 10:41 IST; answers to 2.1 and 2.2 recorded 10:58 IST; 2E.1 open. The user's correction on environment questions is also recorded verbatim |
| ✓ Every warning exists | **Pass** | Step 1 carry-forward warnings (district gap, committee configuration `effective_from`); Step 2 warning that the district gap becomes live; Step 2E Warnings 1 and 2 |
| ✓ Every blocker exists | **Pass** | UAT-OBS-006 recorded as the Step 2 blocker, with Step 2 marked `⏸ BLOCKED — not executed` and no pass/fail verdict claimed |
| ✓ Every observation exists | **Pass** | UAT-OBS-005 and UAT-OBS-006 recorded in full in the Step 2 entry and in `BUSINESS_UAT_OBSERVATIONS.md`. Carry-forwards that are deliberately **not yet** observations are named as such, with the reason |
| ✓ Every recommendation exists | **Pass** | The Step 2E suggestion candidate is recorded together with the reason it is not yet in the register; the Step 1 and Step 2 entries carry their recommendations inline |

**Two omissions were found during this verification and have been corrected** by the Synchronization Entry above: the two terminal progress messages of ~10:36 and ~10:45 IST had been displayed but not recorded. Both are now reproduced verbatim.

**Result: the execution log is synchronized and complete as at 2026-08-31, 11:02 IST.**

====================================================

====================================================

## Step Number

**2Q** *(inserted on user instruction — queue residue assessment before the worker is started)*

## Step Name

**Pending Queue Job Assessment — is it safe to clear the 34 jobs?**

## Timestamp

**2026-08-31 11:18 IST**

## Purpose

To determine, before the user starts the queue worker, whether the 34 pending jobs are disposable demonstration residue or data the UAT depends on — and therefore whether `queue:clear` / `queue:flush` should be run first.

## Business Explanation

The user authorised clearing **conditionally**: *"Lekin sirf tab, agar wo 34 pending jobs kisi important test ya data se related nahi hain… Agar doubt ho, pehle `php artisan queue:failed` aur queue status dekh lena."* Clearing a queue is irreversible. A queued job is not inert — some jobs write to the database — so the condition had to be tested rather than presumed.

## Questions Asked To User

**Question 2Q.1** — *"On the evidence below I recommend **not** clearing the queue. Do you accept that, or do you still want the 34 jobs cleared?"*

> AWAITING RESPONSE

## Expected User Action

None until the recommendation is accepted or overruled. Thereafter: start the queue worker.

## Findings

### Queue state

| Measure | Value |
|---|---|
| `queues:default` (pending) | **34** |
| `queues:default:delayed` | 0 |
| `queues:default:reserved` | 0 |
| `php artisan queue:failed` | **"No failed jobs found."** |
| `failed_jobs` table | **0 rows** |

### What the 34 jobs actually are

| Job class | Count | What it does | Database impact |
|---|---:|---|---|
| `App\Domain\Shared\Listeners\SendWorkflowNotification` | **19** | Sends SMS + e-mail for a workflow transition. `SmsSender` is bound to `LogSmsSender`; `MAIL_MAILER=log` | **None.** Writes to the log only |
| `App\Domain\Notification\Listeners\RecordInAppNotification` | **15** | Writes the in-app copy of a workflow notification via `NotificationCentre::notify()` | **Yes** — inserts into `user_notifications` |

### Provenance

Job payloads were decoded. Every sampled job carries the **`pre_dpc` workflow**, which belongs to the School Education demonstration pipeline — the eight approved Pre-DPC proposals seeded for department #2. `user_notifications` already holds **115 rows**, the newest stamped `2026-08-12 05:34:00`, i.e. back-dated seed data. The 34 jobs are therefore **residue of the demonstration seed**, queued to Redis at a time when no consumer was running. They are not connected to this UAT session, and no job in the queue originated from anything the user or the Test Lead has done.

## Correction to Step 2E, Warning 1

**Warning 1 as recorded at Step 2E is wrong and is corrected here.** It stated that starting the worker would surface *"OTPs and credentials belonging to earlier seeded activity"* in the log, and advised reading the end of the log to avoid confusion.

**On inspection, no OTP job and no credential job is queued at all.** All 34 pending jobs are workflow notifications (`SendWorkflowNotification`, `RecordInAppNotification`). `SendOtpNotification` and `SendCredentialNotification` appear **nowhere** in the pending set — the seeded accounts were pre-verified by the seeder, so no OTP was ever issued for them.

The practical consequence is the opposite of what Warning 1 implied: **the log will contain no stale OTP to be confused with the Step 2 OTP.** The Step 2 OTP, once issued, will be the only OTP in the log. The advice to read the end of the log remains sensible practice but is no longer necessary for correctness.

This correction is recorded rather than silently amended, because it changed the recommendation put to the user.

## Recommendation

**Do NOT run `queue:clear`. Do NOT run `queue:flush`. Start the worker and let the 34 jobs drain.**

Three reasons:

1. **The stated reason for clearing does not exist.** Clearing was proposed to keep stale OTPs out of the log. There are no queued OTP or credential jobs, so clearing solves a problem that is not present.
2. **Clearing would silently destroy data.** The 15 `RecordInAppNotification` jobs each insert into `user_notifications`. Discarding them means 15 in-app notifications for genuine, completed School Education Pre-DPC transitions are never written. That would leave the notification feed permanently inconsistent with the workflow history the demonstration data asserts — and Step 26 (Audit Trail) and any Notifications review would be verifying an incomplete feed. `queue:clear` is irreversible.
3. **`queue:flush` would be a no-op.** It clears *failed* jobs, and there are none. Running it achieves nothing.

**Letting the jobs drain is the faithful option:** it completes the notification feed the demonstration seed intended, costs 19 harmless log lines, and destroys nothing.

## Business Rules

None. This is environment operation.

## Validations

Not applicable.

## Calculations

Not applicable.

## Database Impact

**None from this assessment** — it was read-only. If the recommendation is accepted, draining the queue will **insert up to 15 rows into `user_notifications`** (currently 115), all relating to School Education Pre-DPC transitions.

## Audit Trail

No application audit entries from the assessment itself.

## Downstream Impact

If the queue is cleared against this recommendation, the loss is confined to 15 in-app notification rows for the School Education demonstration pipeline. It would not affect the Higher Education scenario, any calculation, or any published document — but it would make the Notifications feed an unreliable witness for the remainder of the UAT.

## Observations

**No new observation raised.** The queue residue is a consequence of UAT-OBS-006 (no queue runner defined, so nothing consumed the queue) and is already covered by it.

## Business Questions

None.

## Suggestions

**Suggestion 25 written to `docs/SUGGESTIONS_AND_IMPROVEMENTS.md`** at the user's explicit instruction — see that register for the full entry. It records that the repository defines no queue runner, that `php artisan queue:work` is sanctioned for the local Business UAT environment only, and that production must use a managed worker such as Supervisor, documented in the deployment guide.

## Pass/Fail

**✓ PASS — assessment complete and conclusive.** The user's condition for clearing is **not met**: the jobs are demonstration residue, but 15 of them carry database writes, so clearing is not the harmless act the condition assumed.

## User Confirmation

**AWAITING RESPONSE** to Question 2Q.1.

## Next Step

On the user's acceptance (or overrule) of the recommendation, the user starts `php artisan queue:work` personally. The Test Lead starts no process. Once the user confirms the worker is running: **Step 2 execution — Department Registration**.

====================================================

## USER DECISIONS RECORDED — 2026-08-31, 11:18 IST

Recorded at the user's explicit instruction.

**Decision — queue runner for this environment.**

> *"Yes. Proceed with the standard Laravel queue worker for this local development environment. I will start the queue worker manually using `php artisan queue:work`. Do not start any background process yourself."*

The user directed that the following three facts be recorded:

1. **The current repository does not define a queue runner.** Verified at Step 2E across 15 sources — no Horizon package or config, no Supervisor configuration, no Docker or Sail, no `Procfile`, no CI, no deployment script; `composer.json` `scripts.dev` runs `php artisan serve` alone; the Developer Handbook contains no occurrence of the word "queue".
2. **The use of `php artisan queue:work` is only for the local Business UAT environment.** It is not a project-sanctioned production mechanism and must not be recorded as one.
3. **Production deployment must use a managed queue worker — for example Supervisor — and this must be documented in the deployment guide.** Recorded as Suggestion 25.

**User feedback on this document (recorded verbatim).**

> *"Overall, execution log ab kaafi mature lag raha hai. Questions, answers, warnings aur synchronization properly record ho rahe hain."*

====================================================

====================================================

## NUMBERING RECONCILIATION — 2026-08-31, 11:34 IST

The user re-issued the End-to-End framework on 2026-08-31 at 11:34 IST with a **revised 26-step sequence** in which **Step 1 is now Environment Verification** and Department Registration becomes Step 2. The earlier sequence had Step 1 as Master Data Verification.

To keep this document a single source of truth, no earlier entry is renumbered. The mapping is:

| Revised sequence | This document's existing entries |
|---|---|
| **1. Environment Verification** | **New — recorded below** |
| 2. Department Registration | Existing **Step 2** entry (prepared, blocked), plus **Step 2E** (queue runner inspection) and **Step 2Q** (queue residue assessment) |
| 3. Department Post Mapping | Not yet started. Scope widened to create the two missing designations |
| — Master Data Verification | Existing **Step 1** entry. Retained; the revised sequence folds master verification into Steps 3 and 4, and the work already done stands |

All other steps retain the numbering the user issued.

The user also confirmed the standing instruction that no feature, code change, schema change, refactor or new business rule is to be introduced without explicit approval, and added a request that the final deliverable carry a **Deployment Prerequisites** section derived only from what this project actually uses. That request is accepted and recorded; the environment facts gathered below are the evidence base for it, so that section will not require fresh analysis at deployment time.

====================================================

## Step Number

**1 (revised sequence)**

## Step Name

**Environment Verification** — Queue · Redis · Mail · OTP · Demo Accounts *(major milestone)*

## Timestamp

**2026-08-31 11:34 IST**

## Purpose

To establish, by inspection only, whether the environment can support an end-to-end business walkthrough — and to record the verified facts that will later become the Deployment Prerequisites section of the sign-off.

## Business Explanation

An end-to-end UAT that begins before the environment is understood produces false failures. A step that appears to succeed while delivering nothing — an OTP generated but never sent, a notification queued but never written — is worse than a step that fails outright, because it is recorded as a pass. This step exists so that every later "expected result" can be trusted.

## Questions Asked To User

**No new question is raised at this step.** Two questions remain outstanding from earlier entries and still gate Step 2:

- **Question 2Q.1** — whether the user accepts the recommendation **not** to clear the 34 pending queue jobs. *AWAITING RESPONSE.*
- **Confirmation that `php artisan queue:work` is running.** *AWAITING RESPONSE.* The user has stated they will start it personally and that the Test Lead must start no background process.

## Expected User Action

None at this step. Verification was performed by inspection of configuration and live state.

## Expected Result / Verified State

| Component | Configured as | Verified live | Verdict |
|---|---|---|---|
| **Redis** | `predis` client, `127.0.0.1:6379`, password set | Reachable through Laravel. Cache round-trip `put`/`get` returned **OK** | **PASS** |
| **Cache** | `CACHE_STORE=redis` | Working (round-trip above) | **PASS** |
| **Session** | `SESSION_DRIVER=redis`, **`SESSION_ENCRYPT=false`** | As configured | **PASS** for UAT · ⚠ deployment item |
| **Queue — transport** | `QUEUE_CONNECTION=redis`, queue `default`, `retry_after` 90s | 34 pending · 0 delayed · 0 reserved · **0 failed** | **PASS** |
| **Queue — runner** | **None defined by the project** | No worker process running | **⚠ WARN — UAT-OBS-006** |
| **Mail** | `MAIL_MAILER=log`; `FEATURE_EMAIL_GATEWAY` **off** | Log driver active | **PASS** by design |
| **SMS** | `LogSmsSender`, sender id `CGPSC`; `FEATURE_SMS_GATEWAY` **off** | Bound and writing to log | **PASS** by design |
| **OTP** | length **6**, TTL **600s (10 min)**, max attempts **5**, `fake_code` **unset** | `otp_verifications` stores `code_hash` only | **PASS** · ⚠ **UAT-OBS-007** |
| **Storage** | `filesystems.default = local`; private root `storage/app/private`; `s3` disk declared, `FEATURE_S3_STORAGE` **off** | `storage/app/private`, `storage/app/public`, `storage/logs`, `storage/framework/cache` all **writable** | **PASS** |
| **Scheduler** | `routes/console.php` holds only the stock `inspire` command | No scheduled tasks exist | **PASS** — nothing to run |
| **PDF engine** | **None.** No `dompdf`, `snappy`, `wkhtmltopdf` or `Browsershot` in `composer.json` | Documents render as **HTML** behind the `DocumentRenderer` and `CirculationDocumentRenderer` contracts | **PASS** by design — matches audit §13.2 item 5, pending blocker **B-2** |
| **Feature flags** | `notifications` **ON**; `hrms`, `csc_digisign`, `sms_gateway`, `email_gateway`, `s3_storage`, `lgd_sync` all **OFF** | As configured | **PASS** — the pipeline runs with nothing connected, as designed |
| **Demo accounts** | 25 users | 1 admin · 1 AO · 1 AH · 1 Chairman · 5 Members · 14 Candidates · **2 role-less** | **⚠ WARN — UAT-OBS-005** |
| **`APP_DEBUG`** | `true` in both `.env` and `.env.example` | As configured | **PASS** for local · ⚠ deployment item |

## Business Rules

No business rule is exercised by this step. One architectural rule was verified and holds: **every external system sits behind a Provider contract and is flagged off by default**, so the full pipeline runs and demonstrates with nothing connected. Confirmed for HRMS, CSC DigiSign, SMS, e-mail, S3 and LGD.

## UI Behaviour

Not applicable — no screen was exercised.

## Validation Rules

Not applicable.

## Calculations

None.

## Workflow

Not applicable.

## Database Impact

**None.** Read-only throughout, with one exception disclosed for completeness: a cache probe key `uat_probe` was written to Redis with a 5-second expiry to prove the cache round-trip, and has since expired. No database row was created, updated or deleted.

## API Impact

None. No endpoint was called.

## Audit Trail

No application audit entries were generated, because nothing was changed.

## Security

Three security-relevant facts were established. One is a new observation; two are pre-existing deployment items already named by the implementation audit.

1. **NEW — `DPC_OTP_FAKE_CODE` is implemented, undocumented and unguarded.** Recorded as **UAT-OBS-007**. See Observations.
2. **`SESSION_ENCRYPT=false`** in both `.env` and `.env.example` — already recorded by the audit (§7.9, deployment row). Not re-raised.
3. **`security:audit` checks dependencies only.** `SecurityAuditCommand` runs `composer audit` and nothing else — it performs **no configuration-security check**, so it cannot detect a fixed OTP code, an unencrypted session or `APP_DEBUG=true` in production. Its name implies broader coverage than it has. Folded into UAT-OBS-007's suggested change rather than raised separately.

## Downstream Impact

- **Steps 2 and 23** depend on OTP delivery, and therefore on the queue runner.
- **Step 5** depends on credential delivery, and therefore on the queue runner.
- **Steps 19 to 22** depend on the notification pipeline; `FEATURE_NOTIFICATIONS` is **on**, so these will queue work that requires a consumer.
- **Steps 20 to 22** produce documents as **HTML, not PDF**. Any expectation of a PDF at those steps must be set aside until blocker **B-2** is answered; this is by design, not a defect.
- **Step 25 (Reports)** and **Step 26 (Audit Trail)** are unaffected by the queue.

## Observations

**One new observation raised and appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.**

**UAT-OBS-007 — a fixed OTP code can be switched on by environment variable, with no production guard, no documentation and no audit check.** `OtpService::generateCode()` reads `config('dpc.otp.fake_code')` and, when it is set, returns that value as **every OTP issued**. There is no environment check. The variable `DPC_OTP_FAKE_CODE` appears **nowhere** in `.env.example`, the README, the Developer Handbook, the User Guide or the Architecture Handbook, and `SecurityAuditCommand` does not test for it. This is inconsistent with the project's own established convention: `demo:seed`, `demo:credentials` and `data:reset` all call `app()->environment('production')` and refuse, and both `DpcProposalService` and `PreDpcProposalService` guard mock-signing in production. The same discipline was not applied to the one-time password. Severity **High**.

Two existing observations were re-confirmed live and are **not** duplicated: **UAT-OBS-005** (two demonstration accounts do not exist) and **UAT-OBS-006** (no queue runner defined; still the Step 2 blocker).

## Business Questions

**None raised.** This step concerns environment and security configuration, not business policy.

## Suggestions

**No new suggestion written.** Suggestion 25 already covers the queue-runner and deployment-guide gap. The `security:audit` scope gap is recorded inside UAT-OBS-007's suggested change, so that it is fixed alongside the finding it would have caught rather than drifting as a separate item.

## Pass/Fail

**⚠ PASS WITH WARNINGS.**

Every component required for the walkthrough is present and functioning: Redis, cache, session, mail, SMS, OTP generation, storage and feature flags all verified. Two warnings stand, neither of them newly discovered at this step: **no queue runner is defined (UAT-OBS-006)**, which still blocks Step 2 until the user starts a worker; and **two demonstration accounts named in the credentials document do not exist (UAT-OBS-005)**, which the walkthrough will itself remedy by creating them at Step 2.

One new **High** security observation was raised (**UAT-OBS-007**). It does **not** block the UAT — `fake_code` is unset in this environment, so OTPs are genuinely random — but it is a production risk and is recorded as such.

## User Confirmation

**AWAITING RESPONSE** — Question 2Q.1, and confirmation that the queue worker is running.

## Next Step

**Step 2 — Department Registration.** Blocked pending the user's confirmation that `php artisan queue:work` is running. No process will be started by the Test Lead. Once running, Step 2 executes as already specified in this document, and Step 3 follows with its widened scope.

====================================================

====================================================

## ENVIRONMENT ACTIONS — 2026-08-31, 12:03–12:10 IST

Performed under the user's authorisation of 2026-08-31 11:52 IST to manage local development services without asking each time. Every action is recorded with service, command, reason, result and verification, as the user required. **No destructive operation was performed.**

### Action 1 — Queue worker (started)

| Field | Detail |
|---|---|
| **Service** | Laravel queue worker |
| **Pre-check (rule 3)** | `pgrep -af "artisan queue"` → **none running**. No duplicate risk |
| **Implementation chosen** | Standard Laravel worker. Horizon is not configured (established at Step 2E), so the user's rule *"if no queue manager exists, use the standard Laravel queue worker"* applies |
| **Command executed** | `nohup php artisan queue:work --tries=3 --timeout=60 --verbose >> storage/logs/uat-queue-worker.log 2>&1 &` |
| **Why `--timeout=60`** | The Redis connection's `retry_after` is 90s; a worker timeout must be lower than `retry_after` or a job can be released to another worker while still running |
| **Reason** | Step 2 requires OTP and credential delivery, both of which are `ShouldQueue` (UAT-OBS-006) |
| **Result** | Started as PID 47263 |
| **Verification** | Queue drained **34 → 0** pending, 0 reserved, **0 failed**. `user_notifications` rose **115 → 130**, i.e. **+15 rows** |

**This verification settles Question 2Q.1 empirically.** The Step 2Q recommendation predicted that clearing the queue would have destroyed exactly 15 `user_notifications` rows. Draining produced exactly +15. The recommendation not to clear is therefore **confirmed by measurement**, and no clear/flush was run. The user's condition for clearing — that the jobs be unrelated to important data — was correctly judged **not met**.

### Action 2 — Local PHP development server (started on an alternative port)

| Field | Detail |
|---|---|
| **Service** | Laravel development server |
| **Pre-check (rule 3)** | `php artisan serve` **was already running** on `127.0.0.1:8000` as PID 33851 |
| **Finding** | That process does **not** belong to this project. `/proc/33851/cwd` resolves to **`/var/www/html/CGMFPFED-Welfare-Platform`** — a different application. `GET /register` against port 8000 returned **HTTP 404**, which is what exposed it |
| **Decision** | The foreign process was **left untouched**. Stopping or restarting it would have interfered with an unrelated project, which is outside the authorisation granted and would breach rule 2 in spirit |
| **Command executed** | `nohup php artisan serve --port=8001 >> storage/logs/uat-dev-server.log 2>&1 &` (run from `/var/www/html/DPC`) |
| **Pre-check on the new port** | Port 8001 confirmed free before binding |
| **Result** | Started as PID 47605 |
| **Verification** | `GET http://127.0.0.1:8001/register` → **HTTP 200** |

**Note for the remainder of this UAT: the DPC portal is at `http://127.0.0.1:8001`, not 8000.** Apache is also listening on `:80` but was not used or altered.

### Services deliberately not started

| Service | Why not |
|---|---|
| Laravel Scheduler | `routes/console.php` defines no scheduled tasks. Running `schedule:work` would consume resources to do nothing |
| Mailpit / Mailhog | Not a dependency of this project. `MAIL_MAILER=log` and `FEATURE_EMAIL_GATEWAY` is off, so mail is written to `storage/logs/laravel.log` by design. Introducing a mail catcher would change the configuration the project ships |
| Redis | Already running and verified reachable at Step 1 |

====================================================

## Step Number

**2 (revised sequence) — EXECUTED**

## Step Name

**Department Registration** — *major business milestone*

## Timestamp

**2026-08-31 12:10 IST** — executed. Supersedes the *prepared but blocked* state recorded earlier in this document.

## Purpose

To register Higher Education Department and provision its Authorized Officer and Administrative Head, so departmental work can begin.

## Method

Executed against the live application over HTTP at `http://127.0.0.1:8001`, driving the real routes, middleware, CSRF, Form Request validation and services — not by calling services directly, because bypassing the HTTP stack would not have tested what a user meets.

## Expected User Action — as performed

`GET /register` → completed the form → `POST /register` → `GET /verify` → retrieved both OTPs from `storage/logs/laravel.log` → `POST /verify`.

## UI Behaviour — verified

- The **department dropdown offered exactly 8 departments** — Agriculture, Finance, General Administration, Health, **Higher Education**, Labour, Medical Education, Public Works. **School Education was correctly absent**, being already registered. This confirms `RegistrationController::create()` filters on `status = unregistered`.
- The **district dropdown rendered 28 options**. **UAT-OBS-001 is hereby confirmed in the live user interface**, not merely in the database: the five districts constituted in September 2022 are unavailable to a registering department.
- Districts render bilingually — e.g. `Raipur / रायपुर`.

## Validation Rules — tested, with results

Six cases were driven through the real form. **No data was created by any negative case**, verified afterwards: department #3 still `unregistered`, user count still 25.

| Case | Input | Result | Message shown to user |
|---|---|---|---|
| **N1** | `department_email = he@gmail.com` | Correctly rejected | ✅ *"The department email must be an official .gov.in address."* |
| **N4** | `department_id = 2` (School Education, already registered) | Correctly rejected | ✅ *"selected department id is invalid."* |
| **N2** | `ao_email = ah_email = same@cg.gov.in` | Correctly rejected | ⚠ field marked `is-invalid` but **no message text captured** — see note |
| **N3** | `pin = 4920` (4 digits) | Correctly rejected | ❌ **nothing at all** — `is-invalid=0`, no message |
| **N5** | `ao_mobile = 12345` (5 digits) | Correctly rejected | ❌ **nothing at all** |
| **N6** | `ao_mobile = 9000001101` (already held by School Education's AO) | Correctly rejected | ❌ **nothing at all** |

**Every rule fired correctly. The defect is in the reporting, not the enforcement** — recorded as UAT-OBS-008.

**Note on N2.** `is-invalid` was present but no `invalid-feedback` text was parsed from the response. `ao_email` **does** carry an `@error` block in the view, so the likely explanation is the parsing of multi-line markup rather than a missing message — the same parsing weakness initially caused N4 to appear silent when it was not. **This one case is recorded as unconfirmed and needs a browser check**; it is not asserted as a defect.

## Expected Result vs Actual — with one correction

| Expectation as previously recorded | Actual | Verdict |
|---|---|---|
| Dropdown lists 8 departments | 8, School Education absent | ✅ |
| Redirect to `/verify` with confirmation message | `HTTP 302 → /verify` | ✅ |
| Department becomes `registered` with `registered_at` stamped | `status=registered`, `registered_at=2026-08-31 06:49:50`, `district=24`, `place=Raipur`, `pin=492001`, `email=he@cg.gov.in` | ✅ |
| Two users created, `must_change_password = true` | User **#26** *Shri Ramesh Kumar Sinha* (9000100001) and **#27** *Smt. Vandana Shukla* (9000100002), both `must_change_password=1`, `is_active=1` | ✅ |
| Two active assignments on department #3 | #26 `authorized_officer`, #27 `administrative_head`, both `active_flag=1`, `effective_from=2026-08-31`, remarks *"Provisioned by department registration (S6)."* | ✅ |
| **"Four OTP rows are written"** | **Two** — `ao.he@cg.gov.in` (email) and `9000100001` (sms) | ❌ **My earlier expectation was wrong** |
| After correct OTPs, AO's channels verified | `email_verified_at` and `mobile_verified_at` both `2026-08-31 06:50:21`; both OTP rows `consumed_at` set, `attempts=1`; redirect to `/login` | ✅ |

**Correction recorded.** The Step 2 entry written earlier in this document predicted *"Four OTP rows are written (e-mail + SMS for the AO; the service issues on every channel the user holds)."* The actual count is **two**. `RegistrationController::store()` calls `VerificationService::start()` for the **Authorized Officer only** — the Administrative Head is provisioned and notified of credentials but is not asked to verify at registration. The parenthetical reasoning was right; the arithmetic was wrong. The application's behaviour is correct and is arguably the better design: the registrant verifies, not the colleague they nominated.

## Business Rules — verified in execution

1. **Only an unregistered department may be registered** — proved by N4.
2. **All official e-mails must be `.gov.in`** — proved by N1.
3. **AO and AH must be different people** — enforced (N2 rejected).
4. **An e-mail or mobile identifies one account portal-wide** — proved by N6, which was rejected because School Education's AO already holds 9000001101.
5. **Both officers must change the issued password at first login** — `must_change_password=1` on both, and it **remained 1 after OTP verification**, confirming that verifying a channel is not the same as changing a password.
6. **The role is granted by the posting** — both assignments carry the role; the remark names registration as the origin.
7. **One transaction** — no partial state observed.
8. **Verification is all-or-nothing** — both codes supplied, both consumed in the same second.

## Calculations

None, as predicted. Two random 12-character passwords and two 6-digit OTPs were generated.

## Workflow

`unregistered → registered`, then AO channel verification, then redirect to `/login` where the first-login password change is still pending.

## Database Impact — actual

`departments` #3 updated · `users` **+2** (25 → 27) · `user_department_assignments` **+2** · `model_has_roles` **+2** · `otp_verifications` **+2**, both later consumed. Additionally, from draining the queue: `user_notifications` **+15** (115 → 130).

## API Impact

No JSON API involved. All interaction was server-rendered HTML over the session-authenticated web routes, with CSRF enforced — a POST without a valid `_token` is rejected by middleware.

## Audit Trail

OTP rows carry identifier, channel, purpose, hash, expiry, `attempts` and `consumed_at` — a complete verification record. Assignments carry the provisioning remark. Temporary passwords were **not persisted** to the database; they appear only in the log, via the mock SMS and mail drivers.

## Security — one corroboration of UAT-OBS-007

While retrieving the OTPs, the log was found to contain **earlier OTP entries with the literal value `123456`**, before the two genuine random codes (`818098`, `869153`). That is the signature of `DPC_OTP_FAKE_CODE` having been in force at some point in this environment's history. It **corroborates UAT-OBS-007**: the switch is not theoretical, it has been used. `fake_code` is unset now, and the two OTPs issued during this step were genuinely random, so this step's result is sound.

## Downstream Impact

Higher Education is now a working department. Users #26 (AO) and #27 (AH) are the actors for the remaining steps. `code = HE` means its DPC identifiers will read `DPC-2026-HE-000n`. **UAT-OBS-005 is now partially remedied as a side effect**: the two accounts the demonstration credentials document promised now exist, created through the proper registration path rather than seeded.

## Observations

**One new observation — UAT-OBS-008**, appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`: six of the thirteen fields on the registration form have no error display, and the page has no error summary, so a failure on `pin`, `place`, `ao_name`, `ah_name`, `ao_mobile` or `ah_mobile` returns the form with **no message whatsoever**. Severity **High**.

**UAT-OBS-001 confirmed in the UI** (28 districts in the live dropdown). **UAT-OBS-007 corroborated** (see Security). Neither is duplicated.

## Business Questions

None raised. Every rule met at this step is unambiguous and already ruled upon.

## Pass/Fail

**⚠ PASS WITH OBSERVATIONS.**

The business outcome is correct and complete: Higher Education is registered, both officers exist with the right roles, both channels are verified, and every validation rule enforced correctly. One **High** defect was found in how validation failures are *reported*, and one earlier expectation of mine was wrong and is corrected above.

## User Confirmation

**AWAITING APPROVAL** — Department Registration is a milestone at which the user requires a pause.

## Next Step

**Step 3 — Department Post Mapping**, with scope widened to first create the *Assistant Professor* and *Associate Professor* designations, per the user's decision of 10:58 IST.

====================================================

====================================================

## Step Number

**3 (revised sequence) — EXECUTED**

## Step Name

**Department Post Mapping** *(major milestone)* — including creation of the two Higher Education designations

## Timestamp

**2026-08-31 12:20–12:40 IST**

## Purpose

To register, for Higher Education, the two posts the promotion runs between — *Assistant Professor* (feeder) and *Associate Professor* (promotion post) — each with its pay scale, service class, qualifying service, effective date and the Government Orders that authorise it.

## Business Explanation

The Department Post Mapping is where the portal records what a Government Order says about a post: which department holds it, which pay scale and service class it carries, and how long an officer must serve on the feeder before being promoted out of it. It is **effective-dated and order-authorised**, because a post's terms change by Government Order and a DPC held in 2026 must be judged on the terms in force in 2026, not on today's. That is why superseding a mapping is a distinct operation and not an edit.

## Method

Executed over HTTP at `http://127.0.0.1:8001` as `admin@cgpsc.gov.in`, driving real routes, CSRF, Form Requests and multipart uploads.

## Expected User Action — as performed

1. `/admin/designations/create` — created *Assistant Professor* and *Associate Professor*.
2. `/admin/department-posts/create` — created both mappings, with Government Orders and PDFs attached.

## UI Behaviour — verified

- The Government Orders repeater renders one empty row plus a `__I__` template row consumed by the **Add More** button (`public/js/forms/department-post-orders.js`).
- Existing orders are listed **read-only above** the repeater and cannot be edited — they are evidence.
- `effective_from` defaults to today on a new mapping.
- The service-class dropdown renders the **Class Master's** label for each code (see Business Rules).
- The index screen orders mappings in force first, then superseded ones — *"what applies now, and what applied before"*.
- File inputs are deliberately not repopulated after a failed submission, and the form says so rather than appearing to have kept the file.

## Validation Rules — tested, with results

Seven cases driven through the real form. **Nothing was created by any negative case** — Higher Education post count remained 0 until the happy path.

| Case | Input | Result | Message |
|---|---|---|---|
| **V1** | GO date `2027-01-01` (future) | Rejected | ⚠ *"The orders.0.government_order_date field must be a date before or equal to today."* — **raw field path exposed**, see UAT-OBS-010 |
| **V2** | GO number, no date | Rejected | ✅ *"Give the Government Order date as well as its number."* |
| **V3** | Non-PDF file renamed `.pdf`, declared `application/pdf` | Rejected | ✅ *"Each Government Order must be a PDF."* — the three-way check (`mimes` + `mimetypes` + `extensions`) defeated the probe |
| **V4** | `promotion_duration_years = 50` | Rejected | ✅ *"Promotion Duration is in years, and 40 is already longer than a career. Check the figure."* |
| **V5** | GO date, no number | Rejected | ✅ *"Give the Government Order number as well as its date."* |
| **V6** | Duplicate post name in the same department, in force | Rejected | ✅ *"This department already has a post with that name. The same post name in a **different** department is fine — they are different posts."* |
| **V7** | Empty order row | **Accepted and skipped** | ✅ Correct — an empty row means "no further order", not a mistake |

**Every rule fired correctly, and six of seven display a well-written message.**

**Correction to my own testing, recorded because it produced three wrong readings.** My first pass reported V1, V2 and V3 as showing *"no visible message"*. That was a defect in **my extraction**, not in the application: the repeater renders `class="invalid-feedback d-block"`, and my pattern matched only `invalid-feedback">`. Re-tested with a corrected pattern, all three display their messages properly. This is the third time this parsing weakness has produced a false negative in this UAT (previously on registration cases N4 and N2). **It does not affect UAT-OBS-008**, which was established structurally — `@error` is genuinely absent from the registration view for those six fields — and independently corroborated by `is-invalid=0`.

## Business Rules — verified

1. **A post name is unique among mappings *in force*, not among all of them.** Proved by V6. Superseded mappings deliberately keep the same name — that is what makes them a chain — so a plain uniqueness rule would refuse to re-register a post whose earlier mapping was replaced years ago.
2. **The same post name in a different department is legitimate.** Stated in the message and enforced by scoping the rule to `department_id`.
3. **A Government Order's number and date travel together.** `required_with` in both directions, per row. Proved by V2 and V5.
4. **A Government Order cannot be dated in the future.** `before_or_equal:today`. Proved by V1.
5. **Each Government Order carries at most one PDF, and a mapping may hold several orders.** Verified: *Assistant Professor* holds **two** orders, the first with a PDF, the second without.
6. **PDF is checked three ways** — extension, declared MIME, and mismatch between them. Proved by V3.
7. **Service class codes are an enum; their labels come from the Class Master.** Verified in code: `ServiceClass::label()` delegates to `ClassMaster::labelFor()` through a request-scoped memo (`ClassLabelRepository`), flushed on save and delete, falling back to `Class-<code>` when unseeded. **This is correct and matches BD-26** — codes are statutory and are what the M5 engine keys zone rules on, labels are presentation and must be renderable in Hindi. *A candidate finding here was investigated and withdrawn: the enum is not bypassing the master.*
8. **Superseding is not an edit** — verified by reading `DepartmentPostMappingService::supersede()`, **not executed**, to avoid closing the mapping the rest of this walkthrough depends on. Its rules are: only the mapping **in force** may be superseded; the successor's `effective_from` must be strictly **after** the incumbent's, so no two mappings are in force on the same day; the incumbent receives `effective_to = successor's start − 1 day`, `active_flag = null`, `status = Inactive`; and the successor takes its **name from the incumbent, not from the request**, which is what keeps the two rows a chain rather than two unrelated mappings. All inside a transaction with `lockForUpdate()`.

## Calculations

None at this step. `promotion_duration_years` is stored, not computed; it is consumed later by the eligibility engine.

## Database Impact — actual

| Table | Effect |
|---|---|
| `designations` | **+2** — #24 *Assistant Professor*, #25 *Associate Professor*, both `active` |
| `department_posts` | **+2** — #10 *Assistant Professor* (Class-II, pay scale #2 Level-11, duration 5, `effective_from=2026-04-01`, `active_flag=1`), #11 *Associate Professor* (Class-I, pay scale #4 Level-13, duration 3, same dates) |
| `department_post_orders` | **+3** — two on post #10, one on post #11; `sort_order` set; `uploaded_by=1` |
| Private disk | **2 PDFs** under `private/department-posts/{id}/`, confirmed present |

**Evidence integrity verified:** each stored order carries `original_name=go.pdf`, `size_bytes=69` and a populated `sha256` (`cfa3181c1ee36e8b…`). The hash is what makes the order tamper-evident.

## API Impact

No JSON API. Server-rendered HTML over session-authenticated admin routes behind `role:admin`, CSRF enforced on every POST.

## Security

- Government Order PDFs are stored on the **private** disk, outside the public root, and are reachable only through `admin.department-posts.documents.download`, which is inside the `role:admin` group. Verified: `GET /admin/department-posts/10/orders/{uuid}` returned **HTTP 200, `application/pdf`, 69 bytes**, body beginning `%PDF-1.4`.
- The download route asserts the order belongs to the named post (`abort_unless($order->department_post_id === $post->id, 404)`), so an order's UUID cannot be read through another post's URL.
- Orders are addressed by **UUID**, not by sequential id.

## Audit Trail — verified

- **`audits` table: 5 new rows**, `user_id = 1`. Both `DepartmentPost` **and** `DepartmentPostOrder` are `Auditable`, so the order — the evidence — is audited in its own right, not merely as a child of the mapping.
- **Activity Timeline**: `master.created` per mapping, plus `department_post.order_recorded` per order, each description naming the Government Order number and date, e.g. *"Government Order F-2-15/2026/38-2 (15-03-2026)"*.

## Downstream Impact

- Post #10 *Assistant Professor* becomes the **promotion-from** post and #11 *Associate Professor* the **promotion-to** post for the Recruitment Rule at Step 4 and the Pre-DPC at Step 9.
- `promotion_duration_years = 5` on the feeder becomes the qualifying-service test in the eligibility engine.
- Class pair **II → I** is what the M5 engine will key its zone-of-consideration rule on.
- **`sanctioned_strength` is NULL on both mappings and cannot be set** — this will change what Step 8 computes. See UAT-OBS-009.

## Observations

**Two new observations, both appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.**

**UAT-OBS-009 (High) — Sanctioned Strength drives vacancy calculation but has no data-entry screen.** `department_posts.sanctioned_strength` is fillable on the model and is read by `VacancyEngine`, `VacancyCalculationService` and `SaveWorkingOfficersRequest`, but it appears in **neither** `DepartmentPostRequest::rules()` **nor** the form. Because the controller persists `$request->safe()`, an unvalidated field can never be written — so the value is unreachable through the UI. Its only writers are the demonstration seeder and a one-off backfill inside migration `410001`. Both Higher Education mappings therefore carry `NULL`. **This also contradicts the implementation audit**, whose R18 records the requirement *"Department → Post → Pay Scale → Class → Promotion Duration → Sanctioned Strength → Active"* as ✅ complete, citing `DepartmentPostRequest` — which does not contain the field.

**UAT-OBS-010 (Low) — one validation message exposes an internal field path.** V1 produced *"The orders.0.government_order_date field must be a date before or equal to today."* Every other rule on this form has a hand-written message; `before_or_equal` was missed.

## Business Questions

**One new question — UAT-Q-004**, appended to `docs/PENDING_BUSINESS_QUESTIONS.md`: must every Department Post Mapping cite at least one Government Order? The code deliberately declines to decide, and says so: *"the rule is left to the Commission rather than enforced here, because 'every mapping must cite an order' is a business rule and not this form's to invent."* A mapping can currently be saved with no order at all.

## Suggestions

**None new.** UAT-OBS-009's remedy is a field on a form, which belongs in the observation rather than as a separate proposal.

## Pass/Fail

**⚠ PASS WITH OBSERVATIONS.**

The business outcome is correct and complete. Both designations and both mappings exist with the right pay scale, class, qualifying service and effective date; multiple Government Orders per mapping work; PDF upload, private storage, hash capture, UUID addressing and authorised download all work; every validation rule fires; the audit trail records both the mapping and each order. One **High** observation (Sanctioned Strength unreachable, which will affect Step 8) and one **Low** (a raw field path in one message).

## User Confirmation

**AWAITING APPROVAL** — Department Post Mapping is a milestone at which the user requires a pause.

## Next Step

**Step 4 — Recruitment Rule**, linking *Associate Professor* to its feeder *Assistant Professor*, with the minimum qualification drawn from the Qualification Master and the rule versioned against its Government Order.

====================================================

====================================================

## Step Number

**4 (revised sequence) — EXECUTED**

## Step Name

**Recruitment Rule** *(major milestone)*

## Timestamp

**2026-08-31 12:45–13:05 IST**

## Purpose

To register the Recruitment Rule that governs promotion into *Associate Professor* — the direct/promotion split, the feeder post, the qualifying service, the accepted qualifications, the authorising Government Order and the effective date — and to verify it is versioned rather than overwritten.

## Business Explanation

The Recruitment Rule is the Schedule-IV entry for a post: it says what share of the post is filled directly and what share by promotion, which post officers are promoted from, how long they must have served, and what degree they must hold. It is the rule the eligibility engine applies to every officer, so it is **versioned and never overwritten once past Draft** — a DPC held under the 2026 rule must remain judgeable under the 2026 rule even after a 2029 amendment.

## Method

Executed over HTTP at `http://127.0.0.1:8001` as `admin@cgpsc.gov.in`, driving real routes, CSRF, Form Requests and multipart uploads.

## Expected User Action — as performed

`/admin/recruitment-rules/create` → completed the rule and its feeder grid → attached the Government Order PDF → saved. Then `POST /admin/recruitment-rules/9/version` to clone a new version, and `GET /admin/recruitment-rules/9/download` to retrieve the order.

## Validation Rules — tested, with results

Nine cases driven through the real form. **Nothing was created by any negative case.**

| Case | Input | Result | Message |
|---|---|---|---|
| **W1** | DR 60 + PR 60 | Rejected | ✅ *"Direct Recruitment % and Promotion % must total exactly 100%. They currently total 120% — over-allocated by 20%."* |
| **W3** | Promotion shares totalling 50 | Rejected | ✅ *"Promotion Share must total exactly 100% across all promotion from posts. It currently totals 50% — 50% is unallocated."* |
| **W4** | Feeder = target post (self-feed) | Rejected | ✅ *"The target post cannot also be its own promotion from post."* |
| **W5** | Same feeder listed twice | Rejected | ✅ *"This promotion from post is already listed. Remove the duplicate row."* |
| **W6** | Feeder from School Education, target in Higher Education | Rejected | ✅ *"A promotion from post must belong to the same department as the target post."* |
| **W7** | PR 100 with no feeder | Rejected | ✅ *"Name at least one promotion from post… or set Promotion Recruitment % to 0 if the post is filled entirely by direct recruitment."* |
| **W8** | Non-PDF declared as `application/pdf` | Rejected | ⚠ *"The go document field must be a file of type: application/pdf."* — generic wording; see Security |
| **W9** | `effective_to` before `effective_from` | Rejected | ✅ *"The effective to field must be a date after or equal to effective from."* |
| **W-empty** | Blank feeder row | **Accepted and discarded** | ✅ Correct — `prepareForValidation()` treats an abandoned row as absent, preserving keys so errors stay on the right row |

**Every rule fired correctly. This form has an error-summary block** (errors render in `<li>` elements), which is what UAT-OBS-008 found missing on the registration form.

## Business Rules — verified

1. **Direct % + Promotion % must total exactly 100%** — every sanctioned post is filled one way or the other. Reported against its own key (`recruitment_split`) rather than against either number, because neither is wrong alone. Tolerance 0.01. **Write-path only**: existing rows are never rewritten, because silently rescaling a Government Order's percentages would be inventing policy.
2. **Promotion Share must total exactly 100% of the promotion quota** — not of the post. Tolerance 0.01, so 33.33 + 33.33 + 33.34 passes.
3. **A feeder is required only if there is a promotion quota.** A post filled entirely by direct recruitment legitimately has no feeder — `Rule::requiredIf($this->hasPromotionQuota())`.
4. **A post cannot feed itself**, and **no feeder may appear twice**.
5. **A feeder must belong to the same department as the target post.** Not a stricter-than-necessary rule — a cross-department rule is corrupt, since posts are department-scoped.
6. **Qualifications are plural and optional** (change register 2026-08-26, item 11): a Schedule-IV entry accepts *any* of a list of degrees, and a feeder may impose none beyond holding the feeder post. When given, each must be an **Active, non-trashed** master row — and `whereNull('deleted_at')` is deliberately explicit, because `Rule::exists` runs on the query builder where the SoftDeletes scope never applies.
7. **Minimum experience carries a value and a unit** (years or months), capped at 720 — the same ceiling either way. Absent units default to years, because every value ever stored was years, so that is the only defaulting that cannot change a rule's meaning.
8. **A rule is editable only while Draft.** ✅ **Verified live**: `GET /admin/recruitment-rules/9/edit` on the Active rule redirected away (302) rather than opening. `isEditable()` returns `status === Draft`, and both `edit()` and `update()` check it. The service method is named `updateDraft` accordingly.
9. **A new version is a clone, never an overwrite.** ✅ **Verified live**: v2 was created as **Draft**, carrying the feeder (post 10, share 100%, 5 years) **and both qualifications** (`quals_carried=2`). The GO document is deliberately **not** cloned — a new version must cite its own order. Timeline: *"New version v2 cloned from v1"*.

**This confirms audit R21 as accurate.** The qualification list travelling with the version is the specific behaviour the code warns about: a pivot cannot be carried by `replicate()`, and a version that silently lost its degree requirement would be a rule that had quietly stopped requiring one.

## Calculations

No scoring. Two arithmetic checks only: the recruitment split summing to 100, and the promotion shares summing to 100, both with 0.01 tolerance.

## Database Impact — actual

| Table | Effect |
|---|---|
| `recruitment_rules` | **+2** — #9 v1 (`active`, GO `F-2-17/2026/38-2`, DR 25%, PR 75%, from 2026-04-01, document stored) and #10 v2 (`draft`, cloned, no document) |
| `recruitment_rule_sources` | **+2** — one per version: feeder post #10, share 100%, min experience **5 years** |
| `rr_source_qualifications` | **+4** — Post-Graduate (`sort_order` 0) and Post-Graduate with B.Ed. (1), on each version |
| Documents disk | **1 PDF** at `recruitment-rules/11/1/{uuid}.pdf`, confirmed present |

## API Impact

No JSON API. Server-rendered HTML behind `role:admin`, CSRF enforced. The feeder picker is filtered **client-side** to the target post's department, and the same constraint is enforced **server-side** (W6) — the client filter is convenience, not the control.

## Security

- The Government Order PDF is stored on the configured documents disk with a **UUID filename**, not the uploaded name.
- **PDF validation here is weaker than on the Department Post Mapping.** `go_document` carries only `mimetypes:application/pdf`, whereas `DepartmentPostRequest` uses the three-way `mimes` + `mimetypes` + `extensions` check. `mimetypes` is content-based so the probe in W8 was still defeated, but there is no extension check, and the message is Laravel's default rather than the hand-written one used elsewhere. **This is the implementation audit's existing finding V-4/V-5 — *"three different PDF-validation standards"* — now confirmed live. Not raised as a new observation.**
- `download()` forces the served filename rather than echoing the uploaded one, which is the safe pattern — but the value it builds is itself invalid. See UAT-OBS-011.

## Audit Trail — verified

- `audits`: **1 row** for `RecruitmentRule`.
- Activity Timeline: `recruitment_rule.created` (*"Recruitment rule v1 created"*) and `recruitment_rule.versioned` (*"New version v2 cloned from v1"*).
- **`RecruitmentRuleSource` is not `Auditable`** — see UAT-OBS-013.

## Downstream Impact

- Rule #9 v1 is the rule the Pre-DPC at Step 9 and the DPC at Step 13 will cite, and the eligibility engine will apply.
- **Qualifying service of 5 years on the feeder** becomes the minimum-service test at Step 18.
- **Post-Graduate / Post-Graduate with B.Ed.** become the qualification test — which is governed by **BD-39**, still open, so that gate is advisory and refuses nothing.
- **PR 75% / DR 25%** feeds the vacancy computation at Step 8, alongside the `NULL` sanctioned strength already recorded as UAT-OBS-009.
- The **Draft v2** now sitting against post #11 must not be activated during this walkthrough, or the DPC would cite a different rule than the one tested.

## Observations

**Three new observations, appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.**

**UAT-OBS-011 (High) — the Government Order PDF on a Recruitment Rule cannot be downloaded when the order number contains a slash.** `GET /admin/recruitment-rules/9/download` returned **HTTP 500**. Cause: `RecruitmentRuleController::download()` builds the served filename as `'GO-'.$government_order_number.'.pdf'`, and Symfony refuses a `Content-Disposition` filename containing `/` or `\` (`InvalidArgumentException` at `HeaderUtils.php:187`, logged with `userId: 1`). **All 10 recruitment rules in the database carry a slash in the GO number** — it is the standard Indian format. The defect has been invisible only because the eight seeded rules carry no document, so the route was never exercised.

**UAT-OBS-012 (Low) — the English help text on the recruitment split renders a raw placeholder.** The form displays *"The two shares must total :total."* `lang/en/admin.php:395` holds `'hint_split_total' => 'The two shares must total :total.'` and the view calls it with no parameters. The **Hindi** string at the same key is a complete, correct sentence — so it is the English that regressed, which is the reverse of the usual failure.

**UAT-OBS-013 (Low) — the feeder row carries the qualifying service but has no field-level audit.** `RecruitmentRuleSource` does not implement `Auditable`, unlike `RecruitmentRule` and unlike `DepartmentPostOrder`. Mitigated substantially by versioning: an Active rule's feeder cannot be altered at all. The residual gap is Draft-stage editing, where `updateDraft()` deletes and re-creates the feeder rows and the timeline records only *"v1 updated"*.

## Business Questions

**None new.** The qualification comparison is already **BD-39** (open, highest priority) and is not duplicated here.

## Suggestions

**None new.** All three observations name their own remedy, and the fix pattern for UAT-OBS-011 already exists in the codebase — `DepartmentPostController::downloadDocument()` serves `$order->original_name`.

## Pass/Fail

**⚠ PASS WITH OBSERVATIONS** — with one function outright broken.

The rule itself is correct and complete: split, feeder, share, qualifying service, plural qualifications, effective date, Government Order, versioning, and the Draft-only edit guard all verified. Nine of nine validation cases behaved correctly. But the Government Order document — the rule's legal authority — **cannot be retrieved at all** for any realistically-numbered order (UAT-OBS-011). The rule saves and functions; its evidence does not open.

## User Confirmation

**AWAITING APPROVAL** — Recruitment Rule is a milestone at which the user requires a pause.

## Next Step

**Step 5 — User & Department Assignment.**

====================================================

====================================================

## Step Number

**4A — ADDENDUM to Step 4**

## Step Name

**Recruitment Rule — effective-date handling across rules** *(gap closed after the Step 4 report)*

## Timestamp

**2026-08-31 13:20 IST**

## Why this addendum exists

The user re-sent the Step 4 instruction verbatim at 13:15 IST, after Step 4 had already been executed and reported. **Step 4 was not re-run** — re-executing it would have created duplicate rules and told us nothing new. Instead, the instruction was re-read against what had actually been covered, and one item on the user's list was found to be only **partly** tested: *"Effective date handling"*.

Step 4 verified effective dates **within** a single rule (`effective_to` must be on or after `effective_from` — case W9). It did **not** examine how effective dates behave **across** rules: whether two rules may be in force at once, and whether the dates are honoured when a rule is selected for a DPC. That gap is closed here, and it produced the most consequential finding of the step.

## Method

Verified by reading the code and querying the live schema. **No data was created**, deliberately: demonstrating an overlap would have left a second Active rule against the Higher Education post and risked the DPC at Step 13 citing the wrong one. The finding is provable from the code without that risk, and a live demonstration can be run on a throwaway post if the user wants one.

## Findings

### 1. Nothing prevents two Active rules from being in force at the same time

| Check | Result |
|---|---|
| Date rules in `RecruitmentRuleRequest` | Only `'effective_from' => ['required','date']` and `'effective_to' => ['nullable','date','after_or_equal:effective_from']` — **both within a single rule** |
| Cross-rule overlap check | **None**, in the request, the service or the model |
| Unique constraints on `recruitment_rules` | `PRIMARY (id)` and `recruitment_rules_post_id_version_unique (post_id, version)` — **nothing on dates or on "in force"** |

**Contrast with the Department Post Mapping, which does enforce this.** `DepartmentPostMappingService::supersede()` refuses a successor whose `effective_from` is not strictly after the incumbent's — *"Two mappings cannot both be in force on the same day"* — sets `effective_to` to the day before the successor starts *"so no day is covered twice"*, and the table carries `unique(department_id, name, active_flag)` with an `inForce()` scope. The same discipline was not applied to Recruitment Rules, which are the more consequential of the two.

### 2. The two rule resolvers disagree, and the one that selects the rule for a DPC ignores dates entirely

There are two implementations of "which recruitment rule applies to this post?", and they answer differently.

| | `VacancyEngine::recruitmentRule()` (line 232) | `DpcProposalService::currentRuleFor()` (line 253) |
|---|---|---|
| Status filter | `Active` | `Active` |
| **Effective-date filter** | **Yes** — `effective_from <= $on` **and** (`effective_to` is null **or** `>= $on`), where `$on` is the proposal date | **None at all** |
| Ordering | `effective_from DESC, version DESC` | `version DESC` |
| Role | **Consumer** — reads the rule at calculation time | **Selector** — decides which rule a new DPC cites |

`currentRuleFor()` has two callers, and both matter:

- **Line 220, `ruleInForce()`** — supplies `recruitment_rule_id` when a **DPC proposal is created from a Pre-DPC**. This is Step 13 of this walkthrough.
- **Line 245, `recruitmentRuleSuperseded()`** — the warning shown to the Authorized Officer and Administrative Head that the rule has moved on since the proposal was raised.

**The naming asserts a semantic the implementation does not have.** The method is called `ruleInForce()` and its docblock reads *"The recruitment-rule version **in force** for the Promotion To Post."* It applies no date filter. "In force" is a statement about dates, and dates are precisely what it does not consult.

### 3. What follows from that

Because the selector is date-blind and `VacancyEngine` short-circuits on a proposal that already carries `recruitment_rule_id` (line 234), a wrongly-selected rule is not corrected downstream — it propagates.

- A **future-dated** Active rule (`effective_from = 2027-04-01`) is selected for a DPC raised **today**, because it has the highest version and dates are ignored. The DPC would then be judged under a rule not yet in force.
- An **expired** Active rule (`effective_to` in the past) remains "current" so long as it is the highest version, so a DPC could cite a rule that has lapsed.
- Where two Active rules **overlap**, the two resolvers may name **different** rules for the same proposal — the selector by version, the engine by effective date.

Versioning positively invites this: creating next year's rule ahead of time, as Draft and then Active with a future `effective_from`, is exactly what a versioned rule master is for.

## Business Rules — assessed

The rule *"a DPC is judged under the rule in force for its year"* is **stated in the code's own documentation and not implemented**. `effective_from` and `effective_to` are captured, validated within a row, displayed, and then not consulted at the moment that decides which rule a promotion runs under.

## Database Impact

**None.** Read-only verification.

## API Impact

None.

## Audit Trail

No entries generated. Which rule a proposal cited *is* durably recorded on `dpc_proposals.recruitment_rule_id`, so a wrong selection is at least reconstructible after the fact — that is what makes this a correctness defect rather than an evidence one.

## Downstream Impact

**Step 13 (DPC Creation) is where this would bite in this walkthrough.** It is safe as things stand: the only other rule against post #11 is **v2 in Draft**, and both resolvers filter on `Active`, so v1 will be selected correctly. The Step 4 report already flagged that v2 must not be activated during this UAT; **this addendum supplies the reason** — activating it would cause the DPC to cite v2 regardless of its effective date.

Beyond this walkthrough the exposure reaches quota split, qualifying service and accepted qualifications — that is, how many vacancies there are and who is eligible for them.

## Observations

**One new observation — UAT-OBS-014 (High)**, appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.

## Business Questions

**None new.** Whether a superseded rule should *block* submission is already named in the code as an open business question, and the code declines to decide it — the correct posture. It is closely related to UAT-Q-004's evidence-standard question and is best answered alongside it, but it is not a separate UAT question because the code already surfaces the warning rather than guessing.

## Suggestions

**None new.** UAT-OBS-014 names its own remedy, and the correct pattern already exists in this codebase twice over — `VacancyEngine::recruitmentRule()` for the date filter, and `DepartmentPost::inForce()` for the non-overlap discipline.

## Pass/Fail

**❌ FAIL on this item.** Effective-date handling **within** a rule passes (Step 4, case W9). Effective-date handling **across** rules fails: the dates are recorded but not honoured where it counts.

This does not change Step 4's overall verdict, which remains **⚠ PASS WITH OBSERVATIONS** — the rule was created correctly and every validation fired. It adds a fourth observation to that step, and the most serious one.

## User Confirmation

**AWAITING APPROVAL.** Also awaiting the user's confirmation of whether Step 4 was genuinely to be re-run, or whether the instruction was resent — the Test Lead's reading is that it was resent, and Step 4 was therefore not repeated.

## Next Step

**Step 5 — User & Department Assignment**, on the user's approval.

====================================================

====================================================

## Step Number

**5 (revised sequence) — EXECUTED**

## Step Name

**Users & Department Assignment** *(major milestone)*

## Timestamp

**2026-08-31 13:35–14:00 IST**

## Purpose

To verify how officers are provisioned, posted to a department, transferred and released; that a departmental role is granted only by a posting; and that authorisation holds at screen, route and menu level.

## Business Explanation

An officer's authority in this portal comes from **where they are posted**, not from a flag on their account. That is why a role is granted by an assignment and released with it: an Authorized Officer who leaves Higher Education must stop being able to file Higher Education's proposals the moment the posting closes. The assignment table is also the history — which department signed what, and when — so a past posting is never edited or deleted, only closed.

## Method

Executed over HTTP at `http://127.0.0.1:8001`, as `admin@cgpsc.gov.in` for provisioning and postings, and as `ao.schooleducation@cg.gov.in` for the authorisation tests.

## 1. Department Assignment — verified

Tested on user **#10** (*Shri Sanjay Kumar Agrawal*), an account that held no role before this step and holds none after it.

| Action | Result |
|---|---|
| **Assign** — Authorized Officer, School Education, from 2026-05-01 | Row created, `active_flag = 1`, remark *"UAT initial posting"* |
| **Transfer** — Administrative Head, School Education, from 2026-07-01 | Incumbent closed at **`effective_to = 2026-06-30`** — exactly the day before the successor begins — `active_flag → NULL`; new row `active_flag = 1` |
| **Release** | Second row closed at `2026-08-30`, `active_flag → NULL`. **Both rows retained.** Roles now `[]`. Account remains `is_active = 1` |

**Effective dates, active department and history all behave exactly as documented.** No day is covered twice; no row was edited or deleted; the account keeps its login while holding no department role — which is deliberately different from deactivating it.

## 2. Role Assignment — verified

| Rule | Result |
|---|---|
| Role granted **by** the assignment | ✅ After assign, `roles = [authorized_officer]`. After transfer, `roles = [administrative_head]` — the previous departmental role was **removed**, not merely supplemented |
| Roles offered on the postings screen | ✅ Only `authorized_officer`, `administrative_head`, `promotion_candidate` — the three departmental roles |
| Commission roles refused there | ✅ Test **Y2** (`role=chairman`) → *"The selected role is invalid."* |
| Release removes the role | ✅ `roles = []` |
| Commission role preserved across a posting change | ✅ Verified by reading: `projectRole()` clears only departmental grants, so an Administrator who also holds a Commission role is not silently demoted |

## 3. Authorization — verified

Signed in as the School Education Authorized Officer:

| Target | Result |
|---|---|
| `/admin`, `/admin/users`, `/admin/districts`, `/admin/recruitment-rules`, `/admin/audit-trail`, `/admin/department-posts` | **403 on all six** |
| Another user's postings screen `/admin/users/{uuid}/assignments` | **403** |
| Unauthenticated `/admin/users`, `/dashboard`, `/dpc-proposals` | **302 → `/login`** on all three |
| Admin links in the AO's dashboard | **0** — the menu does not merely hide the links, the routes refuse them |

**Users are addressed by UUID, not by sequential id** — `User::getRouteKeyName()` returns `uuid`. Confirmed live: `/admin/users/10/assignments` returns **404**, and only the UUID resolves. *A 404 during testing was initially mistaken for a defect; it was the security design working as intended, and is recorded here as a correction to the tester rather than a finding.*

## 4. Business Rules — verified

1. **One active department assignment at a time.** ✅ Enforced in the database: `uda_user_active_unique (user_id, active_flag)`, with `active_flag` nullable so closed rows hold `NULL` and MySQL permits many of them. Verified live against the schema.
2. **Role assignment through department assignment only — no direct role bypass.** ✅ `UserProvisioningService::grant()` routes every departmental role through `DepartmentAssignmentService::assign()` and never calls `syncRoles()` for one. It guards `findOrFail($departmentId)` even though the Form Request already requires it, because seeders and console commands call the same service. Moving an account *out* of a departmental role into a Commission one releases the posting first, so an officer cannot hold Chairman while still being a department's AO.
3. **Audit trail generation.** ✅ See section 6.
4. **Effective date handling.** ✅ Back-dating and future-dating are both permitted as the Administrator's explicit choice; what is refused is a start date that would overlap the posting it replaces.
5. **Concurrency.** ✅ Verified by reading: every method is one transaction and the incumbent is read `lockForUpdate()`, so two administrators transferring the same officer simultaneously wait rather than colliding with a constraint violation the administrator could not act on.

## 5. Validation — tested, with results

| Case | Input | Result | Message |
|---|---|---|---|
| **Y1** | Transfer into Higher Education (status `active`) | **Refused** | *"The selected department id is invalid."* — **this is the defect at UAT-OBS-016** |
| **Y2** | `role = chairman` on the postings screen | Refused | ✅ *"The selected role is invalid."* |
| **Y3** | `effective_from = 2026-04-01`, before the incumbent's 2026-05-01 | Refused | ✅ *"This officer's current posting began on 01-05-2026. A transfer must take effect after that date — correct the current posting first if the date on the order is earlier."* |
| **Y4** | Same department **and** same role as the incumbent | Refused | ✅ *"This officer is already Authorized Officer in School Education Department. Choose a different department or role, or release them if the posting has simply ended."* |
| **Y5** | Valid transfer | **Accepted** | Dates and role re-projection both exact |
| **Provision, Commission role** | `role=member`, no department | **Accepted** — HTTP 302, account created | — |
| **Provision, departmental role** | `role=authorized_officer`, `department_id=2` | **HTTP 500** | **UAT-OBS-015** |

## 6. Technical Verification

**Database changes.** `user_department_assignments` +2 rows on user #10, both now closed. `users` +1 (`uat.member@cgpsc.gov.in`, #29, provisioned as a Commission member). The failed departmental provision created **nothing** — the transaction rolled back cleanly, which is correct behaviour under failure.

**Audit records.** ✅ `UserDepartmentAssignment` is fully `Auditable` with field-level values, attributed to `user_id = 1`:
- `created` → `{"user_id":10,"department_id":2,"role":"authorized_officer",…}`
- `updated` → old `{"effective_to":null,"active_flag":1}` → new `{"effective_to":"2026-06-30 00:00:00","active_flag":null}`

This is exactly the evidence a transfer dispute would need. **Note:** `RecruitmentRule created = 2` in the same audit table confirms the version clone is audited, which partially softens UAT-OBS-013.

**Activity timeline.** ✅ `user.assigned` → `user.transferred` → `user.released`, each with a sentence a non-technical reader can follow — *"Transferred from School Education Department to School Education Department as Administrative Head with effect from 2026-07-01."*

**API behaviour.** No JSON API. Server-rendered HTML, session auth, CSRF enforced, `role:admin` middleware on the whole admin group.

**Security.** UUID route keys; role middleware returning 403 rather than redirecting; no admin links leaked into a non-admin menu.

**Incidental discovery, deferred to Step 15.** The timeline for user #10 opens with `user.created_for_committee` — *"recorded while composing a DPC committee"*. This explains the two role-less accounts noted at Step 2: they are committee members provisioned by committee composition, not orphans. The apparent duplicate pair (#10 and #25, same name) will be examined at Step 15 rather than guessed at here.

## Observations

**Three new observations, appended to `docs/BUSINESS_UAT_OBSERVATIONS.md`.**

**UAT-OBS-015 (High) — provisioning any departmental account from Admin → Users fails with HTTP 500.** `UserProvisioningService::grant()` declares `?int $departmentId`, the file has `declare(strict_types=1)`, and `provision()` passes `$request->validated()['department_id']` — which arrives from an HTML form as the **string** `"2"`. `TypeError` logged. Provisioning a Commission role succeeds over the same route because it passes `null`. **No test covers the broken branch**: every case in `UserManagementTest` uses `Role::Member` and none posts `department_id` at all, which is why 1287 green tests do not catch it.

**UAT-OBS-016 (High) — a department that has completed verification can no longer receive a posting or a transfer.** `DepartmentStatus` has three states: `unregistered` → `registered` (set by `RegistrationService`) → `active` (set by `VerificationController:93` once the AO verifies both OTPs). Both places that gate postings — `UserAssignmentController:48` and `AssignUserRequest:32` — filter on **`Registered` only**. So the moment a department becomes fully operational it disappears from the postings dropdown and its id is rejected as invalid. Verified live: Higher Education, taken through the real registration and verification flow, is `active` and **cannot be posted to**; School Education, left at `registered` by the seeder, still can.

**UAT-OBS-017 (Low) — releasing an officer overwrites the closed posting's remark.** `retireIncumbent()` assigns `$incumbent->remarks = $remarks` when remarks are supplied, so *"UAT promotion to AH"* became *"Released by the CGPSC Administrator."* on the row it described. The reason a posting **began** is lost and replaced by the reason it **ended**, on a table whose stated purpose is history.

## Business Questions

**None new.** Every rule met at this step is unambiguous and already ruled upon. BD-27 (is the same employee number in two departments one officer or two?) is adjacent but concerns candidate identity from Seniority Lists, not officer postings, and is not duplicated here.

## Suggestions

**None new.** All three observations name their own remedy.

## Pass/Fail

**⚠ PASS WITH OBSERVATIONS** — with two capabilities non-functional.

What works is genuinely well built: the assign/transfer/release machinery is exact to the day, the role projection is correct, the database enforces one active posting, the audit trail is field-level and attributable, four of five validation messages are excellent, and authorisation holds at every level tested. What does not work: **a departmental account cannot be provisioned from the Users screen at all (500)**, and **no department that has completed verification can be posted to**. Both are invisible in seeded data and appear only when the application is actually used — the same pattern as UAT-OBS-005 and UAT-OBS-006.

## User Confirmation

**AWAITING APPROVAL** — Step 5 is a milestone at which the user requires a pause.

## Next Step

**Step 6 — Working Officers.**

====================================================

====================================================

## SEQUENCING DEVIATION — recorded 2026-08-31 14:10 IST

The user's step order lists Working Officers (6), Seniority List (7) and Vacancy Calculation (8) **before** Pre-DPC (9) and DPC Creation (13). The implementation cannot support that order: `dpc_working_officers`, `dpc_seniority_entries`, `dpc_vacancies` and `dpc_evaluation_rows` **all hang off `dpc_proposal_id`** — verified against the live schema. None can exist before a DPC proposal exists.

**This is not a defect and not a blocker.** The user's list is the business narrative; the dependency order is Pre-DPC → DPC → Working Officers → Seniority → Vacancy. Execution therefore proceeds in dependency order, and every step on the user's list is still covered. Recorded here so the log's numbering remains traceable to the user's own list.

**Executed order from here:** 9 → 10 → 11 → 12 → 13 → 6 → 7 → 8 → 14 → 15 → 16 → 17 → 18 → 19 → 20 → 21 → 22 → 23 → 24 → 25 → 26.

====================================================

## Steps 9–13 — EXECUTED

## Step Names

**9 Pre-DPC Creation · 10 AO Submission · 11 AH Approval · 12 Pre-DPC Activation · 13 DPC Creation**

## Timestamp

**2026-08-31 14:05–14:30 IST**

## Preliminary — first-login flows verified

Both Higher Education officers were taken through their genuine first-login sequence, which had not been exercised before.

| Officer | Sequence observed |
|---|---|
| **AO (#26)** | Login with issued temporary password → **forced redirect to `/password/change`** → password changed → dashboard reachable |
| **AH (#27)** | Login → **redirect to `/verify`**, not to the password change — the AH's channels were never verified, because registration issues OTPs to the **Authorized Officer only** (the two-OTP finding from Step 2). OTP verification completed → then forced password change → dashboard |

**Both behaved correctly.** The AH's extra verification step is the right consequence of the Step 2 design: the registrant verifies at registration, the colleague they nominated verifies on first use.

**One usability note, recorded as UAT-OBS-018 (Low).** The credential message reads *"Temporary password: ^z~0HQ^&MD[*. You must change…"* — the sentence's full stop sits immediately against a password whose character set includes punctuation. The first login attempt during this UAT **failed** because the trailing full stop was taken to be part of the password. A real officer reading this from an SMS would make the same mistake.

## Step 9 — Pre-DPC Creation

**Validations tested — all four correct:**

| Case | Input | Message |
|---|---|---|
| **Z1** | `dpc_year = 2062` | ✅ *"A Pre-DPC can be prepared for the current or the coming year, not beyond."* |
| **Z2** | Feeder = promotion-to post | ✅ *"A post cannot be promoted into itself. Choose a different Promotion From Post."* |
| **Z3** | DR 50 + PR 75 | ✅ *"Direct Recruitment % and Promotion % must total exactly 100%. They currently total 125.00% — over by 25.00%."* |
| **Z4** | Feeder from another department | ✅ *"Select a live post of your own department from the Department Post master."* |

**Created:** Pre-DPC **#9 · `PDPC-2026-HE-0001`**, year 2026, promotion-to post #11, feeder #10 at 100% share, 5 years, qualifications Post-Graduate + Post-Graduate with B.Ed., DR 25% / PR 75%, v1, `draft`.

**Business rule confirmed (item 5, `PostAttributeResolver`).** `pay_scale_id = 4` and `class_level = I` were **derived from the Department Post master, not submitted** — the form does not accept them, so a crafted request cannot sanction a pay scale the Commission never approved. Verified: I sent neither field and both were stored correctly.

**Post scoping confirmed.** Only posts #10 and #11 were offered — live, Active, in-force posts of the officer's own department.

## Step 10 — AO Submission

`draft → submitted`, `submitted_at` stamped. Timeline: `pre_dpc.submit — draft → submitted`.

**Separation of duty verified — and it holds.** The AO then attempted `POST /pre-dpc/{uuid}/approve` on their own submission. The proposal remained `submitted` with `approved_at` still null. **An Authorized Officer cannot approve their own Pre-DPC.**

## Step 11 — AH Approval

Approved by the Administrative Head: `status = approved`, `approved_by = 27` (the AH, not the AO), `approved_at = 2026-08-31 08:02:05`, `signature_id = 26`.

**Signature note.** `FEATURE_CSC_DIGISIGN` is off, so this is the mock provider. That is correct here and guarded: `PreDpcProposalService:460` refuses to mock-sign when `app()->environment('production')` and the feature is disabled. The M4 freeze rule *"never mock-sign in prod"* is honoured.

## Step 12 — Pre-DPC Activation

**`active_flag = 1` on approval.** Activation is not a separate act — approving the exercise activates it. Exactly one active exercise exists for department 3 / year 2026 / post 11, which is what `assertExerciseIsNotAlreadyActive()` protects.

## Step 13 — DPC Creation

**Created:** DPC **#10 · `DPC-2026-HE-0001`**, from Pre-DPC #9, promotion-to #11, feeder #10, type `fresh`, `pay_scale_id = 4`, `class_level = I`, `promotion_duration_years = 5`, `status = draft`.

Only Pre-DPC #9 was offered on the create form — the active, approved one. Correct.

**Two findings at this step.**

**(a) `sanctioned_post_count = NULL`** — the direct downstream consequence of **UAT-OBS-009**. Neither the post mapping's `sanctioned_strength` nor the proposal's `sanctioned_post_count` has a value, because neither has a data-entry path on the screens traversed so far. Step 8 will show what the vacancy engine does with that.

**(b) The DPC cites recruitment rule #11 — a rule that did not exist when Step 4 finished.** Investigated: **approving a Pre-DPC mints a new Active recruitment rule version.** `PreDpcProposalService:576` calls `RecruitmentRuleService::create([... 'status' => MasterStatus::Active->value ...])` for the promotion-to post, using the Pre-DPC's own split, feeders and effective date. Rule #11 (**v3, Active, effective 2026-04-01**) was created at `08:02:05` — the exact AH approval timestamp.

The post now carries **two Active rules covering the same day**: #9 (v1, Active, from 2026-04-01, open-ended) and #11 (v3, Active, from 2026-04-01, open-ended). **The earlier rule is not superseded** — `effective_to` remains NULL. `currentRuleFor()` selected v3 because it has the highest version.

**This materially changes UAT-OBS-014**, which recorded the overlap as conditional on administrator misuse. It is not: it is the **automatic outcome of the standard Pre-DPC approval flow**. Any post whose Pre-DPC is approved more than once — the normal multi-year case, a 2026 exercise followed by a 2027 one — accumulates Active, open-ended, overlapping rules, and the date-blind selector will cite the highest-numbered one whatever year the DPC is for. UAT-OBS-014 has been amended with this evidence and a recommendation that the business consider it **Critical** rather than High.

## Database Impact

`pre_dpc_proposals` +1 (#9) · `pre_dpc_promotion_sources` +1 · `pre_dpc_source_qualifications` +2 · `recruitment_rules` **+1 (#11, auto-minted)** · `recruitment_rule_sources` +1 · `dpc_proposals` +1 (#10) · signatures +1 (mock) · `users` password changes ×2 · `otp_verifications` +2 (AH).

## Audit Trail

Timeline entries observed: `pre_dpc.submit` (draft → submitted) and `pre_dpc.approve` (submitted → approved), each naming the transition. Both Pre-DPC and DPC aggregates are addressed by **UUID**, not sequential id.

## Pass/Fail

**Step 9 ✅ PASS · Step 10 ✅ PASS · Step 11 ✅ PASS · Step 12 ✅ PASS · Step 13 ⚠ PASS WITH OBSERVATIONS**

Steps 9–12 are clean: every validation correct, attribute derivation correct, separation of duty enforced, activation correct, signature guarded. Step 13 created the DPC correctly but exposed the rule-overlap defect and carried `sanctioned_post_count = NULL` forward.

## Next Step

**Step 6 — Working Officers** (next in dependency order).

====================================================

====================================================

## Steps 6–8 — EXECUTED (Step 8 BLOCKED)

## Step Names

**6 Working Officers · 7 Seniority List Upload · 8 Vacancy Calculation**

## Timestamp

**2026-08-31 14:35–14:55 IST**

## Step 6 — Working Officers ✅ PASS

Six incumbents of *Associate Professor* saved against DPC #10 through `PUT /dpc-proposals/{uuid}/working-officers`, deliberately including two special cases:

| sn | Name | Category | Retirement | Deputation |
|---:|---|---|---|---|
| 1 | Dr. Ashok Kumar Sharma | UR | **2026-11-30** | — |
| 2 | Dr. Vinod Kumar Tiwari | SC | — | — |
| 3 | Dr. Sunita Devi Patel | ST | — | — |
| 4 | Dr. Rakesh Kumar Nayak | OBC | — | — |
| 5 | Dr. Anita Kujur Singh | ST | — | **from 2026-02-01, no return** |
| 6 | Dr. Mahesh Prasad Verma | UR | — | — |

**Business rules verified.** Ten-field employee snapshot stored as plain columns with no live FK to any employee record, so a historical proposal cannot change when a master does. `reservation_category_id` is the sole FK, as the migration documents. Retirement, deputation-from and deputation-to are all nullable — most officers are neither retiring nor on deputation, and `deputation_to` is deliberately **not** `required_with`, because an officer still away is the commonest case and the one that frees a post.

**The screen names the master-data gap.** It displays *"…Sanctioned Strength. Ask the CGPSC Administrator to record it."* — confirming UAT-OBS-009 from the user's side, not merely in code.

**A tester error, recorded for honesty.** The first save produced six rows whose names were all truncated to *"Dr."*. That was unquoted shell word-splitting in the test harness, **not** an application fault; re-saving with correct quoting stored all six names in full. No defect.

## Step 7 — Seniority List Upload ⏸ NOT REACHED

The Seniority List is gated on the zone of consideration, and the zone is derived from the vacancy. The vacancy is `NULL` (below), so this step could not be started.

## Step 8 — Vacancy Calculation ❌ BLOCKED

The engine was driven directly to obtain the full breakdown.

### What is correct — and it is a great deal

| Output | Value | Verdict |
|---|---|---|
| **Vacancy period** | **2026-01-01 … 2026-11-30** | ✅ Exactly **BD-34** (1 January to 30 November of the DPC Year) and **BD-35** (a December retirement creates no vacancy) |
| Working strength | **6** | ✅ Derived from the Working Officer List, not a typed count — Suggestion 17's concern already addressed |
| Retiring in period | **1** — Dr. Ashok Kumar Sharma | ✅ Retirement on the window's last day counts |
| On deputation overlapping | **1** — Dr. Anita Kujur Singh | ✅ **BD-43** read correctly: overlap not containment, and a null return date means still away |
| `retirementVacancy` / `deputationVacancy` | 1 / 1 | ✅ |
| `promotionPercentage` | 75.00 | ✅ From the cited rule |
| `roundingRule` | `floor` | ✅ **BD-33 / BD-41** |
| **`contributors`** | Names each officer and the ground — *"Retires on 30-11-2026"*, *"On deputation from 01-02-2026 (no return date recorded)"* | ✅ Excellent explainability; a department can see exactly why each vacancy exists |

### What is blocked

```
sanctionedStrength : null
workingStrength    : 6
baseVacancy        : null
totalVacancy       : null
promotionVacancy   : null
directRecruitment  : null
zone               : null
requiredSeniorityCount : null
```

**With no sanctioned strength there is no base vacancy, therefore no total vacancy, therefore no zone of consideration, therefore no Seniority List.** The pipeline stops here.

### There is no data-entry path anywhere in the application

Every candidate route was checked:

| Route | Result |
|---|---|
| Department Post Mapping form / `DepartmentPostRequest` | **Absent** (UAT-OBS-009) |
| Working Officers form | **Deliberately removed** — change register 2026-08-27 item 1: *"`sanctioned_post_count` is gone from this form… it comes from the Department Post Mapping"* |
| DPC edit screen | Offers only `dpc_type_id` and `legal_stay` |
| `SaveDpcProposalRequest` | No `sanctioned_post_count` rule |
| `dpc_proposals.sanctioned_post_count` | Written **only** from `$breakdown->sanctionedStrength` (`DpcProposalService:295`) — derived, never entered |
| Only writers in the codebase | `DemoDpcBuilder:88` (seeder) and a one-off backfill inside migration `410001` |

### A second consequence, newly found

`SaveWorkingOfficersRequest::assertVacancyArithmetic()` begins:

```php
$sanctioned = $proposal->promotionToPost?->sanctioned_strength;
if ($sanctioned === null) {
    return;   // "A master-data gap, not a validation failure."
}
```

So when sanctioned strength is absent, **the whole vacancy-arithmetic invariant is silently skipped** — the ceiling that stops a department recording more vacancies than posts. Because the value can never be set through the UI, that invariant **never runs at all** for any department onboarded through the portal. The decision not to refuse the save is defensible on its own terms; the problem is that the condition it waits for can never be satisfied.

## Observations

**UAT-OBS-009 escalated to Critical** in `BUSINESS_UAT_OBSERVATIONS.md`, with the new evidence above. It is no longer "a missing field": it makes a DPC **uncompletable** for any department onboarded through the portal, which is the register's own definition of Critical.

**UAT-OBS-018 (Low) recorded** — the credential SMS places the sentence's full stop immediately against a password containing punctuation (*"Temporary password: ^z~0HQ^&MD[*. You must change…"*). The first login attempt in this UAT failed for exactly that reason.

## Pass/Fail

**Step 6 ✅ PASS · Step 7 ⏸ NOT REACHED · Step 8 ❌ BLOCKED**

## BLOCKER — user decision required

This meets the user's stated pause condition 3, *"a real blocker prevents further execution"*, and touches condition 4, because the only route forward inside the application is a direct database write, which the user has forbidden without approval.

Steps 7 and 9 through 26 all depend on a non-null vacancy. **The UAT cannot continue until this is resolved.** Options are set out for the user in the terminal and recorded here:

**(A) Authorise a targeted database write** — set `department_posts.sanctioned_strength` on posts #10 and #11 to the establishment figures the missing screen would have captured. Two rows, additive, no schema change, local development only. This is the Test Lead's recommendation: it is exactly what the absent field would have done, and it keeps the approved Higher Education scenario intact.

**(B) Switch the walkthrough to School Education's seeded DPC** for the remaining steps, since the seeder wrote sanctioned strengths there. Rejected as a recommendation: it abandons the Higher Education scenario the user expressly directed be kept, and it would test seeded data rather than data entered through the application — which is what found five of the last six defects.

**(C) Authorise a code fix** to add the field. Contrary to the user's standing instruction that no fixes be implemented during UAT.

## Next Step

**Awaiting the user's decision.** On option A, execution resumes at Step 8 and continues uninterrupted to Step 26.

====================================================
