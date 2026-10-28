# UAT: A New Department's First DPC (baseline commit `eed36c0`)

**Baseline:** commit `eed36c02f65e69ac397bdc141ea6914f22cdc280`, the code deployed on UAT.

**How the baseline was isolated:**
- The commit was extracted with `git archive` into a separate scratch folder.
- The working tree was not touched: nothing was stashed, reset, committed or pushed.
- The uncommitted registration mock-up, its Aadhaar OTP, and the uncommitted documents are **not** part of this guide.
- Between `eed36c0` and the working tree, only registration files differ. Every Pre-DPC, vacancy, DPC, Employee Details, CGPSC and promotion-order file is identical.

**How it was tested:**

| Kind | Result |
|---|---|
| **Automated route test on the `eed36c0` copy** | In-memory SQLite, real HTTP routes, run from the extracted commit with its own `vendor` and `.env`. **25 of 25 steps passed** (§6). |
| **Browser** | **Not tested.** |
| **UAT server** | **Not inspected or written to.** Its `.env` is unknown to this audit; see §1. |

**Example (hypothetical):**
- Department: **Public Health Engineering Department**, code `PHE`.
- Posts: **Assistant Engineer** (Class II) → **Executive Engineer** (Class I).
- DPC year 2026.

---

## 1. UAT environment conditions: check these first

The code behaves differently depending on settings this audit could not see on UAT.

| # | Setting on UAT | Effect | Reference |
|---|---|---|---|
| E1 | `APP_ENV` | If **`production`** and `FEATURE_CSC_DIGISIGN` is off, the AH **cannot approve** a Pre-DPC or a DPC Proposal ("Digital signing is unavailable…"). Turning the flag on does not help: the CSC DigiSign provider is a placeholder that throws "not yet configured". **The first DPC is then BLOCKED at Pre-DPC approval.** Any value other than `production` uses the mock signer. | `PreDpcProposalService::assertSignatureAvailable` :523; `DpcProposalService` :765; `CscDigiSignProvider::sign` |
| E2 | Queue worker | OTPs (e-mail and SMS) and the temporary passwords are sent by **queued** listeners. Without a running `queue:work` (or `QUEUE_CONNECTION=sync`), no OTP and no password reaches anyone. | `SendOtpNotification`, `SendCredentialNotification` (both `ShouldQueue`) |
| E3 | SMS | No SMS gateway is implemented. With `FEATURE_SMS_GATEWAY=false`, the **mobile OTP and the password SMS are written only to `storage/logs/laravel.log`**. With `true`, sending throws. Registration and first login need the mobile OTP, so someone with server access must read it from the log. | `AppServiceProvider` SmsSender binding; `LogSmsSender` |
| E4 | Mail | The e-mail OTP and the password e-mail use the configured mailer. With `MAIL_MAILER=log` they are also only in the log. | `config/mail.php` |
| E5 | OTP code | `DPC_OTP_FAKE_CODE` set → every OTP is that code. `APP_ENV=local` → `DEMO_OTP` (123456). Otherwise the code is random. | `OtpService::generateCode` |
| E6 | Masters seeded on UAT | Roles, districts, designations, qualifications, DPC types, committee rules and the CGPSC Chairman come from seeders. Class Master, CR Grades, Record Reasons and Sealed Cover Reasons come from migrations. Confirm they exist. | `database/seeders/DatabaseSeeder.php` |

**Fallback for passwords:** Admin → **User Management → Reset Password** shows a new password once on screen (`Admin/UserController.php:73-95`). This does not replace the OTP that registration and first login need (E3/E4).

---

## 2. PART 1: Admin prerequisites (identical at `eed36c0`)

Admin login → Admin sidebar (`resources/views/layouts/admin.blade.php`). All routes are `/admin/...` with role `admin`.

**Every master form defaults to Status Draft. Choose Active**, except the Department's status, which must be Unregistered (A1).

### A. Before Department Registration

