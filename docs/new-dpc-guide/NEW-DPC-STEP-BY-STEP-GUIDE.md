# New DPC from Zero: Step-by-Step Guide

**Prepared:** 2026-10-23.

**How it was prepared:**
- The code (`main` at `eed36c0` plus the uncommitted working tree) and the dev database (`dpc_dev`) were inspected **read-only**.
- No record was created, changed or deleted.

**Scope:**
- Only implemented behaviour is described. Anything absent is marked **NOT IMPLEMENTED**.
- Each step lists the code that enforces it.
- Example values are *chosen for this guide*. They were checked not to clash with anything on dev.

**Testing status:**
- **Browser:** this guide has **not** been walked through in a browser.
- **Automated (Steps 3 to 6):** run on an in-memory test database, never on dev. The run used the real routes: Pre-DPC create, submit and approve; the Recruitment Rule created by the approval; a vacancy record of 10 / 7; the new DPC; and both CSV uploads. See §V at the end.

---

## 0. Read first: where this will stop unless handled

| # | Blocker | Why (code) | What to do |
|---|---|---|---|
| B1 | **No queue worker runs on dev** (`QUEUE_CONNECTION=redis`, no `queue:work` process). New accounts' temporary passwords are sent by a **queued** listener (`SendCredentialNotification`), so they never arrive, even in the mail log (`MAIL_MAILER=log`). The same applies to Admin-created users, committee members and sitting or promotion-order notices. | `app/Domain/Authentication/Listeners/SendCredentialNotification.php` (implements `ShouldQueue`) | Either run `php artisan queue:work` while testing (passwords then appear in `storage/logs/laravel.log`), or have the Admin use **User Management → Reset Password**, which shows the new password once on screen (`Admin/UserController.php:73-95`). |
| B2 | **Approving the CGPSC result needs an account with the `member` role.** The Chairman's account (`chairman` only) is refused: "You are not permitted to perform [approve_result]." | `config/dpc.php:540` (`'role' => 'member'`); `WorkflowEngine::assertAllowed` :86 | The Admin creates a **Member, CGPSC** account (Step 1.8). The AO seats that member on the committee (Step 5.2), and the Chairman allots the DPC to that member (Step 9.1). |
| B3 | **Department Post names come from the Designations master.** The post form has no free-text name. "Assistant Engineer" and "Executive Engineer" do not exist on dev. | `DepartmentPostRequest` :43-59 | The Admin creates both designations first (Step 1.2). |
| B4 | **Every master form defaults to Status = Draft.** Only Active rows are offered later. | department-posts/form.blade.php:82 and the other master forms | Choose **Active** on every master you create. |
| B5 | **Promotion CR rules are global per class pair and cannot be edited.** A new rule for II→I effective 2026 becomes the rule for *every* II→I DPC raised from then on, including other departments, because the latest rule wins. | `PromotionCrRules::ruleFor` :146-156 (orders by year, then id) | Decide before creating it. Dev already has two II→I 2026 rules (ids 1 and 2). The newest is fixed on a DPC when the DPC is raised. |
| B6 | **Production signing.** With `APP_ENV=production` and CSC DigiSign off, the AH cannot approve a Pre-DPC or a DPC Proposal. Dev is `APP_ENV=local`, where the mock signer works. | `PreDpcProposalService::assertSignatureAvailable` :523; `DpcProposalService` :765 | No action on dev. |
| B7 | **Allottee tiles.** An allottee who holds no role sees no Schedules or Proceedings tile. | `dashboard.blade.php:104-115` | Use a `member` account (B2), which does see them. |
| B8 | **Department Registration on dev runs the uncommitted new flow** (working tree): Aadhaar OTP plus e-mail OTP, and the department's district, place, PIN and e-mail must already be in the Department Master. | `docs/DEPARTMENT-REGISTRATION.md` | Fill those four fields in Step 1.1. |

---

## Example used throughout

| Item | Value | On dev today |
|---|---|---|
| Department | **Public Health Engineering Department**, code **PHE** | does not exist |
| Promotion From Post | **Assistant Engineer**, Class **II** | does not exist |
| Promotion To Post | **Executive Engineer**, Class **I** | does not exist |
| DPC year | **2026** | — |
| Recruitment split | **Promotion 100 % / Direct 0 %** | — |
| Vacancy | sanctioned **10**, working **7** (all by promotion) → promotion vacancy **3** | — |
| Employee IDs in the CSVs | `PHE/AE/3001-3010` (candidates), `PHE/EE/4001-4007` (working) | none exist |

