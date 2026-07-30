# 01 — Requirements (Extracted from PPTs)

Slide references: `S<n>` = ODPC_NEW_FINAL.pptx; `C<n>` = CONVENTIONAL DPC PROCESS.pptx. Nothing here is invented; gaps are flagged in `06-ambiguities.md`.

---

## 1. Roles / Actors

| Role | PPT term | ID level | Rank shown | Responsibilities | Source |
|---|---|---|---|---|---|
| Authorized Officer (AO) | DPC हेतु अधिकृत अधिकारी | Level-1 | Deputy/Under Secretary | Registers dept; enters Pre-DPC posts; prepares DPC proposal (posts, committee, working officers, seniority, tabulation) | S5–S18, S37, S47 |
| Administrative Head (AH) | प्रशासकीय प्रमुख | Level-2 | Secretary | Approves Pre-DPC posts; approves detailed proposal → forwards to CGPSC | S10–S11, S19–S25, S39–S40 |
| CGPSC Chairman | माननीय अध्यक्ष | — | Chairman | Receives all proposals; allots each DPC to self or a Member; conducts; approves results | S4, S26–S28, S32–S34 |
| CGPSC Member | माननीय सदस्य | — | Member | Accepts allotted DPC; schedules/reschedules; conducts; approves results | S29–S30, S32–S34, S42–S48 |
| Individual under consideration | विचाराधीन अधिकारी/कर्मचारी | — | US/DS etc. | Self-inspection of own data; raise time-boxed objection | S49–S51 |

**Not shown in PPT (ambiguity):** who provisions Chairman/Member logins and seeds master data (proposed "CGPSC Admin" role — see `06-ambiguities.md` Q2).

---

## 2. Six Stages of ODPC (S2–S4)

1. **Registration of Departments** (S2, S5–S6)
2. **Pre-DPC Proposal** — AO enters promotion-post details (pay scale, % promotion, feeder cadres, experience); AH approves (S2, S7–S11)
3. **DPC Proposal (detailed)** — post to/from + class, legal-stay declaration, committee, working officers, sanctioned count, seniority list, evaluation/tabulation sheet (S3, S12–S18); AH approves → forwards to CGPSC (S19–S25)
4. **Workflow Management (Allotment)** — proposals reach Chairman; Chairman allots to self or a Member (S4, S26–S28)
5. **Scheduling** — allotted Chairman/Member sets DPC date & time (accept / re-allot / reschedule) (S4, S29–S30)
6. **Online DPC Proceeding** — on scheduled date/time: conduct, CALCULATE, produce Main + Waiting list, approve, issue proceedings (S31–S34)

**Variant / auxiliary flows:** Review DPC (S35–S44), Circulation DPC (S45–S48), Self-Inspection (S49–S51).

---

## 3. Screen Inventory (35 screens)

| # | Screen | Actor | Slide | Buttons / key elements |
|---|---|---|---|---|
| 1 | Department Registration | AO | S6 | Dept dropdown, district, place, PIN, AH & AO name/designation/mobile/email(.gov). REGISTER, EDIT, SAVE |
| 2 | Login — AO | AO | S8, S13 | Aadhaar No + OTP *(auth ambiguity Q1)* |
| 3 | Pre-DPC: posts sanctioned (entry) | AO | S9 | Post table per Schedule-4. SAVE, CLEAR, EDIT, NEXT |
| 4 | Login — AH | AH | S10, S20 | Aadhaar No + OTP |
| 5 | Pre-DPC: posts sanctioned (approval) | AH | S11 | APPROVE |
| 6 | Proposal: selection of posts | AO | S14 | Post-to/from + class, legal-stay flag, TYPE OF DPC, proposal date. NEXT, SAVE, EDIT, RESET |
| 7 | Proposal: committee | AO | S15 | SN, Name, Designation, Mobile, Email, Capacity (Chairman prefilled). SAVE AND NEXT |
| 8 | Proposal: working officers under consideration | AO | S16 | Vacancy breakdown, sanctioned count, officers table. SAVE AND NEXT, CALCULATE, CLEAR, UPLOAD |
| 9 | Proposal: seniority list (feeder) | AO | S17 | Zone count "2×2+4=8", seniority table. SAVE AND NEXT, CALCULATE, UPLOAD, CLEAR |
| 10 | Proposal: tabulation/evaluation chart | AO | S18 | Loyalty, immovable-property, year-wise CR, note. SAVE AND NEXT, UPLOAD |
| 11–15 | Approval of posts / committee / officers / seniority / tabulation | AH | S21–S25 | Read-only + APPROVE |
| 16 | Login — Chairman | Chairman | S27 | Login |
| 17 | CGPSC Allotment | Chairman | S28 | Allotment table (Dept, DPC P.No, date, post from/to, ALLOTED TO M1–M5); Report (+ STATUS, DEFAULT DPC DATE). ALLOTMENT OF DPC, DONE |
| 18 | Login — Member | Member | S29 | Login |
| 19 | Scheduling | Member | S30 | Accept + DPC DATE/TIME; RE-ALLOTMENT, (RE)SCHEDULING |
| 20 | Login — Chairman/Member (proceeding) | C/M | S32 | DPC ID + OTP |
| 21 | Proceeding: action | C/M | S33 | Evaluation-cum-final-seniority; per-year CR; CALCULATE → MARK, REMARK; TRIBAL. SAVE, CALCULATE, EDIT, CANCEL DPC |
| 22 | Proceeding: approval | C/M | S34 | MAIN LIST + WAITING LIST. APPROVE BY CGPSC |
| 23–27 | Review DPC: login AO, proposal (reasons + orig DPC), correction, login AH, approval | AO/AH | S36–S40 | REASON FOR REVIEW DPC; SHOW DETAILS; APPROVE |
| 28–30 | Review DPC: login C/M, action, result | C/M | S42–S44 | CALCULATE; SAVE, EDIT, CANCEL DPC; APPROVE BY CGPSC |
| 31–33 | Circulation DPC: login C/M, action (select post + orig DPC), result | C/M | S46–S48 | type=CIRCULATION; MAIN + WAITING; APPROVE BY CGPSC |
| 34 | Self-Inspection: login | Individual | S50 | Name + Date of joining current cadre (+ OTP) |
| 35 | Self-Inspection: action | Individual | S51 | View own data; objection-deadline notice. CLOSE, PRINT, RAISE OBJECTION |

