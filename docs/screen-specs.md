# e-DPC Portal — Screen Specifications (for Figma handoff)

Source: `ODPC_NEW_FINAL.pptx` (52 slides) and `CONVENTIONAL DPC PROCESS.pptx` (7 slides), cross-checked against `docs/technical-proposal.md` (Draft 03, confirmed).

**Two things below are adapted, not copied verbatim from the slides — flagged inline where they occur:**
1. **Auth fields.** The slides show Aadhaar No. + Aadhaar OTP as login/signing on every screen. Per the confirmed Draft 03 decision, this is replaced everywhere with email+password login, dual email/SMS OTP at registration, and OTP step-up at signing moments. Screens below reflect the confirmed design, not the raw mockup.
2. **Department Dashboard & the multi-member vote panel on Committee/Live Voting.** Neither slide deck shows a literal charted dashboard screen or a simultaneous multi-member vote tally (the mockups show a single allotted Chairman/Member signing off). These two elements are synthesized — the dashboard from the SRS Word mockup's "Dashboard Analytics" concept scoped to one department, and the vote panel from the SRS's explicit "real-time voting" functional requirement — and are marked as such in their sections.

Everything else (field names, table columns, button labels, workflow order, scoring codes) is taken directly from the slides.

---

## 1. Login / Registration

### Purpose
One-time account setup for a department's two-tier accounts (Authorized Officer, Administrative Head), plus the recurring login every actor uses to enter the portal.

### Fields / Inputs — Registration
| Field | Type | Notes |
|---|---|---|
| Department Name | Dropdown | Master list (General Administration, School Education, Higher Education, Finance, Agriculture, PWD, Medical Education, Labour, …) |
| District | Dropdown | |
| Place / Location | Text | |
| PIN Code | Numeric | 6 digits |
| Administrative Head — Name | Text | |
| Administrative Head — Designation | Text | e.g. "Secretary" |
| Administrative Head — Mobile Number | Numeric | 10 digits; becomes Level-2 account identifier |
| Administrative Head — Email | Email | Government domain only (mockup note: "ONLY .GOV ID") |
| Administrative Head — Password / Confirm Password | Password ×2 | *Adapted: replaces Aadhaar No.+OTP as identity proof* |
| Authorized Officer — Name | Text | |
| Authorized Officer — Designation | Text | e.g. "Deputy Secretary" |
| Authorized Officer — Mobile Number | Numeric | 10 digits; becomes Level-1 account identifier |
| Authorized Officer — Email | Email | Government domain |
| Authorized Officer — Password / Confirm Password | Password ×2 | *Adapted* |

### Fields / Inputs — Login (recurring, all roles)
| Field | Type | Notes |
|---|---|---|
| Email | Text | |
| Password | Password | Masked, show/hide toggle |
| Remember Me | Checkbox | |

### Actions / Buttons
| Action | Triggers |
|---|---|
| REGISTER | Validates form → sends independent Email OTP and SMS OTP → account stays inactive until **both** are confirmed |
| Verify Email OTP / Verify Mobile OTP | Two separate confirmation steps, either order |
| EDIT | Unlocks a submitted-but-not-yet-verified registration for correction |
| SAVE | Persists in-progress registration edits |
| LOGIN | Validates email+password → creates session → redirects by role |
| Forgot Password | Sends a password-reset OTP to the registered email |

### States
- **Empty** — form blank; REGISTER / LOGIN disabled
- **Filled** — client-side validation passes (email format, 10-digit mobile, 6-digit PIN, password match); buttons enable
- **Error** — invalid email/mobile format; passwords don't match; OTP mismatch or expired; duplicate email/mobile already registered; "Incorrect email or password"; "Please complete OTP verification before logging in"
- **Success** — "Both channels verified — account active"; on login, redirect with session established

