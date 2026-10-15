# e-DPC Demo Kit

**Chhattisgarh Public Service Commission · one folder, everything the presenter needs.**
Prepared 2026-10-05 · rewritten for clarity 2026-10-06.

```
demo-kit/
├── README.md          ← you are here: the chain, the credentials, every step, every value
└── uploads/           ← the files you actually upload, in step order
```

**How this file is numbered.** Top-level parts are **§1 … §9**. The walkthrough in §6
is numbered **Step 1 … Step 17**. Nothing says just "§5" and means a step any more —
a reference is either "§3" (a part) or "Step 9" (a step in the walkthrough).

---

# §1 · The chain — पूरा सिलसिला

**Read this part first.** Everything else is detail. The confusion this demonstration
usually causes is *"where did this DPC come from and who put these people on it"*, and
the answer is below.

## The one-line version

> एक **पद-जोड़ी** (किस पद से किस पद पर पदोन्नति) तय होती है → उस पर **Pre-DPC** बनता है →
> AH के approve करते ही **Recruitment Rule अपने आप बन जाता है** → उसी Pre-DPC से **DPC**
> खड़ा होता है → AO **committee** बनाता है → CGPSC Chairman उसे एक **member को allot**
> करता है → वही member **sitting** रखता और चलाता है।

> A **post pair** is sanctioned → a **Pre-DPC** is drafted on it → the AH's approval
> **writes the Recruitment Rule by itself** → a **DPC** is raised *from that Pre-DPC* →
> the AO **composes the committee** → the CGPSC Chairman **allots** it to one member →
> that member **holds the sitting**.

## The chain, drawn

```
  Department Post Master            ← Administrator seeds the ladder        [Step 3]
                                       + Sanctioned Strength
          │
          │  pick TWO posts: FROM (feeder) and TO (promotion post)
          ▼
  ┌──────────────────┐
  │  PRE-DPC         │   AO drafts [Step 4] ──submit──▶ AH approves & signs [Step 5]
  │  PDPC-2026-SE-…  │
  └────────┬─────────┘
           │  approval does three things in ONE transaction:
           │    1. signs the proposal
           │    2. makes it the active Pre-DPC for this exercise
           │    3. ✦ WRITES THE RECRUITMENT RULE ✦  (nobody types it)
           ▼
  ┌──────────────────┐
  │  DPC PROPOSAL    │   New DPC form [Step 6]: first field is the Pre-DPC.
  │  DPC-2026-SE-…   │   Year, To-Post, Pay Scale, Class, Duration, Rule
  └────────┬─────────┘   are all COPIED from it. AO chooses only
           │             Type of DPC · the feeder · legal stay.
           │
           ├── S15  Committee        [Step 7]  AO names 3–6; Chairman seated by the system
           ├── S16  Working Officers [Step 8]  upload → VACANCY derives itself
           ├── S17  Seniority List   [Step 9]  upload → candidate accounts provisioned
           └── S18  Evaluation Chart [Step 10] projected from S17; upload CR grades
           │
           │  AO submits → AH reviews S21–S25 → approves & signs [Step 12] → forwarded
           ▼
  ┌──────────────────┐
  │  CGPSC CHAIRMAN  │   it arrives by itself. THREE decisions:     [Step 13]
  │  allots          │     • the ALLOTTEE   (which member executes it)
  └────────┬─────────┘     • the DPC CHAIRMAN (who presides)
           │               • the COMPLETION DEADLINE
           ▼
  ┌──────────────────┐
  │  THE ALLOTTEE    │   accepts → schedules within the deadline    [Step 14]
  │                  │   → opens it → calculates → Commission approves [Step 17]
  └──────────────────┘   → Proceedings generated and signed
```

## Who does what — एक नज़र में

**The "Step" column is the same numbering as §6**, so this table and the walkthrough never
disagree.

| Step | Act | Who does it | What they actually choose | What the system fills in |
|---|---|---|---|---|
| **3** | Department Post ladder | **Administrator** | the posts, class, pay scale, **Sanctioned Strength** | — |
| **4** | **Pre-DPC** | **AO** | **the post pair**, year, share, experience, qualifications, GO | the Pre-DPC Number |
| **5** | Approve the Pre-DPC | **AH** | approve / return | **the Recruitment Rule, in full** |
| **6** | **Raise the DPC** | **AO** | Type of DPC · which feeder · legal stay | Year, To-Post, Pay Scale, Class, Duration, Rule, DPC Number, Proposal Date |
| **7** | Committee (S15) | **AO** | 3–6 members | the **CGPSC Chairman's seat** |
| **8–10** | S16 · S17 · S18 | **AO** | the three CSVs | the vacancy · candidate accounts · the chart's officers |
| **12** | Approve the DPC | **AH** | approve / return | the signature, and routing to the Commission |
| **13** | **Allot** | **CGPSC Chairman** | **allottee · DPC Chairman · deadline** | — |
| **14–17** | Schedule & conduct | **the Allottee** | the sitting date | notifications to 14 people |

