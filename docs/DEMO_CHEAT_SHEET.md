# e-DPC Portal — Demo Cheat Sheet

**One page to keep open while presenting.** Narrative and talking points are in
[`DEMO_GUIDE.md`](DEMO_GUIDE.md); the slide-by-slide script is in
[`DEMO_SCRIPT.md`](DEMO_SCRIPT.md). This file is the data: who to log in as,
what the records are called, and which screen to open next.

Rebuild the whole thing at any time with:

```bash
php artisan demo:seed          # drops and rebuilds the DEV database, then prints these credentials
php artisan demo:seed --force  # same, without the confirmation prompt
```

The command prints the account table and the identifiers below from the database
itself, so it is never out of step with what is actually seeded.

**One department.** Every record in the demo database — every master, every
officer, every DPC — belongs to the **School Education Department**. Nothing on
any screen belongs to a department that has not been introduced, and there is
never a moment in the demonstration that needs the phrase "ignore that, it's
another department".

---

## 1. Department

| | |
|---|---|
| **Department** | School Education Department |
| **Code** | `SE` — the prefix of every DPC ID it is issued |
| **District / Place** | Raipur — Indravati Bhawan, Naya Raipur Atal Nagar, 492002 |
| **Contact email** | schooleducation@cg.gov.in |
| **Status** | Registered — through the real S5–S6 registration service, not an inserted row |

The department master still lists all nine Chhattisgarh departments, because
that list is what the registration dropdown is drawn from and showing it is the
point of it. School Education is the only one that is registered.

### The promotion ladder

Nine sanctioned posts, created by **approving the Pre-DPC** — not typed into the
Cadre master. Every DPC in the demo is a promotion along one rung of it, so no
two rows on a list screen are promotions to the same cadre.

| Post | Class | Scale | Feeder post(s) |
|---|---|---|---|
| Teacher | III | L10 | — (100% direct recruitment) |
| Head Master, Middle School | III | L10 | Teacher |
| Lecturer | II | L11 | **Teacher + Head Master** — two feeders on one rule |
| Principal, Higher Secondary School | II | L12 | Lecturer |
| Block Education Officer | II | L12 | Principal, HSS |
| District Education Officer | I | L12 | Principal, HSS |
| Joint Director, Education | I | L13 | District Education Officer |
| Additional Director, Education | I | L13A | Joint Director, Education |
| Director, Public Instruction | I | L14 | Additional Director, Education |

---

## 2. Credentials

**The password for every account is `Demo@2026`.** All accounts are verified and
past the first-login password change, so each one signs straight in. Log in with
either the email or the mobile number.

### The department — School Education

| Role | Name | Email | Mobile |
|---|---|---|---|
| **Authorized Officer (AO, Level-1)** | Shri Anil Kumar Verma | `ao.schooleducation@cg.gov.in` | `9000001101` |
| **Administrative Head (AH, Level-2)** | Smt. Sunita Rathore | `ah.schooleducation@cg.gov.in` | `9000001102` |

### The Commission — Chairman

| Role | Name | Email | Mobile |
|---|---|---|---|
| **Chairman** | Dr. Ajay Kumar Shrivastava | `chairman@cgpsc.gov.in` | `9000000101` |

One Chairman, who allotted every DPC in the demo.

### The Commission — Members

Five Members, because a Member's queue is personal to them and the demo needs
five different Commission-side situations on screen at once. **The right-hand
column is the reason each one exists** — sign in as the one whose situation you
are about to demonstrate.

| Role | Name | Email | Mobile | Their queue holds |
|---|---|---|---|---|
| **Member** | Shri Brijesh Kumar Pandey | `member1@cgpsc.gov.in` | `9000000201` | **`SE202600001` and its Review `SE202600002`** — both concluded |
| **Member** | Smt. Kavita Deshmukh | `member2@cgpsc.gov.in` | `9000000202` | **`SE202600003`** — concluded with a held officer; signs the Circulation |
| **Member** | Dr. Suresh Chandra Netam | `member3@cgpsc.gov.in` | `9000000203` | `SE202600005` — an allotment **waiting to be accepted** |
| **Member** | Shri Mohan Lal Kashyap | `member4@cgpsc.gov.in` | `9000000204` | `SE202600006` — a sitting **scheduled, not yet opened** |
| **Member** | Smt. Anita Bhonsle | `member5@cgpsc.gov.in` | `9000000205` | `SE202600007` — a sitting **open right now**, result calculated |

### Administration

| Role | Name | Email | Mobile |
|---|---|---|---|
| **CGPSC Admin** | CGPSC Administrator | `admin@cgpsc.gov.in` | `9990000000` |

---

## 3. Identifiers