### Role-Based Differences
- Only **Authorized Officer** and **Administrative Head** self-register; both accounts attach to the same department record.
- **Chairman / Member / CGPSC Admin** accounts are provisioned internally by CGPSC Admin — no self-registration UI.
- **Employee (Self-Inspection)** does not get a full account at all — see Screen 6 for their lighter-weight, non-password access.

### Navigation
- **Before:** none (entry point) — portal landing page
- **After:** Registration success → Login. Login success → Department Dashboard (Officer/Admin Head), CGPSC Dashboard (Chairman/Member/Admin — not itemized here), or the Self-Inspection landing (employee, via a direct link rather than this login form).

---

## 2. Department Dashboard

*Synthesized screen — see note at top.*

### Purpose
The department's landing screen after login: at a glance, where every DPC case currently stands, plus quick actions to start or continue one.

### Fields / Inputs
| Field | Type | Notes |
|---|---|---|
| Metric Cards | Display | Draft Proposals · Awaiting Admin-Head Approval · Sent to CGPSC · Scheduled · Completed This Year |
| Department Info Card | Display | Dept name, district, registered Administrative Head, registered Authorized Officer — with an edit link back to Registration |
| DPC Proposal Status Table | Data Grid | DPC P.No., Post From, Post To, Class, Type of DPC, Proposal Date, Status, DPC Date (once scheduled) |
| Status Filter | Dropdown | Draft / Pending Approval / Sent to CGPSC / Allotted / Scheduled / Completed / Cancelled |
| Date Range | Date Pickers ×2 | |
| Search | Text | By DPC P.No. or post name |

### Actions / Buttons
| Action | Triggers |
|---|---|
| + New DPC Proposal | Opens DPC Proposal Intake (Screen 3), Step 0 |
| Upload Seniority List | Shortcut into Intake's seniority-list step |
| View (row) | Opens that case's current screen (Intake, Tabulation Chart, or read-only Proceedings) |
| Continue (row, Draft status) | Resumes Intake wizard at the last saved step |
| Review & Approve (row, Administrative Head only) | Opens Intake in Admin-Head review mode |
| Export | Downloads the status table as CSV/PDF |

### States
- **Empty** — no proposals yet: empty-state illustration + "No DPC proposals yet" + prominent New DPC Proposal CTA
- **Filled** — table populated, metric cards show live counts
- **Error** — table/metrics fail to load → retry banner
- **Success** — post-action toast, e.g. "Proposal HE201711111 sent to Administrative Head for approval"

### Role-Based Differences
- **Authorized Officer:** sees "+ New DPC Proposal" and "Continue" on drafts; no approval action.
- **Administrative Head:** an extra "Pending My Approval" metric card and filter preset; row action reads "Review & Approve" instead of "Continue" for cases awaiting them.

### Navigation
- **Before:** Login
- **After:** New/Continue → DPC Proposal Intake (Screen 3). Objections raised against this department's data (Screen 6) surface here as a notification.

---

## 3. DPC Proposal Intake

### Purpose
A multi-step wizard where the Authorized Officer assembles a full DPC proposal — post details, committee, vacancy/officer data, seniority list — and the Administrative Head reviews the same data before it is sent to CGPSC.

### Step 0 — Type & Posts
| Field | Type | Notes |
|---|---|---|
| Post To Be Promoted To | Dropdown | e.g. Deputy Secretary, Under Secretary, Section Officer |
| Class of Post To | Dropdown | I / II / III |
| Post Promoted From | Dropdown | |
| Class of Post From | Dropdown | I / II / III |
| Type of DPC | Radio | Fresh DPC / Review DPC / Circulation |
| Original DPC ID *(if Review)* | Text/Lookup | References the case being corrected |
| Reason for Review *(if Review)* | Checklist (multi-select) | Seniority-list name omitted · wrong seniority order · wrong roster point · wrong CR entry · reservation-rule error · tribal-service-calculation error |
| Legal Stay Declaration | Radio | "Is there a legal stay/stop order on this proposal?" Yes/No |
| Proposal Date | Date Picker | Defaults to today, editable |

