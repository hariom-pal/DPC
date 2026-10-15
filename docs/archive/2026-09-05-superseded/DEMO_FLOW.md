> # ⚠ SUPERSEDED — do not use for the demonstration of 2026-09-05 onward
>
> This document describes a **seeded** database that no longer exists. Its DPC
> numbers (`SE-0001` … `SE-0009`), row counts, user counts, Self-Inspection
> details and base URL are all out of date, and following it will not work.
>
> The database was cleaned of all transactional data on **2026-09-05** and the
> demonstration is now given from an empty workflow. Use these instead:
>
> | For | Use |
> |---|---|
> | Logins and account status | `docs/demo/DEMO_USERS.md` |
> | The step-by-step flow | `docs/demo/CURRENT_DEMO_FLOW.md` |
> | Upload screens and sample files | `docs/demo/DEMO_DATA_GUIDE.md` |
>
> Kept for reference only.

---

# Demo Flow — e-DPC Portal (CGPSC)

**Demonstration day:** 2026-09-02 · **Re-verified:** 2026-09-02 07:2x · **Total running time:** ≈ 50 minutes (core path ≈ 42 minutes)
**Companions:** `DEMO_USERS.md` · `UPLOAD_FILES_GUIDE.md` · `DEMO_CHEAT_SHEET.md`

**Password for every account: `Demo@2026`** · **URL:** `http://localhost`

---

## How this flow is built

The demonstration database holds **a DPC sitting at every stage of the process at once**. That is deliberate, and it is what makes a 50-minute demonstration of a months-long statutory process possible: rather than driving one DPC through fourteen steps and waiting at each, you show the *data entry* on the one Draft proposal, then walk across DPCs that are already parked at each later stage.

| Stage | DPC | Who acts |
|---|---|---|
| Draft — data entry and uploads | **DPC-2026-SE-0004** | Authorized Officer |
| Submitted — awaiting approval | **DPC-2026-SE-0005** | Administrative Head |
| Approved — not yet allotted | **DPC-2026-SE-0006** | Chairman |
| Allotted — awaiting acceptance | **DPC-2026-SE-0007** | Dr. Suresh Chandra Netam (member3) |
| Scheduled — sitting not opened | **DPC-2026-SE-0008** | Shri Mohan Lal Kashyap (member4) |
| Sitting open — ready to conclude | **DPC-2026-SE-0009** | Smt. Anita Bhonsle (member5) |
| Concluded — result approved | **DPC-2026-SE-0001** | documents downloadable |
| Review DPC — a correction after conclusion | **DPC-2026-SE-0002** | Shri Brijesh Kumar Pandey (member1) |
| Circulation — a reserved promotion released | **DPC-2026-SE-0003** | Smt. Kavita Deshmukh (member2) |

**Steps 1–9 are the core path** — login to final recommendation. **Steps 10–14 are optional depth** for a longer session or specific questions.

---

# Step 1 — Sign in as the Authorized Officer

| | |
|---|---|
| **Module Name** | Authentication |
| **Purpose** | Enter the portal as the departmental officer who prepares a DPC |
| **Login User** | `ao.schooleducation@cg.gov.in` — Shri Anil Kumar Verma, Under Secretary, School Education |
| **Navigation Path** | `http://localhost` → **Login** |
| **Approximate time** | 2 minutes |

### Exact click sequence
1. Open `http://localhost`.
2. Click **Login** in the header.
3. **Login ID:** `ao.schooleducation@cg.gov.in`
4. **Password:** `Demo@2026`
5. Click **Login**.

### Expected result
The Dashboard opens, headed **Your workspace**, showing only the tiles this role may use: **Pre-DPC Proposals**, **DPC Proposals**, **Upload Templates**. No Commission tiles appear.

### Business explanation
The portal has six roles and each sees a different application. An Authorized Officer prepares proposals for one department; they cannot allot a DPC, conduct a sitting, or see another department's data. The role is granted by a **dated posting**, not by a property of the account — transfer the officer tomorrow and the access moves with them.

### What to explain during the demo
> "Notice what is *not* on this screen. There is no Allotments tile, no Proceedings tile. This officer physically cannot reach those pages — it is not a hidden menu, it is enforced on the server. And the note underneath says so: *only the areas your role permits are shown*."