## The three different "members" — यही सबसे ज़्यादा confuse करता है

Three distinct things are all called some kind of chairman or member. They are not the
same, and two of them can be the same person.

| Term | Who sets it | Where it lives | What it means |
|---|---|---|---|
| **Committee Member** | the **AO**, at S15 | `dpc_committee_members` | sits on this one DPC. **No role is granted** — membership is valid for this DPC only |
| **CGPSC Chairman** | nobody — **seeded** | `cgpsc_chairmen` | the Commission's serving Chairman. **Seated on every committee automatically**; the AO cannot add or remove him. He is also the one who allots |
| **DPC Chairman** | the **CGPSC Chairman**, at allotment | a column on the membership row | **presides over this one sitting**. A column, not a role |
| **Allottee** | the **CGPSC Chairman**, at allotment | `dpc_allotments.allotted_to` | **executes** the DPC: accepts, schedules, opens the sitting, runs it |

> **The Allottee and the DPC Chairman may be the same person or two different people.**
> In this walkthrough they are deliberately **different** — Brijesh Kumar Pandey executes,
> Sunita Rathore presides — so the audience sees that they are two separate decisions.

> **The Allottee must be a member of this DPC's own committee and must have an account.**
> That is why Step 7 seats `member1@cgpsc.gov.in` and Step 13 allots to that same person.

---

# §2 · The post pair — किस पद से किस पद पर

**This is the single most important choice in the whole demonstration, and it is made
once, at Step 4.** Everything downstream is derived from it.

## For this walkthrough

| | Post | Class | Pay scale | Sanctioned |
|---|---|---|---|---|
| **Promotion FROM Post** (feeder / फीडर पद) | **Block Education Officer** | **II** | Level-12 | 4 |
| **Promotion TO Post** (promotion post / पदोन्नति पद) | **Additional Director, Education** | **I** | Level-13A | **10** |

**Read it as:** *Block Education Officer के अधिकारियों को Additional Director, Education
पर पदोन्नति दी जा रही है।* Officers **leave** the FROM post and **arrive at** the TO post.

| | |
|---|---|
| The **seniority list** (Step 9) is of the **FROM** post | these are the candidates — ten Block Education Officers |
| The **vacancy** (Step 8) is counted on the **TO** post | ten sanctioned chairs of Additional Director |
| The **qualifying service** (5 years, Step 4) is served **in the FROM** post | five years as a BEO, not five years anywhere |

## Why this pair and not another

The system refuses a pair that does not go **upward**. `PromotionHierarchyService` is
*designed* to make four checks — class, pay scale, same-class pay scale, and grade pay —
but **on this database only the class check actually runs**, because all six pay scales
carry a NULL `level` and `grade_pay`. See the second warning below.

Block Education Officer → Additional Director is **Class-II → Class-I**, so it passes on
the class check alone, and that check is a real one. The pair is sound.

> **Consequence worth knowing before someone asks.** Because this is **Class-II → Class-I**,
> **no Zone of Consideration formula applies** — the rules state one only for Class-I →
> Class-I (`2 × vacancies + 4`). So all ten officers on the seniority list are evaluated,
> uncapped. If somebody asks to see the zone formula working, that needs a Class-I →
> Class-I pair; see **§9**.

## What else a Pre-DPC / DPC can be made on — **aur kis pe ban sakta hai**

**Only School Education.** It is the one `registered` department, the only one with posts,
and the only one with an AO and an AH. The other eight departments have no ladder and no
officers — their dropdowns would be empty (§9).

Within it, **eight of the nine posts can serve as a Promotion TO Post**:

| TO Post (पदोन्नति पद) | Class | Scale | Sanctioned | Sensible feeder (FROM) |
|---|---|---|---|---|
| Teacher | III | L10 | **NULL** | **unusable — no sanctioned strength, so no vacancy can be derived** |
| Head Master, Middle School | III | L10 | 4 | Teacher |
| Lecturer | II | L11 | 4 | Head Master, Middle School |
| Principal, Higher Secondary School | II | L12 | 9 | Lecturer |
| Block Education Officer | II | L12 | 4 | Lecturer |
| District Education Officer | I | L12 | 4 | Principal, HSS · Block Education Officer |
| Joint Director, Education | I | L13 | 12 | District Education Officer |
| **Additional Director, Education** | **I** | **L13A** | **10** | **Block Education Officer ← this walkthrough** |
| Director, Public Instruction | I | L14 | 10 | Additional Director, Education |

