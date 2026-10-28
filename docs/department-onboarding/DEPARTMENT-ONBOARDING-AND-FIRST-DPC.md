# A New Department on the CGPSC DPC Portal: Onboarding and First DPC

**Prepared:** 2026-10-23.

**Method:**
- The code was audited read-only (`main` at `eed36c0`, plus the uncommitted working tree that the dev server runs).
- No code was changed, and nothing was written to the dev or UAT database.

**Example used:**
- A **hypothetical new department**, Public Health Engineering (`PHE`), which does not exist on dev.
- Its values are chosen to pass the code's validation.

**Testing:**

| Kind | Status |
|---|---|
| **Automated route test** | The whole path in §4, from Admin masters to the promotion order, was run through the real HTTP routes on an **in-memory SQLite** database, using a throwaway test kept outside the repository. Result: **25 of 25 steps passed** (§4). |
| **Browser test** | **Not done** for any step. |

---

## 1. Dependency chart

```
A. ADMIN, before registration                  B. ADMIN, before the first DPC
   Department (+ district, place, PIN, e-mail)    Designations for post names
   [District, Designation masters: seeded]        Department Posts (From + To, Active, classes)
              │                                   Qualifications (Active)
              ▼                                   Promotion CR Rule (class pair, year)
   DEPARTMENT REGISTRATION (public)               CR Grades (valid on 1 Jan of the year)
   AH + AO in one form, Aadhaar + e-mail OTP      DPC Type "Fresh"; CGPSC Chairman linked to an account
              │                                   CGPSC Member account (role member)
              ▼                                                │
   AO: PRE-DPC ──► AH: approve & sign ──► Recruitment Rule (system) │
              │                                                │
              ▼                                                │
   AO: VACANCY RECORD (sanctioned, working, finalize)          │
              │                                                │
              ▼                                                ▼
   AO: DPC PROPOSAL ─► Committee ─► Working Officers CSV ─► Seniority List CSV
        ─► Employee Details (x every officer) ─► Loyalty on Evaluation Chart
        ─► Rule 1.1 (7 items) ─► Submit
              │
              ▼
   AH: verify every document + PH ─► Approve, Sign & Forward
              │
              ▼
   CGPSC: Chairman allots to a seated Member ─► Member accepts ─► schedules
        ─► opens proceeding ─► calculates ─► approves result (role member)
              │
              ▼
   AH: Promotion Order (signed PDF, link to officers and committee)
```

---

## 2. PART 1: Admin prerequisites

Admin screens are at `/admin/...` and need role `admin` (`routes/web.php:639-853`). Sidebar: `resources/views/layouts/admin.blade.php`.

**Every master form defaults to Status = Draft. Choose Active**, or the record is not offered later.

### A. Mandatory before Department Registration

#### A1. Department Master

| | |
|---|---|
| Menu / URL | Departments → `/admin/departments/create` |
| Fields | **Code** (required, A–Z/0–9, ≤ 20, unique; becomes the prefix of the Pre-DPC and DPC numbers). **Name** (required, unique). **Status** (required; Unregistered / Registered / Active). District, Place, PIN (6 digits), Contact e-mail: optional on this form. |
| Example | `PHE`, `Public Health Engineering Department`, **Unregistered**, Raipur, `Nava Raipur Atal Nagar`, `492002`, `phe.dpc@cg.gov.in` |
| Depends on | District master (seeded) |
| Active needed? | Status must be such that registration offers it (see below) |
| Validated at | Registration: the department dropdown; the master details are checked on Register |
| Scope | **Per department** |
| If missing | The department is not in the registration dropdown, so it cannot register |

References: `DepartmentRequest::rules` :36-47.

Which registration version applies matters here:
- **Working tree (what dev runs):** every department is listed. District, place, PIN and e-mail are shown read-only from this master, and registration is refused if any is blank: "The Department Master does not yet hold this department's district, place, PIN and contact e-mail. Ask the CGPSC Administrator to complete them." (`Department::hasRegistrationDetails`, `RegisterDepartmentRequest::after`).
- **Committed `main`:** only *Unregistered* departments are listed, and the registrant types district, place, PIN and e-mail on the form.

#### A2. Designations (for the AH's and AO's own designation)

| | |
|---|---|
| URL | `/admin/designations` |
| Fields | Name (required, unique), Code (optional), Status |
| Scope | Global |
| Validated at | Registration: `{seat}_designation_id` must exist |
| If missing | A validation error on the designation field |
| On dev | 26 designations are seeded (e.g. Secretary, Deputy Secretary) |