Only one department exists on dev (School Education, with posts BEO and Additional Director). Its Additional Director 2026 already has a finalized vacancy record, so it cannot host a fresh exercise. Hence a new department.

---

## Step 1: Admin setup

Login role for every sub-step: **Admin** (`admin@cgpsc.gov.in` on dev). Menu: Admin sidebar.

### 1.1 Department

| | |
|---|---|
| Menu / URL | Departments → `/admin/departments/create` |
| Button | **+ New** → **Save** |
| Fields (rules) | Code: required, A–Z/0–9, ≤20, unique, upper-cased. Name: required, unique. Status: required. District, Place, PIN (6 digits), Contact e-mail: optional here, **but required for registration (B8)**. |
| Example | Code `PHE`; Name `Public Health Engineering Department`; Status **Unregistered**; District `Raipur`; Place `Nava Raipur Atal Nagar`; PIN `492002`; e-mail `phe.dpc@cg.gov.in` |
| Result | Department exists, status **Unregistered** |
| Next | Admin, 1.2 |

References: `DepartmentRequest` :36-47.

### 1.2 Designations (needed by the posts)

| | |
|---|---|
| Menu / URL | Designations → `/admin/designations/create` |
| Fields | Name; Status |
| Example | `Assistant Engineer`, **Active**; then `Executive Engineer`, **Active** |
| Result | Both appear in the Department Post name list |

### 1.3 Department Posts (Promotion From and To)

| | |
|---|---|
| Menu / URL | Department Posts → `/admin/department-posts/create` |
| Fields (rules) | Department: required. Post Name: from Active designations; unique among in-force posts of the department. Class: required (I / II / III). Pay Scale: optional (Active scales). Promotion Duration in years: 0–40, optional. Status: required. Effective From: required date. Government Orders: optional repeater (number, date ≤ today, PDF ≤ 10 MB). |
| Example 1 | PHE, `Assistant Engineer`, Class **II**, Pay Scale `Level-12 (78800-209200)`, Duration `5`, Status **Active**, Effective From `01-01-2026` |
| Example 2 | PHE, `Executive Engineer`, Class **I**, Pay Scale `Level-13 (123100-215900)`, Duration blank, Status **Active**, Effective From `01-01-2026` |
| Result | Both posts in force. Status Active sets `active_flag` (`DepartmentPost::booted`). |
| Next | Admin, 1.4 |

Rules that matter:
- **The feeder must sit below the post it feeds.** The class rank must be lower (II below I). Within the same class, the pay-scale level must be lower (`PromotionHierarchyService::verdict`).
- **There is no sanctioned-strength field.** It is entered per year in the vacancy record (Step 4).

### 1.4 Qualifications

| | |
|---|---|
| URL | `/admin/qualifications` |
| Rule | Active qualifications are offered on the Pre-DPC. **Every feeder must name at least one.** |
| Example | `B.E.` already exists and is Active on dev. Nothing to create. |

### 1.5 Promotion CR Rule (Class Master)

**Read B5 first.**

| | |
|---|---|
| Menu / URL | Class Master → open **Class-I** → Promotion rules → `/admin/service-classes/{Class-I}/promotion-rules/create` |
| Button | **Save rule**. The form starts pre-filled from the rule in force. A rule is never edited: saving always records a new rule. |
| Fields (rules) | Promotion from: same class or the class below. Applies from DPC year: 2000–2100; a CR grade must be valid on 1 Jan of that year. Years of CR: 1–10. Years of Loyalty: 0–10. Years of Property: 0–10. For each year: CR passing marks (required for every CR year; must be a grade value), CR mandatory, loyalty mandatory, property mandatory (ticks). Qualifying total: 1–1000. Super-seat grade: optional. Tribal bonus: 0–10. No tribal bonus at the highest or lowest grade: tick. Remarks. |
| Example | From **II**; year **2026**; CR years **5**; Loyalty years **0**; Property years **5**; each of the 5 years: passing marks **5**, CR mandatory ✓, property mandatory ✓; Qualifying total **25**; Super-seat **9**; Tribal bonus **0** |
| Result | New II→I rule. It is fixed on the DPC when the DPC is raised. |

References: `RecordPromotionCrRuleRequest` :37-53; `PromotionCrRules::record` :178.

