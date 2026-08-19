# Demo Script

A step-by-step business demonstration of the ODPC Portal, following the PPT slide by slide.

**Created 2026-08-13 by architect directive.**

**Duration:** ~35 minutes for the full run · ~15 minutes for the short version (marked ★)
**Audience:** CGPSC officials and department users — no technical knowledge assumed

---

## Before the room

**Accounts needed.** Have all five logged in on separate browser profiles or windows, so switching roles is instant rather than a pause for typing passwords:

| Role | Represents | Used in |
|---|---|---|
| CGPSC Admin | Commission administrator | Part 0 |
| Authorized Officer (AO) | Department, Level-1 | Parts 1–4 |
| Administrative Head (AH) | Department, Level-2 | Part 5 |
| Chairman | Hon'ble Chairman, CGPSC | Part 7 |
| Member | Hon'ble Member, CGPSC | Part 8 |

**Data to prepare in advance:** a department with approved Pre-DPC posts and cadres. Preparing that live wastes ten minutes on the least interesting part.

**Have ready:** one seniority-list spreadsheet that is correct, and one with a deliberate error in a single row. Part 4 is more convincing with a real file.

### Say this at the start

> Three things are switched off for this demonstration, deliberately.
>
> **SMS and e-mail** are not connected — no government gateway has been contracted yet, so messages are recorded rather than sent.
> **HRMS** is not connected — every officer's details are typed in or uploaded.
> **The digital signature** uses a stand-in. In the live system, production will **refuse** to approve a proposal without a real CSC signature rather than fall back to a stand-in.
>
> Everything else you will see is the real system.

Saying this first prevents the three questions that would otherwise interrupt at the worst moments.

---

## Part 0 — Integration status *(2 min)*

**As CGPSC Admin → `/admin/integrations`**

| Step | Say |
|---|---|
| Show the flag table | "Every external connection is a switch. All of them are off, and the system still runs completely." |
| Point at the fallback column | "Each one says what happens while it is off. Off never means broken." |
| Point at CSC DigiSign | "This is the one that behaves differently in production: with it off, production refuses to sign rather than pretending." |

**Why open with this:** it answers "is this ready for the real world?" before anyone has to ask.

---

## Part 1 ★ — The proposal begins *(S12–S14, 3 min)*

**As AO → DPC Proposals → Create**

| Step | Screen | Say |
|---|---|---|
| Choose Post-From and Post-To | S14 | "Under Secretary to Deputy Secretary. These come from the department's approved posts — not typed in, so they cannot drift." |
| Set DPC type and proposal date | S14 | |
| Save | | "Now look at the readiness checklist." |
| **Point at the checklist** | | "The system knows what is still missing. It will not let this be sent for approval until it is complete." |

---

## Part 2 ★ — Committee and officers *(S15–S16, 4 min)*

| Step | Screen | Say |
|---|---|---|
| Add the Chairman and members | S15 | |
| **Try adding a second Chairman** | S15 | "Refused. Exactly one Chairman." |
| Enter sanctioned post count | S16 | |
| Add vacancies by category — 1 UR clear, 1 ST from retirement | S16 | "Vacancies are recorded one row per type and category — never lumped together, so the reservation position is always visible." |
| Enter the working officers | S16 | |

---

## Part 3 ★ — Seniority and evaluation *(S17–S18, 5 min)*

| Step | Screen | Say |
|---|---|---|
| Enter two or three officers manually | S17 | "Every officer is a snapshot taken now. If HR records change next year, this proposal does not." |
| Open the Evaluation Chart | S18 | |
| Enter year-wise CR, loyalty and property for one officer | S18 | "Loyalty and immovable property are recorded per year, as the Commission's format requires." |
| **Point at the absent Mark and Remark** | S18 | "The department records facts. It never records a score. Marks and remarks are the Commission's, calculated later." |

---

## Part 4 — Uploading from Excel *(S16–S18, 4 min)*

**This is the part departments will care most about.**

| Step | Say |
|---|---|
| Upload the **correct** spreadsheet | "Nothing has been saved yet." |
| Show the preview and the counts | "You see exactly what will happen before it happens." |
| Commit | "Now it is saved." |
| Upload the **broken** spreadsheet | |
| **Point at the rejected row** | "It tells you the row number in *your* file and what is wrong with it." |
| Download the error report | "Take this back, fix those rows, upload again." |
| Show the good rows still committable | "One bad row does not cost you the whole file." |
| Upload the same file again | "It recognises the file and warns you." |

---

## Part 5 ★ — Approval by the Administrative Head *(S19–S25, 5 min)*

**Switch to AH**