> **Sanctioned Strength is what makes a TO post usable.** `VacancyEngine` returns a
> *null* total when it is absent, so **Teacher cannot be a TO post today** — the vacancy
> would never compute. Every other post carries one.

### Two warnings before you pick a different pair

**1 · Only this pair has data.** `uploads/` holds a working chart, a seniority list and an
evaluation chart built for **Block Education Officer → Additional Director**. Any other pair
needs three new CSVs written for it — the officers, their chairs and their CR grades. The
walkthrough is not "pick any two posts and go".

**2 · The upward check is weaker than it looks.** Running the real
`PromotionHierarchyService` over all 9 × 9 pairs: **46 accepted, 26 refused**. But all six
pay scales carry a **NULL `level`**, so only the **class** check actually runs — and these
are all accepted today:

| FROM | TO | |
|---|---|---|
| Director, Public Instruction (L14) | District Education Officer (L12) | **accepted — a demotion** |
| Additional Director (L13A) | Joint Director (L13) | **accepted — a demotion** |
| Head Master, Middle School (L10) | Teacher (L10) | **accepted — a demotion** |

Class-I → Class-I is unguarded **in both directions**, as is Class-II → Class-II and
Class-III → Class-III. Only a pair that goes **down a class** is refused. Keep to the
feeders in the table above and nothing odd appears on screen.

---

# §3 · Before you start

```bash
cd /var/www/html/DPC
php artisan serve --host=127.0.0.1 --port=8001
php artisan queue:work                            # NOT optional
```

| | |
|---|---|
| URL | **http://127.0.0.1:8001/login** |
| OTP, wherever one is asked | **123456** |
| Password, every account | **Demo@2026** |

> **Port 8001, not 8000.** Port 8000 on this machine serves a different application
> whose login screen looks similar.

> **`queue:work` is not optional.** Notifications, OTP delivery and credential dispatch
> are queued. Without a worker the notification bell stays empty all day and Step 15
> shows nothing.

**Do not run `php artisan demo:seed`** — it drops the whole database, masters included.
`php artisan demo:credentials` is safe (it resets the nine passwords and creates nobody).

## Expected starting state

| | |
|---|---|
| Accounts | 9 |
| Transactional rows | 0 — no proposals, no imports, no audit entries |
| Recruitment Rules | **0 — deliberately.** Show it empty at Step 3b; Step 5 fills it. Nobody types it |
| Departments | 9 exist, **1 registered** (School Education) |
| Department Posts | 9, all School Education |
| CGPSC Chairman | **on record** — Dr. Ajay Kumar Shrivastava, active since 02-09-2026 |

> **Check the Chairman exists before you begin** — Administration → CGPSC Chairman, one
> active row. **This is an `admin` screen**, so check it as `admin@cgpsc.gov.in`. It is seeded and nothing in this kit loads it, but it is a precondition of
> the *whole* workflow, not just of one screen: `DpcCommitteeService` refuses to compose a
> committee without a serving Chairman, so an empty record stops the demonstration at
> Step 7 with a message about a master nobody was demonstrating.

First identifiers will be `PDPC-2026-SE-0001` and `DPC-2026-SE-0001`. If they are not
`0001`, the database was not reset — run `php artisan dpc:reset-transactional-data`.

---

# §4 · Who can do what — **kaun bana sakta hai**

Password for all nine: **`Demo@2026`**. The login box takes an **e-mail** or a
**10-digit mobile** and works out which you gave it.

## The rule in one line

> **Pre-DPC aur DPC sirf Authorized Officer bana sakta hai — aur sirf apne hi department
> mein.** Nobody else has the screen. `pre-dpc.index` and `dpc-proposals.index` are behind
> `role:authorized_officer|administrative_head`, and the AH gets them to *review*, not to
> create.

## The nine accounts

| Login | Role | Name | Does what, at which Step |
|---|---|---|---|
| **`ao.schooleducation@cg.gov.in`** | Authorized Officer | Shri Anil Kumar Verma | **creates the Pre-DPC (Step 4)** and **raises the DPC (Step 6)**; committee and all three uploads (Steps 7–11); submits (Step 12) |
| **`ah.schooleducation@cg.gov.in`** | Administrative Head | Smt. Sunita Rathore | **approves and signs** the Pre-DPC (Step 5) and the DPC (Step 12). Also sits on the committee |
| **`admin@cgpsc.gov.in`** | CGPSC Administrator | CGPSC Administrator | owns **every Administration screen** — Department Post Mapping, Recruitment Rules, Pay Scales, CGPSC Chairman. **Steps 3 and 5 need this login** |
| **`chairman@cgpsc.gov.in`** | Chairman, CGPSC | Dr. Ajay Kumar Shrivastava | **allots** the DPC — allottee, DPC Chairman, deadline (Step 13) |
| **`member1@cgpsc.gov.in`** | Member | Shri Brijesh Kumar Pandey | the **Allottee** — accepts, schedules, opens and conducts the sitting (Steps 14–17) |
| `member2@` … `member5@cgpsc.gov.in` | Member | Kavita Deshmukh · Suresh Chandra Netam · Mohan Lal Kashyap · Anita Bhonsle | spare committee seats |

