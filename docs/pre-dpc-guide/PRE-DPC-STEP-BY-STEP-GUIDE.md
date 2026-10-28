# Pre-DPC to Promotion Order: Step-by-Step Guide

This guide describes only what is implemented in the code on 2026-10-23.

- Anything absent from the code is marked **NOT IMPLEMENTED**.
- References point to the code that enforces each step.
- What is complete and what is open is listed in `PRE-DPC-COMPLETE-STATUS.md`.
- The upload rules are in `PRE-DPC-CSV-VALIDATION-RULES.md`.

**Roles**

| Role | Who |
|---|---|
| Admin | CGPSC Administrator (`admin`) |
| AO | Authorized Officer of the department, Level-1 (`authorized_officer`) |
| AH | Administrative Head of the department, Level-2 (`administrative_head`) |
| CGPSC Chairman | `chairman` |
| Member | `member` |
| CGPSC Staff | `cgpsc_staff` |

**Navigation**
- The AO and the AH work from the **Dashboard tiles**: "Pre-DPC Proposals", "DPC Proposals", "Upload Templates". The top bar holds "Vacancy Management" and "Reports". There is no Pre-DPC entry in the top bar (`resources/views/layouts/app.blade.php:38-60`, `dashboard.blade.php:73-89`).
- The Admin works from the **Admin sidebar** (`resources/views/layouts/admin.blade.php`).

---

## The order of work

```
Admin masters ──► AO: Pre-DPC ──► AH: approve Pre-DPC (creates the Recruitment Rule)
                                          │
AO: Vacancy Management record (finalize) ◄┘
          │
AO: DPC Proposal ─► committee ─► Working Officers ─► Seniority List ─► Employee Details
          │          (CR, missing CR, sealed cover, PH, declarations, IPR, documents)
          ├─► Rule 1.1 checklist ─► AO submits ─► AH verifies documents and PH ─► AH approves and forwards
          ▼
CGPSC: allot ─► accept/schedule ─► proceeding ─► calculate ─► approve result ─► AH issues promotion order
```

Two facts decide this order:
- **A Recruitment Rule exists only after a Pre-DPC is approved.** There is no Admin screen for it (`PreDpcProposalService::projectToMasters` :612).
- **A DPC Proposal can only be raised from an active, approved Pre-DPC** (`SaveDpcProposalRequest`, `DpcProposalController::store` :117).

---

## 1. Department Master prerequisites (Admin)

**Admin → Departments** (`admin.departments`, `web.php:681`).
- The department needs a code, used in the Pre-DPC number `PDPC-<year>-<CODE>-<serial>`.
- It also needs a name.
- For registration it needs district, place, PIN and contact e-mail.

**AO and AH accounts** come from **Department Registration** (`/register`).
- The committed version creates both officers in one form and verifies the AO by OTP.
- The uncommitted version follows the CGPSC mock-up; see `docs/DEPARTMENT-REGISTRATION.md`.
- The Admin can also post a user to a *Registered* department from User Management (`UserAssignmentController`).

If the AO has no department, creating a Pre-DPC returns 403 (`PreDpcProposalController::store` :59).

## 2. Post Master prerequisites (Admin)

**Admin → Department Posts** (`admin.department-posts`, `web.php:747-763`). For each post:

| Field | Why it matters |
|---|---|
| Name | Shown everywhere. It must equal the **CURRENT POST** in the Seniority List file, compared ignoring case and spaces. |
| Class (I / II / III) | Pre-DPC hierarchy check (a feeder must be below); Class Master rule lookup; zone formula. |
| Pay scale | Auto-filled on the Pre-DPC; optional. |
| Promotion duration (years) | Auto-filled per feeder; optional. |
| Effective from, and optional Government Order(s) | Required date; orders are optional on create. |
| Status **Active** | Saving as Active puts the post in force (`DepartmentPost::booted` sets `active_flag`). Only in-force posts are offered (`DepartmentPost::inForce()`). |

The sanctioned strength is **not** entered on the post (since 2026-10-17). It is recorded per year, with its order, in the AO's Vacancy Management record (§5; `DepartmentPostRequest` comment at :101-105).

If the department has no posts, the Pre-DPC form shows "Your department has no posts registered yet" and Save is disabled (`pre-dpc/form.blade.php:40-45`).

Also needed: **Admin → Qualifications**. Every feeder on a Pre-DPC must name at least one active qualification.

## 3. Recruitment Rules