**Loyalty: correction of 2026-10-23, proven by the route test.**
- Whatever "Years of Loyalty" says, the calculation makes an officer **Not Eligible** when a Loyalty Certificate is missing for **any graded CR year** (`CandidateGateService` :340-362).
- Employee Details has **no loyalty entry**, and its completeness check does not ask for one.
- So, before submitting, the AO must record **Loyalty = Y for 2021–2025 for every officer** on DPC Proposal → **Evaluation Chart** (`DpcProposalController::updateEvaluationChart`). That screen saves CR grades and property flags together with loyalty, so keep the values it shows.
- Without this, all 10 officers came out NE and no promotion order could be issued.

### 1.6 CR Grades

| | |
|---|---|
| URL | `/admin/cr-grades` |
| Rule | Grade values must be valid on 1 Jan of the DPC year, with a grade code. |
| Example | Already on dev: क+ 9, क 7, ख 5, ग 3, घ 1, valid from 2019-01-01. Nothing to create. |

### 1.7 Other masters (check only; all exist on dev)

| Master | Dev state |
|---|---|
| Record Reasons | 10 rows |
| Sealed Cover Reasons | 4 rows |
| DPC Types | Fresh, Review, Circulation |
| CGPSC Chairman | Dr. Ajay Kumar Shrivastava, **linked to user `chairman@cgpsc.gov.in`** |
| DPC Committee Rules | 4–7 in total, so 3–6 members besides the Chairman. Coverage: SC, ST, OBC, Female, Bhar Sadhak Sachiv, Vibhag Sachiv. |
| PH policy | 3 % |

### 1.8 CGPSC Member account (needed by B2)

| | |
|---|---|
| Menu / URL | User Management → `/admin/users/create` |
| Fields (rules) | Name: required. E-mail: required, `.gov.in`, unique. Mobile: required, 10 digits, unique. Designation: optional. Role: required. Department: only for departmental roles. |
| Example | `Shri R. K. Member (Test)`, `member.test@cgpsc.gov.in`, a dummy mobile such as `9000000301` (check it is unused), Designation `Member, CGPSC`, Role **Member, CGPSC** |
| Password | Sent by the queued listener (B1). Otherwise **Reset Password** on the user's row shows it once. |
| Next | AO and AH registration (Step 2) |

---

## Step 2: Department Registration (AH and AO)

**Login role:** none (public page).

| | |
|---|---|
| URL | `/register` (top bar **Register**) |
| Department | Choose **Public Health Engineering Department**. District, Place, PIN and e-mail appear **read-only** from the master. |
| AH section (upper) | E-mail (`.gov.in`) → if the officer is new, type the Name → Designation → Mobile → Aadhaar (12 digits, shown masked) → **Send OTP** → enter **Aadhaar OTP** and **E-mail OTP** → **Verify OTP** |
| AO section (lower) | Same as the AH section |
| Example AH | `ah.phe@cg.gov.in`, `Shri P. K. Head (Test)`, Designation `Secretary`, mobile `9000000302`, Aadhaar `234567890123` (dummy) |
| Example AO | `ao.phe@cg.gov.in`, `Shri S. K. Officer (Test)`, Designation `Deputy Secretary`, mobile `9000000303`, Aadhaar `345678901234` (dummy) |
| OTP on dev | Aadhaar OTP **`123456`** (mock provider, `FEATURE_UIDAI_AADHAAR_OTP=false`). E-mail OTP **`123456`** (`APP_ENV=local` demonstration OTP). |
| Rules | Both seats are required on a first registration. Once verified, e-mail, name, mobile and Aadhaar are locked, and the server refuses any change. Verification lasts 15 minutes. |
| Button | **Register** (one button) |
| Result | Two new accounts, both channels verified. The department becomes **Registered**. Passwords: see B1. |
| Approval | **None.** There is no Admin or Commission approval step; registration takes effect at once. |
| Next | AO |

Code: `RegistrationController`, `RegistrationVerificationService`, `RegistrationService`.

At first login the officer must change the system password (`must_change_password`).

---

## Step 3: Fresh Pre-DPC

### 3.1 Create and submit (AO)