### Step 1 — DPC Committee
| Field | Type | Notes |
|---|---|---|
| Committee Members | Data Grid (add-row) | SN, Name (Text), Designation (Text), Mobile (Numeric), Email (Email), Capacity (Dropdown: Chairman/Member) |

Actions: **+ Add Member**, **Remove row**, **SAVE AND NEXT**

### Step 2 — Vacancy & Officers Under Consideration
| Field | Type | Notes |
|---|---|---|
| Sanctioned Post Count (as of year) | Numeric | |
| Clear Vacancy | Numeric, per category | UR/SC/ST/OBC/EWS |
| Vacancy Expected — from Promotion | Numeric, per category | |
| Vacancy Expected — from Retirement | Numeric, per category | |
| Vacancy Expected — from Deputation | Numeric, per category | |
| Officers Under Consideration | Data Grid or Upload | S.N., Name, DOB (Date), Qualification (Text), Date of Joining Service (Date), Date of Posting on Feeder Post (Date), Category (Dropdown), Roster Position (Numeric), Deputation Date (Date, optional) |

Actions: **CALCULATE** (zone-of-consideration count), **UPLOAD** (Excel), **CLEAR**, **SAVE AND NEXT**

### Step 3 — Seniority List
| Field | Type | Notes |
|---|---|---|
| Seniority List (as of year) | Data Grid or Upload | Same columns as Step 2's officer table, ordered by seniority |
| Effective Date | Date Picker | |

Actions: **CALCULATE** (shows "2 × vacancies + 4" or class-specific formula result), **UPLOAD**, **SAVE AND NEXT**

### Step 4 — Tabulation Chart data entry
Captures the raw per-officer CR/loyalty/property-return inputs; computed marks and Main/Waiting lists are reviewed on **Screen 5**. Actions: **UPLOAD**, **SAVE AND NEXT** → opens Screen 5.

### Final Submit
| Action | Triggers |
|---|---|
| SUBMIT *(Authorized Officer)* | OTP step-up ("Enter the OTP sent to your verified email/mobile to sign and submit") → sends the proposal to the Administrative Head |
| APPROVE *(Administrative Head)* | OTP step-up → sends the proposal to CGPSC |
| SEND BACK FOR CORRECTION *(Administrative Head)* | Requires a Remarks field → returns to Officer, editable again |

### States
- **Empty** — step shows guidance text (e.g. "Follow Schedule-4 of the department recruitment rules")
- **Filled** — NEXT enables once required fields validate; running totals (vacancy math, zone-of-consideration count) recalculate live
- **Error** — upload format/column mismatch; Sanctioned count ≠ sum of category vacancies; OTP invalid/expired at Submit/Approve
- **Success** — "Proposal HE201711111 submitted to Administrative Head" / "Approved and sent to CGPSC," with timestamp

### Role-Based Differences
- **Authorized Officer:** full edit rights on every step; sees SUBMIT.
- **Administrative Head:** same steps, read-only with inline "flag back" option; sees APPROVE / SEND BACK instead of SUBMIT.
- Once sent to CGPSC, all department-side fields lock (matches the mockup's rule that entries can't change except between submission and the DPC date).

### Navigation
- **Before:** Department Dashboard ("+ New DPC Proposal")
- **After:** Officer SUBMIT → Administrative Head's "Pending My Approval" queue. Admin Head APPROVE → CGPSC Allotment/Scheduling (internal CGPSC screen, not itemized among these 7) → once scheduled, the case appears on the allotted Chairman/Member's **Committee/Live Voting** screen (Screen 4). Step 4 links directly into the **Tabulation Chart** screen (Screen 5).

---

## 4. Committee / Live Voting

### Purpose
The live working screen for the Chairman or allotted Member to conduct the DPC: review the case, discuss over video conferencing, and formally record the decision.