- **There is NO Admin screen for Recruitment Rules.**
- A rule is created as a new version when the **AH approves a Pre-DPC**. It takes the direct/promotion split, the feeders, experience and qualifications from the Pre-DPC (`projectToMasters` :612-651).
- The DPC Proposal records the rule in force when it is raised (`DpcProposalService::ruleInForce` :246).

## 4. Promotion CR Rules (Admin)

**Admin → Service Classes → Promotion rules** (`admin.service-classes.promotion-rules.*`, `web.php:700-705`). For each class pair and effective year:
- number of CR years;
- per-year minimum and whether the year is mandatory;
- loyalty and property years;
- qualifying total;
- super-seat grade;
- tribal bonus (per year, optionally skipping the highest and lowest grade).

Without a rule for the pair and year, the DPC cannot be raised. The error reads: "No Class Master promotion rule for Class :from → Class :to is in force in :year, so this DPC cannot be raised…" (`DpcProposalService::promotionRuleFor` :74; `lang/en/dpc.php:23`).

When several rules qualify, the one with the latest effective year, and then the highest id, is used (`PromotionCrRules::ruleFor` :146).

Also needed by the calculation:
- **Admin → CR Grades** (`admin.cr-grades`): points per grade code, with validity dates.
- **Admin → Record Reasons**: why a CR year is missing, and which documents that needs.
- **Admin → Sealed Cover Reasons**.
- **Admin → Designations**: committee designations.
- **Admin → CGPSC Chairman**: the committee cannot be composed without one.

## 5. Vacancy details (AO, Vacancy Management)

Do this after the Pre-DPC is approved, because finalizing needs the Recruitment Rule.

**Top bar → Vacancy Management** (`vacancy-management.*`). The Admin's view of it is read-only.

1. **Start draft** for the post (the To Post) and year.
2. **Sanctioned strength:** total, order number, order date, order PDF.
3. **Working strength:** total, split by promotion, direct and deputation, with order number, date and PDF.
4. Record entries, if any (anticipated vacancies, higher-post entries with undertaking).
5. **Finalize vacancy position.** This is refused while any issue is open: no mapping, strength not entered, split missing, no rule, pending entries, quota overfilled (`VacancyMasterService::finalize` :789).

The DPC Proposal reads the latest **finalized** record for the same department, To Post name and year (`ProposalVacancyPosition::for` :41).

## 6. New Pre-DPC proposal

**AO:** Dashboard → **Pre-DPC Proposals** → **+ New Proposal**. The form is `pre-dpc/form.blade.php`.

| Field | Mandatory | Notes |
|---|---|---|
| DPC Year | Yes | 2019 to next year |
| Promotion To Post | Yes | In-force post of the AO's department. Class and pay scale are **auto-filled** from it. |
| For each feeder row: Promotion From Post | Yes | Must differ from the To Post, sit below it in the hierarchy, and not repeat |
| For each feeder row: Share % | Yes | Feeder shares must total 100 |
| For each feeder row: Minimum experience and unit | Yes | 0–40 |
| For each feeder row: Qualifications | Yes, at least one | From the Qualification master |
| Feeder row: promotion duration | Auto-filled | From the feeder post |
| Direct recruitment % and Promotion % | Yes | Must total exactly 100 |
| Government Order number and effective date | Needed to **submit** | Can be blank on Save |

- **Documents:** NOT IMPLEMENTED on the Pre-DPC. No upload, checklist or undertaking.
- **Save:** status **Draft**. The Pre-DPC number is issued.
- **Submit for Approval:** status **Submitted**. The AH is notified.
- **AH:** opens it and chooses one of:
  - **Approve & Sign:** status **Approved** and active. The previous active version is superseded and the Recruitment Rule is created.
  - **Return for Correction**, with remarks: status **Returned**. The AO edits and submits again.
- **AO actions:**
  - **Cancel:** from draft or returned; ends as **Cancelled**.
  - **Delete Draft:** draft only.
  - **Amend (new version):** on an approved proposal. Creates a new draft; the old version stays active until the new one is approved.
- **Production:** with `APP_ENV=production` and CSC DigiSign off, Approve is refused.

## 7. Create the DPC Proposal, then upload the Working Officers

**AO:** Dashboard → **DPC Proposals** → **New**.

| Field | Mandatory | Notes |
|---|---|---|
| Approved Pre-DPC | Yes | Active, approved, own department |
| DPC type | Yes | Only **Fresh** is accepted here |
| Promotion From Post | Yes, if the Pre-DPC names more than one feeder | — |
| Legal stay | No | — |