| | |
|---|---|
| Login | **AO** (`ao.phe@cg.gov.in`) |
| Menu / URL | Dashboard → **Pre-DPC Proposals** → **+ New Proposal** (`/pre-dpc/create`) |
| Fields | DPC Year (2019 to next year). Promotion To Post (class and pay scale auto-fill). Feeder rows, each with: Promotion From Post, Share %, Minimum experience and unit, at least one Qualification (promotion duration auto-fills). Direct %. Promotion %. GO number and Effective date (required at **submit**). |
| Example | Year **2026**; To **Executive Engineer**; From **Assistant Engineer**, share **100**, experience **5 years**, qualification **B.E.**; Direct **0**, Promotion **100**; GO `F-1/2026/PHE/RR`; effective `01-01-2026` |
| Checks | Direct + Promotion = 100. Feeder shares = 100. Feeder below the To Post. No duplicate feeder. Only one active order per department, To Post and year. |
| Buttons | **Save** → status **Draft**, number issued (e.g. `PDPC-2026-PHE-0001`) → **Submit for Approval** → **Submitted** |
| Documents | **NOT IMPLEMENTED** on the Pre-DPC |
| Next | AH |

Code: `SavePreDpcProposalRequest`, `PreDpcProposalService`.

### 3.2 Approve (AH)

| | |
|---|---|
| Login | **AH** |
| Menu | Dashboard → **Pre-DPC Proposals** → open it |
| Buttons | **Approve & Sign** → **Approved**, active. Or **Return for Correction** (remarks required) → **Returned**. |
| Signature | Mock on dev. In production it needs CSC DigiSign (B6). The CSC DigiSign provider itself is a placeholder that throws ("not yet configured"). |
| Next | AO |

### 3.3 Verify the Recruitment Rule was created

There is **no Recruitment Rule screen** (NOT IMPLEMENTED). You can see the rule in two places:
- On the DPC Proposal's edit form after Step 5 (`dpc-proposals/form.blade.php:137-145`), which shows the rule in force.
- In the vacancy record's summary (Step 4).

The test run produced **Recruitment Rule v1, promotion 100 / direct 0** for Executive Engineer (`PreDpcProposalService::projectToMasters` :612).

---

## Step 4: Fresh Vacancy Record (AO)

| | |
|---|---|
| Login | **AO** |
| Menu / URL | Top bar → **Vacancy Management** → choose Post **Executive Engineer**, Year **2026** → open |
| Button | **Start draft** → revision 1, status **Draft**, period 01-01-2026 to 31-12-2026 |
| 4a Sanctioned strength | Total (0–100000), order number, order date ≤ today, **order PDF (required, ≤ 10 MB)**, remarks. Example: **10**, `PHE/SANC/2026`, `01-01-2026`, any PDF. |
| 4b Working strength | Total, Promotion, Direct, Deputation (all three or none; they must add up to the total), order number, date, **PDF (required)**. Example: **7 = 7 + 0 + 0**, `PHE/EST/2026`, `01-01-2026`. |
| 4c Entries | Optional. Leave empty. |
| 4d Review | Shows promotion chairs = round(10 × 100 %) = 10, promotion vacancy = 10 − 7 = **3**, direct vacancy = **0** |
| Button | **Finalize vacancy position** → status **Finalized** |
| Next | AO, Step 5 |

Finalize is refused while any of these is open:
- no post mapping;
- strength not entered;
- split missing;
- **no Recruitment Rule in force on 1 Jan**, which is why Step 3 comes first;
- entries awaiting verification;
- quota overfilled.

Code: `VacancyMasterService::finalize` :789; messages in `lang/en/vacancymaster.php:212-223`.

**Compatibility check:**
- At 100 / 0 the result is 3 promotion vacancies.
- At 80 / 20 it would be 1 promotion and 2 direct.
- At 60 % the record cannot be finalized (quota overfilled).

The record's promotion % must equal the Pre-DPC's, or the proposal reports "rule differs".

A second record for the same post and year is not possible. A correction is a **revision**, which needs a reason.

---

## Step 5: Fresh DPC Proposal (AO)

### 5.1 Raise it

| | |
|---|---|
| Login | **AO** |
| Menu / URL | Dashboard → **DPC Proposals** → **+ New Proposal** (`/dpc-proposals/create`) |
| Fields | Approved Pre-DPC (required; only active approved orders of your department). Promotion From Post (only if the order names more than one feeder). DPC type: **Fresh** only. Legal stay: No / Yes. |
| Example | `PDPC-2026-PHE-0001`, Fresh, Legal stay **No** |
| Not chosen by you | **Recruitment Rule**, **Vacancy Record** and **Promotion CR Rule** are all picked by the system: the rule in force for the To Post; the latest finalized record for department + To Post + year; the Class Master rule for II→I in 2026. None of them is a field. |
| Button | **Save** → status **Draft**, code e.g. `DPC-2026-PHE-0001` |
| Refusal | No Class Master rule → "No Class Master promotion rule for Class II → Class I is in force in 2026…" |
| Next | AO |

