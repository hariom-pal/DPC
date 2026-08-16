# e-DPC Portal — Demonstration Guide

**For the presenter. Not a technical document.**

Read section 13 the morning of the demonstration and section 14 on the way in. Everything before them is background you can draw on when a question goes deeper than the script.

**One sentence to open with, if you only get one:** *this portal conducts the entire departmental promotion process online — from the department's proposal to the Commission's signed promotion list — and every decision in it can be explained, traced and verified years later.*

---

## 1. Introduction

A Departmental Promotion Committee (DPC) decides which eligible officers are promoted against available vacancies. Today that process moves on paper between the department and the Commission: a proposal is compiled, a sitting is held, marks are worked out, a list is issued.

The e-DPC Portal does the same process online, in the same order, with the same rules. Nothing about how a DPC works has been redesigned. What has changed is that each step is recorded as it happens, the arithmetic is done once by a fixed engine rather than by hand, and every document the Commission issues can be checked against what it actually issued.

**Three phrases worth using, because they are what senior officers listen for:**

- **Paperless** — the proposal, the sitting, the result and the promotion list exist as records, not as files in a cupboard.
- **Auditable** — who did what, and when, is recorded permanently and cannot be quietly edited.
- **Defensible** — when a promotion is challenged in CAT or the High Court, the system can show what was decided, on what data, under which rules, and who signed it.

---

## 2. What problem this system solves

Be concrete here. These are the failures the portal is designed to prevent, and each maps to something you can show on screen.

| The problem on paper | What the portal does |
|---|---|
| **The arithmetic is done by hand**, differently by different people, and a mistake is only found when someone complains | Marks are computed once by a fixed engine from the confidential reports on record. The same officer and the same data always produce the same result |
| **Nobody can reconstruct why an officer was refused** two years later | Every result explains itself: the years used, the marks, the gates applied and the threshold for that class pair |
| **A correction means retyping the whole list**, and the old list is still in circulation | A Review DPC corrects a concluded DPC without altering it, supersedes it on approval, and marks the old documents as superseded — while leaving them readable |
| **An officer with an incomplete record holds up everyone else** | The officer takes part, the promotion alone is reserved, and a Circulation note releases it later on evidence |
| **A document in a department's hands may not be the one the Commission issued** | Every document is stored unchanged and carries a content hash. A copy can be verified against it |
| **"Who approved this?" has no reliable answer** | Every approval is digitally signed, with an OTP step-up recorded as evidence of intent |

---

## 3. Registration flow

**Show it, don't only describe it — it is the first screen a new department sees.**

1. A department opens the portal and chooses **New Registration**.
2. It supplies the department, district, office details, and the names, mobile numbers and e-mail addresses of two officers: the **Authorized Officer** (who prepares proposals) and the **Administrative Head** (who approves them).
3. Both are verified — **e-mail and mobile OTP**, not one or the other.
4. The **Commission approves the registration** and credentials are issued.
5. On first login the officer is **forced to change the system-issued password** before reaching any screen.

**The point to make:** a department cannot simply create an account and start submitting promotion proposals. Access is granted by the Commission.

---

## 4. Super Admin — what they can actually do

The Super Admin runs the portal on the Commission's behalf. This is the role senior management asks most about, so be precise.

**Can:**

- **Approve or reject department registrations**, and issue credentials.
- **Maintain master data**: cadres and posts, pay scales, designations, districts, reservation categories, the reservation roster and its points, committee types and composition rules, and the versioned **recruitment rules**.
- **Manage users** — create, deactivate, assign roles.
- **See integration status** — which external services (SMS, digital signature, HRMS) are switched on.

**Cannot — and this is the more important half:**

- **Cannot change any officer's marks.** Marks come from the engine and the confidential reports on record.
- **Cannot approve a DPC result.** That is the Commission's Member, in a proceeding.
- **Cannot edit or delete an audit record, a signed document, or a concluded DPC.**

