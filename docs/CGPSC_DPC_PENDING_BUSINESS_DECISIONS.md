# CGPSC DPC — Pending Business Decisions

**For:** CGPSC and the business owners of the DPC process
**Version 1.0 · 2026-08-22**
**Status:** Engineering complete. This document lists **only what the system cannot decide for itself.**

Nothing here is a defect and nothing here blocks a demonstration. Each item is a question of **policy**, which is CGPSC's to answer and not the software's to guess.

---

## How to read this

The e-DPC Portal is built and works end to end. Twenty decisions arose during construction that are **matters of policy, not of software**. Each was written down instead of guessed at, because guessing would have meant the system quietly asserting a rule CGPSC had never made — about an officer's promotion, seniority or pay.

**Twelve of the twenty are now closed** — they turned out to be answerable from the presentation decks, or from a decision already taken, or they were engineering questions wearing business clothes. **Eight remained, and a ninth (BD-21) arrived with the vacancy directive of 2026-08-22 — nine in total are listed here.**

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

**Current behaviour.** The officer signs in by confirming **three facts their department recorded about them** — name, date of joining the current cadre, and date of birth. All three must match exactly, nothing is matched partially, attempts are rate-limited more strictly than anywhere else in the portal, and **every attempt is recorded** whether it succeeds or fails. A match opens **only that officer's own record**.

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

### BD-18 — Who registers a department's cadres first?

The Pre-DPC screen now selects posts and feeder cadres **from the Cadre master** rather than accepting typed names, which is what stops a department inventing a sanctioned post. The consequence: **a newly registered department cannot file its first Pre-DPC until somebody populates its Cadre master.** Existing departments are unaffected, and the screen explains this rather than appearing broken.

**Recommendation:** the Commission registers each department's sanctioned posts, because Schedule-IV is a published Government Order and not departmental data. Whichever way this goes, it belongs in the onboarding runbook.

### BD-20 — Is an online objection in scope at all?

Slide S51 has **two buttons: CLOSE and PRINT.** Its notice tells the officer to raise any objection **in person at the department**, and states that the Commission will not consider any representation. There is no online objection anywhere in the deck, so **none was built.**

The project roadmap described a *"time-boxed objection"* for this screen. We believe that came from reading the notice's phrase about representations being consigned to file as though it applied to late objections; in the sentence it applies to **every** representation to the Commission.

**Recommendation:** confirm that self-inspection is view-and-print only, as the deck shows. If CGPSC would prefer an online objection — a real improvement on requiring an officer to travel — it should be approved as a deliberate addition, and it is a small piece of work.

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

## What changed since 2026-08-19

**One item was added: BD-21**, from the vacancy directive of 2026-08-22 — see above. The other eight are unchanged, with the same recommendations. The final phase closed engineering work only — the Content-Security-Policy is now enforced, every screen is addressed by a UUID rather than a number, and the evidence-table database grants are now generated by a command instead of a runbook. **None of that touches a business rule**, and the scoring engine was not modified.

One item is worth re-reading in that light: **BD-19** (how an individual officer signs in). Its Option A — the three facts — remains the running default, and the screen it protects is now behind an enforced Content-Security-Policy and a UUID-addressed URL. That improves the surrounding security; it does not change the recommendation, because the weakness in Option A is that the three facts themselves appear on a departmental seniority list that circulates.

---

## In one paragraph, for a meeting

The portal is complete and demonstrable. Eight policy questions remain, and the system handles all of them the same way: it never guesses. **Three answers would unblock published promotion lists** — reserved relaxation (BD-2/BD-3), the effective date of a circulated promotion (BD-14), and how CR-shortfall holds are resolved (BD-15). **One needs answering first even though it blocks nothing**, because it is the only one that can produce a *confidently wrong* published list rather than a blocked one: which waiting list an officer joins when their category has no vacancy (BD-9). **Six of the eight are applied by changing a setting**, not by writing software.
