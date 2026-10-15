# CGPSC DPC — Pending Business Decisions

**For:** CGPSC and the business owners of the DPC process
**Version 1.0 · 2026-08-22**
**Status:** Engineering complete. This document lists **only what the system cannot decide for itself.**

Nothing here is a defect and nothing here blocks a demonstration. Each item is a question of **policy**, which is CGPSC's to answer and not the software's to guess.

---

## How to read this

The e-DPC Portal is built and works end to end. Twenty decisions arose during construction that are **matters of policy, not of software**. Each was written down instead of guessed at, because guessing would have meant the system quietly asserting a rule CGPSC had never made — about an officer's promotion, seniority or pay.

**Twelve of the twenty are now closed** — they turned out to be answerable from the presentation decks, or from a decision already taken, or they were engineering questions wearing business clothes. **Eight remained, and BD-21 arrived with the vacancy directive of 2026-08-22.** Since then the change registers of 2026-08-24, 08-26 and 08-27 have added a further twenty-four, of which the ten in **BD-40 to BD-49** are questions the register of 2026-08-27 asked us to record rather than build.

For each open item you will find: the requirement it comes from, **what the system does today**, the options, our recommendation, the impact of each answer, and a priority.

**Nothing below blocks a demonstration.** The system runs, and every one of these is handled the same way: it applies the printed rule, marks any case the unruled rule could have changed, and refuses to publish a result it cannot stand behind.

### What "the system decides nothing" means in practice

Three behaviours, applied consistently:

| Behaviour | When | Effect |
|---|---|---|
| **Marked provisional** | A result an unruled rule could have changed | Visible on screen; **cannot be published** |
| **Structured refusal** | An action that would need an unmade rule | The screen says *"Pending Business Decision (BD-n)"* and names the question |
| **Recorded and left alone** | A fact whose consequence is unruled | The fact is stored; no consequence is asserted |

### How an answer reaches the system

**Most answers are a configuration change, not a code change.** Where a decision maps onto a value the system already reads, applying it means editing one setting — there is no new development, no redeployment of business logic, and the change is auditable. Each item below says which.

---

## Priority summary

| # | Decision | What it affects | Priority |
|---|---|---|---|
| **BD-2 / BD-3** | Reserved-category relaxation — automatic or the Committee's discretion, and how far does it extend? | **Blocks publishing** any DPC short of reserved-category officers | **P1 — highest** |
| **BD-9** | Which waiting list an officer joins when their own category has no vacancy | **Publishes a wrong list silently** — the only item that fails without warning | **P1 — highest** |
| **BD-14** | From what date does a circulated promotion take effect? | **Blocks publishing** a circulation note | **P1** |
| **BD-15** | May a CR-shortfall hold be resolved by Review instead of Circulation? | **Refused today** — those officers cannot be resolved at all | **P1** |
| **BD-11** | What happens to an officer already promoted under a DPC later corrected? | Blocks switching on the correction policy in production | **P1** |
| **BD-19** | How does an individual officer sign in to view their own data? | Decides how the self-inspection screen authenticates | P2 |
| **BD-12** | What if the record supplied to resolve a held promotion is adverse? | Decides what the Commission may do with bad news | P2 |
| **BD-4** | Does the CSC signing service take raw bytes or a PDF? | Blocks real digital signing in production | P2 — technical, needs CGPSC's supplier |
| **BD-21** | Does the zone formula `(V × 2) + 4` override the printed rule for a single vacancy, and for class pairs the rules leave uncapped? | Option B would **narrow who is considered** in every non-Class-I promotion | **P1 — new, 2026-08-22** |

---

## BD-2 / BD-3 — Reserved-category relaxation

**Business requirement.** The rules allow a reserved-category officer to be considered on a relaxed standard when their category has fewer eligible officers than it has vacancies. Slide C5 states the relaxed mark. Two things are not stated: **whether relaxation applies automatically or is the Committee's discretion**, and **whether the relaxation to 10 marks applies only to Class-I → Class-I promotions or to every class pair**.

**Current behaviour.** The system applies the printed rule, identifies every officer the unruled rule could have affected, and **marks the whole result provisional**. A provisional result is fully visible, fully explicable officer by officer, and **cannot be published**.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | Relaxation applies **automatically** whenever the condition is met | The system decides; results publish without a Committee step. Consistent, and removes discretion the Commission may want to keep |
| B | Relaxation is the **Committee's discretion**, recorded per officer | The Committee decides in the sitting and the reason is recorded. Slower, and the record shows who decided what |
| C | Relaxation to 10 applies to **all class pairs** / **Class-I → I only** | Independent of A/B; decides how many officers are in scope at all |

**Recommendation. Option B, with the scope confined to Class-I → Class-I** unless CGPSC has applied it more widely in practice. Discretion recorded per officer is the safer default for a promotion that may be contested years later: it produces a named decision-maker and a stated reason, where automatic relaxation produces neither. If CGPSC prefers automatic, that is a one-line configuration change.

**Impact.** Until answered, **no DPC in which a reserved category is short of eligible officers can produce a publishable promotion list.** This is the single most limiting open item, because the condition is common rather than exceptional.

**How the answer is applied.** Configuration. No code change.

---

## BD-9 — Which waiting list when a category has no vacancy

**Business requirement.** Slide S34's own worked example places officer **US4 (SC)** on the **unreserved** waiting list — `UR_W` — because the DPC has no SC vacancy at all. **The rule that implies is written nowhere in either deck.** It is visible only by reading the expected output on that slide.

**Current behaviour.** The system places the officer on **their own category's** waiting list (`SC_W`) — a waiting list for a category with no seat.