**Say this plainly:** the administrator maintains the rules and the reference data; the administrator does not decide promotions. That separation is deliberate and it is what makes the audit trail worth having.

---

## 5. Department Administrator

Two officers, deliberately separate:

**Authorized Officer (AO)** — prepares.
- Creates the DPC proposal: post being filled and post promoted from, class, committee, working officers, vacancies (regular and ad-hoc, with reservation), the **seniority list**, and the **evaluation chart** (CRs, loyalty, immovable property, notes).
- May upload the three chart sections from Excel — with a preview, validation and an error report before anything is saved.
- Raises **Review** and **Circulation** proposals.

**Administrative Head (AH)** — approves.
- Walks through every section, returns it with remarks, or approves it.
- **The approval is a single digital signature covering the whole proposal.**
- On approval the DPC receives its **DPC ID** and is forwarded to the Commission.

**The point to make:** the person who prepares a proposal is never the person who approves it.

---

## 6. Chairman

- Receives forwarded DPCs and **allots** each to a Member.
- May **re-allot** — the previous allottee stays visible on the record, struck through beside the new one.
- Is accountable for the Commission's work on every DPC.
- **May conduct a sitting only after re-allotting it to himself.**

**Worth saying:** that last rule exists so that exactly one person is accountable for any given DPC. It is the first question asked when a promotion is contested.

---

## 7. Member

- **Accepts** the allotment and **schedules** the sitting (and may re-schedule, with the old date shown beside the new).
- On the day, **opens the proceeding** and works on the evaluation chart:
  - corrections are recorded **beside** the department's entry, never over it, each with a stated reason;
  - the department's original submission is fingerprinted at the moment of opening.
- Runs **CALCULATE** — the engine produces the Main List and the Waiting List.
- **APPROVE BY CGPSC**, or **CANCEL DPC**.
- Signs **circulation notes** releasing reserved promotions.

**Show the corrections screen if you have time.** It is the most persuasive screen in the system: the department's value and the Commission's correction sit side by side, permanently.

---

## 8. Commission

The institutional authority the whole process runs under.

- Approves results by CGPSC.
- Consents to the circulation of reserved promotions.
- Publishes signed proceedings and promotion lists to the department.
- **A provisional result cannot be published.** Where an unsettled rule could have changed the outcome, the system marks the result provisional and refuses publication rather than issuing a list it cannot stand behind.

---

## 9. Review DPC

**What it is:** a new DPC that corrects an error found in a concluded one — a name left off the seniority list, a wrong roster point, a mis-entered CR, tribal-area service not counted, a reservation rule misapplied.

**How to explain it in one breath:** *the Review is a fresh sitting on corrected data, which references the original, states why it was raised, and replaces it — without ever altering the original record.*

**Four things worth showing or saying:**

1. The chart arrives **pre-filled** from the original. The department corrects; it does not retype.
2. The Review is decided **under the rules that applied when the original DPC was held** — not today's. A correction to fix a typing error must not quietly re-decide eligibility under thresholds introduced since.
3. Supersession takes effect **when the Review is approved**, not when it is raised. A Review that is abandoned leaves the original standing.
4. The original's documents stay downloadable and are marked **SUPERSEDED** — and the watermark is added when the document is served, so the stored file and its hash remain exactly as issued.

---

## 10. Circulation DPC

**What it is:** the release of a promotion the Commission *reserved*.

**The situation:** an officer's confidential reports are missing for two or more years, or the immovable-property return is not recorded. The officer **still takes part** in the DPC and is still evaluated — only the promotion action is held (shown as **CIR** on the list). When the department supplies the missing record, a **circulation note** goes to the Commission, and on signature the promotion proceeds.

**Three things worth showing or saying:**

1. **The DPC is not re-run.** No marks are recalculated and no other officer's position changes. This is a decision being released, not a promotion being re-decided.
2. The before-and-after view shows the same result twice — with `CIR` and without it.
3. **The signature is what releases the promotion**, not the consent. A note that is never signed leaves the promotion reserved.