#### A3. System prerequisites (seeded, not Admin screens)

- Roles: `RolesAndPermissionsSeeder`.
- Districts.

### B. Mandatory before the first DPC

| # | Master | Menu / URL | Required fields (example) | Depends on | Active needed | Validated at | Scope | If missing: exact blocker |
|---|---|---|---|---|---|---|---|---|
| B1 | **Designations (post names)** | `/admin/designations` | Name, Status (`Assistant Engineer` Active; `Executive Engineer` Active) | — | Yes | Department Post form (the post name is chosen from Active designations, `DepartmentPostRequest` :43-59) | Global | The post cannot be created; the name is not in the list |
| B2 | **Department Posts** (From and To) | `/admin/department-posts/create` | Department, Post Name, **Class** (I/II/III), Status **Active**, **Effective From**. Optional: Pay Scale, Promotion Duration, Government Orders (PDF ≤ 10 MB). (`Assistant Engineer`, II, L12, 5 yrs, Active, 01-01-2026; `Executive Engineer`, I, L13, Active, 01-01-2026) | Department, Designations, Class Master, Pay Scales | Yes (Active sets `active_flag`) | Pre-DPC To/From lists; hierarchy check | Per department | Pre-DPC: "Your department has no posts registered yet." Save disabled. A feeder not below the To Post gets the `feeder_not_below_post` message. |
| B3 | **Class Master** | `/admin/service-classes` | Seeded I, II, III (sort 10/20/30) | — | Yes | Post class, rule pairs | Global | — (seeded) |
| B4 | **Qualifications** | `/admin/qualifications` | Code, Name (English), Name (Hindi), Status (`B.E.` is seeded) | — | Yes | Pre-DPC: at least one per feeder | Global | "Choose at least one qualification this feeder accepts." |
| B5 | **CR Grades** | `/admin/cr-grades` | Value, Valid from, CR grade code (seeded 9/7/5/3/1 = क+/क/ख/ग/घ from 2019-01-01) | — | Valid on 1 Jan of the year | Promotion rule form; calculation | Global | Rule form: `no_grades`. Calculation: "No Eligible CR Grade with a CR grade is valid on 1 January :year…" |
| B6 | **Promotion CR Rule** | Class Master → **Class-I** → Promotion rules → `/admin/service-classes/{id}/promotion-rules/create` → **Save rule** | From class (II); Applies from year (2026); CR years (5); Loyalty years; Property years (5); per-year passing marks (5) and mandatory ticks; Qualifying total (25); Super-seat (9); Tribal bonus (0) | CR Grades | — (append-only; the newest wins) | DPC create; calculation | **Global per class pair** | DPC create: "No Class Master promotion rule for Class II → Class I is in force in 2026, so this DPC cannot be raised…" |
| B7 | **DPC Types** | seeded (`LookupMasterSeeder`) | Fresh / Review / Circulation | — | Yes | DPC create (`fresh` only) | Global | DPC create validation error |
| B8 | **CGPSC Chairman** | `/admin/cgpsc-chairman` | Name, designation, effective from, **linked user account** (optional in the form, but needed in practice) | A `chairman` user | Serving | Committee save; allotment | Global | Committee: "No CGPSC Chairman is recorded… Record the serving Chairman under Administration → CGPSC Chairman" |
| B9 | **CGPSC Member account** | User Management → `/admin/users/create` | Name, `.gov.in` e-mail, mobile, designation, Role **Member, CGPSC** | — | Active | **Result approval** | Global | Approval: "You are not permitted to perform [approve_result]." Only role `member` may approve (`config/dpc.php:540`). |
| B10 | Record Reasons, Sealed Cover Reasons | `/admin/record-reasons`, `/admin/sealed-cover-reasons` | Seeded by migration | — | Yes | Missing CR; sealed cover | Global | Only needed when a gap or enquiry is recorded |

Notes on these rows:
- **B6 rules are global and cannot be edited.** A new rule for a class pair and year becomes the rule for **every** department's DPC raised after it (`PromotionCrRules::ruleFor` :146). The rule is fixed on a DPC when the DPC is raised.
- **B6 loyalty:** see the defect in §5.1.
- **B9 password delivery:** the password is sent by a queued listener; see §5.4.

### C. Optional or later-stage