**⚠ This is the only open item that fails without warning.** Every other one marks a result provisional or refuses outright. This one raises no flag: the system considers the calculation complete and would publish it. **A wrong published list is worse than a blocked one**, which is why this is the item to answer first even though it is not the most limiting.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | The officer waits against the **unreserved** list | Matches slide S34's own answer. The officer stays in contention |
| B | The officer waits against **their own** category's empty list | Current behaviour. The officer is on a list that cannot produce a promotion |
| C | The officer does not wait at all | The officer is out of contention entirely |

**Recommendation. Option A** — it is what the Commission's own worked example on S34 shows, and it is the only one of the three under which the officer remains in genuine contention. We have deliberately **not** changed the system to match, because a promotion rule inferred from one worked example needs confirming rather than assuming.

**Impact.** Changes **who appears on a published waiting list**, in every DPC where a reserved category has no vacancy.

**How the answer is applied.** A change inside the scoring engine, which is frozen — so it needs a formal approval, a re-run of the affected calculations, and the golden test updated. Not configuration. **This is the only P1 item that is not a configuration change.**

---

## BD-14 — From what date does a circulated promotion take effect?

**Business requirement.** When a promotion was held (`CIR`) because an officer's record was incomplete, and the Commission later signs a circulation note releasing it, **the promotion needs an effective date.** Slide S48 does not state one.

**Current behaviour.** The circulation note is produced, stored, hashed, signed and verifiable. It states **both** the original DPC's promotion-as-on year **and** the date the reservation was released, and **asserts neither as governing**. **Publication is refused**, displaying *"Pending Business Decision (BD-14)"*.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | **Retrospective** to the original DPC's promotion year | The officer's seniority is restored as though never held — which **moves every officer below them** |
| B | From the **date of the Commission's consent** | Simple and defensible; the officer loses seniority for the period their record was incomplete |

**Recommendation. Option A (retrospective)** where the hold was for a departmental record-keeping failure rather than the officer's own default, and **Option B** where it was the officer's. If CGPSC wants one rule for both, **Option B** is the safer single answer, because it changes nobody else's seniority.

**Impact.** Option A **reorders the seniority of officers who were never party to the circulation** and never notified of it. That consequence is the reason this was not guessed.

**How the answer is applied.** Configuration (`dpc.circulation.effective_date_basis`). No code change, no re-test.

---

## BD-15 — May a CR-shortfall hold be resolved by Review instead of Circulation?

**Business requirement.** Slide S45 names a CR shortfall as a ground for circulation. But resolving a CR shortfall means **supplying the missing CR**, which **re-decides the officer's mark** — and a changed mark can reorder the promotion list, including for officers who had nothing to do with the circulation and were never told.

**Current behaviour.** A circulation raised on CR grounds is **detected, recorded, and refused** with a structured *Pending Business Decision (BD-15)*. It is neither calculated nor escalated. Circulations on property-declaration grounds work normally.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | CR shortfalls go to a **Review DPC**, not a Circulation | Correct in substance — a Review recalculates properly and supersedes the earlier list. Slower and more formal |
| B | Circulation **may** re-decide the mark | Faster, but a promotion list changes without the affected officers being party to it |
| C | A CR shortfall **cannot** be cured after the sitting | Simple; the officer permanently loses that DPC |

**Recommendation. Option A.** A Review DPC already does exactly this correctly: it recalculates under the original's rules, supersedes the earlier list, and records what it corrected. Routing CR shortfalls there uses a mechanism that already exists rather than stretching one that was not designed for it.

**Impact.** Today, **an officer held for a CR shortfall cannot be resolved by any route.** That is a real gap in service to those officers, and it is the reason this is P1 despite affecting fewer people than BD-2/BD-3.

**How the answer is applied.** Configuration if Option A or C; Option B needs development.

---

## BD-11 — An officer already promoted under a DPC that is later corrected

**Business requirement.** A Review DPC corrects an earlier DPC and supersedes its promotion list. **What happens to an officer already promoted, possibly serving in the post for months?** Nothing in either deck says.

**Current behaviour.** The system records the supersession, notifies the department naming both DPC codes, shows a `SUPERSEDED` watermark on the earlier documents, and **asserts nothing about the promotion itself** — no withdrawal, no reverted rank, no recovery of pay. The notification says only which promotion list is no longer the current one.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | The promotion **stands**; only the paper is corrected | Kindest, and defensible where the error was the department's. The corrected list and the served reality differ |
| B | Withdrawn **from the date of the Review** | The officer keeps what they were paid; loses the post going forward |
| C | Withdrawn **retrospectively, with recovery of pay** | Strictly correct, and the harshest — recovery from a serving officer who did nothing wrong |

**Recommendation.** This is a service-law question and we make no recommendation on the substance. What we do recommend is that **the answer distinguish by cause**: an officer promoted through the department's error is in a different position from one promoted through their own misstatement, and a single rule for both will be wrong in one direction or the other.

**Impact.** Affects a serving officer's **substantive rank and pay**. Blocks switching the correction policy on in production — the Review mechanism itself works and is demonstrable.

**How the answer is applied.** Options A and B are configuration. Option C needs development (arrears calculation).

---

## BD-19 — How does an individual officer sign in?

**Business requirement.** Slide S50 shows the officer under consideration signing in with **Aadhaar number and Aadhaar OTP**. Aadhaar was replaced across the whole portal by an approved decision early in the project (identifier + password + OTP).

**Current behaviour.** The officer signs in by confirming **three facts their department recorded about them** — name, date of joining service, and date of birth. All three must match exactly, nothing is matched partially, attempts are rate-limited more strictly than anywhere else in the portal, and **every attempt is recorded** whether it succeeds or fails. A match opens **only that officer's own record**.

**Why the deck's approach cannot be used as written.** An officer under consideration **has no account** on this portal. They exist as a row on the chart their department filed — and **none of the officer tables holds a mobile number or an email address**, deliberately: they are point-in-time snapshots with no link to live employee data, which is what stops a historical proposal changing when somebody's record is updated later. **There is nowhere to send an OTP.**