### Validation to demonstrate
Point at the header: the login box accepted an e-mail, but it equally accepts a **mobile number** or an **employee number** — which is why the field is a single "Login ID" and not "Email". The third form exists for Promotion Candidates, who have neither e-mail nor mobile. (In this demonstration database those accounts cannot actually be signed into — see step 14 — so demonstrate the *mobile* alternative if you want to show it live: `9000001101` works for this same officer.)

### Report to open
None yet.

---

# Step 2 — Open the DPC and read its readiness

| | |
|---|---|
| **Module Name** | DPC Proposals |
| **Purpose** | Show the proposal that will be built, and the checklist that governs whether it may be submitted |
| **Login User** | Authorized Officer (already signed in) |
| **Navigation Path** | Dashboard → **DPC Proposals** → **DPC-2026-SE-0004** |
| **Approximate time** | 3 minutes |

### Exact click sequence
1. Click the **DPC Proposals** tile.
2. In the list, click **DPC-2026-SE-0004** — *Block Education Officer*.
3. Scroll to **Readiness for Submission**.

### Expected result
The proposal detail page opens showing status **Draft**, the promotion pair *Principal, Higher Secondary School → Block Education Officer*, the **Readiness for Submission** checklist with incomplete sections marked, and **Revision history**.

### Business explanation
A DPC is not one form but five sections that must each be complete before the proposal may leave the department. The checklist is the same rule set the submit button enforces, shown up front rather than discovered at the end — so an officer knows what is missing before they try.

### What to explain during the demo
> "This DPC is in Draft. The Commission cannot see it, and nothing here is binding yet. The checklist tells the officer exactly what is still outstanding — and the Submit button will refuse until every line is satisfied. The rule is written once and used in both places, so the checklist can never disagree with the button."

### Validation to demonstrate
The **Readiness for Submission** panel itself. Point out that the Tabulation Chart line is unsatisfied — and that this is what the next three steps will fix.

### Report to open
None yet.

---

# Step 3 — Upload the Currently Working Officers

| | |
|---|---|
| **Module Name** | Officers Under Consideration (S23 / S16) |
| **Purpose** | Record who already holds the target post — the input the vacancy engine subtracts from sanctioned strength |
| **Login User** | Authorized Officer |
| **Navigation Path** | DPC-2026-SE-0004 → **Officers Under Consideration** → **Upload from Excel** |
| **File** | `docs/demo/sample-data/demo-working-officers.csv` — **3 officers** |
| **Approximate time** | 5 minutes |

### Exact click sequence
1. On the proposal page, click **Officers Under Consideration**.
2. Click **Upload from Excel**.
3. Click **Choose file** and select `demo-working-officers.csv`.
4. Click **Upload & Preview**.
5. On the preview screen, read the counts, then click **Commit 3 Row(s)**.

### Expected result
Preview shows **3 valid, 0 rejected**. After commit the section lists 3 officers, and the vacancy summary shows **Total Vacancy 3**.

### Business explanation
Vacancy is never typed in — it is derived. Sanctioned strength minus officers actually working gives the clear vacancy; officers retiring within the DPC year and officers away on deputation each free a further post. Recording the officers is therefore the only input; the number follows from the rules.

### What to explain during the demo
> "Three officers against a sanctioned strength of four. The system now computes three vacancies, and it can tell you where each one comes from: one clear, because four minus three is one. One because Shri Ramkumar Verma retires on the 30th of June, inside the DPC year. And one because Shri Bhupendra Singh Thakur is on deputation with no return date — an officer still away frees the post. Nobody entered the number three. It was derived, and it can be explained line by line."

### Validation to demonstrate
**The sanctioned-strength ceiling.** Say aloud that a file carrying five officers against a sanctioned strength of four is refused outright — the system will not let a department record more people in a post than the post has. Also point out that **preview is not commit**: the numbers on screen have changed nothing yet.

### Report to open
The **vacancy breakdown** panel on the section itself.

---

# Step 4 — Upload the Final Seniority List