### Fields / Inputs
| Field | Type | Notes |
|---|---|---|
| Case Header | Display | DPC P.No., Department, Post From→To, Class, Type of DPC, Scheduled Date/Time |
| Video Conference Panel | Embedded (Jitsi) | Join/Leave controls |
| Digital Notes / Minutes | Text Area | Auto-saves |
| Tabulation Chart | Embedded, read-only | Reference view of Screen 5 |
| Recommendation | Radio | Recommended / Deferred / Rejected |
| Remarks | Text Area | Required if Deferred or Rejected |
| Vote *(synthesized panel)* | Radio per present member | Approve / Object — implements the SRS's real-time-voting requirement; the mockups themselves show a single decision-maker, not a simultaneous tally |
| Vote Tally *(synthesized)* | Display, auto-computed | "X Approve / Y Object" |
| Signed Proceedings Upload | File Upload (PDF) | Optional scanned physical copy |

### Actions / Buttons
| Action | Triggers |
|---|---|
| START MEETING / JOIN | Launches the VC session |
| SAVE NOTES | Autosave + manual save |
| CAST VOTE *(per member, multi-member mode)* | Records that member's vote |
| RECORD DECISION | OTP step-up → locks the recommendation for this case |
| CANCEL DPC | Requires a reason (Text Area) + OTP step-up → case status becomes Cancelled |
| RE-SCHEDULE | Opens a date/time picker (quorum not met / VC failure) |
| NEXT CASE | Advances to the next DPC P.No. on today's docket |

### States
- **Empty** — case loaded, no decision yet; RECORD DECISION disabled until Recommendation (+ Remarks if required) is filled
- **Filled** — all required decision fields present; RECORD DECISION enabled
- **Error** — OTP invalid/expired at Record Decision; VC connection failure banner; vote count below quorum
- **Success** — "Decision recorded for HE201711111 — Recommended"; case moves off today's docket

### Role-Based Differences
- **Chairman:** can act on any case, including ones allotted to a Member; is also the only role that performs the prior Allotment step (assigning cases to self/Members — a separate CGPSC-internal screen).
- **Member:** only sees/acts on cases allotted to them; cannot re-allot.
- **IT Support / Admin:** read-only observer, no decision/vote controls — for live technical support only.

### Navigation
- **Before:** CGPSC Allotment & Scheduling (internal screen — not itemized among the 7) — this screen becomes available once a case is scheduled
- **After:** RECORD DECISION → case status Decided → once every case in the batch is decided and Screen 5's Main/Waiting lists are finalized, the batch proceeds to **Order Generation** (Screen 7)

---

## 5. Tabulation Chart (Main / Waiting List)

### Purpose
The authoritative scoring worksheet for a DPC case: one row per officer under consideration, showing service/eligibility inputs, the computed mark, and the resulting Main List / Waiting List placement per reservation category. Used both by the department (data entry) and CGPSC (review + approval).

### Fields / Inputs (repeating row, one per officer)
| Field | Type | Notes |
|---|---|---|
| SN | Auto | |
| Name of Officer | Text / lookup | Pulled from the seniority list |
| DOB | Date | |
| Qualification | Text | |
| Date of Posting on Feeder Post | Date | |
| Category | Dropdown | UR / SC / ST / OBC / EWS |
| Loyalty Certificate Filed (per year, up to 5 cols) | Radio | Y / N |
| Immovable Property Return Filed (per year, up to 5 cols) | Radio | Y / N |
| Year-wise CR Grade (per year, up to 5 cols) | Dropdown | क+ / क / ख / ग / घ = A+/A/B/C/D = 4/3/2/1/0 |
| Note | Text | Free-form, e.g. "TRIBAL 2021" service-in-tribal-area flag |
| Mark | Numeric, auto | Sum of the 5-year CR values + tribal bonus |
| Remark | Auto-tag (multi) | PUNISHMENT · C.CASE (pending court case) · CIR (circulation required — missing data) · SS (super seat / notional seniority) · NE (not eligible) · EL (eligible) · category-waiting tags (e.g. ST_W, UR_W) |