## Which screens each role can actually open

Checked against the route middleware, not assumed:

| Screen | Required role | So this login |
|---|---|---|
| Pre-DPC · DPC Proposals | `authorized_officer` **or** `administrative_head` | AO creates · AH reviews |
| **Administration → Department Post Mapping** | **`admin` only** | `admin@cgpsc.gov.in` |
| **Administration → Recruitment Rules** | **`admin` only** | `admin@cgpsc.gov.in` |
| Administration → CGPSC Chairman | `admin` only | `admin@cgpsc.gov.in` |
| Allotments | `chairman` **or** `member` | Chairman allots · Member executes |

> **This trips up the walkthrough if you miss it.** The AO and the AH **cannot open any
> Administration screen** — they get a 403. Steps 3 and 5 both need you to sign in as
> **`admin@cgpsc.gov.in`**, and Step 5 is the demonstration's strongest moment.

**There are no Promotion Candidate accounts, and that is correct.** Step 9 creates all
ten of them in front of the audience.

---

# §5 · The vacancy — what a human sets, and what the engine works out

This is the question most often asked, so it is answered before the steps.

## The Administrator sets exactly one number: **Sanctioned Strength = 10**

It lives on **Administration → Department Post Mapping → Additional Director, Education**
— the **TO** post of §2 — and it is the only vacancy input a human enters. Everything
else is derived.

| Where it comes from | Value |
|---|---|
| **Sanctioned Strength** — Department Post Mapping, set by the Administrator | **10** |
| Working Strength — counted from the Currently Working chart you upload at Step 8 | 7 |
| Promotion / Direct split — from the Recruitment Rule created at Step 5 | 100% / 0% |

## How 4 falls out

The chart at Step 8 lists **ten chairs, not ten people** — every sanctioned chair is a row:

| Rows | What they are | Effect |
|---|---|---|
| 1–6 | Officers substantively holding the chair | Chair occupied |
| 7 | Shri Vinod Kumar Baghel, **away on deputation** until 31-07-2027 | **Frees his chair** — he holds neither quota while away |
| 8, 9, 10 | `vacant` — empty chairs, carrying only a category and a roster point | **3 clear vacancies** |

```
Base Vacancy   = Sanctioned 10 − Working 7            = 3
+ Deputation   = row 7's chair                        = 1
+ Retirement   = nobody retires inside the window     = 0
─────────────────────────────────────────────────────────
TOTAL VACANCY                                         = 4
× Promotion share 100%  →  Promotion Vacancy          = 4
```

**The retirement window is 01-01-2026 to 30-11-2026**, derived from the **DPC Year**, not
from today — so the same chart gives the same answer whenever it is re-run. The earliest
retirement on this chart is 30-04-2028, which is why retirement contributes nothing.

## The four seats, by category

| Seat | Category | Ground |
|---|---|---|
| Row 8 | **UR** | clear |
| Row 9 | **ST** | clear |
| Row 10 | **OBC** | clear |
| Row 7's chair | **SC** | from promotion — the deputed officer's category |

**Remember these four.** They decide who reaches the Main List at Step 11.

> **Zone of Consideration: no formula here.** See the note at the end of §2 — this is a
> Class-II → Class-I promotion, so every officer meeting the experience bar is considered,
> uncapped. That is why all ten on the seniority list are evaluated.

> **Reservation Rosters do not affect this.** The Administrator can maintain them, but no
> calculation reads them. A vacancy takes the category of the officer who vacated the
> chair, in the chart's serial order. See `docs/demo/DEMO_DEPARTMENT_READINESS.md` §5.

---

# §6 · The walkthrough — Step 1 to Step 17

## Every upload, at a glance