| | |
|---|---|
| **Module Name** | Seniority List (S24 / S17) |
| **Purpose** | Establish the candidate field — the officers on the feeder post, in seniority order |
| **Login User** | Authorized Officer |
| **Navigation Path** | DPC-2026-SE-0004 → **Seniority List** → **Upload from Excel** |
| **File** | `docs/demo/sample-data/demo-seniority-list.csv` — **8 officers** |
| **Approximate time** | 4 minutes |

### Exact click sequence
1. Return to the proposal and click **Seniority List**.
2. Click **Upload from Excel**.
3. Choose `demo-seniority-list.csv`.
4. Click **Upload & Preview**.
5. Click **Commit 8 Row(s)**.

### Expected result
**8 valid, 0 rejected.** The Seniority List holds 8 officers in order, mixed UR / SC / ST / OBC. **The Tabulation Chart now already contains the same 8 officers.**

### Business explanation
The Seniority List is the spine of the DPC. Everything downstream — who is considered, who is scored, who can be promoted — descends from it. It is therefore entered once and projected forward, never re-keyed, because two lists of the same people are two lists that can disagree.

### What to explain during the demo
> "Eight officers, in seniority order, with their categories. Now watch what happens when I open the Tabulation Chart — I have not touched it, but the officers are already there. The chart is *projected* from the Seniority List. The department cannot accidentally score somebody who is not a candidate, or leave out somebody who is."

### Validation to demonstrate
Open the **Tabulation Chart** immediately after committing, without uploading anything, and show the 8 officers already present with their identity columns filled. That projection is the validation.

### Report to open
None.

---

# Step 5 — Upload the Evaluation / Tabulation Chart

| | |
|---|---|
| **Module Name** | Tabulation Chart (S25 / S18) |
| **Purpose** | Record the Confidential Report grades, Loyalty and Property declarations and tribal-area service that the merit engine scores |
| **Login User** | Authorized Officer |
| **Navigation Path** | DPC-2026-SE-0004 → **Tabulation Chart** → **Upload from Excel** |
| **File** | `docs/demo/sample-data/demo-evaluation-chart.csv` — **8 rows, 33 columns** |
| **Approximate time** | 6 minutes |

### Exact click sequence
1. On the **Tabulation Chart** section, first click the **pre-filled template** download — show that it comes back already carrying the 8 officers and the CR year columns.
2. Click **Upload from Excel**.
3. Choose `demo-evaluation-chart.csv`.
4. Click **Upload & Preview**.
5. Click **Commit 8 Row(s)**.

### Expected result
**8 valid, 0 rejected.** The chart is complete: CR grades in Devanagari for 2019–2024, Loyalty and Immovable Property per year, tribal-area service against its own years.

### Business explanation
The Confidential Report grade for each of six years is what the merit engine actually scores. Two declarations — Loyalty and Immovable Property — are recorded per year alongside them, and tribal-area service is tied to the specific CR year it was earned in and is never carried forward to another year.

### What to explain during the demo
> "Six years of CRs per officer, graded in Devanagari exactly as the Commission grades them. Look at officer 5, Shri Jagdish Prasad Markam — tribal-area service marked against 2019, 2020 and 2021 specifically. It is deliberately not one tick-box for the officer, because the benefit belongs to the year it was earned in and cannot be carried forward. And officer 7 carries a note of CIR, which the engine will treat differently from a clean record."

### Validation to demonstrate
**The candidate-match rule — the strongest one to show.** Say that if the chart's officers do not match the Seniority List exactly — a missing serial number, an added one, or an employee number changed — the commit is refused and the offending serial numbers are named. The chart is not allowed to disagree with the list it came from.

Also worth a sentence: the blank template on *Upload Templates* spans 2021–2026, but **this** proposal's window is 2019–2024, which is why the pre-filled template is the right starting point.

### Report to open
None.

---

# Step 6 — Calculate and submit for approval

| | |
|---|---|
| **Module Name** | Eligibility Calculation + Workflow |
| **Purpose** | Score the chart, produce the merit list, and send the proposal to the Administrative Head |
| **Login User** | Authorized Officer |
| **Navigation Path** | DPC-2026-SE-0004 → **Eligibility Calculation** → **CALCULATE**, then **Submit for Approval** |
| **Approximate time** | 4 minutes |