**If asked about CR-missing cases:** the portal currently accepts circulations for missing property returns and refers CR cases to the Commission for a ruling, because supplying missing CRs changes the officer's marks and can move other officers on the list. **Say that as a strength** — the system refuses to guess.

---

## 11. Reports

- **Proceedings document** and **promotion list** for every concluded DPC.
- **Circulation note** and **revised promotion list** for every circulation.
- Every document: **downloadable, verifiable, and version-histories kept** — regenerating produces a new version rather than overwriting.
- Calculation history per DPC, with **Explain Result** for each officer.

---

## 12. Audit

Two independent records, and it is worth naming both:

1. **The audit trail** — every change to every business record, with the actor and the time.
2. **The activity timeline** — the human-readable history of a DPC: submitted, approved, allotted, scheduled, opened, corrected, calculated, approved, published.

**The strong claim, which the system can actually back:** the timeline is complete enough that a notification or a calendar entry can be rebuilt from it alone, without reading a single business table. Actor names are **snapshotted**, so a user later renamed or removed does not change what the record says happened.

---

## 13. Demo flow — screen by screen

**Before you start:** open the portal home page and leave it on screen while people settle. Have the login credentials for a department AO, an AH, and a Commission Member ready. Allow 20–25 minutes.

| # | Open this | Do this | Say this | You should see |
|---|---|---|---|---|
| 1 | **Home page** (`/`) | Scroll slowly through Process Flow and Statistics | "The whole promotion process, ten stages, one portal. The figures are live from this installation." | Government-style landing page, statistics counting up |
| 2 | **New Registration** | Do not submit — just show the form and the notice above it | "A department registers; the Commission approves. Nobody self-registers into the promotion process." | Registration form, with the "what happens next" notice |
| 3 | **Login** as **Authorized Officer** | Sign in | "Two officers per department: one prepares, one approves." | Dashboard with the department workspace tiles |
| 4 | **DPC Proposals → a proposal** | Open the evaluation chart | "This is the department's submission — CRs, loyalty, property, notes. Uploaded from Excel or typed." | The tabulation chart |
| 5 | Same proposal | Point at the seniority list and vacancies | "Vacancies are recorded by type and reservation category, against the roster." | Vacancy and seniority sections |
| 6 | **Log in as the Administrative Head** | Walk the review, then approve | "One signature covers the whole proposal, and it is an OTP step-up recorded as evidence of intent." | Approval succeeds; a DPC ID is issued |
| 7 | **Log in as the Chairman** | Allot the DPC to a Member | "The Chairman allots. He can re-allot, and the previous allottee stays on the record." | Allotment recorded |
| 8 | **Log in as that Member** | Accept, then schedule | "The Member accepts and fixes the date." | Schedule recorded |
| 9 | **Proceedings → open the sitting** | Show the corrections screen; correct one CR grade with a reason | **The key line:** "The correction sits *beside* the department's entry, not over it. Both are permanent." | Original and corrected values side by side |
| 10 | Same screen | Press **CALCULATE** | "The engine applies the published rules — grade marks, the tribal bonus, the qualifying score, the two hard gates." | Main List and Waiting List |
| 11 | **Explain Result** for one officer | Open it | "Every officer's mark can be explained: the years used, each grade, the threshold and the reason." | Per-officer breakdown |
| 12 | Point at an officer marked **CIR** | Do not click | "This promotion is reserved — the record is incomplete. The officer still took part." | `CIR` on the list |
| 13 | **APPROVE BY CGPSC** | Approve | "The result is approved and the proceedings and promotion list are generated and signed." | Documents generated |
| 14 | **Documents** | Download one, then press **Verify** | "The document carries a content hash. This is how a department proves its copy is the copy the Commission issued." | Verification confirms the file is intact |
| 15 | **Review DPC** (if time) | Show a Review's chain screen | "A concluded DPC can be corrected without touching the original. The Review supersedes it, and the old documents are watermarked but stay readable." | The correction chain |
| 16 | **Circulation** (if time) | Open a signed circulation's before/after | "The reserved promotion is released. Nothing was recalculated and nobody else moved." | The two result tables |