| Master | Menu / URL | Fields (mandatory in **bold**) | Example | Dependencies | Validated at |
|---|---|---|---|---|---|
| A1 Department | Departments → `/admin/departments/create` | **Code** (A–Z/0–9, ≤ 20, unique), **Name** (unique), **Status**; District, Place, PIN, Contact e-mail optional | `PHE`, `Public Health Engineering Department`, Status **Unregistered** | District master | **Registration:** only **Unregistered** departments appear in the dropdown (`RegistrationController::create`; `RegisterDepartmentRequest` `department_id` exists where status = unregistered). District, place, PIN and e-mail are **typed on the registration form** and written onto the department. |
| A2 Designations (for the AH and AO) | Designations → `/admin/designations` | **Name**, **Status**; Code optional | Seeded: Secretary, Deputy Secretary, … | — | Registration: `ah_designation_id` / `ao_designation_id` must exist |
| A3 Districts | seeded | — | Raipur | — | Registration: `district_id` must exist |

### B. Before the first DPC

| Master | Menu / URL | Fields (mandatory in **bold**) | Example | Dependencies | Validated at | Scope |
|---|---|---|---|---|---|---|
| B1 Designations (post names) | `/admin/designations/create` | **Name**, **Status** Active | `Assistant Engineer`; `Executive Engineer` | — | Department Post form: the post name is picked from Active designations | Global |
| B2 Department Posts | Department Posts → `/admin/department-posts/create` | **Department**, **Post Name** (designation), **Class** (I/II/III), **Status** Active, **Effective From**; Pay Scale, Promotion Duration (0–40), Government Orders (number + date + PDF ≤ 10 MB) optional | `Assistant Engineer` II, L12, 5 yrs, Active, 01-01-2026; `Executive Engineer` I, L13, Active, 01-01-2026 | Department, Designation, Class Master, Pay Scale | Pre-DPC post lists; hierarchy (feeder below the To Post: class rank, or pay level within a class) | Per department |
| B3 Class Master | `/admin/service-classes` | seeded I, II, III | — | — | Post class; rule pairs | Global |
| B4 Qualifications | `/admin/qualifications` | **Code**, **Name (EN)**, **Name (HI)**, **Status** Active | `B.E.` (seeded) | — | Pre-DPC: at least one per feeder | Global |
| B5 CR Grades | Eligible CR Grades → `/admin/cr-grades` | **Value**, **Valid from**, Grade code | seeded 9/7/5/3/1 = क+/क/ख/ग/घ from 2019-01-01 | — | Promotion rule form (`no_grades`); calculation (`no_grade_points`) | Global |
| B6 Promotion CR Rule | Class Master → **Class-I** → Promotion rules → `/admin/service-classes/{id}/promotion-rules/create` → **Save rule** | **From class**, **Applies from year**, **CR years**, **Loyalty years**, **Property years**, **passing marks for every CR year** (a grade value), mandatory ticks, **Qualifying total**, Super-seat, **Tribal bonus** | II; 2026; 5; 5; 5; marks 5 each year, CR + property mandatory; total 25; super-seat 9; bonus 0 | CR Grades | **DPC create** ("No Class Master promotion rule for Class II → Class I is in force in 2026…"); calculation | **Global per class pair. Append-only; the newest wins for every department.** |
| B7 DPC Types | seeded | Fresh / Review / Circulation | — | — | DPC create (Fresh only) | Global |
| B8 CGPSC Chairman | `/admin/cgpsc-chairman` | **Name**, designation, effective from, **linked user account** (optional on the form, needed in practice) | — | a `chairman` user | Committee save ("No CGPSC Chairman is recorded…"); allotment | Global |
| B9 CGPSC Member account | User Management → `/admin/users/create` | **Name**, **E-mail** (`.gov.in`, unique), **Mobile** (10 digits, unique), Designation, **Role = Member, CGPSC** | `member.test@cgpsc.gov.in`, `9000000301` | — | **Result approval**: only role `member` may approve (`config/dpc.php:540`) | Global |
| B10 Record Reasons, Sealed Cover Reasons | `/admin/record-reasons`, `/admin/sealed-cover-reasons` | seeded by migration | — | — | Missing CR; sealed cover | Global |

**Not Admin masters:**
- **Recruitment Rule:** created by the AH's Pre-DPC approval; there is no screen.
- **Vacancy record:** kept by the department AO; the Admin view is read-only.