### 5.2 DPC Committee (screen S15)

| | |
|---|---|
| Menu / URL | DPC Proposal → **DPC Committee** (`/dpc-proposals/{id}/committee`) |
| Automatic | The CGPSC Chairman's seat is added by the system with the Chairman's account. |
| Per member | Search an existing account (by e-mail, mobile or name), or type: Name, Designation (master), Mobile (`6–9` + 9 digits; the screen requires it), E-mail (`.gov.in`). Ticks: SC / ST / OBC (only one), Female, Bhar Sadhak Sachiv, Vibhag Sachiv. |
| Account link | An e-mail or mobile that matches an existing account links it. Otherwise a **new account with no role** is created, and it can sign in only after Reset Password. |
| Example (4 members) | 1) **The Member account from 1.8** (search `member.test@cgpsc.gov.in`), tick SC. 2) `Smt. A. Test`, Designation `Joint Secretary`, mobile `9000000311`, tick ST + Female. 3) `Shri B. Test`, `Deputy Secretary`, `9000000312`, tick OBC + Bhar Sadhak Sachiv. 4) `Shri C. Test`, `Under Secretary`, `9000000313`, tick Vibhag Sachiv. |
| Save is refused if | Too many members; Chairman seat duplicated or missing; two DPC Chairmen; two social categories on one member |
| Submit is refused if | Fewer than the minimum, or SC / ST / OBC / Female / Bhar Sadhak Sachiv / Vibhag Sachiv not covered |

Code: `SaveDpcCommitteeRequest`, `DpcCommitteeService`, `CommitteeComplianceService`.

### 5.3 Then, in order

1. **Working Officers** upload (Step 6).
2. **Seniority List** upload (Step 6).
3. **Employee Details** (Step 7).
4. **Verification / Rule 1.1** (Step 7).
5. **Submit for Approval**.

| | |
|---|---|
| Submit button | DPC Proposal → **Submit for Approval** |
| Result | **Submitted**; the vacancy record revision is pinned |
| Next | AH (Step 8) |

Submit is refused while any of these sections is incomplete:
1. Selection of Posts
2. Committee
3. Vacancy record and working list
4. Seniority List
5. Evaluation Chart
6. Employee Details
7. Rule 1.1

In the test run, after both uploads only **Committee**, **Employee Details** and **Rule 1.1** remained incomplete. Selection of Posts, Vacancy, Seniority and Evaluation Chart completed by themselves.

---

## Step 6: The two CSV files

**Files:**
- `new_dpc_working_officers.csv`: 7 working officers by promotion plus 3 vacant posts, To Post "Executive Engineer".
- `new_dpc_seniority_list.csv`: 10 Assistant Engineers; CURRENT POST is "Assistant Engineer".

Both are UTF-8 with a BOM and use the exact template headers.

The two lists hold **different people** by design:
- Working Officers = officers holding the To Post now.
- Seniority List = candidates on the feeder post.

The code makes no identity match between them, so no ID appears in both.

| | Working Officers | Seniority List |
|---|---|---|
| Who uploads | **AO**, proposal Draft or Returned | same |
| Screen | DPC Proposal → **Working Officers** → **Upload & Preview** (template *Currently Working Officers (S16)*) | DPC Proposal → **Seniority List** → **List as on** `01-04-2025` → **Upload & Preview** (template *Final Seniority List (S17)*) |
| Mandatory columns | SN, STATUS (`working` / `vacant`, lower-case), SOURCE (working rows: promotion / direct / deputation; blank on vacant rows), NAME and DOB (working rows only); CAT only when category-wise is on | SENIORITY NO, EMPLOYEE ID, EMPLOYEE NAME, DATE OF BIRTH, DATE OF APPOINTMENT, CURRENT POST (= the From Post) |
| Dates | `DD-MM-YYYY`, zero-padded | same |
| Max | 5 MB, 500 rows | same |
| Preview | Valid and rejected rows, error report CSV, then **Commit** or **Cancel** | same, plus a kept / added / removed summary |
| Errors | **Only valid rows are committed**; a rejected officer is dropped | **Any rejected row blocks the whole file** |
| Duplicates | Not checked | Duplicate seniority number or Employee ID rejects those rows |
| Re-upload | Any time while editable; **replaces the whole list** | same; an officer's entered details are kept when the Employee ID is unchanged |
| Order | First. Its counts must equal the vacancy record (total 7, promotion 7, direct 0, deputation 0). | Hidden and refused until the working list matches |