### Exact click sequence
1. Return to the proposal page.
2. Under **Eligibility Calculation**, click **CALCULATE**.
3. Read the result, then click **Explain Result** on any officer.
4. Return to the proposal and confirm **Readiness for Submission** is now complete.
5. Click **Submit for Approval**.

### Expected result
A calculation result appears with each officer scored and ranked. After submission the status changes to **Submitted** and the proposal becomes read-only for the Authorized Officer.

### Business explanation
The engine turns six years of CR grades into a score, applies the eligibility gates, and ranks the field. Every figure it produces is explainable back to the record it came from — the system never asks anyone to trust a number it cannot justify.

### What to explain during the demo
> "This is the part that used to take a committee days on paper. And crucially — **Explain Result**. For any officer, the system will show which CR years it used, what each grade contributed, and which rule decided the outcome. If an officer questions their position, the department has an answer, not an assertion."

### Validation to demonstrate
Click **Explain Result** for one officer. Then note that once submitted, the Authorized Officer's edit buttons disappear — the proposal is locked to them and only the Administrative Head can now act.

### Report to open
**Eligibility Calculation** result, and **Explain Result** for one officer.

---

# Step 7 — Approve, sign and forward to the Commission

| | |
|---|---|
| **Module Name** | DPC Proposals — approval |
| **Purpose** | The departmental approval, with a digital signature, that releases the proposal to CGPSC |
| **Login User** | `ah.schooleducation@cg.gov.in` — Smt. Sunita Rathore, Secretary |
| **Navigation Path** | Logout → Login as AH → **DPC Proposals** → **DPC-2026-SE-0005** |
| **Approximate time** | 4 minutes |

> Use **DPC-2026-SE-0005**, which is already sitting in *Submitted* and waiting. If your own submission from step 6 has landed, either will do.

### Exact click sequence
1. Click **Logout**.
2. Log in as `ah.schooleducation@cg.gov.in` / `Demo@2026`.
3. Click the **DPC Proposals** tile — the head sees *Awaiting your approval*.
4. Open **DPC-2026-SE-0005**.
5. Click **Start the section-by-section review →** and page through the five sections.
6. Click **Approve, Sign & Forward to CGPSC** and confirm.

### Expected result
The proposal is approved, a digital signature is recorded against it, and it is forwarded to the Commission. It now appears on the Commission's *Proposals Awaiting Action* list.

### Business explanation
Departmental approval is a signed act, not a status change. The Administrative Head reviews each section in turn and either approves with a signature or returns the proposal with remarks. The signature is what makes the forwarded proposal a document the Commission can rely on.

### What to explain during the demo
> "Two different officers, two different responsibilities — the one who prepares cannot be the one who approves. And approval is digitally signed. From this moment the department cannot alter the proposal; if something is wrong, the Commission returns it, and that return is recorded too."

### Validation to demonstrate
Point at **Return for Correction**: the remarks field is **required**. The system will not let a proposal be sent back without saying why. Mention that return remarks are internal — the candidate sees *that* a proposal was returned and when, never what was said about them.

### Report to open
The section-by-section review screens.

---

# Step 8 — Commission allots the DPC to a Member

| | |
|---|---|
| **Module Name** | Allotments (S28) |
| **Purpose** | The Commission assigns the forwarded DPC to the Member who will conduct it |
| **Login User** | `chairman@cgpsc.gov.in` — Dr. Ajay Kumar Shrivastava |
| **Navigation Path** | Logout → Login as Chairman → **Allotments** |
| **Approximate time** | 4 minutes |

### Exact click sequence
1. **Logout**, then log in as `chairman@cgpsc.gov.in` / `Demo@2026`.
2. Click the **Allotments** tile.
3. Under **Proposals Awaiting Action**, find **DPC-2026-SE-0006**.
4. In **Allot to…**, choose a Member.
5. Optionally set a **Default DPC date** and a **Must be concluded by** date.
6. Click **Allot**.

### Expected result
The DPC moves to **DPC Allotted To** with the Member's name, the allotting officer and the timestamp. An entry appears in **Allotment History**.

### Business explanation
A DPC is conducted by one named Member of the Commission, and the allotment is the formal act that puts it in their hands. It carries a completion deadline, so a DPC cannot sit unattended, and it is fully reversible — a re-allotment is recorded with a reason rather than overwriting the original.