**Optional:** Pay Scales, DPC Committee Rules (default 3–6 members besides the Chairman), reservation policy (PH 3 % by migration), CGPSC Staff and Previous DPC records.

---

## 3. PART 2: Department Registration as deployed (`eed36c0`)

| # | Question | Answer from `eed36c0` |
|---|---|---|
| 1 | URL | **`/register`** (top bar **Register**). `GET` → `RegistrationController::create`; `POST` → `store`. |
| 2 | Form | One page: Department (dropdown), District, Place, PIN, Department contact e-mail, then **Administrative Head (Level-2)**: Name, Designation, Mobile, E-mail. Then **Authorized Officer (Level-1)**: Name, Designation, Mobile No., E-mail. Then one **Register** button. (`resources/views/auth/register.blade.php` at `eed36c0`) |
| 3 | AH and AO together? | **Together, in one submission.** Both accounts are created at once (`RegistrationService::register`). |
| 4 | Fields | All required (`RegisterDepartmentRequest`). The full list is in the field table below. |
| 5 | OTP process | After **Register**, the app sends OTPs **only to the AO**: one to the AO's e-mail and one to the AO's mobile (`VerificationService::start`). It redirects to **`/verify`**, where both codes are entered. **The AH is not verified at registration.** The AH meets the same OTP screen at their first login (`LoginController` :69-79). OTPs expire after `DPC_OTP_TTL` (600 s), with at most 5 attempts. |
| 6 | Aadhaar OTP? | **No.** Only an **e-mail OTP + a mobile (SMS) OTP**. Aadhaar does not exist in this version. |
| 7 | How accounts are created | `RegistrationService::createOfficer` creates each officer with a random 12-character password and `must_change_password = true`, and posts them to the department through `DepartmentAssignmentService::assign` (role AO or AH). `CredentialsIssued` then sends "Login ID + temporary password" by SMS and e-mail (queued; see E2–E4). |
| 8 | Department status | **Unregistered → Registered** on Register (`RegistrationService` :53). **Registered → Active** when the AO's OTPs are verified (`VerificationController::activateDepartmentIfRegistrant`). |
| 9 | Admin approval? | **None in the code.** The form's own text says "The Commission then approves the registration and issues credentials", but no approval step exists. Credentials are issued at Register. |
| 10 | How the AO and AH log in | `/login` with **e-mail or 10-digit mobile** (or employee number) and the temporary password (`LoginController::store`). **AO:** already verified, so goes straight in. **AH:** not yet verified, so is sent to `/verify` with new OTPs (e-mail + mobile) and is signed in after entering them. Both are then forced to **change the password** (8+ characters, mixed case, number, symbol; `ChangePasswordRequest`). |

**Registration fields** (`RegisterDepartmentRequest` at `eed36c0`):

| Field | Rule |
|---|---|
| `department_id` | must be an Unregistered department |
| `district_id` | — |
| `place` | — |
| `pin` | 6 digits |
| `department_email` | `.gov.in` |
| `ah_name`, `ao_name` | — |
| `ah_designation_id`, `ao_designation_id` | must exist |
| `ah_mobile`, `ao_mobile` | 10 digits, **unique** among users; the AO mobile must differ from the AH mobile |
| `ah_email`, `ao_email` | `.gov.in`, **unique**; the AO e-mail must differ from the AH e-mail |

Two consequences:
- An officer who already has an account **cannot** be registered again.
- A department already Registered or Active is no longer offered on the form.

**Example values:** Department `PHE`; District Raipur; Place `Nava Raipur Atal Nagar`; PIN `492002`; e-mail `phe.dpc@cg.gov.in`.
- AH: `Shri P. K. Head (Test)`, Secretary, `9000000302`, `ah.phe@cg.gov.in`.
- AO: `Shri S. K. Officer (Test)`, Deputy Secretary, `9000000303`, `ao.phe@cg.gov.in`.
- Check on UAT that these mobiles and e-mails are unused.

**Route test confirmed** (on the `eed36c0` copy):
- Register → 302 to `/verify`; department **registered**.
- AO OTPs → 302 to `/login`; department **active**.
- AO login → dashboard.
- AH login → `/verify` → OTPs → dashboard.