Everything else is copied from the Pre-DPC: year, posts, class, pay scale and duration. The Recruitment Rule and the Class Master rule are fixed on the proposal. Status: **Draft**. A DPC code is issued (`createFromPreDpc` :116-170).

**DPC Committee** (screen S15): add 3–6 members besides the CGPSC Chairman, whom the system seats.
- Each member needs a capacity and a name or linked user, and a designation.
- Mobile and `.gov.in` e-mail are optional.
- The committee as a whole must cover SC, ST, OBC, Female, Bhar Sadhak Sachiv and Vibhag Sachiv. Without this coverage the section stays incomplete at submit (`CommitteeComplianceService::findings` :67-137).

**Working Officers** (screen S16): DPC Proposal → **Working Officers** → **Upload & Preview**.
- Template: *Currently Working Officers (S16)*.
- Review the preview, then **Commit**.
- On a Vacancy Management proposal, the uploaded working counts must equal the finalized record on four figures: total, promotion, direct, deputation (`ProposalVacancyPosition::reconcile` :216).
- Manual entry is off by default (`DPC_MANUAL_WORKING_OFFICERS=false`).

## 8. Seniority List upload

DPC Proposal → **Seniority List** → enter **List as on** → **Upload & Preview** → **Commit**.

- **List as on** must be **1 April of the year before the DPC year**. For DPC 2026 that is 01-04-2025.
- The upload is hidden and refused until the Working Officers list reconciles.
- Every row's CURRENT POST must be the proposal's Promotion From Post.
- **Any rejected row blocks the whole file.**
- For a Class I→I promotion, the row count must equal the zone of consideration.
- Committing builds the evaluation rows and creates Promotion Candidate logins.

Full rules: `PRE-DPC-CSV-VALIDATION-RULES.md`.

## 9. Employee and officer data validation

DPC Proposal → **Employee Details**. This lists every officer from the Seniority List with a completeness status per section (`EmployeeDetailsService::status` :535). Each officer's screen has these sections:

| Section | What the AO enters | Documents |
|---|---|---|
| Basic Information | category (only if category-wise), qualification, mobile, date of posting, **DOB order no. and date**, **joining order no. and date**, service-record undertaking (tick) | none (decision: no PDF) |
| CR | see §10 | CR report per graded year |
| Property Return (IPR) | per year: submitted, date, next increment date. A late return needs the department's decision. | IPR file; decision document if late and accepted |
| PH Reservation | three questions | PH undertaking where required |
| Departmental Enquiry (Sealed Cover) | see §12 | order, if the reason requires one |
| Deputation and Promotion Refusal | see §16 | deputation order; refusal letter and DPC order |

## 10. CR / APAR details

For each mandatory year of the Class Master rule:

1. Was a CR put up? If no, record a reason under §11.
2. Was it graded? If no, upload the "CR without grading" document.
3. Enter up to 4 reports. The final grade of each report is the accepting officer's, else the reviewer's, else the reporting officer's. Several reports in a year are averaged and truncated.

Rules for documents and undertakings:
- A graded year needs the **CR report** upload with the "no duplicate CR" undertaking.
- A missing remark needs an NRC.
- The channel undertaking with its document is also required.

Points come from Admin → CR Grades. The screen shows the Class Master rule check (per-year minimum, qualifying total including the tribal bonus). The check is shown only; it does not block submission (`PromotionRuleCheck::check` :51-142).

- **"Take previous year CR" is NOT IMPLEMENTED** (deliberate).
- A missing mandatory year without a justified reason makes the officer **Not Eligible** (or CIR).

## 11. Missing CR documents

DPC Proposal → **Missing Records** (`dpc-proposals.record-gaps.*`). Choose the officer and year, then the reason from the Record Reason master. Upload the document the reason names.
- Some reasons require a **second document**, e.g. suspension needs the suspension order and the reinstatement order.
- Some reasons require remarks (`RecordGapService::record` :55-150).

## 12. Vigilance / Sealed Cover

Employee Details → **Departmental Enquiry (Sealed Cover)**:
- Is an enquiry pending? Choose the reason from the Sealed Cover Reason master, then enter the date and remarks.
- Upload the order if the reason requires a document.

Reasons have one of two effects:
- **sealed_cover:** the officer keeps the seat with remark **SCOVER** and is left out of the promotion order.
- **no_effect.**