### What to explain during the demo
> "The Commission's workload is distributed here. Note the *Must be concluded by* date — the system tracks it and marks a DPC **Overdue** if it passes. And if a Member has to hand a DPC back, re-allotment demands a reason. The history shows who held it, when, and why it moved."

### Validation to demonstrate
Click **Re-allot** on an already-allotted DPC and show that **Reason for re-allotment** is required. Then show **Allotment History** on DPC-2026-SE-0007.

### Report to open
**DPC Allotment Report** on the same screen.

---

# Step 9 — Member accepts, schedules, conducts and approves the result

| | |
|---|---|
| **Module Name** | Schedules + Proceedings (S31–S34) |
| **Purpose** | The sitting itself — accept, schedule, open, calculate, and approve the final recommendation |
| **Login User** | Three Members in turn — see the sequence below |
| **Approximate time** | 10 minutes |
| **This is the end of the core path.** |

### 9a — Accept and schedule · `member3@cgpsc.gov.in` (Dr. Suresh Chandra Netam)
1. **Logout**, log in as `member3@cgpsc.gov.in` / `Demo@2026`.
2. Click the **Schedules** tile — *Acceptance & Scheduling of DPC*.
3. On **DPC-2026-SE-0007**, click **Accept DPC**.
4. Enter a **DPC date** and **time**, then click **Schedule**.

**Expected:** the DPC moves from allotted to accepted, then to scheduled with the date shown.

### 9b — Open the sitting · `member4@cgpsc.gov.in` (Shri Mohan Lal Kashyap)
1. **Logout**, log in as `member4@cgpsc.gov.in` / `Demo@2026`.
2. Click the **Proceedings** tile.
3. On **DPC-2026-SE-0008** (scheduled 10-09-2026), click **Open Proceeding**.

**Expected:** the sitting opens and the proceeding workspace appears.

### 9c — Calculate and approve the result · `member5@cgpsc.gov.in` (Smt. Anita Bhonsle)
1. **Logout**, log in as `member5@cgpsc.gov.in` / `Demo@2026`.
2. Click **Proceedings** → **DPC-2026-SE-0009**, whose sitting is already open → **Open Workspace**.
3. Click **CALCULATE**.
4. Review **DPC Result — Approval**: **MAIN LIST**, **Promotions Held (CIR)**, **Not Listed**.
5. Click **APPROVE BY CGPSC**.

**Expected:** the result is pinned permanently. The DPC reaches **Result Approved** — the final DPC recommendation.

### Business explanation
The sitting is where the Commission converts a scored chart into a recommendation. The Member calculates, examines the main list and any officer whose promotion is held, and approves. Approval pins that specific calculation for ever: a later recalculation cannot change what was approved, and no role can edit an approved result. A correction afterwards is a **Review DPC**, not an edit.

### What to explain during the demo
> "This is the final recommendation. Three groups: the main list — the officers recommended for promotion; promotions held, where a departmental inquiry or an adverse note means the Commission cannot recommend today; and those not listed. And read what the screen itself says before I approve: *approving pins this calculation permanently… a correction afterwards is a Review DPC*. Nobody, at any level, can quietly edit a promotion order after it has been approved. If it has to change, that change is itself a recorded, signed process."

### Validation to demonstrate
**Immutability.** After approving, show that the edit controls are gone. Then say plainly: even the Commission cannot alter this record — the only route to a change is a Review DPC, which is step 12.

### Report to open
**DPC Result — Approval**: MAIN LIST, Promotions Held (CIR), Officers Considered.

---

*The core demonstration ends here — login to final DPC recommendation.* The steps below add depth if there is time or a specific question.

---

# Step 10 — Reports *(optional, 3 minutes)*

| | |
|---|---|
| **Module Name** | Reports |
| **Purpose** | Show the Commission-wide picture and the CSV export |
| **Login User** | `admin@cgpsc.gov.in`, or any role — the screen scopes itself |
| **Navigation Path** | Header → **Reports** |