---

## 4. PART 3: The first DPC

Each step lists: login role → menu or URL → button → input → result → next role.

1. **Pre-DPC (AO).**
   - Dashboard → **Pre-DPC Proposals** → **+ New Proposal** (`/pre-dpc/create`).
   - Fields:
     - DPC Year (2019 to next year);
     - Promotion To Post (class and pay scale fill in);
     - for each feeder: From Post, Share %, Minimum experience and unit, **at least one Qualification**;
     - Direct %;
     - Promotion %;
     - GO number and Effective date (required to submit).
   - Rules (`SavePreDpcProposalRequest`): Direct + Promotion = 100; feeder shares = 100; feeder below the To Post; no repeated feeder; one active order per department, To Post and year.
   - Example: 2026; Executive Engineer ← Assistant Engineer 100 %, 5 years, B.E.; **Direct 0 / Promotion 100**; `F-1/2026/PHE/RR`, 01-01-2026.
   - **Save** → **Draft** (`PDPC-2026-PHE-0001`) → **Submit for Approval** → **Submitted**.
   - No documents. Next: AH.
2. **AH approval.** Pre-DPC → open → **Approve & Sign** → **Approved**, active. Or **Return for Correction** with remarks. Blocked if E1 applies.
3. **Recruitment Rule.** It is created by the approval (`PreDpcProposalService::projectToMasters` :612): version 1 for Executive Engineer, promotion 100 / direct 0, with feeder, experience and qualification. There is no screen. It is visible on the vacancy record and on the DPC Proposal edit form.
4. **Vacancy record (AO).**
   - Top bar → **Vacancy Management** → Post *Executive Engineer*, Year *2026* → **Start draft**.
   - **Sanctioned strength:** total, order number, order date ≤ today, **order PDF** (≤ 10 MB), remarks.
   - **Working strength:** total, promotion, direct, deputation (all three or none, adding up to the total), order number, date, **PDF**.
   - Entries: optional.
   - **Finalize vacancy position.** Example: 10; 7 = 7 + 0 + 0 → promotion vacancy **3**.
   - Finalize is refused without a Recruitment Rule in force on 1 January (`no_rule`), so this step comes after the Pre-DPC.
5. **DPC Proposal (AO).**
   - Dashboard → **DPC Proposals** → **+ New Proposal**.
   - Fields: approved Pre-DPC, type **Fresh**, legal stay No. The Recruitment Rule, vacancy record and Class Master rule are **picked by the system**.
   - Result: **Draft** `DPC-2026-PHE-0001`.
6. **Committee (AO).** Proposal → DPC Committee.
   - The CGPSC Chairman is seated automatically.
   - Add 3–6 members, each with: name (or search an existing account), designation, mobile, `.gov.in` e-mail, and category ticks.
   - Coverage: SC, ST, OBC (one per member), Female, Bhar Sadhak Sachiv, Vibhag Sachiv.
   - **Seat the Member account from B9** (search its e-mail).
7. **CSVs (AO).**
   - First: Proposal → Working Officers → **Upload & Preview** → **Commit**. The counts must equal the vacancy record (7 / 7 / 0 / 0).
   - Then: Proposal → Seniority List → **List as on 01-04-2025** → **Upload & Preview** → **Commit**.
   - The files are in `docs/new-dpc-guide/` (`new_dpc_working_officers.csv`: 7 working + 3 vacant; `new_dpc_seniority_list.csv`: 10 candidates). Both passed on `eed36c0`.
   - Limits: 5 MB and 500 rows. Dates `DD-MM-YYYY`. A Seniority List with any rejected row is blocked. A Working Officers list commits its valid rows only. Every commit replaces the list.
8. **Employee Details (AO),** for each of the 10 officers (Proposal → Employee Details → officer):

   | Section | What to enter | Documents (tick "verified" on each upload) |
   |---|---|---|
   | Basic | DOB order no. and date; joining order no. and date; undertaking | — |
   | CR 2021–2025 | put up → graded → report(s) | CR report per year; one CR channel document |
   | IPR 2021–2025 | submitted, date, next increment date | IPR document per year |
   | Sealed cover | No | — |
   | PH | No | — |
   | Declarations | No / No | — |

   Missing years go to **Missing Records** (reason plus document).