**Options.**

| | Option | Consequence |
|---|---|---|
| A | **Keep the three facts** (current behaviour) | Works today, needs nothing new. Weaker than a password: the three facts all appear on a departmental seniority list, which circulates |
| B | Departments supply a **mobile number** with the officer chart, and the officer receives an OTP | Strongest. Needs a new column on the chart, a rule on who supplies and verifies it, and departments to collect it |
| C | Departments **issue accounts** to officers | Strongest and heaviest. A new workflow in neither deck, and somebody must own an officer's credentials |

**Recommendation. Option A for the demonstration and for UAT; Option B before production**, if self-inspection is to be opened to officers generally. The screen shows year-wise CR grades, reservation category and property-declaration compliance — that is more than one would want protected by three facts from a document that circulates.

**Impact.** Decides how strongly a screen showing an officer's confidential record is protected.

**How the answer is applied.** Option A is the running default. Option B and Option C are each a configuration value plus the data or workflow they need.

---

## BD-12 — What if the record supplied to resolve a held promotion is adverse?

**Business requirement.** A promotion is held because a record is missing. The department later supplies it. **What if the record, once supplied, is bad** — an adverse CR, or a property declaration showing non-compliance?

**Current behaviour.** The Commission records a decision per held officer and may release the hold or return the note for further evidence. **The system does not decide what an adverse record means** and does not refuse the promotion on its own.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | An adverse record **refuses** the promotion | The system decides on the Commission's behalf |
| B | The Commission decides, with the record before it (current behaviour) | Discretion stays where the deck puts it. Needs no change |
| C | An adverse record routes to a **Review DPC** | Most correct where the mark itself changes; overlaps BD-15 |

**Recommendation. Option B**, which is what the system does. The circulation screen already shows the Commission the record it is deciding on, and S48 gives the Commission the decision. Formalising an automatic refusal would remove a discretion the deck grants.

**Impact.** Low. This is a confirmation that current behaviour is intended, not a gap.

**How the answer is applied.** Nothing to change if Option B is confirmed.

---

## BD-4 — Does the CSC signing service take raw bytes or a PDF?

**Business requirement.** Every legally significant approval is digitally signed. In production that means CSC DigiSign / CSC eSign. **We need the service's interface contract**: whether it signs arbitrary bytes or requires a PDF.

**Current behaviour.** Signing runs through a provider interface with a working mock. Every document is rendered, stored, hashed with SHA-256, signed, and independently verifiable. **The real CSC provider is not implemented**, and production refuses to sign rather than pretending to.

**Options.** Not a choice — a fact to be obtained from CGPSC's CSC supplier. It determines one thing: whether the system needs a PDF renderer added (documents are currently HTML).

**Recommendation.** Obtain the CSC integration specification. If a PDF is required, that is a contained piece of work behind the interface that already exists — no workflow changes.

**Impact.** **Blocks real digital signing in production.** Everything else about the signing pipeline is built and verifiable.

**How the answer is applied.** Development behind the existing provider interface, then one configuration flag.

---

## Two more we recommend you confirm, though neither blocks anything

### BD-18 — Who registers a department's posts first?

The Pre-DPC screen selects both posts **from the Department Post master** rather than accepting typed names, which is what stops a department inventing a sanctioned post. Since the change register of 2026-08-24 the master belongs to the single CGPSC Administrator, which answers half of this question — the remaining half is whether the Commission populates it from the published Schedule-IV on a department's behalf, or waits to be asked. The consequence either way: **a newly registered department cannot file its first Pre-DPC until its Department Post master is populated.** Existing departments are unaffected, and the screen explains this rather than appearing broken.

**Recommendation:** the Commission registers each department's sanctioned posts, because Schedule-IV is a published Government Order and not departmental data. Whichever way this goes, it belongs in the onboarding runbook.

### BD-20 — Is an online objection in scope at all?

Slide S51 has **two buttons: CLOSE and PRINT.** Its notice tells the officer to raise any objection **in person at the department**, and states that the Commission will not consider any representation. There is no online objection anywhere in the deck, so **none was built.**

The project roadmap described a *"time-boxed objection"* for this screen. We believe that came from reading the notice's phrase about representations being consigned to file as though it applied to late objections; in the sentence it applies to **every** representation to the Commission.

**Recommendation:** confirm that self-inspection is view-and-print only, as the deck shows. If CGPSC would prefer an online objection — a real improvement on requiring an officer to travel — it should be approved as a deliberate addition, and it is a small piece of work.

---

## Five raised by the change register of 2026-08-24

Each is a question the register created and did not answer. In every case the code does the safe thing and says so; none blocks a demonstration.

### BD-22 — Which member categories must a DPC committee represent?

Item 15 asks the system to compose a committee that satisfies the composition requirements, and the register lists the categories a member may hold: **SC, ST, OBC, Female, Bhar**. It does not say which of them are *mandatory*.

So `dpc.committee.required_categories` ships **empty**, and the optimiser proposes the minimum quorum without imposing a representation requirement. Populating that list is a business rule with a real effect: a committee missing a required category is refused on save, so an over-broad list would block DPCs the rules permit, and an empty one permits a committee the rules may not.

**Recommendation:** confirm the mandatory set, in writing, with the Government Order that fixes it. It is then one configuration line, and `CommitteeComplianceService` enforces it from that moment. Until it is confirmed, the safe state is the current one — the optimiser advises and nothing is refused on a rule nobody has stated.

### BD-23 — Must a committee member hold a post *equal to* or *higher than* the promotion post?