| Step | File in `uploads/` | Rows | Screen | Expected |
|---|---|---|---|---|
| **Step 8** | **`demo-working-officers.csv`** | 10 | DPC → **Working Officers (S16)** → Upload from Excel | 10/10 valid · TOTAL VACANCY **4** |
| **Step 9** | **`demo-seniority-list.csv`** | 10 | DPC → **Seniority List (S17)** → Upload | 10/10 valid · **ten accounts created** |
| **Step 10** | **`demo-evaluation-chart.csv`** | 10 | DPC → **Evaluation Chart (S18)** → Upload | 10/10 valid · **50 CR · 50 loyalty · 50 property** |
| optional | `demo-evaluation-chart-with-hold.csv` | 10 | same screen — **Circulation branch only** | one officer held for a missing CR |
| optional | `demo-recruitment-rule-go.pdf` | — | Step 4, GO upload | — |
| optional | `demo-department-post-order.pdf` | — | Step 3, Post Mapping order | — |
| optional | `demo-missing-record-justification.pdf` | — | Step 10, record-gap evidence | — |

**Upload the three main CSVs in this order.** The Evaluation Chart is *projected from* the
Seniority List — the officers appear on S18 by themselves and cannot be added or renamed
there — so S17 must be committed first.

Every other screen is **typed, not uploaded**, and every value is written into the step.

---

## Step 1 · Sign in — AO
`ao.schooleducation@cg.gov.in` → dashboard, **every tile at 0**. Say so out loud; it is
the proof the system is starting from nothing.

## Step 2 · Department Registration *(optional — read the warning first)*

> **This step creates a second department and two more accounts.** It registers **Higher
> Education** with its own Authorized Officer and Administrative Head. If the
> one-department rule matters to your audience, **skip it** — or delete the department and
> both accounts afterwards. Nothing later in the walkthrough depends on it.

Open `/register` and fill:

| Field | Value |
|---|---|
| Department | Higher Education Department |
| District · Place · PIN | Raipur · Mantralaya, Mahanadi Bhawan, Naya Raipur · 492002 |
| Department Email | `he.secretariat@cg.gov.in` |
| AO | Shri Vivek Kumar Shukla · Under Secretary · 9000002101 · `ao.highereducation@cg.gov.in` |
| AH | Smt. Archana Dubey · Secretary · 9000002102 · `ah.highereducation@cg.gov.in` |
| Email OTP · Mobile OTP | **123456** · **123456** |

## Step 3 · The Administrator's two masters — **sign in as `admin@cgpsc.gov.in`**

> **Different login.** The AO cannot open any Administration screen; it is `role:admin`.
> Sign out and sign in as **`admin@cgpsc.gov.in`**.

### 3a · Department Post Mapping — where the post pair comes from

Administration → **Department Post Mapping**. Nothing is typed — the nine posts are seeded.
This is the ladder the pair in §2 is picked from. Show **Additional Director, Education →
Sanctioned Strength 10**; that is the one number the vacancy in §5 rests on.

| Post | Class | Pay scale | Promotion yrs | Sanctioned |
|---|---|---|---|---|
| Teacher | III | Level-10 | 5 | — |
| Head Master, Middle School | III | Level-10 | 4 | 4 |
| Lecturer | II | Level-11 | 5 | 4 |
| Principal, Higher Secondary School | II | Level-12 | 5 | 9 |
| **Block Education Officer ← FROM** | **II** | **Level-12** | — | **4** |
| District Education Officer | I | Level-12 | 5 | 4 |
| Joint Director, Education | I | Level-13 | 4 | 12 |
| **Additional Director, Education ← TO** | **I** | **Level-13A** | **3** | **10** |
| Director, Public Instruction | I | Level-14 | — | 10 |

All nine carry Government Order `F-2-14/2025/20-Three`, effective 01-01-2026. The two in
bold are the pair this walkthrough runs on.

### 3b · Recruitment Rules — **show that it is EMPTY, and say so out loud**

Administration → **Recruitment Rules**. **Zero rows.**

**Do not skip this.** It is the setup for the payoff at Step 5, and without it that step
means nothing. Say plainly: *"इस मास्टर में अभी एक भी नियम नहीं है। कोई इसे टाइप नहीं करेगा।"*

## Step 4 · **Pre-DPC — this is where the post pair is declared**
**AO** · Pre-DPC → **+ New Pre-DPC**

| Field on screen | Type this | Why |
|---|---|---|
| DPC Year | **2026** | fixes the retirement window of §5 |
| **Promotion To Post** | **Additional Director, Education** | the post being promoted **into**. Pay scale, class and duration fill themselves, read-only |
| **Promotion From Post** | **Block Education Officer** | the **feeder** — the post officers are promoted **out of** |
| Share | **100** | this feeder supplies the whole promotion quota |
| Minimum experience | **5** years | qualifying service **in the feeder post** |
| Qualifications | tick **Post-Graduate**, **Post-Graduate with B.Ed.**, **Ph.D.** | what an officer must hold |
| Direct / Promotion | **0** / **100** | the whole vacancy is filled by promotion |
| GO number · effective | **`F-2-14/2026/20-Three`** · **01-01-2026** | the order that sanctions it |