9. **Loyalty (AO), required.** Proposal → **Evaluation Chart** → Loyalty **Y** for 2021–2025 for every officer → Save. Keep the CR and property values the screen shows.
   - Without this, every officer is **Not Eligible** at calculation (`CandidateGateService` :340-362).
   - Employee Details has no loyalty section, and submission does not check it.
10. **Rule 1.1 (AO).** Proposal → Verification → confirm:
    1. sanctioned strength;
    2. Recruitment Rule;
    3. seniority list;
    4. previous DPC (none, with remarks);
    5. representations;
    6. original documents;
    7. RR conformity (notified on 01-01-2026).
11. **Submit (AO).** **Submit for Approval** → **Submitted**. It is refused while any section is incomplete, and the refusal names the section.
12. **AH.** Employee Details → **Verify all** for each officer; decide any PH claim → **Approve, Sign & Forward to CGPSC** → **Approved**, forwarded. Before the verifications it is refused with ":count document(s) are still to be verified by the Administrative Head…". E1 applies here too.
13. **CGPSC.**
    1. Chairman: **Allotments** → Allot to the Member; DPC Chairman = that Member's seat; complete by ≥ today.
    2. Member: **Schedules** → **Accept DPC** → **Schedule** (date and time).
    3. Member: **Proceedings** → **Open Proceeding** → **CALCULATE** → **APPROVE BY CGPSC** (promotion as on 2026).
    - The Chairman's own account is refused at approval: role `member` is required.
14. **Promotion Order (AH).** Proposal → Promotion Order → **Issue order**: number, date ≤ today, confirm.
    - The PDF is English only and signed with the mock signer.
    - The link goes to officers and committee members by SMS (log only), e-mail and in-app, all queued (E2–E4).

---

## 5. Known limits at `eed36c0`

| Limit | Effect | Reference |
|---|---|---|
| Loyalty demanded for every graded CR year | Whatever the rule says, there is no Employee Details entry for it. Use the Evaluation Chart (step 9). | `CandidateGateService` :340-362 |
| Only `member` approves a result | — | `config/dpc.php:540` |
| Qualification not recorded on officers | The result is **provisional**, which blocks publishing the proceeding documents but not the order | `DpcCalculationService` :539-580; `PromotionOrderService::issue` :82-94 |
| Re-allotment | Only while the allotment is still pending | `config/dpc.php:478` |
| NOT IMPLEMENTED | Recruitment Rule screen; Admin-created vacancy record; Pre-DPC documents; result-approval signature; real CSC DigiSign; SMS gateway; Hindi order PDF; Admin approval of registration (despite the form's text) | — |

---

## 6. Test results on the `eed36c0` copy (in-memory SQLite; browser NOT TESTED)

| Step | Route test |
|---|---|
| 1.1 Department · 1.2 Designations · 1.3 Posts · 1.5 Promotion CR Rule · 1.8 Member account | PASS ×5 |
| 2 Registration: Register → AO OTP → AO login → AH login with OTP → password change | PASS |
| 3 Pre-DPC create, submit, approve; Recruitment Rule 100/0 | PASS |
| 4 Vacancy record 10/7, finalize (promotion vacancy 3) | PASS |
| 5.1 DPC Proposal · 5.2 Committee | PASS ×2 |
| 6 Working Officers + Seniority List CSV (0 rejected each) | PASS |
| 7 Employee Details, 10 officers (110 documents) · 7b Rule 1.1 · 7c Loyalty | PASS ×3 |
| 5.3 Submit | PASS |
| 8a AH approve refused before verification · 8b Verify all + approve | PASS ×2 |
| 9.1 Allot · 9.2 Accept · 9.3 Schedule · 9.4 Open · 9.5 Calculate (10 eligible, Main 3, Waiting 7) | PASS ×5 |
| 9.6a Chairman approve refused · 9.6 Member approves | PASS ×2 |
| 10 Promotion order: signed PDF, 3 officers | PASS |
| **On UAT itself** | **NOT TESTED**, and it depends on E1–E5 |