| Master | Why optional | Reference |
|---|---|---|
| Pay Scales (`/admin/pay-scales`) | Optional on a post. The hierarchy check uses level and grade pay only if they are set, and only the CSV import sets them. | `PromotionHierarchyService::verdict` |
| DPC Committee Rules (`/admin/committee-configurations`) | Falls back to config: 3–6 members besides the Chairman, with SC, ST, OBC, Female, Bhar Sadhak Sachiv and Vibhag Sachiv coverage | `CommitteeRuleResolver` |
| Reservation categories and policies | PH 3 % is guaranteed by migration | migration `2026_10_17_500001` |
| CGPSC Staff account and Previous DPC records (`/previous-dpcs`) | Only when a department cites an offline previous DPC | `PreviousDpcRecordController` |
| Additional designations for committee members | A committee member may also carry a free-text designation | `SaveDpcCommitteeRequest` |
| Recruitment Rule | **Not an Admin master.** It is created by the AH's Pre-DPC approval (§3, step 3). There is no screen. | `PreDpcProposalService::projectToMasters` :612 |
| Vacancy record | **Not Admin.** The department AO creates it; the Admin can only view it. | `VacancyMasterController::canManage` :320 |

---

## 3. PART 2: The new department's first DPC

### Answers to the ten questions