### The three the presentation turns on

| Record | Identifier |
|---|---|
| **DPC ID** | **`SE202600001`** |
| **Review DPC ID** | **`SE202600002`** |
| **Circulation ID** | **`SE202600003/C1`** |

### Every DPC in the database

Nine proposals, one per rung of the ladder, one per workflow state. The `#`
column is the proposal id, so `/dpc-proposals/1` opens the first row — stable on
any freshly seeded database.

| # | DPC ID | Promotion | State | Who holds it |
|---|---|---|---|---|
| 1 | **`SE202600001`** | District Education Officer → Joint Director | Concluded, published — **superseded** by the Review | `member1` |
| 2 | **`SE202600002`** | District Education Officer → Joint Director | **Review DPC**, concluded, published — **operative** | `member1` |
| 3 | `SE202600003` | Lecturer → Principal, HSS | Concluded with **one officer held** → the Circulation | `member2` |
| 4 | *(no ID yet)* | Principal, HSS → Block Education Officer | **Draft** — every section filled, ready to submit | AO |
| 5 | *(no ID yet)* | Teacher → Head Master, Middle School | **Submitted** — waiting on the AH | AH |
| 6 | `SE202600004` | Teacher → Lecturer | **Approved & forwarded** — waiting to be allotted | Chairman |
| 7 | `SE202600005` | Principal, HSS → District Education Officer | **Allotted** — not yet accepted | `member3` |
| 8 | `SE202600006` | Additional Director → Director, Public Instruction | **Scheduled** — sitting not yet opened | `member4` |
| 9 | `SE202600007` | Joint Director → Additional Director | **Sitting open**, result calculated, not approved | `member5` |

Rows 4–9 are the ones the presenter **acts on live**. Rows 1–3 are finished and
cannot be submitted, allotted or accepted again, so every step performed on
stage rather than described is performed on one of rows 4–9 — each is parked
exactly one step short of the button being demonstrated.

**A DPC ID is minted at the AH's approval**, which is also what forwards the
proposal to the Commission. That is why rows 4 and 5 have no ID yet — and why
approving row 5 on stage mints one in front of the audience.

**Why two concluded DPCs.** A Review supersedes the DPC it corrects, and the
application refuses to circulate a superseded DPC — the operative list has moved
on. So the department ran two promotions: `SE202600001`, which a Review corrects,
and `SE202600003`, which a Circulation resolves. Same department, same ladder,
same case file.

---

### The result to point at — `SE202600001`

Five vacancies, so the zone of consideration is **2 × 5 + 4 = 14**: serials 15
and 16 are eligible but fall outside it, and are recorded as considered-and-not-
selected rather than dropped. Qualifying score for Class I → Class I is 15.

| S.N. | Officer | Cat. | Score | Outcome |
|---|---|---|---|---|
| 1 | Shri Rajesh Kumar Sahu | UR | 20 | **Super Seat** — every scored CR year क+; main list #1 |
| 2 | Smt. Sunita Verma | UR | 17 | Main list #2 |
| 3 | Shri Anil Kumar Tandon | SC | 17 | Main list #3 |
| 4 | Smt. Meena Baghel | ST | 17 | Main list #4 |
| 5 | Shri Prakash Chandra Dewangan | OBC | 16 | Main list #5 |
| 6 | Smt. Kavita Nayak | UR | 15 | Waiting list #1 |
| 7 | Shri Ramesh Prasad Yadav | UR | 15 | **Was 13 and ineligible as submitted.** The Commission corrected his 2024 CR at the sitting; the recalculation carried him to waiting list #2 |
| 9 | Shri Dilip Kumar Sahu | SC | 16 | Waiting list #3 |
| 10 | Smt. Anita Kujur | ST | 18 | Waiting list #4 — includes the **tribal-area bonus** |
| 11 | Shri Mohan Lal Sinha | OBC | 15 | Waiting list #5 |
| 13 | Shri Vinod Kumar Netam | ST | 17 | Waiting list #6 — tribal-area bonus |
| 14 | Smt. Pushpa Lata Sahu | OBC | 16 | Waiting list #7 |
| 8 | Smt. Geeta Sharma | UR | 15 | **Not eligible** — most recent CR is ग, which a Class-I to Class-I promotion does not permit. *This is what the Review corrects* |
| 12 | Smt. Sarita Patel | UR | 11 | **Not eligible** — CR grade घ inside the scoring window |
| 15 | Shri Suresh Chandra Gupta | UR | 15 | Eligible, but **outside the zone** |
| 16 | Smt. Rekha Bhonsle | SC | 16 | Eligible, but **outside the zone** |