Item 16 asks for the rule to be configurable and does not say which value is correct. `dpc.committee.member_level` therefore ships as `equal` — a member at the same class as the promotion post is permitted, and one below it is not.

The three values are `equal`, `higher` and `unrestricted`. A member whose post is not in any department's establishment (a retired officer, an outside expert) has no class to compare and is permitted under all three, because refusing them would be the rule deciding something it was never asked about.

**Recommendation:** confirm the value. Changing it later invalidates no past committee — the rule is applied when a committee is saved, and a saved committee stands.

### BD-24 — Should the signed documents be bilingual?

Item 7 requires the whole application to be bilingual. `resources/views/documents/` is deliberately exempt: those templates render the **signed** proceedings, promotion list and circulation note. Translating them changes the language a government order is issued in, and that is the Commission's decision, not an implementation detail.

**Recommendation:** rule on the language of issue. The three plausible answers each need different work — Hindi only (translate the templates), English only (current behaviour, made explicit), or both side by side in one document (a layout change, and the largest of the three).

### BD-25 — May a Promotion Candidate see their own marks?

Item 17 lists what a candidate may see and what they may not. *Other officers' marks* are explicitly excluded; **their own marks are not mentioned in either list.** The portal therefore shows a candidate their **outcome** — recommended or not, once published — and no marks at all, on the reading that a list of what is forbidden which omits a thing is not permission.

**Recommendation:** confirm. There is a real argument each way: the officer's own assessment is arguably theirs to see, and equally a mark is meaningless without the comparative list it sits in, which they may not see.

### BD-26 — Should the service class codes themselves be administrable?

The Class Master (item 1) lets the Administrator set each class's bilingual name, order and active flag. The **code** — `I`, `II`, `III` — is not editable, because the M5 scoring engine keys its zone-of-consideration rules on class *pairs*: a code invented at runtime is a class the engine has no rule for, and would fall through to the uncapped default without saying so.

**Recommendation:** leave the codes fixed unless the service rules change. If a new class is ever created, it needs a zone rule and a qualifying score before it needs a master row — which is a scoring-engine change, not a master-data one.

### BD-27 — Is the same employee number in two departments one officer, or two?  *(new, 2026-08-25)*

An employee number is issued **by a department**, so two departments can each hold an officer numbered 1024 with neither being wrong. From a Seniority List the portal sees only the number, and two readings fit:

- the **same officer**, transferred, whose number followed them; or
- **two different officers**, each numbered by their own department.

**Why this is not an engineering question.** Under the first reading the account should follow the officer to the new department. Under the second, doing that transfers a serving officer out of their own department and shows one officer the other's DPC. Until 2026-08-25 the portal assumed the first, silently — a Forest upload could retire a Higher Education officer's posting and strip their departmental role.

**Current behaviour.** Neither is assumed. An account already posted to another department is **not moved, not duplicated and not granted anything**; the clash is reported on the screen that caused it and recorded on the timeline, for the Administrator to resolve. Candidature is scoped to the departments an account has actually been posted to, so no officer sees another's exercise whichever way this is ruled.

**Recommendation:** confirm whether departmental employee numbers are unique across the state service or only within a department. If they are unique statewide, the current reporting is redundant and the reuse can be automatic again. If they are not — which is what we expect — the reporting is correct as it stands, and the remaining question is only whether the Administrator resolves a clash by transferring the officer or by recording a second identity for them.

### BD-28 — When an order sanctions several feeder posts, is that one DPC or several?  *(new, 2026-08-25)*

A Pre-DPC now names every post its promotion may draw on, as Schedule-IV orders do: *Deputy Director* from Assistant Director, Senior Lecturer **and** Research Officer, with a share each. What that order produces when the department comes to conduct the promotion has two readings:

- **one DPC per feeder** — three exercises, each with its own seniority list, its own qualifying-service test and its own marks; or
- **one DPC for the order** — a single exercise in which officers from all three feeders are ranked against one another for the same vacancies.

**Why this is not an engineering question.** The two produce different promotion lists from identical inputs. Under the first, an Assistant Director competes only with other Assistant Directors for the share of vacancies the order gave that feeder. Under the second, they compete with Senior Lecturers and Research Officers for all of them — and the shares become a distribution rule applied after ranking rather than before it.

**Current behaviour.** The first, unchanged from before the order became plural. A DPC carries exactly one Promotion From Post, and it is chosen when the DPC is raised — the form offers only the feeders its order sanctions, and the service refuses to guess when there are several. This was **not** a decision taken while restoring the plural order: it is what the portal has always done, because the seniority list, the qualifying-service test and the scoring engine are all defined per post, and nothing in the decks describes ranking two feeder posts against each other.

**Recommendation:** confirm the first reading. It is what the shares in a Schedule-IV order appear to mean — a quota *per* feeder rather than a distribution applied afterwards — and it is the only reading the M5 engine's zone rules, which key on a class **pair**, can express without a new rule. If the Commission rules the second, it is a scoring-engine change and not a screen change, and it should be raised as such.

---

## Three raised by the change register of 2026-08-26

**None of the three blocks a demonstration, and none blocks a published promotion
list.** In each case the code does the safe thing and says which thing it did.

---

## BD-29 — Must a DPC committee member out-rank the post being filled?

**Status: the rule is not in force, and it used to be.**

The register of 2026-08-24 asked for a configurable member-level rule (item 16): a
committee member's post had to be **equal to or higher than** the promotion post,
and the comparison used the post recorded against them on the Commission Members
master.

Item 5 of 2026-08-26 withdrew that master. A committee row now carries a free-text
designation and **no registered post**, so there is no class to compare and the rule
cannot be evaluated at all. It was removed rather than left in place, because a
`permits()` that returned true for every member — on a screen stating the rule was in
force — would have claimed an enforcement that did not exist.

**What we need from you.** One of three:

1. **Let it go.** The composition attributes (item 8) are the rule that governs who
   sits, and seniority is the Commission's judgement rather than the portal's. Costs
   nothing; this is the current state.
2. **Capture each member's post on the committee.** Add a post selector to the
   committee row, and the rule can be enforced again. Roughly a day, and it makes the
   committee screen longer for a rule that may not be needed.
3. **Enforce it on the designation instead** — only the DPC Chairman must out-rank
   the post. Narrower, and arguably where the requirement actually bites.

**Our recommendation: (1), unless the Commission's own rules state the condition.**
It was a configurable option in the earlier register rather than a stated
requirement, which suggests nobody was sure it applied.

---

## BD-30 — Which categories must a DPC committee represent?

**Status: unchanged from BD-22, and now more answerable.**

`dpc.committee.required_categories` still ships **empty**, so a committee of the
right size is compliant whatever it represents. What changed is that the attribute
set is now the register's own six — SC, ST, OBC, Female, Bhar Sadhak Sachiv, Vibhag
Sachiv — and one member may satisfy several, so a mandatory set is cheaper to satisfy
than it looks.

**What we need from you.** The list, if there is one. Setting it is a configuration
change and no code.

**Our recommendation.** State the list even if it is short. An empty requirement
means the portal advises nothing and refuses nothing, and a committee that should
have had a woman on it will be discovered at the sitting rather than on the screen.

---

## BD-31 — Should an open sitting earn a grace period past the completion date?

**Status: it does not. The register was read literally.**

An allotment carries a date the DPC must be concluded by, and
`dpc:return-expired-allotments` sends an unconcluded DPC back to the Chairman when
that date passes. A DPC whose sitting has **concluded** is never returned, however
late — the member did the work.

But a sitting that is **open and not concluded** *is* returned, because the condition
the register states is "not concluded by the date", not "not started". That is the
literal reading, and inventing a grace period would be inventing a rule.

**What we need from you.** Whether an open proceeding should hold the DPC past the
deadline, and if so for how long.

**Our recommendation: leave it as it is.** A member who has opened a sitting can
conclude it, and a deadline that a member can extend by opening a sitting and
stopping is not a deadline. If the Commission would rather be generous, the safest
form is a fixed extension the Chairman grants explicitly and the timeline records —
not an automatic one.

---

## What is already settled, and needs nothing from you

Recorded so that nobody reopens them: **BD-1** (punishment — ruled a business rule, current behaviour pinned by test), **BD-5** (HRMS — no integration scheduled), **BD-6** and **BD-7** (circulation trigger and proceedings layout — both resolved from the decks during construction), **BD-8** (objection handling — superseded by BD-20, which asks whether the screen exists at all), **BD-10** (court case — recorded and displayed; affects nothing until ruled, and the result is marked provisional), **BD-13** (time limit on a held promotion — visibility only, nothing blocked), **BD-16** and **BD-17** (linking a DPC to its Pre-DPC, and the standing of the older SRS — engineering questions, resolved).

---

## BD-21 — Does the zone formula `(V × 2) + 4` override the printed rule in two cases?  *(new, 2026-08-22)*

**Business requirement.** The vacancy directive of 2026-08-22 states the Zone of Consideration as **`(Total Vacancy × 2) + 4`**. That is exactly the rule printed on slide C6 for a **Class-I → Class-I** promotion, and it reproduces the directive's own worked example: 3 vacancies → 10 officers. **In two cases the flat formula and the printed rule give different answers.**

**Current behaviour.** The printed rule is applied, because AD-1 makes the decks the source of business requirements and AD-2 forbids inventing one. Both cases are visible on screen and pinned by named tests, so a decision either way is a recorded change rather than a silent drift.

| Case | The deck | `(V × 2) + 4` | What the system does |
|---|---|---|---|
| **Exactly one vacancy** | the top **5** officers | 2×1+4 = **6** | **5** |
| **Any pair other than Class-I → Class-I** | **no formula** — every officer meeting the experience qualification is considered, with no cap | 2V+4 | **no cap**, and the exact-count rule on the Seniority List does not apply |

**Options.**

| | Option | Consequence |
|---|---|---|
| A | **Keep the printed rule** (current behaviour) | The zone matches the Commission's own published sizing. A single-vacancy DPC considers 5 officers, and a Class-II promotion considers everyone qualified |
| B | Apply `(V × 2) + 4` **to every case** | Simpler to explain in one line. A single-vacancy DPC considers 6 instead of 5, and **every Class-II and Class-III promotion gains a cap the rules do not impose** — which would exclude officers who are currently considered |
| C | Flat formula for the **single-vacancy** case only, printed rule elsewhere | Removes the one arithmetic exception while leaving the class-pair scope alone |

**Recommendation. Option A.** The second row is the one that matters: extending the formula to class pairs the rules leave uncapped would **narrow the field of officers considered for promotion**, and doing that on the strength of a formula written for a different class pair is the kind of inference this project has consistently refused. If CGPSC intends the formula to govern everywhere, that is a legitimate ruling — but it should be a ruling, because officers become ineligible as a result.

**Impact.** Option A affects nobody: it is what the printed rules say. Option B changes who is considered in **every non-Class-I promotion**, and adds one officer to the zone of every single-vacancy DPC.

**How the answer is applied.** Configuration — `dpc.scoring.zone.rules`, adding an entry per class pair. No code change. The golden test that pins the deck's answers would need updating, which is the intended friction.

---

## The change register of 2026-08-27 — the Vacancy and Merit engines

Eighteen items were added by the register of 2026-08-27. **Ten are the register's own
Part 5**, listed for discussion and deliberately not implemented. **Eight arose while
implementing Parts 1 and 2** — places where the register asked for a mechanism and did
not state a value, or where two of its items pull against each other.