Opening the cover after the enquiry ends is **NOT IMPLEMENTED**.

## 13. PH verification

Employee Details → **PH Reservation**. The answers give one of: not PH, previously availed, declined, or claimed.

- A claim becomes **pending**.
- The AH verifies each claim while the proposal is Submitted: verified, or not verified with remarks (`EmployeeDetailsService::verifyPhClaim` :477).
- Verified claims get separate PH seats first in the calculation (`DpcCalculationService::placeOnLists`).

## 14. AO verification

Every Employee Details document upload has a mandatory **"verified"** checkbox. The upload is refused without it. Ticking it records the AO's verification (`ao_verified_by/at`; `EmployeeDetailsService::upload` :435).

## 15. AH verification

The AH works while the proposal is **Submitted**. In Employee Details the AH can:
- verify each document, or use **Verify all** (`employees.documents.verify` / `verify-all`);
- decide each PH claim.

The AH's approval is refused while:
- any current document is unverified: ":count document(s) are still to be verified by the Administrative Head…";
- any PH claim is pending.

References: `DpcProposalService::approve` :639-655.

Missing-record files and representations are not part of the AH document count *(inferred)*.

## 16. Undertakings and declarations

| Item | Where | Effect |
|---|---|---|
| Service-record undertaking, DOB and joining orders | Basic Information | Required for completeness |
| Unsanctioned deputation (needs order) | Deputation and Promotion Refusal | **Not Eligible** |
| Refused promotion with year (needs the refusal letter and that DPC's order) | same | Refused last year → **Not Eligible** |
| No-duplicate-CR undertaking | CR upload | Required |
| Rule 1.1 checklist | DPC Proposal → **Verification** | All 7 items must be confirmed and not stale |

The 7 Rule 1.1 checklist items (`ProposalVerificationService`):
1. sanctioned strength;
2. Recruitment Rule;
3. seniority list;
4. previous DPC reconciliation: in the system, offline (citing a CGPSC Staff record, or a reference with date and minutes PDF), or none with remarks;
5. representations: a summary and a PDF each, and re-confirm after every new upload;
6. original documents (Mul folder);
7. Recruitment Rule conformity, with clauses 8–11 and the RR notification date.

## 17. Proposal submission (AO) and approval (AH)

**AO:** DPC Proposal → **Submit**. Submission is refused while any section is incomplete. The message lists the sections in this order:
1. Selection of Posts
2. DPC Committee
3. Working Officers & Vacancies / Vacancy Management record
4. Seniority List
5. Evaluation Chart
6. Employee Details
7. Rule 1.1 verification

References: `DpcProposalCompletenessService::checklist` :141-326. Result: **Submitted**, and the vacancy record revision is pinned to the proposal.

**AH:** reviews through the walkthrough (S21–S25), verifies documents and PH (§15), then either:
- **Approve, Sign & Forward to CGPSC:** status **Approved**, `forwarded_at` set; or
- **Return** with remarks: status **Returned**, and a new revision opens.

## 18. CGPSC-side processing

| Step | Who | Screen |
|---|---|---|
| Allot the DPC to a seated committee member (linked to a portal user) | CGPSC Chairman or the designated DPC Chairman | `cgpsc.allotments.*` |
| Accept (or decline with a reason), then schedule | Allottee | `cgpsc.allotments`, `cgpsc.schedules.*` |
| Open the proceeding at the scheduled time; correct, hold, calculate | Allottee (conductor) | `cgpsc.proceedings.*` |
| Approve the result | Conductor | `cgpsc.proceedings.approve` |
| Issue the promotion order: number and date; PDF signed; link sent by SMS, e-mail and in-app | **Department AH** | DPC Proposal → **Promotion orders** |

- **CGPSC scrutiny or return of a proposal to the department is NOT IMPLEMENTED.** After approval, a correction is a **Review DPC**.
- **Signing at result approval is NOT IMPLEMENTED.** The signature flag is off.

## 19. Completion criteria

| Stage | Complete when |
|---|---|
| Pre-DPC | Status **Approved** and **Active** for the exercise (the Recruitment Rule exists) |
| Vacancy record | **Finalized** with no open issue |
| DPC Proposal ready to submit | Every checklist section is complete (§17) |
| Ready for AH approval | Also: 0 documents awaiting AH, 0 PH claims pending, signing available |
| With CGPSC | Approved and forwarded |
| DPC concluded | Result approved, then promotion order issued |

---

## Part 5A: A brand-new exercise from scratch (new department, new posts)

### Who creates what in the code today

| Item | Created by | Screen | Admin can create it? |
|---|---|---|---|
| Department | Admin | Admin → Departments | Yes |
| Posts (name, class, pay scale, duration) | Admin | Admin → Department Posts | Yes |
| Qualifications, CR Grades, Record Reasons, Sealed Cover Reasons, Designations, CGPSC Chairman | Admin | Admin sidebar | Yes |
| **Class Master Promotion CR Rule** | Admin | Admin → Service Classes → Promotion rules | **Yes** |
| AO and AH accounts | Department Registration (`/register`) | public form | Admin can only post an existing user to a Registered department (User Management) |
| **Recruitment Rule** | **System**, on the AH's approval of the Pre-DPC | none | **No.** There is no Admin screen; `PreDpcWorkflowTest::test_the_administrator_has_no_recruitment_rule_screen` asserts it |
| **Vacancy Management record** (sanctioned and working strength, finalize) | **Department AO** | top bar → Vacancy Management | **No.** Admin's copy is read-only (`VacancyMasterController::canManage`, decision of 2026-10-19) |

So an exercise started "by the Admin" can begin with the department, posts, masters and the **Promotion CR rule**. The **vacancy record and the Recruitment Rule cannot be created by the Admin in the current code**. Letting the Admin do either would be a code change.

### Steps

| # | Who | Where | Action | Result |
|---|---|---|---|---|
| 1 | Admin | Admin → Departments → New | Code (capitals/digits), name; district, place, PIN, contact e-mail; status *Unregistered* | Department exists |
| 2 | Admin | Admin → Department Posts → New | Feeder post (e.g. Class II) and promotion post (e.g. Class I), status Active, effective from | Posts in force |
| 3 | Admin | Admin → Qualifications | Ensure the qualifications the order names are active | — |
| 4 | Admin | Admin → Service Classes → Promotion rules | Rule for the class pair (e.g. II→I), effective year ≤ DPC year: CR years, per-year mandatory and minimum, qualifying total, tribal bonus | DPC can be raised and calculated |
| 5 | Admin | Admin → CR Grades | Grade values valid for the CR years (e.g. from 2019) | Calculation has points |
| 6 | Admin | Admin → Record Reasons / Sealed Cover Reasons / Designations / CGPSC Chairman | Check they exist (seeded on dev) | Committee and Employee Details work |
| 7 | AO and AH | `/register` | Register the department with its AH and AO | Department Registered, logins issued |
| 8 | AO → AH | Pre-DPC (§6) | Create, submit, approve | **Recruitment Rule created** |
| 9 | AO | Vacancy Management (§5) | Start draft for the To Post and year, enter sanctioned strength and working strength with orders, finalize | Vacancy fixed |
| 10 | AO | DPC Proposal (§7 onwards) | Committee, Working Officers, Seniority List, Employee Details, Rule 1.1, Submit | — |
| 11 | AH, then CGPSC | §15 to §18 | Verify, approve, allot, proceed, result, promotion order | Done |

**To use the dummy CSVs unchanged in a new department:**
- Name the posts exactly **Block Education Officer** (Class II) and **Additional Director, Education** (Class I).
- In step 9, enter **sanctioned 10, working 7 (promotion 7, direct 0, deputation 0)**.
- In step 8, enter **Promotion 100 / Direct 0**.

With other figures, change the CURRENT POST column, and the working and vacant rows, to match.

---

## Part 6: Practical example on the dev database

The values below are taken from the dev database on 2026-10-23, read-only. Nothing was created.

| Item | Value on dev |
|---|---|
| Department | **School Education Department** (code `SE`, status registered; district, place, PIN and e-mail filled) |
| Promotion From Post | **Block Education Officer**, Class II, sanctioned 4, duration 3 years |
| Promotion To Post | **Additional Director, Education**, Class I, sanctioned 10 |
| DPC Year | **2026** |
| Class Master rule II→I, 2026 | Two rules exist; the newer (id 2: 5 CR years, qualifying total 25, tribal bonus 2 per year, skipping the highest and lowest grade) is the one picked (`ruleFor` orders by id desc). Mandatory CR, loyalty and property years: **2021–2025**. |
| CR grades | क+ = 9, क = 7, ख = 5, ग = 3, घ = 1 (valid from 2019) |
| Vacancy record | **Finalized**: Additional Director 2026, sanctioned 10, working 7 (all promotion), promotion vacancy 3, promotion **100 %** |
| Accounts | `admin@cgpsc.gov.in` (admin), `chairman@cgpsc.gov.in` (chairman), `ao.schooleducation@cg.gov.in` (AO), `ah.schooleducation@cg.gov.in` (AH). No `member` and no `cgpsc_staff` accounts. |
| Pre-DPC / DPC / Recruitment Rule | none |

### Screen by screen

1. **AO logs in.** Dashboard → **Pre-DPC Proposals** → **+ New Proposal**.
   - Year 2026.
   - Promotion To Post: *Additional Director, Education*. Class I and its pay scale fill in.
   - Feeder: *Block Education Officer*, share 100, experience e.g. 5 years, at least one qualification.
   - **Direct 0, Promotion 100.** This must equal the finalized vacancy record's 100 %.
   - GO number and effective date.
   - **Save**, then **Submit for Approval**.
2. **AH logs in.** Dashboard → Pre-DPC Proposals → open it → **Approve & Sign**. The Recruitment Rule v1 is created. On dev the mock signer is used (`APP_ENV=local`).
3. **AO.** Dashboard → **DPC Proposals** → **New**: choose the approved Pre-DPC, type *Fresh*, Save. The proposal is a Draft and picks up the finalized vacancy record.
4. **AO → DPC Committee.** Seat at least 3 members; the CGPSC Chairman is added by the system. Cover SC, ST, OBC, Female, Bhar Sadhak Sachiv and Vibhag Sachiv. To allot later, at least one member must be linked to a portal user.
5. **AO → Working Officers → Upload & Preview.**
   - Template *Currently Working Officers (S16)*, file **`dummy_working_officers.csv`**: 7 working officers by promotion plus 3 vacant posts = 10 rows.
   - Preview shows 10 valid, 0 rejected → **Commit**.
   - Reconciliation shows working 7 / promotion 7 / direct 0 / deputation 0, which matches the record.
6. **AO → Seniority List.**
   - List as on **01-04-2025**.
   - Template *Final Seniority List (S17)*, file **`dummy_seniority_list.csv`**: 10 Block Education Officers.
   - Preview 10 valid → **Commit**.
   - II→I has no zone formula, so no fixed count applies.
7. **AO → Employee Details**, for each of the 10 officers:
   - Basic Information: DOB and joining orders, undertaking.
   - CR for 2021–2025 with documents, ticking "verified".
   - IPR 2021–2025.
   - PH questions.
   - Sealed cover question.
   - Declarations.
   - Missing years go through **Missing Records**.
8. **AO → Verification (Rule 1.1):** confirm all 7 items. Give the RR notification date. For previous DPC, choose none with remarks, or cite a record.
9. **AO → Submit.** Any incomplete section is named in the refusal.
10. **AH:**
    - Review S21–S25.
    - Employee Details → **Verify all** documents; decide any PH claims.
    - **Approve, Sign & Forward to CGPSC.**
11. **CGPSC Chairman** (`chairman@cgpsc.gov.in`):
    - Allotments → allot to a seated committee member who has a user account.
    - The allottee accepts and schedules.
    - The allottee opens the proceeding → **Calculate** → **Approve result**.
12. **AH** → DPC Proposal → **Promotion orders** → number and date → issue. The signed PDF is generated and the officers receive the link.

### Where this example stops today if a step is skipped

| If… | It stops at | Message (source) |
|---|---|---|
| The Pre-DPC uses any split other than Promotion 100 % | Proposal vacancy section | "rule differs" (`vacancymaster.proposal.rule_differs`) |
| The working file's working counts ≠ 7/7/0/0 | Seniority List upload hidden and refused | `vacancymaster.php:336` |
| List-as-on date ≠ 01-04-2025 | Seniority upload | `lang/en/dpc.php:777-779` |
| CURRENT POST ≠ "Block Education Officer" | Seniority preview rejects the row; the whole file blocks | `lang/en/dpc.php:787` |
| Committee coverage incomplete | Submit | completeness message |
| Any Employee Details section incomplete | Submit | completeness message |
| Any document not AH-verified or PH claim pending | AH approve | `lang/en/dpc.php:501, :547` |
| No committee member linked to a user | CGPSC allotment | "A DPC is allotted to a serving member of its own committee…" (`AllotmentService` :502) |