Main list 5 · waiting list 7 · 14 of 16 eligible · no unresolved business rule,
so the documents are published.

**Two calculation runs are on record and neither overwrote the other:**

| | Run 1 — as submitted | Run 2 — after the correction |
|---|---|---|
| S.N. 7 | score 13, **not eligible** | score **15**, waiting list #2 |
| Eligible | 13 | 14 |
| Waiting list | 6 | 7 |
| Main list | 5 | 5 — unchanged |

### What the Review changed — `SE202600002`

The department corrected Smt. Geeta Sharma's 2024 CR from ग to क+ and carried
forward the correction the Commission had made to S.N. 7 at the original
sitting. (That second one matters: a sitting correction belongs to the sitting,
not to the department's proposal, so a Review that silently dropped it would
take an officer back off a list the Commission had already put him on.) The
Commission conducted the Review on **14-07-2026** and approved it, which is the
act that made supersession take legal effect.

| | Original `SE202600001` | Review `SE202600002` |
|---|---|---|
| Smt. Geeta Sharma (S.N. 8) | Score 15, **not eligible** | Score **18**, eligible, waiting list #3 |
| Eligible officers | 14 | 15 |
| Waiting list | 7 | 8 |
| Main list | 5 | 5 — unchanged |
| Status | **Superseded** — documents watermarked, still readable, still verifiable | **Operative** |

The main list is deliberately unchanged, and that is worth saying out loud: the
correction restored an officer to consideration, it did not displace anyone
already selected. **A Review corrects the record; it does not re-run the
promotion.**

### The held officer — `SE202600003`

**Shri Ravi Shankar Dubey (S.N. 5)** — every CR year is on record, but the
immovable-property return for **2022** is not. The system held him
**automatically**; nobody typed the hold in. He is the only officer held, and
the ground is a property ground, which is the ground a Circulation may resolve.
The Circulation note released the promotion on **25-07-2026**.

---

## 4. Exact order of screens

24 steps. Each names the account to sign in as. Keep the AO, AH, Chairman and
Member windows open side by side; switching is then instant.