**One of the eighteen needs answering before a real promotion list is published:
BD-39.** It is the only one that can produce a *confidently wrong* list rather than a
blocked one, and it is described first for that reason.

---

### BD-39 — How is an officer's qualification compared with the Recruitment Rule's?  *(highest priority)*

**Business requirement.** Item 12: *"Validate against Recruitment Rule — Qualification,
Experience, Promotion Duration. If not satisfied → Candidate Status = Not Eligible."*

**The problem is the data, not the rule.** The two sides of the comparison do not
describe the same kind of thing:

| Side | What it holds | Example |
|---|---|---|
| Recruitment Rule → Qualification Master | a qualification **level** | `Post-Graduate`, `Graduate`, `Diploma` |
| Evaluation Chart → `qualification` | the **degree** an operator typed, free text | `M.A., B.Ed.`, `M.Sc. (Physics)` |

No comparison in software knows that an M.A. *is* a post-graduate degree. That is a
mapping the Commission owns, and it does not exist yet.

**Current behaviour.** The finding is **reported and not applied**. An unmatched
qualification appears on the run's Pending Decisions naming every officer affected, and
it changes no candidate's status — the score decides, as it did before this register.
Experience and Promotion Duration, the other two limbs of item 12, **are** enforced:
those compare numbers, and numbers compare.

**Options.**

| | Option | Consequence |
|---|---|---|
| A | **Report only** (current behaviour) | No officer is refused on a comparison the portal cannot make. Item 12's qualification limb is not yet enforced, and the run says so on its face |
| B | Enforce the substring match | Refuses `M.A., B.Ed.` against a requirement of `Post-Graduate` — that is, refuses officers who plainly hold the qualification |
| C | Add a qualification level to the chart, mapped to the master, then enforce | Item 12 fully enforced. Needs a master mapping degrees to levels, a migration, and a decision about the charts already recorded |

**Recommendation. Option A now, Option C as the target.** Option B is the only one that
can put a wrong refusal on a signed promotion list, and the direction of the error is
the worst available: a promotion refused, in writing, on a ground that is not true. The
Commission should supply the degree-to-level mapping; until it does, reporting the
finding is the honest state, and the run is marked provisional so nothing is published
on it.

**Impact.** Option A affects no officer's status today. Option B would refuse most
officers on most charts. Option C is a data project, not a rule change.

**How the answer is applied.** `dpc.merit.qualification_gate` — `advise` (current) or
`reject`. Option C additionally needs a structured qualification on the chart.

---

### BD-32 — Is an unsatisfied Recruitment Rule *Not Eligible* or *Rejected*?

**The register says both.** Item 12 ends *"→ Candidate Status = Not Eligible"*; item 20,
*"Automatic Rejection"*, lists **Qualification not satisfied**, **Recruitment Rule not
satisfied** and **Promotion experience not completed** among the grounds for rejection.
The same three conditions, two different statuses.

**Current behaviour. Item 12 is applied — the status is `Not Eligible`** — because item
12 names the status directly for those conditions while item 20 is a list of the gates
that run automatically.

**Why it matters.** `Rejected` is terminal in this DPC; `Not Eligible` is a shortfall a
later year or a corrected record can change. The distinction is what an officer reads.

**Recommendation. Keep item 12's reading.** It is the more specific instruction and the
less final of the two, and finality should be ruled rather than inferred.

**How the answer is applied.** `dpc.merit.rule_failure_status` — `NE` or `RJ`.

---

### BD-33 — How is a fractional promotion vacancy rounded?

**Business requirement.** Item 6: the promotion share comes from the Recruitment Rule.
Item 7: *"Remaining vacancies shall be treated as Direct Recruitment."* Neither says
what happens when the arithmetic does not divide — 7 vacancies at 75% is 5.25 seats.

**Current behaviour. Floor**, so 5.25 becomes 5 promotion seats and 2 direct
recruitment. The exact figure is stored beside the rounded one
(`promotion_vacancy_exact`), and the rule applied is stored with it, so a re-run under a
different rule is visibly a different answer rather than a silent one.

**Options.** Floor (fewest promotions, never over-fills the sanctioned strength),
ceiling (most promotions, can exceed the promotion share the rule states), or
round-half-up.

**Recommendation. Floor.** A promotion seat that the rule's percentage does not fully
support is a seat the department may not be entitled to fill by promotion, and creating
one is harder to undo than leaving it to the next DPC.

**How the answer is applied.** `dpc.vacancy.promotion_rounding` — `floor`, `ceil` or
`round`.

---

### BD-34 — Is the vacancy period 1 January to 30 November of the DPC Year?

**Business requirement.** Items 3 and 4 name **1 January to 30 November of the DPC
Year** for both the retirement and the deputation vacancy. Part 5 then asks the period
to be confirmed.

**Current behaviour.** Exactly that window, derived from `dpc_year` and **never from the
clock** — a calculation re-run next year gives the answer it gave when the Commission
acted.

**What we need from you.** Confirmation, and in particular what happens to an officer
retiring in **December** — see BD-35.

**How the answer is applied.** `dpc.vacancy.period`.

---

### BD-35 — Why does a December retirement not create a vacancy?

**The rule as written.** Item 3 counts a retirement between 1 January and 30 November.
An officer retiring on 15 December vacates the post within the DPC Year and **adds no
vacancy**.

**Current behaviour.** The window is applied literally. The post is vacant, the DPC does
not count it, and the seat waits for the following year's exercise.

**What we need from you.** Whether that is intended. It may well be — a promotion made
in November cannot take effect against a December vacancy — but the rule reads like a
cutoff rather than an exclusion, and the two have different consequences for the officer
next in line.

**Recommendation. Confirm the literal reading, or move the window.** Both are one
setting. What should not happen is the portal quietly extending a published window.