### Exact click sequence
1. Click **Reports** in the header.
2. Read **Where the DPCs are** — the pipeline by stage.
3. Show **DPCs by year** and **By department**.
4. Show **Corrections and circulations**.
5. Click **Export pipeline (CSV)**.

### Expected result
The pipeline shows: Drafted 1 · Awaiting departmental approval 1 · Forwarded to the Commission 7 · Allotted to a Member 6 · Sitting opened 1 · Result approved 3. The CSV downloads.

### Business explanation
The report scopes itself to the viewer: a department sees only its own DPCs, the Commission sees everything. Every figure is counted from stored records — nothing is recalculated for display, so the report cannot disagree with the workflow.

### What to explain
> "The same screen, different data depending on who opens it. And a DPC appears against every stage it has reached, so these deliberately do not sum to a total — it is a picture of where work is, not an accounting entry."

### Validation to demonstrate
Sign in as the AO and open the same screen — the figures narrow to School Education only.

### Report to open
**Where the DPCs are**, and the **Export pipeline (CSV)**.

---

# Step 11 — The completed DPC and its documents *(optional, 4 minutes)*

| | |
|---|---|
| **Module Name** | Proceedings — Documents |
| **Purpose** | Show the legal output: signed Proceedings and Promotion List |
| **Login User** | `member1@cgpsc.gov.in` (Shri Brijesh Kumar Pandey) or `chairman@cgpsc.gov.in` |
| **Navigation Path** | **Proceedings** → **DPC-2026-SE-0001** → **Documents** |

### Exact click sequence
1. Open **Proceedings** → **DPC-2026-SE-0001** (result approved).
2. Click **Documents**.
3. Open the **Proceedings** document, then the **Promotion List**.

### Expected result
Both documents open with real content — the proceedings record and the promotion list, each carrying a signature and a content hash.

### Business explanation
The output of a DPC is a document, and the system stores it with a cryptographic hash taken at the moment it was signed. That hash is how a department's copy can later be shown to be the copy the Commission issued.

### What to explain
> "This is the artefact the whole process exists to produce. It is signed, and it is hashed — so if anybody ever produces a different version of this promotion list, the difference is provable."

### Validation to demonstrate
The **verify** action on a document — it re-hashes the stored file and compares it to the hash taken at signing.

### Report to open
The **Proceedings** and **Promotion List** documents.

---

# Step 12 — Review DPC *(optional, 4 minutes)*

| | |
|---|---|
| **Module Name** | Review DPC (S35–S44) |
| **Purpose** | Show the only lawful way to correct a concluded DPC |
| **Login User** | `ao.schooleducation@cg.gov.in`, then `member1@cgpsc.gov.in` |
| **Navigation Path** | **DPC Proposals** → **DPC-2026-SE-0002** |

**DPC-2026-SE-0002 is a Review of DPC-2026-SE-0001** — a CR grade was corrected and the DPC re-run.

### What to explain
> "Step 9 ended with a result nobody can edit. So what happens when a CR grade turns out to have been wrong? A Review DPC. It copies the original, applies the correction, and re-runs — and the original stays on record marked *Superseded*, showing what it was superseded by and from when. Nothing is erased. The history of the decision survives the correction."

### Validation to demonstrate
Open the superseded original's document and show the **SUPERSEDED** banner naming the Review DPC that replaced it and the date it took effect.

---

# Step 13 — Circulation DPC *(optional, 3 minutes)*

| | |
|---|---|
| **Module Name** | Circulation (S45–S48) |
| **Purpose** | Release a promotion the Commission had reserved, without a fresh sitting |
| **Login User** | `member2@cgpsc.gov.in` (Smt. Kavita Deshmukh) |
| **Navigation Path** | **Circulations** → **DPC-2026-SE-0003/C1** |

### What to explain
> "Sometimes a promotion is held at the sitting — an inquiry pending, a report awaited. When that clears, the Commission does not reconvene. A circulation note goes round, the Members consent and sign, and the promotion is released. It is a decision already made, being resolved — which is why a circulation never scores or ranks anybody."

### Validation to demonstrate
The circulation documents screen, and the fact that publication is refused with a structured reason while a business decision is outstanding — a refusal with an explanation, not an error.

---

# Step 14 — The officer's own view: Self-Inspection *(optional, 3 minutes)*