**How to navigate.** The admin side has a left-hand menu ("Administration →
…" below). The department and Commission sides do not: signing in lands on the
dashboard, and the **tiles on it** are the way in — only the tiles your role
permits are shown, which is itself worth a sentence on stage.

### Part A — the department is set up *(admin)*

| # | Screen | Where | What to say |
|---|---|---|---|
| 1 | Department Master | Administration → **Departments** | The list registration draws from. The code is the DPC ID prefix — that is why it locks once IDs are issued |
| 2 | Registration | Log out → **Register** | School Education is already registered, so open the form only to show the department dropdown is fed from the master |
| 3 | **Cadres** | Administration → **Cadres** | Nine posts. Note they were **created by approving the Pre-DPC**, not typed here |
| 3a | **Qualifications** | Administration → **Qualifications** | The controlled list a rule's Minimum Qualification is chosen from. Point at the **Used by** column — a qualification that rules already cite cannot be deleted, only set Inactive |
| 4 | **Recruitment Rules** | Administration → **Recruitment Rules** | One versioned rule per cadre. The **Feeder Cadres → Min. Qualification** column shows both as references rather than text: **Lecturer** carries **two feeder posts**, which is why feeders are rows and not a column, and **Teacher** reads *Direct recruitment only* because a 100%-direct cadre has no feeder |
| 5 | **Committee Configuration** | Administration → **Committee Configurations** | Quorum, voting method, tie-break, whether a digital signature is mandatory |
| 6 | **Reservation Policy** | Administration → **Reservation Policies** | Effective-dated percentages — SC 13, ST 32, OBC 27, EWS 10. Changing a policy never rewrites a past DPC |
| 7 | **Reservation Roster** | Administration → **Reservation Rosters** | Two 100-point rosters, one per journey cadre, each point mapped to a category |

### Part B — the department prepares the DPC *(AO, then AH)*

| # | Screen | Where | What to say |
|---|---|---|---|
| 8 | Dashboard | Sign in as **AO** | The tiles are the workspace. Summary cards: what is drafting, what is with the AH, what is with the Commission |
| 9 | **Approved Pre-DPC** | Dashboard → **Pre-DPC Proposals** → the approved proposal | Nine sanctioned posts, each with its feeder. **Approval is what created steps 3 and 4** |
| 10 | **The complete DPC record** | Dashboard → **DPC Proposals** → **`SE202600001`** | One page carrying every section of a finished DPC, read-only: S14 posts, S15 committee (AH in the chair, AO as member, one external member from GAD), S16 — 12 sanctioned / 7 working / 5 vacancies by category, S17 — 16 officers on the feeder post, S18 — six CR years with integrity and property returns. Scroll it rather than clicking through |
| 11 | **The section screens as the AO fills them** — S14–S18 | DPC Proposals → the **Draft** (row 4) → Committee, Working Officers, Seniority List, Evaluation Chart | Each ends in SAVE AND NEXT. These four screens are **editable only while the proposal is a draft** — on a concluded DPC they are refused, which is why step 10 reads the record instead. 2019 sits on the chart but outside the five-year scoring window: worth pointing at |
| 12 | **Submit live** — S19 | ↳ still on the Draft | Completeness checklist all green — press **SUBMIT** on stage |
| 13 | **AH approval live** — S21–S25 | Sign in as **AH** → the **Submitted** proposal (row 5, Head Master) → **Review** | Walk the review steps and **approve**. One signature covers every section, **the DPC ID is minted here**, and approving is what forwards it to the Commission |

### Part C — the Commission conducts it *(Chairman, then Members)*

| # | Screen | Where | What to say |
|---|---|---|---|
| 14 | **Allotment live** — S28 | Sign in as **Chairman** → Dashboard → **Allotments** | **`SE202600004`** is waiting. **Allot it live** to a Member. Then open the allotment **history** on `SE202600001` — re-allotment **supersedes**, it never overwrites |
| 15 | **Acceptance & scheduling live** — S30 | Sign in as **`member3`** → Dashboard → **Schedules** | `SE202600005` is allotted and unaccepted. **Accept and schedule it live** |
| 16 | **Open sitting** — S31/S33 | Sign in as **`member5`** → Dashboard → **Proceedings** → `SE202600007` | A sitting already open with the result calculated. Show the corrections panel and press **CALCULATE** |
| 17 | The concluded sitting | Sign in as **`member1`** → Dashboard → **Proceedings** → **`SE202600001`** | The finished article: the correction to S.N. 7, **both** calculation runs, and the approved result |
| 18 | Promotion list & documents | ↳ **Result**, then ↳ **Documents** | Main list of 5, waiting list of 7. Proceedings document and promotion list, both signed, hash-verifiable, published to the department |
| 19 | Eligibility explained | Sign in as **AO** → DPC Proposals → `SE202600001` → **Calculations** → a run → any officer's **Explain** | Years used, marks per year, gates applied, threshold for this class pair. **Two runs are listed** — before and after the Commission's correction; neither overwrote the other |

### Part D — corrections after the fact

| # | Screen | Where | What to say |
|---|---|---|---|
| 20 | Review DPC — S35/S37/S38 | Sign in as **AO** → DPC Proposals → **`SE202600002`** | Every screen of a Review carries the amber **"This is a Review DPC"** banner naming what it corrects — a Review looks like a Fresh DPC, so it says so |
| 21 | The correction chain | ↳ **Review Chain** (button on that banner) | The original marked **superseded**, the Review marked **operative**, with the reason and who established the link |
| 22 | The superseded original | Sign in as **`member1`** → Proceedings → `SE202600001` → **Documents** → open the promotion list | The original was **superseded, never altered**. Its promotion list is stamped SUPERSEDED and still opens, still downloads and still verifies against its stored hash. Nothing was rewritten |
| 23 | Circulation DPC — S45/S47 | Sign in as **AO** → Dashboard → **Circulation DPC** → **`SE202600003/C1`** | One held officer, the resolution the department received, and the Commission's consent |
| 24 | Circulation, Commission side — S48 | Sign in as **`member2`** → Dashboard → **Circulations** → the same note | Decided, consented, **signed** — and signing is what released the held promotion |

**Closing line.** Every screen shown was produced by the system's own workflow.
Nothing was typed into the database, and every step carries who did it, when,
under which rules, and with which signature.

---

## 5. If something goes wrong on the day

| Symptom | Fix |
|---|---|
| An account will not sign in | The password is `Demo@2026` — capital D, `@`, no spaces. Try the mobile number instead of the email |
| A live action fails because a previous run already did it | `php artisan demo:seed --force` rebuilds everything in about 30 seconds |
| The data looks half-built | The seeder is meant for a fresh database. Always rebuild with `demo:seed`, never `db:seed` on top |
| An OTP screen appears | That account was not seeded by `demo:seed`. Use one of the accounts in section 2 |
| A URL with a long id in it 404s | Proceedings, calculation runs and circulations are addressed by UUID, and UUIDs change on every re-seed. Navigate from the list screen instead of typing a URL |

**Deliberately switched off for the demonstration** (say this at the start, as
`DEMO_SCRIPT.md` advises): the CSC digital-signature service, the SMS gateway
and the HRMS interface. Signatures are produced by the development provider and
notifications are written to the application log.