---

## 4. Field Inventory (distinct fields per screen)

**Registration (S6):** Department Name (dropdown), District, Place, PIN, AH Name, AH Designation, AH Mobile *(basis for ID+password)*, Department Email *(only .gov)*, AO Name, AO Designation, AO Mobile.

**Pre-DPC posts (S9/S11):** SN, Post Name, Pay Scale, % Direct Recruitment, % Promotion Recruitment, Feeder Cadre-1 (name, %, experience yrs), Feeder Cadre-2 (name, %, experience yrs). On-screen constraint: *"as per Schedule-4 of departmental recruitment rules."*

**Proposal — selection of posts (S14):** Post-To (dropdown), Class-To (I/II/III), Post-From (dropdown), Class-From (I/II/III), Legal-stay effective? (Yes/No), Type of DPC (Fresh/Review/Circulation), Proposal Date.

**Committee (S15):** SN, Name, Designation, Mobile No, Email, Capacity (Chairman/Member).

**Working officers under consideration (S16/S23):** Sanctioned post count; Vacancy — Clear (e.g. UR-1), Expected-from-Promotion, Expected-from-Retirement (e.g. ST-1), Expected-from-Deputation. Table: SN, Name, DOB, Qualification, Date of Joining Service, Date of Posting on (target post), Category, Roster Position, Deputation Date.

**Seniority list (S17/S24):** Zone count (computed); Table: SN, Name, DOB, Qualification, Date of Joining Service, Date of Posting on (feeder post), Category.

**Tabulation / evaluation chart (S18/S25/S33):** SN, Name, DOB, Qualification, DOP, Category, **Loyalty** (per-year Y/N), **Immovable Property Details** (per-year Y/N), **Year-wise CR** (grade per year), **Note** (PUNISHMENT / C.CASE / …), **Mark** (computed), **Remark** (computed). Years 2012–2017 (5 CR years scored). *Per-year layout confirmed in mockup — see Q4.*

**Allotment (S28):** SN, Department, DPC P.No, Proposal Date, Post From, Post To, DPC Alloted To (M1–M5), Status, Default DPC Date.

**Scheduling (S30):** SN, Department, DPC P.No, Proposal Date, Post From, Post To, Scheduling(status), DPC Date, DPC Time (+ old→new on reschedule).

**Review proposal (S37):** Post-To/From + Class, Fresh-DPC date, DPC ID selector, Review proposal date, Type=Review, **Reason for Review** (§6.2).

**Self-inspection (S50/S51):** Name, Date of Joining Current Cadre; view of own DOB, Category, CR, Loyalty, Property; objection-deadline notice.

---

## 5. Business Rules — Scoring & Eligibility (C4–C6, S33)

**5.1 CR grade → marks:** `क+ = 4 · क = 3 · ख = 2 · ग = 1 · घ = 0`

**5.2 Tribal-area service bonus:** `+1` (else NO CHANGE); remark carries `TRIBAL <year>`.

**5.3 Eligibility gates (hard fail → NE):**
- Any of last 5 CRs = **घ (0)** → not eligible.
- CLASS-I → CLASS-I: the **last year's** CR must **not** be **ग (1)**.