### Computed / Summary Fields
| Field | Type | Notes |
|---|---|---|
| Zone of Consideration | Display, formula | Class-I→I, single vacancy: 5 officers. Class-I→I, multiple vacancies: 2×vacancies+4. All other cases: all eligible officers. |
| Qualifying Score Threshold | Display | Class-I→I ≥15, Class-II→I ≥13, Class-II→II ≥11, others ≥10 (reserved-category relaxation to 10) |
| Main List | Data Grid, auto | Officers clearing the threshold, ranked by seniority within category, count = sanctioned vacancies |
| Waiting / Supplementary List | Data Grid, auto | Next-ranked eligible officers per category, for backfill |

### Actions / Buttons
| Action | Triggers |
|---|---|
| CALCULATE | Recomputes Mark, Remark tags, zone-of-consideration count, Main/Waiting placement |
| UPLOAD | Bulk Excel import of the grid |
| EDIT | Unlocks a row/field for correction (pre-submission only) |
| SAVE / SAVE AND NEXT | Persists draft / advances to committee review |
| CANCEL DPC | Same as Screen 4, available at CGPSC-review stage |
| APPROVE BY CGPSC | Final action — locks the chart and both lists |

### States
- **Empty** — grid pre-filled with names from the seniority list only; CALCULATE disabled until CR/loyalty/property columns are filled
- **Filled** — Mark/Remark update live as grades are entered; Main/Waiting preview updates
- **Error** — a missing year's CR grade auto-flags that row CIR rather than hard-blocking (per the Circulation-DPC rule); qualifying-score math mismatch; upload column-count mismatch
- **Success** — "Tabulation chart calculated — 8 officers considered, 2 Main List, 6 Waiting List"; APPROVE BY CGPSC → "Main and Waiting lists finalized for HE201711111"

### Role-Based Differences
- **Authorized Officer / Administrative Head:** data entry and internal review (CALCULATE, UPLOAD, EDIT, SAVE); no APPROVE BY CGPSC.
- **Chairman / Member:** review-only + APPROVE BY CGPSC + CANCEL DPC; cannot edit source CR data (corrections go through Review-DPC).
- A **CIR-flagged** officer's decision is held back even after the rest of the list is approved, until the missing data is resolved via a separate Circulation-DPC sign-off.

### Navigation
- **Before:** Step 3/4 of DPC Proposal Intake (department entry pass); Committee/Live Voting (CGPSC review pass)
- **After:** APPROVE BY CGPSC → Order Generation (Screen 7). A CIR-flagged row instead routes into the Circulation-DPC flow.

---

## 6. Self-Inspection

### Purpose
Lets an individual officer confirm the data being used to decide their own promotion is correct, and raise a time-boxed objection if it isn't — a transparency/grievance safeguard, not a management screen.

### Fields / Inputs
| Field | Type | Notes |
|---|---|---|
| Employee / Service ID | Text | *Adapted:* replaces the mockup's Aadhaar-No.+Name+DOJ pattern |
| OTP | Numeric | Sent to the mobile/email already on file in service records — a lighter-weight check than the password accounts used elsewhere, since employees aren't regular portal users |
| Data Under Consideration | Display, read-only | Name, DOB, Category, Qualification, Date of Posting, per-year Loyalty/Property flags, per-year CR grades, computed Mark |
| Objection Reason | Dropdown/Text | Appears only after RAISE OBJECTION: "CR grade incorrect for [year]" · "Category incorrect" · "Date of posting incorrect" · "Other" |
| Objection Details | Text Area | |

### Actions / Buttons
| Action | Triggers |
|---|---|
| RAISE OBJECTION | Opens the reason/details fields |
| SUBMIT OBJECTION | Only enabled before the stated cutoff date/time |
| PRINT | Generates a PDF of the displayed data |
| CLOSE | Dismiss, implicit no-objection acknowledgement |