| Step | Screen | Say |
|---|---|---|
| Open the proposal | S19 | "The Head is invited to review it section by section." |
| Walk steps 1 → 4 | S21–S24 | "Posts. Committee. Officers. Seniority." |
| **Point out there is no Approve button yet** | | "You cannot approve until you have been through all of it." |
| Reach step 5 | S25 | "Now approval appears." |
| Approve | | "One approval. One signature. Covering everything." |
| **Show the DPC ID** | | "Generated on approval, and forwarded to the Commission." |
| Try to edit a section | | "Locked. An approved proposal cannot be changed — corrections go through a Review DPC." |

*If time allows:* open the signed document and scroll it. "The signature covers all five sections, not a cover page."

---

## Part 6 ★ — The calculation *(S33, 5 min)*

**The centrepiece. Do not rush it.**

| Step | Say |
|---|---|
| Press **CALCULATE** | |
| Show the Main List and Waiting List | "Main list and supplementary list, per category." |
| **Open one officer → Explain** | "This is why this officer is where they are." |
| Read the explanation aloud | "Which years counted, what each was worth, the total, the requirement, the outcome." |
| Open a **refused** officer | "And every reason they were refused — not just the first." |
| **Point at the rule version** | "This calculation records the rules it ran under. If the rules change next year, this result still explains itself under the rules that applied to it." |
| Press CALCULATE again | |
| Open the history | "A new calculation. The old one is still here, unchanged. Nothing is ever overwritten, and nobody — including an administrator — can edit a result." |

**If a provisional warning shows:** "That is the system telling you a rule has not been settled. It applied the printed rule and flagged the case rather than guessing. Those are the two questions still with CGPSC."

---

## Part 7 ★ — Allotment by the Chairman *(S27–S28, 4 min)*

**Switch to Chairman**

| Step | Screen | Say |
|---|---|---|
| Open Allotment | S28 | "Only proposals a department has approved and forwarded appear here." |
| Allot to a Member with a default DPC date | S28 | |
| Show the Allotment Report | S28 | "Status and default date, as in the Commission's format." |
| **Re-allot to another Member with a reason** | S28 | "Suppose the first Member cannot take it." |
| **Point at the struck-through name** | | "The previous allottee is still shown. Nothing is erased." |
| Open the allotment history | | "Every allotment, in order, with reasons and who made them." |

---

## Part 8 ★ — Scheduling by the Member *(S29–S30, 4 min)*

**Switch to Member**

| Step | Screen | Say |
|---|---|---|
| Show the Member's list | S30 | "A Member sees only the DPCs allotted to them." |
| Accept | S30 | |
| Set the date and time — **12/07/2026, 2:00 PM** | S30 | "The Commission's own example." |
| Show the Scheduling Report | S30 | "DONE, with the date and time." |
| **Re-schedule to 15/07/2026 with a reason** | S30 | |
| **Point at the struck-through slot** | S30 | "Exactly as the Commission's format shows it — the old date beside the new." |

---

## Part 9 — Why the record can be trusted *(3 min)*

**The closing argument. This is what distinguishes the system from a form-filling tool.**

| Step | Say |
|---|---|
| Open any workflow history | "Every step, who took it, when." |
| **Change a user's display name, then re-open the history** | "The history still shows who acted, as they were then. Renaming someone cannot rewrite the past." |
| Show a calculation result | "No role can edit this. Not the officer, not the Head, not an administrator." |
| Point at a web address | "References are long codes, never sequential numbers — so nobody can guess their way to a neighbouring record." |

### Close with this

> If a promotion is challenged in a tribunal three years from now, this system can show what was decided, who decided it, when, on what data, and under which rules — and demonstrate that none of it has been altered since.
>
> That is the point of it.

---

## Questions you should expect

| Question | Answer |
|---|---|
| "When will SMS work?" | As soon as a government gateway is contracted. The system is built for it; nothing needs rewriting. It is the highest-value outstanding integration. |
| "Can we connect our HRMS?" | Yes, and it is designed for. HRMS would pre-fill forms; it can never change a saved proposal or affect the workflow. |
| "Can the marks be changed if a rule changes?" | The rules are configuration. A government notification changes a setting, not the software. Past calculations keep the rules they ran under. |
| "What if we make a mistake after approval?" | A Review DPC — a new proposal correcting the original, which stays intact. |
| "Can this be used by another state?" | The rules and integrations are configuration. That was a design goal. |
| "Why does it refuse to sign in production?" | Because a promotion order carrying a stand-in signature would look valid while being legally worthless. Better to refuse. |

---

## If something goes wrong live

- **A screen errors** — move on and note it. Do not debug in front of the room.
- **A rule seems wrong** — say "that is one of the two rules still with CGPSC" if it concerns punishment or reserved relaxation; otherwise note it as a finding.
- **Running short** — the ★ parts alone tell the whole story: proposal → approval → calculation → allotment → scheduling.
- **Running long** — Part 4 (Excel) and Part 9 (trust) are the ones to cut, in that order. Part 6 is not cuttable; it is the reason the system exists.