| | |
|---|---|
| **Module Name** | Self-Inspection (S49–S51) |
| **Purpose** | Show how an officer checks the data recorded about them — **without any account at all** |
| **Login User** | **None.** Sign out first; this screen is deliberately public |
| **Navigation Path** | Logout → `http://localhost/self-inspection` |
| **Approximate time** | 3 minutes |

> **Do not attempt a Promotion Candidate login.** It will fail. Candidate accounts are issued a
> random password that has no delivery channel — see the explanation below, and `DEMO_USERS.md` §3.

### Exact click sequence
1. Click **Logout**.
2. Go to `http://localhost/self-inspection`.
3. Click through to **identify**.
4. Enter the three identity facts:
   - **Name (as recorded by your department):** `Shri Ashwani Kumar Sahu`
   - **Date of Joining Promotion To Post:** `01-03-2011`
   - **Date of Birth:** `08-06-1969`
5. Submit.

### Expected result
The identity is accepted and the officer's own record opens — verified on 2026-09-02 returning
**200 with 18,512 bytes** of real content.

### Business explanation
An officer on a Seniority List has no portal account, because a Seniority List records no e-mail
address and no mobile number. Self-Inspection exists for exactly that person: they prove who they
are from three facts their own department already recorded, and see the data held about them —
nothing about anybody else.

### What to explain during the demo
> "This officer has no account, and could not be given one — the department's records hold no
> e-mail and no mobile for them. So rather than invent a credential, the portal lets them prove
> who they are from what is already on record: their name as the department wrote it, their date
> of birth, and the date they joined the post. They see their own data and nothing else. Every
> view and every print is recorded, so the department knows who looked."

### Validation to demonstrate
Enter a wrong date of birth and show the refusal. Note that failed attempts are rate-limited
(`throttle:self-inspection`) and that a failed attempt does not store the answers given.

### Report to open
The Self-Inspection **record** screen, and its **print** view.

### On Promotion Candidate logins — if asked
The login box genuinely accepts an **employee number** as a third identifier, and it exists
precisely because a candidate has neither e-mail nor mobile. What is missing is a way to deliver
the *first* password to somebody with no channel to receive it — a known gap with a recorded
proposal (`SUGGESTIONS_AND_IMPROVEMENTS.md` item #4). Say so plainly; Self-Inspection is the
route the system actually provides today.

---

# Timing summary

| Steps | Section | Time |
|---|---|---|
| 1–2 | Login and proposal readiness | 5 min |
| 3–5 | The three uploads | 15 min |
| 6–7 | Calculate, submit, approve and sign | 8 min |
| 8–9 | Allotment, sitting and final recommendation | 14 min |
| | **Core path total** | **≈ 42 min** |
| 10–14 | Reports, documents, Review, Circulation, candidate view | +17 min |
| | **Full demonstration** | **≈ 59 min** |

Trim by dropping steps 12–14 first; they are the least likely to be asked about unopened.

---

# Before you start

1. Confirm MySQL and Redis are running.
2. Sign in as `admin@cgpsc.gov.in` and confirm the dashboard renders.
3. Open one **Proceedings** document to confirm storage is intact.
4. Have `docs/demo/sample-data/` open in a file manager, ready to pick from.
5. Confirm **DPC-2026-SE-0004** is still in **Draft**. If a rehearsal submitted it, rebuild with `php artisan demo:seed --force` (≈ 35 seconds).

**Do not** attempt the `ds.gad@cg.gov.in` login — it is an external committee member, not a portal account, and it leads nowhere.

**Do not** attempt a Promotion Candidate login (`CG100037` and the rest). Verified failing: those
accounts hold a random password with no delivery channel. Use **Self-Inspection** (step 14).

**Never run `php artisan test` on the demonstration machine.** The suite includes
`ResetTransactionalDataTest`, which invokes `dpc:reset-transactional-data`. That command deletes
`storage/app/private/{proceedings,circulations,signatures,imports,record-gaps}` by real filesystem
path, bypassing the Storage facade — so every stored Proceedings document, Promotion List,
Circulation note and signature is destroyed, and the downloads in steps 11–13 go blank. Recovery
is `php artisan demo:seed --force`.