---

### BD-36 — Is the tribal-area bonus per year, or one flat bonus?

**Two items pull against each other.** Item 18: *"Tribal Area benefit shall always be
linked with the same CR year. No Tribal benefit shall be carried forward."* Item 21:
*"Implement the CR marking system exactly as defined in the current approved PPT. Do NOT
modify the marking formula."* And PPT §5.2's formula awards **one flat bonus** for having
served in a tribal area at all, not one per year.

**Current behaviour — item 18 as a linkage requirement, item 21 as the formula.** The
chart now records tribal-area service **against each CR year**, so the benefit is
attributable and cannot be read as applying to a year it was not served in. The
*score* still adds PPT §5.2's single bonus, because changing that would modify the
marking formula.

**What we need from you.** Whether item 18 was also meant to change the arithmetic — one
bonus per tribal year instead of one per officer.

**Impact.** Material. Per-year bonuses would raise the scores of long-serving tribal-area
officers and change the order of the promotion list.

**Recommendation. Leave the formula alone until CGPSC rules**, which is what item 21
requires on its face. The data is now in place either way, so a ruling is applied to
charts already recorded rather than requiring them to be re-entered.

---

### BD-37 — Which five years is the Property Return window?

**Business requirement.** Item 13: *"Validate the last five continuous years. If even
one year is missing, Candidate Status = Rejected."* It does not say what "last" is
measured from.

**Current behaviour.** The window ends at the **most recent year the officer's own chart
carries** and runs back five years. A calendar anchor was deliberately not used: it would
make the same calculation give different answers in different years, which an auditable
promotion record cannot afford.

**What we need from you.** Whether "the last five continuous years" means the five before
the DPC Year, or the five the officer's records actually cover.

**Why it matters.** An officer whose returns run 2018–2022 has a complete window under
the current reading and an incomplete one under a DPC-Year anchor — and the consequence
is rejection.

**How the answer is applied.** `dpc.merit.property_return_years` sets the length; the
anchor is a code change, small and pinned by test.

---

### BD-38 — Does item 13's rejection supersede the M6 hold, or sit beside it?

**What changed.** Before this register, a missing Property Return raised a **hold** in
the proceedings subsystem and left the merit verdict alone — architect ruling AQ-4,
*holds are independent of merit*. Item 13 now makes the engine itself **reject** the
candidate. The same missing return therefore produces both.

**Current behaviour.** Both. The hold still fires — it is detected from the records, not
read off the engine's verdict — and the candidate's status is `Rejected`.

**What we need from you.** Whether the hold mechanism is now redundant for this ground,
or whether the two are meant to coexist (the hold recording that the Commission noticed,
the rejection recording the consequence).

**Recommendation. Coexist, for now.** They answer different questions and neither is
wrong. But a Commission member seeing one officer flagged twice for one fact is entitled
to an explanation, and this is it.

---

## The register's own Part 5 — ten items recorded, not implemented

The register lists these under *"DO NOT IMPLEMENT. Only document the following for
discussion with CGPSC."* Nothing below has been built. Each entry says what the portal
does today, so the Commission can see what it is choosing between.

| | Question | What the portal does today |
|---|---|---|
| **BD-40** | **Exact vacancy calculation period.** Is the vacancy assessed as at the DPC Year, the proposal date, or the sitting date? | Derived from `dpc_year`, never from the clock — see BD-34 |
| **BD-41** | **Fractional promotion vacancy rounding.** | Floor, with the exact figure stored beside it — see BD-33 |
| **BD-42** | **Retirement Vacancy — the Government Rule.** Which order or rule authorises adding a vacancy for a retirement inside the window? | Item 3 applied as written; the authority is not recorded on the calculation |
| **BD-43** | **Deputation Vacancy — the Government Rule.** Likewise, and additionally: does a deputation that *begins* mid-window count differently from one already running? | Overlap, not containment. A deputation overlapping the window by one day adds one vacancy; a null return date means still away |
| **BD-44** | **Cascade Vacancy.** When a promotion vacates the feeder post, does that post's own DPC gain a vacancy in the same year? | **No.** Each DPC counts only its own Promotion To Post. Cascades are not modelled |
| **BD-45** | **Departmental Enquiry.** What is the consequence for a candidate under enquiry? | Recorded as a Note on the chart and displayed. It affects no status and no score |
| **BD-46** | **Court Case.** Likewise. (Also **BD-10**, still open from construction.) | Recorded and displayed; the result is marked provisional. No automatic consequence |
| **BD-47** | **Lokayukt Case.** Likewise. | No field of its own. It would be typed into the Note |
| **BD-48** | **Sealed Cover.** The whole workflow — when a promotion is placed in sealed cover, who opens it, and on what event | **Not implemented.** There is no sealed-cover state |
| **BD-49** | **Class-I to Class-I CR Grade.** The deck's grade vocabulary for a Class-I → Class-I promotion needs clarification | The single grade set from PPT §5.1 is applied to every class pair |

**Three of these ten would change a published list if answered differently: BD-44**
(cascade vacancies would raise seat counts across linked posts), **BD-48** (a sealed
cover suspends a promotion the portal currently publishes), and **BD-49** (a different
grade vocabulary changes every score in a Class-I → Class-I DPC). The other seven are
either already handled conservatively or affect display only.

---

## Four raised by Rule 1.1 of the DPC Understanding Document (2026-10-15)

Rule 1.1 is implemented as a pre-submission verification step on the DPC proposal
(four confirmations, recorded with who and when, required by the submission gate).
BD-50 to BD-58 are reserved by `docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`, so these
start at BD-59.

### BD-59 — What exactly does the "fewer than 50" exception make optional?