**5.4 Qualifying score (sum of last 5 CR marks):**
| Promotion | Minimum |
|---|---|
| CLASS-I → CLASS-I | ≥ 15 |
| CLASS-II → CLASS-I | ≥ 13 |
| CLASS-II → CLASS-II | ≥ 11 |
| Others (शेष) | ≥ 10 |

Reserved relaxation: may be relaxed **down to 10** if reserved-category officers remain insufficient even after zone expansion (Q5/Q6).

**5.5 Super Seat (SS):** all 5 CRs = क+ (sum 20) → notional (काल्पनिक) seniority; among multiple SS, actual seniority decides. Remark `SS`.

**5.6 Zone of Consideration:**
- CLASS-I → CLASS-I: 1 vacancy → top **5**; >1 vacancy → **(2 × vacancies + 4)** (matches "2×2+4=8", S17); reserved shortfall → expand to **7 × that category's vacancy** (only for that category).
- Other cases: all officers in zone meeting the experience qualification.

**5.7 Output:** Main List (मुख्य सूचि) + Waiting/Supplementary List (अनुपूरक सूचि) per category, produced by CALCULATE — *"मूल्यांकन पत्रक सह अंतिम पदक्रम सूचि."*

---

## 6. DPC Types & Coded Values

**6.1 Types (S3, S14, S37, S47; C3/C7):**
- **Fresh DPC** — new proposal.
- **Review DPC** — corrects an error in a Fresh DPC; submitted with representation; references original **DPC ID** + coded reason; process identical to Fresh.
- **Circulation DPC** — officer(s) missing **2+ years CR** or **1+ years immovable-property**: included but promotion **held**; on resolution, Commission signs a circulation note. (S14 label also shows "CR ≤ 4" — Q12.)

**6.2 Review reasons (C-deck, S37):** name omitted from seniority list · wrong seniority order · wrong roster point · wrong CR entry · reservation rule not followed · tribal-area service not counted.

**6.3 Note / Remark codes (S18, S33):** `EL` eligible · `NE` not eligible · `CC`/`C.CASE` court case · `CIR` circulation (held) · `SS` super seat · `PUNISHMENT` · `TRIBAL <year>` · `UR/SC/ST/OBC` main-list category · `UR_W/ST_W…` waiting-list category · `Y/N` loyalty & property flags.

**6.4 Vacancy sub-types (S16/S23):** Clear · Expected-from-Promotion · Expected-from-Retirement · Expected-from-Deputation (each with reservation category).

**6.5 DPC ID format:** `<DeptCode><Year><Serial>` e.g. `HE201711111` (also the login DPC ID at proceeding, S32).

---

## 7. Workflow / Status Model

Fresh proposal lifecycle:
`DRAFT (AO) → PRE-DPC APPROVED (AH) → PROPOSAL SUBMITTED (AO) → PROPOSAL APPROVED (AH) → FORWARDED TO CGPSC → ALLOTTED (Chairman) → SCHEDULED (Member/Chairman) → CONDUCTED/CALCULATED → APPROVED BY CGPSC → RESULT PUBLISHED`

Allotment status (S28): `PENDING` → accepted / re-allotted. Scheduling (S30): `DONE` + date/time, with re-allot & reschedule paths. `CANCEL DPC` is a terminal proceeding action. Circulation officers sit in a `HELD` sub-state until resolved.

---

## 8. Notifications

- **Registration complete (S5):** Level-1 & Level-2 ID + password to AO and AH via **mobile + email**.
- **DPC scheduling (S26):** date/time to AH & AO via **registered mobile + email**.
- Login/sign OTPs per Q1.

## 9. Uploads & Documents

- **Three Excel upload templates** (S16/S17/S18 + folder image): `CURRENTLY_WORKING_US`, `FINAL_SENIORITY_LIST_US`, `EVALUATION_CHART_US`. Each screen allows manual entry **or** upload.
- **Generated outputs:** Main + Waiting list (evaluation-cum-final-seniority); Self-inspection **PRINT**; proceedings issued ("कार्यवाही विवरण जारी", S4/S31 — PDF layout is Q7).
- Documents stored on **S3**; only metadata in MySQL.

## 10. Master Modules

Department · Post/Cadre (with class I/II/III) · Pay-scale · Recruitment-rule (Schedule-4) reference · Category (UR/SC/ST/OBC) · CR-grade→mark · Qualifying-threshold (by class pair) · Zone-rule params · Review-reason · Remark/Note-code · Vacancy-type · DPC-type.

## 11. Explicitly OUT of scope (absent from both PPTs)

Video conferencing; real-time multi-member voting (decks show a single allotted Chairman/Member conducting the DPC); HRMS import. None to be built unless explicitly approved (Q16).