### States
- **Empty** — data loads read-only, no objection drafted
- **Filled** — reason + details entered; SUBMIT OBJECTION enabled
- **Error** — SUBMIT OBJECTION after cutoff → blocked: *"Objections are not accepted after [date] — any representation received after this date will be filed without consideration"* (verbatim rule from the mockup); OTP invalid/expired at login
- **Success** — "Objection submitted — reference no. [X]"

### Role-Based Differences
Single-role screen — the officer under consideration only. Department (Officer/Administrative Head) and CGPSC roles instead see a downstream notification/queue of objections raised against their pending cases.

### Navigation
- **Before:** A direct link (SMS/email) sent once the officer's name appears on a submitted proposal's officer list (Intake Step 2)
- **After:** CLOSE → no further action, data proceeds as-is. SUBMIT OBJECTION → routed to the Department Dashboard/Intake for correction pre-submission, or to the Review-DPC flow if raised after CGPSC submission.

---

## 7. Order Generation

### Purpose
The final step: once CGPSC approves the Main/Waiting lists, generate the formal promotion order(s), get them e-signed, and publish/distribute them to departments and employees.

### Fields / Inputs
| Field | Type | Notes |
|---|---|---|
| Promotion Event Header | Display | Promotion Event ID, Effective Date (Date Picker, editable pre-generation), Department, DPC P.No. |
| Final Promotion Selection List | Data Grid | Checkbox, Employee Code, Employee Name, Current Cadre/Post, Promoted-From Cadre, New Designation, Final DPC Status (Promoted), Order Letter Status (Draft / Awaiting e-Sign / Signed & Distributed) |
| Waiting List reference | Data Grid, read-only | For backfill if a Main List promotee declines/is ineligible |

### Actions / Buttons
| Action | Triggers |
|---|---|
| GENERATE PDF | Drafts the order document (selected rows or bulk) from a template |
| e-SIGN SELECTED ORDERS | OTP step-up (fresh OTP to the signing officer's verified email/mobile, per Draft 03) → status → "Signed" |
| DISTRIBUTE SELECTED LETTERS | Queues email/SMS notifications to department + employees → status → "Signed & Distributed" |
| GENERATE BULK PROMOTION LETTERS | Runs Generate + e-Sign + Distribute across the whole Main List in one action |
| VIEW LETTER (row) | Opens the generated PDF |
| PUBLISH ORDER | Makes the order visible in Reports/Audit Trail |

### States
- **Empty** — Main List approved, no PDFs yet; all rows "Draft"; only GENERATE PDF enabled
- **Filled** — PDFs generated, rows "Awaiting e-Sign"; e-SIGN enabled per selection
- **Error** — OTP invalid/expired during e-Sign; a row missing a required field (e.g. new designation unmapped) blocks GENERATE PDF for that row; distribution failure (bad email/mobile) shows a per-row "Distribution failed — retry" state
- **Success** — "3 orders signed and distributed"; a "Pending Distribution" panel lists rows still awaiting e-Sign/distribution (e.g. "EMP1002 (e-Sign required)")

### Role-Based Differences
- **Chairman / authorized CGPSC signing officer:** only role that can e-SIGN SELECTED ORDERS — the legally significant act.
- **CGPSC Admin / IT Support:** can GENERATE PDF and DISTRIBUTE, not e-SIGN.
- **Department (Officer/Administrative Head):** read-only recipient view — their own department's rows and status, with a download link once "Signed & Distributed."

### Navigation
- **Before:** Tabulation Chart (Screen 5) APPROVE BY CGPSC action
- **After:** DISTRIBUTE SELECTED LETTERS → each promoted employee/department gets a Notification; the record becomes visible on Reports & Audit Trail (not itemized among these 7) — end of the DPC case lifecycle.