**Business requirement.** Rule 1.1: when a previous DPC was held offline and is entered
by Commission staff, uploading the final seniority list and the working officers is
optional *"if the total number of eligible employees due for promotion in the final
seniority list is less than 50."*

**Current behaviour.** **Not implemented.** Both lists are mandatory for every proposal.

**What we need from you.** Whether the exception applies only to a historical DPC entered
by Commission staff or to live proposals as well; which count is compared with 50 (the
list's length, the zone, or the eligible officers); and what replaces the lists when they
are not uploaded.

### BD-60 — Must Rule 1.1 be confirmed again after a return?

**Current behaviour.** Yes. A return opens a new revision, and confirmations made for the
returned revision are kept as history but do not count for the next one. A confirmation
also lapses if the figure it was given against changes (sanctioned strength, Recruitment
Rule version, Seniority List, working strength).

**What we need from you.** Confirmation that this is the intended reading, or a ruling that
confirmations carry across a return when the data is unchanged.

### BD-61 — Is "no previous DPC for this post" an acceptable basis?

**Current behaviour.** The working-strength reconciliation accepts three bases: a previous
DPC held in the portal, a previous DPC held offline (reference, date and minutes PDF), or
a statement that no DPC has been held for the post before, with mandatory remarks. Without
the third, the first DPC for any post could never be submitted.

**What we need from you.** Whether the third basis is acceptable, and whether it needs a
supporting document.

### BD-62 — Full entry of an offline previous DPC by Commission staff

**Business requirement.** Rule 1.1: *"a user account shall be created for the Commission's
staff. Through this account, complete details of the previous DPC will be entered in the
same manner as a new DPC proposal."*

**Current behaviour.** **Not implemented.** The department can cite an offline previous
DPC (reference, date, minutes PDF) when reconciling working strength. There is no
Commission-staff role or route that enters a completed historical DPC (committee,
sections, result, promotion list) without running a live sitting.

**What we need from you.** Which role enters it, which parts are mandatory (minutes only,
or the full proposal and result), and whether such a record may be used as the "previous
DPC" for reconciliation and for seniority.

---

## What changed since 2026-08-19

**Seven items were added: BD-22 to BD-26** from the change register of 2026-08-24, **BD-27** from implementing the improvement register on 2026-08-25, and **BD-28** from restoring the several Promotion From Posts on 2026-08-25 — see above. None blocks a demonstration and none blocks a published promotion list; each is a place where the register asked for a mechanism and left the value to the Commission.

**Eighteen items were added by the change register of 2026-08-27: BD-32 to BD-49.** Eight arose while building the Vacancy Calculation Engine and the Merit Calculation Engine — places where the register asked for a mechanism and left the value open, or where two of its own items pull against each other. Ten are the register's own Part 5, which it explicitly asked to be documented and **not** implemented. **BD-39 is the one to read first**: it is the only item in this project that can put a wrong *refusal* on a signed promotion list, and the portal declines to make that comparison until CGPSC supplies the mapping it needs.

**One item was added earlier: BD-21**, from the vacancy directive of 2026-08-22 — see above. The other eight are unchanged, with the same recommendations. The final phase closed engineering work only — the Content-Security-Policy is now enforced, every screen is addressed by a UUID rather than a number, and the evidence-table database grants are now generated by a command instead of a runbook. **None of that touches a business rule**, and the scoring engine was not modified.

One item is worth re-reading in that light: **BD-19** (how an individual officer signs in). Its Option A — the three facts — remains the running default, and the screen it protects is now behind an enforced Content-Security-Policy and a UUID-addressed URL. That improves the surrounding security; it does not change the recommendation, because the weakness in Option A is that the three facts themselves appear on a departmental seniority list that circulates.

---

## The register of 2026-08-27, in one table

| | Question | Blocks a published list? | Applied by |
|---|---|---|---|
| **BD-39** | How is a qualification compared against the Recruitment Rule? | **Yes — answer this one first** | a setting now, a data project for the full answer |
| BD-32 | Rule failure — *Not Eligible* or *Rejected*? | No | a setting |
| BD-33 | Rounding a fractional promotion vacancy | No | a setting |
| BD-34 | Confirming the 1 Jan – 30 Nov window | No | a setting |
| BD-35 | A December retirement creates no vacancy | No | a setting |
| BD-36 | Tribal-area bonus — per year or flat? | No, but it reorders lists | a code change, pinned by test |
| BD-37 | Which five years the Property Return window covers | No, but it decides rejections | a setting plus a small code change |
| BD-38 | Does item 13's rejection supersede the M6 hold? | No | a decision only; nothing to change |
| BD-40 … BD-49 | The register's own Part 5 — ten items | BD-44, BD-48 and BD-49 would | varies; three need building |

**Read BD-39 first.** It is the only item in this register that can put a *wrong
refusal* on a signed promotion list rather than blocking one, and the portal is
currently declining to make that comparison for exactly that reason.

---

## In one paragraph, for a meeting

The portal is complete and demonstrable. **Thirty-three policy questions remain** —
eight from construction, five from the change register of 2026-08-24, two from the
corrective work of 2026-08-25, three from the register of 2026-08-26, and **eighteen
from the register of 2026-08-27** (eight found while building the Vacancy and Merit
engines, and the register's own ten Part 5 items). The system handles all of them the
same way: it never guesses.

**Three answers would unblock published promotion lists** — reserved relaxation
(BD-2/BD-3), the effective date of a circulated promotion (BD-14), and how CR-shortfall
holds are resolved (BD-15). **Two need answering first even though neither blocks
anything**, because they are the only ones that can produce a *confidently wrong*
published list rather than a blocked one: which waiting list an officer joins when their
category has no vacancy (BD-9), and how a qualification is compared against the
Recruitment Rule (**BD-39**). **Twenty-one of the thirty-three are applied by changing a
setting**, not by writing software.