1. **AH and AO registration sequence.** One public form at `/register`, with the AH section first and the AO section second. Each officer goes through: e-mail (a known officer's name fills in) → designation → mobile → Aadhaar → **Send OTP** → Aadhaar OTP + e-mail OTP → **Verify OTP**. Then one **Register** button. A first registration needs **both** seats. There is no approval step afterwards. *(Working-tree flow; committed `main` registers both on one form and sends OTP to the AO after submit.)*
2. **Can the department start immediately?** Yes, once Category B is in place. Each officer must first change the system password at first login (`must_change_password`). The AO can then open Pre-DPC.
3. **Is a Pre-DPC mandatory?** **Yes.** A DPC Proposal can only be raised from an **active, approved** Pre-DPC of the department (`SaveDpcProposalRequest`; `DpcProposalController::store` :117).
4. **What the AO fills in a Pre-DPC:**
   - DPC year;
   - Promotion To Post (class and pay scale auto-fill);
   - each feeder: From Post, share %, minimum experience, qualification(s);
   - Direct % and Promotion % (must total 100);
   - Government Order number and effective date (needed to submit).
   - No documents.
5. **How the Recruitment Rule is generated.** When the AH presses **Approve & Sign**, `PreDpcProposalService::approve` signs the document, makes the order active, and creates a **new Recruitment Rule version** for the To Post with the split, feeders, experience and qualifications (`projectToMasters` :612-651).
6. **Vacancy record: when and how.** It comes **after** the Pre-DPC is approved, because finalize needs a Recruitment Rule in force on 1 January. The AO goes to top bar **Vacancy Management** → post and year → **Start draft**, then:
   - enters the sanctioned strength with its order PDF;
   - enters the working strength (total + promotion + direct + deputation) with its order PDF;
   - presses **Finalize** (`VacancyMasterService::finalize` :789).
7. **When a DPC Proposal can be created.** Once the Pre-DPC is approved and a Class Master rule exists for the class pair and year. The vacancy record is read later, by the proposal's vacancy section, not at create time.
8. **When the CSVs are uploaded.** On the DPC Proposal while it is Draft or Returned, by the AO:
   - **Working Officers first.** Its counts must match the finalized vacancy record.
   - **Seniority List next.** It is hidden until that match, and the "List as on" date must be 1 April of the year before.
9. **When Employee Details and verification are done.** After the Seniority List commit, which creates one evaluation row per officer:
   - the AO completes every officer's Employee Details, ticking "verified" on each upload (AO verification);
   - the AO records Loyalty on the Evaluation Chart (§5.1);
   - the AO confirms the 7 Rule 1.1 items, then submits;
   - the AH verifies every document and decides PH claims while the proposal is Submitted, then approves.
10. **How the proposal reaches the CGPSC.** The AH's **Approve, Sign & Forward to CGPSC** sets status Approved and `forwarded_at` (`DpcProposalService::approve` :657-684). The proposal then appears on the CGPSC Chairman's **Allotments** screen. Nothing else forwards it.

### Step by step (example: PHE, Assistant Engineer II → Executive Engineer I, 2026)

| # | Login role | Menu / URL | Button | Required input (example) | Documents | Status after | Next role | Blocker if a prerequisite is missing |
|---|---|---|---|---|---|---|---|---|
| 1 | — | `/register` | Send OTP, Verify OTP (×2), **Register** | AH `ah.phe@cg.gov.in`, AO `ao.phe@cg.gov.in`, designations, mobiles, dummy Aadhaar; OTP `123456` on dev | none | Department **Registered**; 2 accounts | AH, AO | Master details blank: refused (A1) |
| 2 | AO, AH | first login | change password | new password: 8+ characters, mixed case, a number, a symbol | — | — | AO | No password arrived: §5.4 |
| 3 | AO | Dashboard → Pre-DPC Proposals → **+ New Proposal** | Save → **Submit for Approval** | 2026; To *Executive Engineer*; From *Assistant Engineer* 100 %, 5 years, *B.E.*; Direct 0 / Promotion 100; GO `F-1/2026/PHE/RR`, 01-01-2026 | none | **Submitted** | AH | No posts (B2) / no qualification (B4) |
| 4 | AH | Pre-DPC → open | **Approve & Sign** | — | — | **Approved**, active; **Recruitment Rule v1 (100/0)** | AO | Production without DigiSign: refused |
| 5 | AO | Vacancy Management → Executive Engineer, 2026 | Start draft → save each step → **Finalize vacancy position** | Sanctioned **10**; working **7 = 7 + 0 + 0** | sanctioned-strength order PDF; working-strength order PDF | **Finalized**; promotion vacancy **3** | AO | No Recruitment Rule: `no_rule` |
| 6 | AO | DPC Proposals → **+ New Proposal** | Save | Approved Pre-DPC; type Fresh; legal stay No | — | **Draft** (`DPC-2026-PHE-0001`) | AO | No Class Master rule (B6) |
| 7 | AO | Proposal → DPC Committee | Save | 4 members: the Member account (SC); ST + Female; OBC + Bhar Sadhak Sachiv; Vibhag Sachiv. The Chairman is seated automatically. | — | Committee complete | AO | No Chairman (B8) |
| 8 | AO | Proposal → Working Officers → **Upload & Preview** → Commit | — | `new_dpc_working_officers.csv` (7 working + 3 vacant) | — | Committed; reconciled 7/7/0/0 | AO | Counts differ from the record |
| 9 | AO | Proposal → Seniority List → List as on **01-04-2025** → **Upload & Preview** → Commit | — | `new_dpc_seniority_list.csv` (10 candidates) | — | Committed; 10 evaluation rows | AO | Working list not reconciled |
| 10 | AO | Proposal → Employee Details → each officer | Save per section | DOB and joining orders + undertaking; CR 2021–2025 (grade क); IPR 2021–2025; sealed cover No; PH No; declarations No/No | CR report ×5, IPR ×5, CR channel ×1 per officer (each with the "verified" tick) | All sections complete | AO | — |
| 11 | AO | Proposal → **Evaluation Chart** | Save | **Loyalty Y for 2021–2025 for every officer** (keep CR and property as shown) | — | — | AO | **Without this every officer is NE at calculation (§5.1)** |
| 12 | AO | Proposal → Verification | Confirm ×7 | Previous DPC: none + remarks; RR notified on 01-01-2026 | — | Rule 1.1 complete | AO | — |
| 13 | AO | Proposal | **Submit for Approval** | — | — | **Submitted** | AH | Any incomplete section is named in the refusal |
| 14 | AH | Proposal → Employee Details → each officer | **Verify all** | Decide any PH claims | — | 0 documents pending | AH | Approving earlier: ":count document(s) are still to be verified by the Administrative Head…" |
| 15 | AH | Proposal | **Approve, Sign & Forward to CGPSC** | — | — | **Approved**, forwarded | CGPSC Chairman | — |
| 16 | CGPSC Chairman | Allotments | **Allot** | Allotted to the Member; DPC Chairman = that member's seat; complete by ≥ today | — | Allotted | Member | Allottee not a seated member with an account |
| 17 | Member | Schedules | **Accept DPC** → **Schedule** | Date and time ≤ deadline | — | Scheduled | Member | — |
| 18 | Member | Proceedings | **Open Proceeding** → **CALCULATE** | — | — | Result: Main / Waiting | Member | No rule or grade points |
| 19 | Member | Result | **APPROVE BY CGPSC** | Promotion as on year 2026 | — | **Result approved** | AH | Not role member: refused |
| 20 | AH | Proposal → Promotion Order → Open | **Issue order** | Order number, date ≤ today, confirm | (PDF generated) | Order **Issued**, signed | — | Result not approved / nobody promoted |

---

## 4. Verification results

### Automated route test (in-memory SQLite, real routes, actors signed in by role)

| Step | Result | Notes |
|---|---|---|
| 1.1 Admin creates department | **PASS** | |
| 1.2 Admin creates 2 designations | **PASS** | |
| 1.3 Admin creates 2 department posts | **PASS** | |
| 1.5 Admin records Promotion CR Rule II→I 2026 | **PASS** | |
| 1.8 Admin creates CGPSC Member account | **PASS** | |
| 2 Registration (Aadhaar + e-mail OTP) | **PASS** | Department Registered |
| 3 Pre-DPC create, submit, approve; RR created | **PASS** | RR v1 100/0 |
| 4 Vacancy record 10/7 via AO routes, finalize | **PASS** | Promotion vacancy 3 |
| 5.1 DPC Proposal raised | **PASS** | RR and Class Master rule fixed automatically |
| 5.2 Committee (4 members, coverage) | **PASS** | |
| 6 Both CSVs preview and commit | **PASS** | 0 rejected each |
| 7 Employee Details, 10 officers via routes | **PASS** | 110 documents uploaded |
| 7b Rule 1.1, 7 items | **PASS** | |
| 7c Loyalty on Evaluation Chart | **PASS** | Required, see §5.1 |
| 5.3 AO submit | **PASS** | |
| 8a AH approve before verification is refused | **PASS** | "110 document(s) are still to be verified…" |
| 8b AH Verify all and Approve & Forward | **PASS** | |
| 9.1 Chairman allots to Member | **PASS** | |
| 9.2 Member accepts | **PASS** | |
| 9.3 Member schedules | **PASS** | |
| 9.4 Member opens proceeding | **PASS** | |
| 9.5 Member calculates | **PASS** | 10 considered, 10 eligible, **Main 3**, Waiting 7, provisional = true (qualification advisory, §5.3) |
| 9.6a Chairman account approves result | refused, as expected | Not role member |
| 9.6 Member approves result | **PASS** | |
| 10 AH issues promotion order | **PASS** | Signed PDF, 3 officers |

The same test **without step 7c** fails at step 10: "The approved result promotes nobody". At step 9.5 all 10 officers were **NE** with "The Loyalty Certificate for 2021 … is not recorded".

### Browser

**NOT TESTED** for every step above. In particular, the JavaScript parts have not run in a browser:
- registration OTP buttons;
- Pre-DPC share total;
- committee member search;
- the Evaluation Chart form.

---

## 5. Findings that affect a first DPC (no code changed)

### 5.1 Loyalty is required for every graded CR year, whatever the Class Master rule says

`CandidateGateService` :340-362 makes an officer NE when a Loyalty Certificate is missing for **any graded CR year**. It ignores the rule's "Years of Loyalty Certificate", and our test rule had 0. Three gaps compound it:
- Employee Details has **no loyalty section**.
- Its completeness check does not mention loyalty.
- Submission is therefore allowed, and every officer then fails at calculation.

**Workaround in the current code:** the AO records Loyalty per year on DPC Proposal → **Evaluation Chart** before submitting. That screen re-saves CR grades and property flags with it, so keep the values it shows.

### 5.2 Only the `member` role can approve a CGPSC result

`config/dpc.php:540`; `WorkflowEngine::assertAllowed` :86. A Member account (B9) must be seated on the committee and allotted the DPC. The Chairman's own account is refused unless it also holds `member` and is switched to that role *(inferred)*.

### 5.3 Qualification advisory makes the result provisional

The Recruitment Rule requires B.E. The Seniority List carries no qualification, and the AO did not enter one in Basic Information. So every officer is flagged and the result is **provisional**.
- This blocks **publishing the proceeding documents**.
- It does **not** block the promotion order (`PromotionOrderService::issue` :82-94).
- Entering each officer's qualification in Employee Details → Basic Information avoids it *(inferred from the advisory text)*.

### 5.4 No queue worker on dev

Temporary passwords, sitting notices and promotion-order links are all queued (`QUEUE_CONNECTION=redis`), and no worker is running. Either run `php artisan queue:work`, or use Admin → User Management → **Reset Password**, which shows the password once.

### 5.5 Other limits

- **Re-allotment** only works while the allotment is pending.
- **Signatures:** there is no signature at result approval. Real CSC DigiSign, the SMS gateway and a Hindi order PDF are NOT IMPLEMENTED.
- **Production signing:** on a production environment with DigiSign off, both AH approvals (Pre-DPC and DPC) are refused.