Full rules: `docs/pre-dpc-guide/PRE-DPC-CSV-VALIDATION-RULES.md`.

---

## Step 7: Employee Details and Rule 1.1 (AO)

**Login:** AO. **Menu:** DPC Proposal → **Employee Details** (`/dpc-proposals/{id}/employees`) → open each of the 10 officers.

| Section | What is needed (example rule: CR and property mandatory 2021–2025) | Documents |
|---|---|---|
| Basic Information | DOB order no. and date; joining order no. and date; service-record undertaking ✓ (category only if category-wise) | none |
| CR (2021–2025) | Each year: put up? → graded? → up to 4 reports (final grade = accepting, else reviewer, else reporter) | **CR report** per graded year, tick "verified", plus the no-duplicate undertaking; "CR without grading" document if not graded; NRC if a remark is missing; channel undertaking and document |
| Missing CR | Officer → **Missing Records** (`/dpc-proposals/{id}/officers/{sn}/missing-records`): year, reason (Record Reason master), remarks if the reason requires them | Document(s) the reason names. Suspension needs two. |
| Sealed Cover | Enquiry pending? Yes → reason (master), date, remarks | Order, if the reason requires one |
| PH | Three questions | PH undertaking where required. A claim goes to the AH. |
| Deputation and Promotion Refusal | Unsanctioned deputation? Refused promotion and year? | Deputation order; refusal letter and that DPC's order |
| IPR (2021–2025) | Per year: submitted, date, next increment date; a late return needs the department's decision | IPR file; decision document if late and accepted |

**Every upload needs the "verified" tick.** That tick is the AO's verification.

**Example data that passes the example rule:**
- Every officer graded **क (7)** each year.
- 5 × 7 = 35, which is ≥ 25 and ≥ the minimum of 5 per year.
- IPR submitted by 31 January each year.
- PH: not PH.
- No enquiry, no deputation, no refusal.

**Loyalty (required):** DPC Proposal → **Evaluation Chart** → Loyalty **Y** for 2021–2025 for each officer → Save. See the note under Step 1.5.

**Rule 1.1 checklist:** DPC Proposal → **Verification**. Confirm all 7 items:
1. sanctioned strength;
2. Recruitment Rule;
3. seniority list;
4. previous DPC reconciliation (example: **none**, with remarks "No previous DPC for this post");
5. representations (upload any, then confirm);
6. original documents;
7. Recruitment Rule conformity, with the **RR notification date** (example `01-01-2026`).

**Responsible role:** AO for everything above. "Take previous year CR" is **NOT IMPLEMENTED** (deliberate).

---

## Step 8: AH verification and approval

| | |
|---|---|
| Login | **AH** (proposal **Submitted**) |
| Menu | Dashboard → **DPC Proposals** → open → review walkthrough S21–S25 → **Employee Details** |
| Must do | Verify every current document (per document, or **Verify all**). Decide every PH claim: verified, or not verified with remarks. |
| Mandatory documents | Every current Employee Details document must be AH-verified. Missing-record files and representations are not counted *(inferred)*. |
| Approve enabled when | All of: no document awaiting the AH, no PH claim pending, signing available. Otherwise refused with ":count document(s) are still to be verified…" or ":count PH claim(s)…". |
| Buttons | **Approve, Sign & Forward to CGPSC** → **Approved**, forwarded. Or **Return** with remarks → **Returned**, new revision, back to the AO. |
| Next | CGPSC Chairman |

Code: `DpcProposalService::approve` :639-684.

---

## Step 9: CGPSC processing