**Save Draft** → **Submit for Approval** → *"Draft saved as `PDPC-2026-SE-0001`"*

> **Minimum experience 5 is load-bearing.** It is what refuses SN 10 at Step 11.

> **To show the system refusing a downward pair**, you must change the **To Post**, not the
> From Post. With To Post = *Additional Director* (Class-I) **every** feeder is accepted,
> because the class check alone decides and nothing in this department is above Class-I.
> Set **To Post = Block Education Officer (Class-II)** and **From Post = District Education
> Officer (Class-I)** and it is refused:
>
> *"Promotion is from Class-I into Class-II, which is a demotion. A promotion must be to an
> equal or higher class."*
>
> **Do not promise more than that.** Because all six pay scales carry a NULL `level`, the
> pay-scale checks are **skipped**, so a same-class demotion such as *Director, Public
> Instruction (Level-14) → District Education Officer (Level-12)* **is currently accepted**.
> See §2 and the worklog of 2026-10-06.

## Step 5 · AH approves — and the Recruitment Rule writes itself

**First, as the AH** — `ah.schooleducation@cg.gov.in` → open the Pre-DPC → **Approve & Sign**.

**Then sign in as `admin@cgpsc.gov.in` again** → Administration → **Recruitment Rules**.

> **The AH cannot open that screen** — it is `role:admin` and the AH gets a 403. The
> walkthrough has to change login here. Showing the empty master at Step 3b and the filled
> one now, under the *same* Administrator login, is what makes the point land.

**One rule has appeared** — Additional Director, Education **v1**, GO
`F-2-14/2026/20-Three`, promotion 100%, active.

> **Nobody typed that rule.** This is why the master was empty at Step 3b. It is the
> strongest moment in the demonstration — make sure the audience saw the empty screen first.

**One approval, three things, one transaction:** the proposal is signed; it becomes the
active Pre-DPC for this exercise; and the exercise is projected into the Recruitment Rule
master. They cannot come apart.

> **If you would rather not switch logins**, the rule is also visible to the **AO** at
> Step 6 — the New DPC form's *"Inherited from the Pre-DPC"* panel names it as
> **Recruitment Rule v1**. It is a weaker moment, because the audience never saw the master
> empty, but it needs no Administrator.

## Step 6 · **Raise the DPC from the approved Pre-DPC**
**AO** · **DPC Proposals → New DPC**

The form opens with a card headed **"1. Sanctioned by"**, and its first field is
**Approved Pre-DPC \***. Until you choose one, the Promotion From Post dropdown is
disabled and reads *"Choose a Pre-DPC first"* — **the order comes before the DPC, and the
screen enforces it.**

| Field | What to do | |
|---|---|---|
| **Approved Pre-DPC** | choose **`PDPC-2026-SE-0001`** | only *active approved* orders are listed — a superseded one is absent |
| **Promotion From Post** | **Block Education Officer** | offered **from that order's own feeders**; it defaults to the only one here |
| **Type of DPC** | **Fresh DPC** | Fresh · Review · Circulation |
| Legal stay | leave unticked | — |

Everything else appears in a read-only panel headed **"Inherited from the Pre-DPC"** with
a **"Not editable here"** badge — DPC Year, From Post, To Post, Class, Pay Scale, Promotion
Duration, **Recruitment Rule (v1)** and the generated Proposal Date.

**Point at that panel.** It is the second-strongest moment after Step 5: the AO is not
retyping the promotion's terms, because an order already sanctioned them. The number
issued is **`DPC-2026-SE-0001`**.

> **Why the feeder is chosen and not inherited.** One order may sanction promotion into a
> post from several feeders. A DPC runs on **one seniority list of one post**, so the order
> alone cannot say which — but you may only pick among what it sanctioned.

> **The terms are copied, not referenced.** Amending the Pre-DPC next year cannot restate
> the terms a concluded promotion ran under.

## Step 7 · Committee (S15) — **how members get assigned**
**AO** · DPC detail → **DPC Committee**

Read the rules panel aloud: *"3 to 6 members besides the Chairman"* and *"The CGPSC
Chairman sits on every DPC committee — the seat is added automatically."*

A member is seated in one of **two ways**, and the demonstration uses both:

| | Name | Category | How to add |
|---|---|---|---|
| 1 | Shri Brijesh Kumar Pandey | SC · Female · Bhar Sadhak Sachiv | **pick the account** `member1@cgpsc.gov.in` |
| 2 | Smt. Sunita Rathore | OBC | **pick the account** `ah.schooleducation@cg.gov.in` |
| 3 | Shri Sanjay Kumar Agrawal — Deputy Secretary, GAD | UR · Vibhag Sachiv | **"Create New Member"** — type the name; **this person has no portal account**, which is allowed |

After **Save Committee** there are **four rows**, not three — the CGPSC Chairman has been
seated by the system. **S15 turns green.**

**Worth demonstrating** — tick **ST** on a member already marked **SC**: the SC box clears
itself. A member holds one social category; ticking none shows a live **UR (General)** badge.

> **Being seated grants no role.** Membership is valid for this DPC only. An account is
> created for a typed member so they are not duplicated next time, never so they can sign
> in as a Commission member.

> **Member 1 must be Brijesh Kumar Pandey**, because Step 13 allots this DPC to him, and
> the allottee must be a serving member of this DPC's own committee **with an account**.

## Step 8 · Working Officers (S16) — the vacancy derives itself
**AO** · Upload **`uploads/demo-working-officers.csv`** → **Upload & Preview** → **Commit 10 Row(s)**

Expect **TOTAL VACANCY 4**, broken down **UR · ST · OBC · SC**. The arithmetic is §5.

> **`DEPUTATION TO` is a date, not a place** — the day the deputation ends. The column name
> says otherwise; the template's help line corrects it.

## Step 9 · Seniority List (S17) — and the ten candidate logins
**AO** · Upload **`uploads/demo-seniority-list.csv`** → **Commit 10 Row(s)** → **read the panel**.

This is the seniority list **of the FROM post** — ten Block Education Officers. Three
things happen on one commit: the list is recorded; the Evaluation Chart is projected from
it; and **ten Promotion Candidate accounts are provisioned** — `SE/BEO/1001` …
`SE/BEO/1010`, under the employee numbers the list carries. Their one-time passwords appear
once, in *"Hand these over to the officers named."*

**Press Reissue password on `SE/BEO/1001`** — Shri Ramesh Chandra Verma, who turns out to be
the Super Seat — and **write the value down**. Step 17 needs it.

## Step 10 · Evaluation Chart (S18)
**AO** · Upload **`uploads/demo-evaluation-chart.csv`** → Commit.

Expect **50 CR grades · 50 loyalty · 50 property** — ten officers × **five** recorded years,
not six: the 2026 columns are deliberately blank. All five sections green; **Submit for
Approval** now enabled.

## Step 11 · Calculate
**Main 4 · Waiting 3 · Not Eligible 3.** Four on the Main List because there are four seats
(§5). Open **Explain Result** on **SN 9** — the adverse grade **घ** is named. SN 10 is
refused on the 5-year experience bar from Step 4.

## Step 12 · Submit → AH review → approve
**AO** Submit · **AH** walks S21–S25 → **Approve**, sign, forward.

## Step 13 · The CGPSC Chairman allots — **three decisions, one act**
**`chairman@cgpsc.gov.in`** → **Allotments**. The DPC is already in *Proposals Awaiting
Action* — **nobody routed it there**; an approved DPC reaches the Commission's Chairman by
itself.

| Decision | Choose | What it means |
|---|---|---|
| **Allot to** (the Allottee) | **Shri Brijesh Kumar Pandey** | **executes** it — accepts, schedules, opens and runs the sitting |
| **DPC Chairman** | **Smt. Sunita Rathore** | **presides** over the sitting — deliberately a different person |
| **Must be concluded by** | **31-12-2026** | the deadline the sitting must fall within |

→ **Allot**

> Both names must already be on the committee from Step 7. The Chairman is choosing
> *among* the members the AO seated — he is not adding anybody.

## Step 14 · The Allottee schedules
**`member1@cgpsc.gov.in`** → **Accept** → then deliberately enter **15-01-2027** at `11:00`
→ **refused, against the date box**:

> *"This DPC must be concluded by 31-12-2026, so it cannot be held on 15-01-2027. Choose an
> earlier date, or ask the CGPSC Chairman to extend the deadline."*

Now **22-12-2026** at **11:00** → **Schedule**.

> The member may reschedule, but never past the deadline — that is the Chairman's to move,
> not theirs.

## Step 15 · Notifications
Automatic. **14 recipients** — every serving committee member and every officer on the
Seniority List — carrying the date, the time and joining instructions. Open the bell as
`member1` to show it. *(This is the step that silently does nothing without `queue:work`.)*

## Step 16 · Self-Inspection
Reachable **without signing in**. The shipped default identifies the officer by OTP:

| Field | Value |
|---|---|
| Mobile | **9425020004** — Smt. Kavita Bhaskar, SN 4 of the seniority list |
| OTP | **123456** |