**If you are short of time, drop steps 4, 5, 7 and 8.** Never drop **9, 10, 11 and 14** — corrections, calculation, explanation and verification are the four that convince people.

---

## 14. Expected questions, with answers

Short answers first; the extra sentence is there if they press.

**Why is a Review DPC needed? Can't we just fix the list?**
Because the first list was signed and issued, and somebody may already have acted on it. A Review produces a corrected decision that references the original, states its grounds, and supersedes it — so the record shows both what was decided and what corrected it. Editing the original would destroy the evidence of what actually happened.

**Why Circulation? Why not just wait for the record?**
Because one officer's incomplete file would otherwise hold up everyone else's promotion. Circulation lets the DPC conclude for everyone, reserves that one promotion, and releases it later on evidence with the Commission's consent.

**Why so much emphasis on audit?**
Because promotions are contested. When a case reaches CAT or the High Court, the question is not whether the system is modern — it is what was decided, on what data, under which rules, and who signed it. The audit trail is the answer to that question, prepared in advance.

**Why digital signature? Is a login not enough?**
A login proves who is at the keyboard. A signature proves intent at a specific moment on specific content. Approving a promotion list is a legally significant act, and it is signed with an OTP step-up recorded as evidence of intent.

**What is a "freeze"? Why does it matter?**
Once a part of the system is complete and approved — the scoring engine, for example — it is frozen: it cannot be changed casually. Business rules change through **configuration, master data or a government notification**, never by editing the software. The benefit to you: a promotion decided in 2026 can be re-checked in 2030, and the engine that decided it has not silently drifted.

**Can the administrator change someone's marks?**
No. The administrator maintains master data and rules. Marks come from the engine, from the confidential reports on record. No role in the system can type a mark in directly.

**What if the rules change next year?**
Configuration and master data are versioned. Each calculation stores the complete rule set it ran under, so an old result still explains itself under the rules that applied to it — and a Review of an old DPC is decided under those same rules, not today's.

**Is the data secure?**
Access is by role, by department and by workflow state, checked at three layers. External integrations are switched off by default and enabled deliberately. Nothing that constitutes evidence is ever overwritten.

**How much of it is complete?**
Registration, master data, the DPC proposal, the scoring engine, the Commission workflow, the proceeding and results, Review DPC and Circulation DPC. Remaining: self-inspection by officers, and reporting dashboards.

**Are there open items?**
Yes, and stating them is better than being caught by them. A small number of **business rules await the Commission's ruling** — for example the exact date from which a circulated promotion takes effect. **Where a rule is unsettled, the system applies the printed rule, flags the case, and refuses to publish rather than guessing.** That is deliberate: a wrong published promotion list is far worse than a delayed one.

---

## 15. Things to avoid saying

- Do not promise dates for anything not yet built.
- Do not say "the system decides promotions". It **computes marks and applies published rules**; the Commission decides.
- Do not describe an unsettled rule as a defect. It is a question the Commission has not yet answered, and the system is behaving correctly by not guessing.
- If something fails during the demo, say what you were about to show and move on. Do not debug in front of the audience.

---

## 16. Pre-demonstration checklist

- [ ] Portal reachable, home page loads, logos correct
- [ ] Credentials ready: AO, AH, Chairman, Member, Admin
- [ ] At least one DPC at each stage: draft, approved, scheduled, concluded, reviewed, circulated
- [ ] At least one officer showing **CIR** on a concluded DPC
- [ ] At least one generated, signed document to download and verify
- [ ] Browser zoom at 100%, one clean window, no other tabs
- [ ] Screen sharing tested; font size legible from the back of the room