| # | Login role | Menu / URL | Action and fields | Prerequisite | Result | Next |
|---|---|---|---|---|---|---|
| 9.1 | **CGPSC Chairman** (`chairman`) | Dashboard → **Allotments** (`/cgpsc/allotments`) | **Allot**: Allotted to (a serving committee member with an account; choose the **Member** from 1.8), DPC Chairman (a member of this committee), Complete by (date ≥ today) | Proposal approved and forwarded | Allotment pending; DPC Chairman designated | Member |
| 9.2 | **Member** (`member`) | Dashboard → **Schedules** (`/cgpsc/schedules`) | **Accept DPC**, or **Decline** (reason ≥ 5 characters) | Allotted to this member | Accepted | Member |
| 9.3 | Member | same | **Schedule**: date and time (no venue field), not after the deadline | Accepted | Scheduled; members and candidates notified (queued, B1) | Member |
| 9.4 | Member | Dashboard → **Proceedings** (`/cgpsc/proceedings`) | **Open Proceeding** (early opening allowed by default) | Scheduled | In proceeding | Member |
| 9.5 | Member | Proceeding → **CALCULATE** | Optional corrections (field, year, new value, reason ≥ 10 characters) | Evaluation rows; Class Master rule; CR grades valid 1 Jan 2026 | Result: Main / Waiting / Others lists; holds (CIR) detected | Member |
| 9.6 | Member | Result → **APPROVE BY CGPSC** | "Promotion as on year" (4 digits) | Calculated; not stale; **role member (B2)** | **Result approved** | AH |

Not implemented or limited:
- **No signature at result approval** (`signature => false`).
- **Re-allotment works only while the allotment is still pending.** After acceptance it is refused, so the deadline cannot be extended.
- The committee's **/sitting** screens (call and conclude candidates) exist but are not needed for calculation, approval or the order.
- Proceeding documents (Generate, Publish to Department) are optional for the order. Publishing is refused while the result is provisional (Q5/Q6).

Code: `AllotmentService`, `SchedulingService`, `ProceedingService`, `ProceedingCalculationService`, `ResultApprovalService`.

---

## Step 10: Promotion Order (AH)

| | |
|---|---|
| Login | **AH** of PHE |
| Menu / URL | DPC Proposal → **Promotion Order** card → **Open** (`/dpc-proposals/{id}/promotion-orders`) |
| Fields | Order number (≤ 120), order date (≤ today), confirm ✓ |
| Example | `F-2/2026/PHE/PROMO`, today's date |
| Button | **Issue order** |
| Refused if | The result is not approved, or the Main List is empty or fully under sealed cover |
| PDF | Generated by dompdf, **English only** (Devanagari NOT IMPLEMENTED) |
| Signature | Mock provider on dev. Real CSC DigiSign is **NOT IMPLEMENTED**: the provider throws if enabled. |
| Notifications | Promoted officers and every committee member get SMS (logged; the gateway is NOT IMPLEMENTED), e-mail (`MAIL_MAILER=log`) and in-app. All queued (B1). |
| Public link | Signed link `/promotion-orders/{uuid}`, no expiry |
| Correction | Issuing again creates version 2. Version 1 becomes **superseded** and is never rewritten. |
| Result | Order **Issued**. The DPC is complete. |

Code: `IssuePromotionOrderRequest`, `PromotionOrderService::issue` :82-150, `SendPromotionOrderLink`.

---

## §V. What was verified, and how

| Check | How | Result |
|---|---|---|
| Steps 3 → 6 on fresh data | Throwaway test outside the repository, **in-memory SQLite**, real HTTP routes as the AO and the AH: Pre-DPC store, submit, approve; vacancy record 10 / 7 finalize; DPC store; both CSVs preview and commit | Pre-DPC `PDPC-2026-PHE-0001` approved and active. **Recruitment Rule v1, promotion 100 / direct 0, created automatically.** Vacancy finalized with promotion vacancy **3**, direct 0. DPC `DPC-2026-PHE-0001` with the rule and the Class Master rule fixed. Working Officers: 10 rows, 0 rejected, reconciled. Seniority List: 10 rows, 0 rejected. Remaining incomplete sections: Committee, Employee Details, Rule 1.1. |
| Steps 1, 2, 7–10 (added 2026-10-23) | The same throwaway in-memory test, extended through every remaining route: Admin masters, registration, committee, Employee Details for 10 officers, Loyalty on the Evaluation Chart, Rule 1.1, submit, AH verify and approve, allot, accept, schedule, open, calculate, approve result, promotion order | **25 of 25 steps passed.** Main List 3, Waiting 7. A Chairman-only account is refused at result approval; the Member account approves. Details: `docs/department-onboarding/DEPARTMENT-ONBOARDING-AND-FIRST-DPC.md` §4 |
| Browser | — | **Not done.** No step of this guide has been carried out in a browser. |
| Dev database | — | Read only; nothing written |