*Only if the driver is set to `identity_factors` instead:* name **Smt. Kavita Bhaskar**,
date of birth **28-02-1976**, date of joining the feeder post **12-03-2019**.

> She exists only after Step 9 — the Seniority List is what provisions her.

## Step 17 · Open the sitting → conclude
**`member1@cgpsc.gov.in`** → **Open sitting** → Calculate (Commission run: Main 4 · Waiting
3) → **Approve by CGPSC**, promotion as on **2026** → generate and sign the Proceedings.

Then **candidate login**: `SE/BEO/1001` with the password you wrote down at Step 9.

> **The sitting is dated 22-12-2026, in the future — open it anyway.** Early opening is
> permitted (`DPC_ALLOW_EARLY_PROCEEDING` defaults to true) and the actual opening time is
> recorded regardless.

---

# §7 · The three dates that expire

Everything else in this kit is stable. These are not:

| Field | Value | Rule |
|---|---|---|
| Completion Deadline (Step 13) | **31-12-2026** | validated `after_or_equal:today` — **once it passes, Step 13 stops the demo dead** |
| Refused sitting (Step 14) | **15-01-2027** | must be *after* the deadline |
| Accepted sitting (Step 14) | **22-12-2026** | must be *on or before* the deadline |

When rolling them forward, keep that shape. They appear in **Step 13 and Step 14 above and
nowhere else** — this file is the only place they are written.

> This exact fault stopped the walkthrough once: the deadline was 30-09-2026 and the demo
> was prepared on 05-10-2026.

---

# §8 · If something looks wrong

| Symptom | Cause |
|---|---|
| Identifiers are not `0001` | Database not reset → `php artisan dpc:reset-transactional-data` |
| Recruitment Rules screen is **not** empty at Step 3b | A previous demo's rule survived — the reset preserves that table. Delete the rules before starting, or Step 5 loses its point |
| **The "Approved Pre-DPC" dropdown is empty** at Step 6 | The Pre-DPC is not approved yet, or it has been superseded. Only an *active approved* order is listed |
| **"Promotion From Post" stays disabled** at Step 6 | No Pre-DPC chosen yet — the field reads *"Choose a Pre-DPC first"* |
| **The committee screen refuses to save** | No serving CGPSC Chairman on record — §3 |
| **The Chairman's name is not in the "Allot to" list** at Step 13 | The person is not a serving committee member of *this* DPC, or has no account — Step 7 |
| **403 / "not authorised" on an Administration screen** | You are signed in as the AO or the AH. Every Administration screen is `role:admin` — use `admin@cgpsc.gov.in` (§4) |
| Notification bell empty at Step 15 | `queue:work` is not running |
| *"The completion date cannot be in the past"* at Step 13 | §7 above — the dates have expired |
| An extra Higher Education officer appears under Users | Somebody ran an old `demo:credentials`; the current one creates nobody |
| Candidate panel at Step 9 reports nothing created | Accounts already exist under `SE/BEO/1001`–`1010` — they must not exist beforehand |

---

# §9 · Running this for Higher Education instead — **not possible without setup**

Asked for occasionally. State of the Higher Education Department today:

| | |
|---|---|
| Department status | **`unregistered`** |
| Department Posts | **0 — the ladder does not exist** |
| AO / AH accounts | **none** (removed in the 2026-10-05 prune) |
| Recruitment Rules | 0 |

So **no Pre-DPC can be drafted for Higher Education today** — Step 4's two dropdowns would
both be empty. Three things would have to be built first:

1. **Register the department** (the Step 2 screen does exactly this, and creates its AO and AH).
2. **Create the post ladder** as `admin@cgpsc.gov.in` — Administration → Department Post Mapping.
3. **Build three new CSVs** for the working chart, the seniority list and the evaluation chart.

**The pair worth choosing, if it is built:** **Principal, Government College (Class I,
Level-13) → Additional Director, Higher Education (Class I, Level-13A)**. Both Class-I, so
the **Zone of Consideration formula `2 × vacancies + 4` actually fires** — the one rule the
School Education walkthrough structurally cannot show (§2).

> **Check first:** `pay_scales.level` and `grade_pay` are **NULL on all six scales**, so for
> a same-class pair `PromotionHierarchyService` **skips** the "must be a higher pay scale"
> check rather than passing it. The pair is accepted either way, but the rule only visibly
> fires once those six rows carry a level.

---

*Full narrative, screen by screen, with validation detail: `docs/demo/CURRENT_DEMO_FLOW.md`.*
*What each department can and cannot run: `docs/demo/DEMO_DEPARTMENT_READINESS.md`.*
