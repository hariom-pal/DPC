# Pending Business Decisions

**Established 2026-08-13 by architect directive. Moved to `docs/` 2026-08-14.** Business questions live here, not in `docs/ARCHITECT_REVIEW.md`. That document is for engineering review; this one is for decisions only CGPSC or the department can make.

Every item below is **blocked on a business answer**, not on engineering. None can be resolved by reading the PPT more carefully or by a design decision — each needs someone with authority over the promotion rules to say what the rule is.

**Standing rule while an item is open:** the engine applies the printed rule, flags every case the unruled rule could have changed, and never guesses in either direction. A flagged case is visible on screen, recorded on the calculation run, and **cannot be published** to a department.

---

## Blocking M7 closure

**M7 is technically complete and its implementation is approved. It is not closed**, because four questions change what a published promotion list says. Business must answer them, or explicitly accept them as deferred.

| # | The question in one line | What it changes |
|---|---|---|
| **BD-2** | Is reserved relaxation automatic, or the committee's discretion? | **Who is eligible.** Any DPC where a reserved category has fewer eligible officers than vacancies is provisional until answered |
| **BD-3** | Does relaxation to 10 marks apply beyond Class-I→I? | **Who is eligible**, in every other class pair |
| **BD-9** | When an officer's category has no vacancy, which waiting list do they go on? | **Which list an officer appears on.** The PPT's own example puts an SC officer on the *unreserved* waiting list; the system puts them on `SC_W` |
| **BD-10** | Does a pending court case annotate the remark, hold the promotion, or disqualify? | **What the published remark says**, and possibly whether the promotion takes effect |

**BD-11** (raised at M8 design) blocks **no milestone**. Architect ruling, 2026-08-14: it is a **Deferred Business Decision — Required before production activation of the Review supersession policy.** M8 is complete, gate-verified and tagged `v0.8.0-m8-phase3` without it.

**None of these is an engineering choice, and none has been guessed at.** BD-9 and BD-10 were found by comparing the system's output against the customer's own worked answer on slide 34, row by row — see `docs/UAT_BUSINESS_VALIDATION.md`, which puts all four to business users in plain language with the worked examples that expose them.

---

---

## Status summary

| # | Item | Blocks | Severity | Raised |
|---|---|---|---|---|
| BD-1 | Does `PUNISHMENT` disqualify an officer? | Publishable results | **P1** | 2026-08-13 |
| BD-2 | Reserved relaxation — automatic or committee discretion? (Q5) | Publishable results | **P1** | 2026-07-30 |
| BD-3 | Relaxation to 10 — Class-I→I only, or all class pairs? (Q6) | Publishable results | **P1** | 2026-07-30 |
| BD-4 | CSC eSign — raw bytes or PDF? | CSC DigiSign integration | P2 | 2026-08-13 |
| BD-5 | HRMS API contract | HRMS integration (unscheduled) | P3 | 2026-08-13 |
| BD-6 | Circulation trigger — which rule governs? (Q12) | M9 | P2 | 2026-07-30 |
| BD-7 | Proceedings/order PDF layout (Q7) | M7 | P2 | 2026-07-30 |
| BD-8 | Objection handling (Q11) | M10 | P3 | 2026-07-30 |
| BD-9 | Waiting-list placement when a category has no vacancy | **M7 closure** | **P1** | 2026-08-14 |
| BD-10 | Does a court case annotate the remark? | **M7 closure** | P2 | 2026-08-14 |
| BD-11 | What happens to an officer already promoted under a reviewed DPC? | **Production activation of the Review supersession policy** — no milestone | **P1** | 2026-08-14 |
| BD-12 | What if the record supplied to resolve a hold is **adverse**? | M9 Phase 2 | P1 | 2026-08-16 |
| BD-13 | Is there a time limit on a held promotion? | Nothing — visibility only | P2 | 2026-08-16 |
| BD-14 | From what date does a **circulated promotion** take effect? | **M9 Phase 3** | **P1** | 2026-08-16 |
| BD-15 | May a **CR-ground** hold be routed to a Review instead of a Circulation? | **M9 — CR-ground circulations are refused today** | **P1** | 2026-08-16 |

---

## BD-1 — Does `PUNISHMENT` in the Note column disqualify an officer?

**Severity: P1. Blocks publishable results.**

**The question.** PPT slide 33 — the customer's own worked answer key — marks two officers **NE (not eligible)** who score **15**, the exact Class-I qualifying threshold. Both carry `PUNISHMENT` in the Note column. No other officer on the slide does.

| Officer | CR marks | Note | Slide 33 says | Engine says |
|---|---|---|---|---|
| US2 | 15 | PUNISHMENT | **NE** | Eligible |
| US10 | 15 | PUNISHMENT | **NE** | Eligible |

The engine agrees with the slide on both **marks**. It disagrees on both **verdicts**.

**Why it is not implemented.** Requirements §5.3 lists exactly two hard eligibility gates — a `घ` grade in the scoring window, and a final-year `ग` for Class-I→I promotions. Neither mentions punishment. Slide 33 is the only evidence for a third gate.

Adding a rule that **refuses officers promotion**, on an engineer's reading of a slide, against approved and now-frozen logic, is not an engineering decision. Architect ruling, 2026-08-13: *"This is a Business Rule. Not an Engineering Rule."*

**What CGPSC needs to confirm:**

1. Does a recorded punishment disqualify outright, or only require the committee's attention?
2. If it disqualifies — is it any punishment ever recorded, or only within the scoring window? Is there a time limit after which it lapses?
3. Which Note values carry this weight? `PUNISHMENT` is one; `C.CASE` (court case) appears on slide 33 as remark `CC` on an officer who **remains on the waiting list**, so C.CASE evidently does *not* disqualify. That contrast is itself useful evidence.
4. Does it interact with the reserved relaxation (BD-2/BD-3)?

**If confirmed, this is a configuration change, not a code change.** It would become a configurable list of disqualifying note codes in `config/dpc.php → scoring`, which places it **inside** the M5 Engine Freeze as a rule-set update rather than outside it as an algorithm change.

**Current behaviour is pinned by test.** `tests/Feature/Eligibility/S33AnswerKeyGoldenTest.php::test_punishment_rows_score_as_the_slide_says_but_are_not_yet_disqualified` asserts today's behaviour with the divergence documented in place, so a ruling has one obvious place to land.

---

## BD-2 — Reserved relaxation: automatic, or committee discretion? *(Q5)*

**Severity: P1. Blocks publishable results.**

The printed rule allows the qualifying score to be relaxed **down to 10** for reserved-category officers when they remain insufficient after zone expansion, and allows the zone to expand to **7 × that category's vacancy**.

**What is unsettled:** whether the engine applies this automatically, or whether a committee exercises it as discretion logged at proceeding time. The two produce different systems — one is arithmetic, the other is a recorded human decision with an audit trail.

**Current behaviour.** Ships disabled (`dpc.scoring.reserved_relaxation.enabled = false`, `dpc.scoring.zone.reserved_expansion.enabled = false`). The engine applies the printed thresholds and **flags** every officer and category the rule could have changed. Flagged runs are marked provisional on screen and record `has_pending_decisions` with the affected names.

**Impact if unanswered:** any real DPC where a reserved category has fewer eligible officers than vacancies cannot be completed correctly. The lists such a run produces are provisional for those categories.

---

## BD-3 — Relaxation to 10: Class-I→I only, or every class pair? *(Q6)*

**Severity: P1. Blocks publishable results.**

Requirements §5.4 prints the relaxation alongside the Class-I→I threshold. It is not stated whether it applies only there, or to all four class pairs.

Configuration already supports either reading (`reserved_relaxation.floor`, keyed thresholds per class pair). Settling BD-2 and BD-3 together is sensible — they are the same rule seen from two angles.

---

## BD-4 — CSC eSign: raw bytes or PDF?

**Severity: P2. Blocks CSC DigiSign implementation.**

Does the CSC eSign API sign **raw bytes**, or require a **PDF** with a signature field?

The signed DPC proposal is currently rendered as plain text containing all five sections. A PDF requirement means rendering the proposal as PDF before signing — work that belongs in the provider or a document-rendering service, not in business logic, but not zero-cost and expensive to discover at integration time.

Nothing is blocked today: `FEATURE_CSC_DIGISIGN` is off, non-production signs through the mock, and production refuses to sign rather than falling back to it. See `docs/integration/CSC-DigiSign.md` §6.

---

## BD-5 — HRMS API contract

**Severity: P3. Blocks nothing.**

No HRMS API exists. Officer records are entered manually or imported from the Excel templates, and every workflow runs normally with `FEATURE_HRMS=false`.

Required before implementation: endpoint and authentication scheme, credentials, employee-lookup response shape, reservation-category vocabulary, rate limits, and a test environment. Full list in `docs/integration/HRMS.md` §6.

---

## BD-6 — Circulation trigger: which rule governs? *(Q12)*

**Severity: P2. Blocks M9.**

The Conventional deck says circulation applies when an officer is missing **2+ years of CR or an immovable-property return**. Slide S14's label says **"CR ≤ 4"**. These are different rules.

Slide 33 supplies two worked instances that should inform the answer:

- **US6** — four graded CR years — is shown with MARK **"–"** and REMARK **CIR**. The slide *withholds the mark entirely* rather than computing a partial score. The engine currently computes a partial score (14) and flags the window incomplete.
- **US8** — 20 marks, Super Seat — is shown as `ST SS CIR`, and its immovable-property row carries an `N`. This supports the property-missing trigger.

Both are recorded for M9 rather than assumed now.

---

## BD-7 — Proceedings / order PDF *(Q7)*

**Severity: P2. Blocks M7.**

Is a formal PDF generated for proceedings, and what is its layout? The PPT says only "proceedings issued". This is the legal output document, so its content and format need CGPSC's specification rather than a designer's guess.

---

## BD-8 — Objection handling *(Q11)*

**Severity: P3. Blocks M10.**

Is the objection deadline configurable? Does raising an objection notify the department, block the DPC, or only log?

---

---

## BD-9 — Waiting-list placement when an officer's category has no vacancy

**Raised:** 2026-08-14, M7 Phase 4 full PPT comparison. **Severity: P1 — it changes a published promotion list.**

**What the PPT shows.** In the S34 worked example the vacancies are 1 UR and 1 ST. US8 (ST) takes the ST seat, so US3 (ST) waits on the ST list — `ST_W`. US4 is **SC**, and there is **no SC vacancy at all**; the PPT places US4 on the **unreserved** waiting list: **`UR_W`**.

**What the system does.** `DpcCalculationService::placeOnLists()` suffixes the officer's own category — `SC_W` — producing a waiting list for a category that has no seat.

**The rule this implies** — *an officer whose own category has no vacancy waits against the unreserved list* — **is stated nowhere in either deck.** It is visible only by reading S34's expected output.

**Why this is a business decision, not an engineering one.** It is a reservation rule, and it sits in the frozen M5 engine. It also interacts with BD-2/BD-3: both concern how reserved-category officers are treated when their category's seats do not accommodate them.

**What CGPSC must confirm:** when an eligible officer's reservation category has no vacancy in this DPC, do they wait against the unreserved list, against their own category's (empty) list, or not at all?

**Current behaviour:** the officer waits under their own category code. Not changed unilaterally; pinned by test either way.

---

## BD-10 — Does a court case (`C.CASE`) annotate the remark?

**Raised:** 2026-08-14, M7 Phase 4 full PPT comparison. **Severity: P2 — it changes what a published list says, not who is on it.**

**What the PPT shows.** US4 carries `C.CASE` in the `NOTE` column, and S34 renders the remark as **`UR_W CC`** — the note composed onto the remark, exactly as `CIR` is composed for a held promotion.

**What the system does.** The note is displayed in its own column; the remark reads `SC_W` (see BD-9) with no `CC`.

**Why it is a business question.** The mechanism now exists — Phase 4 built exactly this composition for `CIR` — so carrying `CC` is small. What is *not* known is what a pending court case **does**: whether it merely annotates, whether it holds the promotion as `CIR` does, or whether it disqualifies. That is adjacent to **BD-1** (`PUNISHMENT`), and answering the two together would be sensible.

**What CGPSC must confirm:** does a pending court case annotate the result only, reserve the promotion, or disqualify the officer?

**Current behaviour:** the note is recorded and displayed, and affects nothing. Not implemented unilaterally.

---

---

## BD-11 — What happens to an officer already promoted under a DPC that is later reviewed?

**Raised:** 2026-08-14, M8 design. **Severity: P1 — it decides what a department is told about a promotion an officer may already hold.**

**Status: Deferred Business Decision — Required before production activation of the Review supersession policy.**

**Architect ruling, 2026-08-14.** BD-11 **does not block M8 completion**, and this register previously said it blocked M8's final phase. M8 is complete, gate-verified and tagged `v0.8.0-m8-phase3`. What the answer gates is narrower and later: **switching the Review supersession policy on in production.** The distinction matters because a milestone gate is invisible outside this project, whereas production activation is a checkpoint someone can be held to.

**The situation.** A DPC concludes. The promotion list is approved, signed and published; officers are promoted and take up their posts. Some time later an error is discovered — a name omitted from the seniority list, a mis-entered CR, a reservation rule misapplied — and a **Review DPC** is raised (S35).

The Review recalculates. Its result may differ from the original's. **The deck does not say what that does to the promotions already given effect.**

**What S35 does say.** It lists six grounds for review, states that a Review may be raised by the department or by the Commission of its own motion, and says the process is identical to a Fresh DPC. It says nothing about consequences.

**Why this is a business decision, not an engineering one.** It is a question about the legal effect of a promotion order, not about how to model one. The engineering choice — whether the Review *supersedes*, *annotates* or simply *follows* the original (architect question **AQ-9**) — merely records whatever the answer is. Implementing a model before the rule is known would mean telling a department something about an officer's promotion that may be wrong.

**What CGPSC must confirm:** when a Review DPC produces a different result from the original,

| | Option |
|---|---|
| **A** | The original promotion is **withdrawn** and replaced by the Review's result |
| **B** | The original promotion **stands**; the Review applies **prospectively** to future vacancies |
| **C** | It **depends on the ground** — e.g. a name wrongly omitted is corrected retrospectively, while a marginal scoring difference is not |
| **D** | Something else — please describe |

**Also worth confirming:** does the answer differ if the officer has since retired, or been promoted again from the corrected post?

**Current behaviour — and this is no longer "none".** M8 Phase 3 is built and shipped. On approval of a Review:

- the DPC it corrects is marked **superseded**, recorded on both DPCs' timelines;
- the department is **notified**, and the message names both DPC codes, because a department holding a signed promotion list needs to know which list replaces it;
- the superseded DPC's documents **remain downloadable** and are served with a `SUPERSEDED` watermark, applied on delivery so the stored file and its SHA-256 stay exactly as issued;
- **nothing is asserted about the promotion itself** — no withdrawal, no reverted rank, no arrears, no revised date of promotion.

**That last line is the deferral.** The system's silence is a deliberate abstention, not a policy — it is correct and shippable, but it is not a position the Commission has taken.

**One consequence worth stating plainly.** A watermark tells a reader the list no longer governs; it does not tell them what happened to the promotion recorded on it. Between now and the ruling, anyone reading a superseded promotion list is reading a document whose legal effect is genuinely undetermined. Deferring BD-11 accepts that knowingly, which is why the answer is required **before the policy is activated in production** rather than merely desirable.

---

## BD-15 — May a CR-ground hold be routed to a Review instead of a Circulation?

**Severity: P1. Raised 2026-08-16, at M9 Phase 1. This is the one M9 is actively refusing on.**

**The question.** S45 lists two grounds on which a promotion is reserved: *"CR not reported for two or more years"* and *"the immovable-property return not recorded for one or more years"*. It then says that when the resolution arrives, a decision is taken and the Commission's consent is obtained in the prescribed form — the circulation note.

**For the property ground that works exactly as written**, and M9 implements it. **For the CR ground it does not**, and the reason is arithmetic rather than legal.

**Why the CR ground is different.** The scoring engine uses *"the five most recent **available** CR years"* — a missing year is left out of the window, not scored as zero. So an officer held on the CR ground has an **incomplete window by definition**, and supplying the missing grade:

- **changes the officer's marks**, because the window grows;
- **makes a Super Seat reachable for the first time** — it is refused outright while the window is incomplete — and a Super Seat carries **notional seniority, which reorders the main list**;
- can **disqualify the officer outright**, if the supplied CR is `घ`, or `ग` in the last year of a Class-I→I promotion.

**Every one of those can change the position of a different officer** — someone below the held officer on the seniority list, who is not party to the circulation and receives no notice of it. A Review DPC has the safeguards for exactly this situation: stated grounds (S37), an Administrative Head's approval of a corrected chart, a sitting, supersession of the published list, and notification. A circulation note has none of them, deliberately.

**Why we cannot simply check whether the mark changed.** Deciding whether a particular resolution *would* change anything is a scoring operation, and **AD-23 forbids a Circulation from performing one.** The test for admissibility would itself be the prohibited act. So the rule has to be categorical: either all CR-ground resolutions are circulations, or none are.

**What the system does today, and why.** A circulation covering a CR-ground hold is **refused with a structured "Pending Business Decision (BD-15)" response** naming the officers affected. Nothing is calculated, nothing is scored, no hold is released, and **the case is not escalated to a Review automatically** — escalating would answer this question by acting. The refusal is recorded on the DPC's timeline, so a case waiting on CGPSC is distinguishable from one nobody raised.

**What we need.** A ruling: may a CR-ground hold be resolved by circulation as S45 appears to allow, accepting that it re-decides the officer's marks and may move other officers on a published list — or must it be raised as a Review DPC?

**Note the practical cost of leaving it open:** those officers' promotions stay reserved with no route to resolution, which is **BD-13 acquiring a second cause.**

## BD-14 — From what date does a circulated promotion take effect?

**Severity: P1. Raised 2026-08-16. Blocks M9 Phase 3 — what the circulation note says.**

The origin DPC states *"PROMOTION AS ON 2017"*. A circulation resolving one of its held promotions may be raised in 2026. **Is the promotion effective from the original DPC's year, or from the date of the Commission's consent?**

This is not a formatting question. **Retrospective seniority reaches every officer below the one promoted** — their relative position, and potentially their pay, changes with the answer. M7 captures `promotion_as_on_year` at approval and never derives it (AQ-7), so the field exists; what it should hold for a circulated promotion is unruled.

## BD-12 — What if the record supplied to resolve a hold is adverse?

**Severity: P1. Raised 2026-08-16. Blocks M9 Phase 2's decision set.**

S45 describes a decision taken *once the resolution is received*. It does not describe the Commission **refusing** a promotion on the strength of what arrives. But a supplied CR of `घ` is a hard disqualifier, and a property return may show a declaration the department cannot accept.

**Does the Commission have a refusal path, and if so, does refusing disturb a promotion list already published?** M9 offers two decisions today — consent, and return for further evidence — and deliberately does not invent a third (AD-2).

## BD-13 — Is there a time limit on a held promotion?

**Severity: P2. Raised 2026-08-16. Blocks nothing.**

S45 sets none. A promotion may therefore remain reserved indefinitely, and until now nothing in the system would have said so.

**M9 makes the age of a held promotion visible** (AQ-24) — showing how long something has been reserved is not a business rule. **Whether there is a limit, and what happens when it passes, is.**

## Closed items

Kept for the record, so a settled question is not reopened by someone reading old notes.

| Item | Resolution | Date |
|---|---|---|
| Ragged S18 rows (three rows list fewer CR grades than year columns) | Accepted as a documentation ambiguity. Natural interpretation stands; do not raise with CGPSC unless UAT proves a mismatch. **Slide 33 subsequently confirmed the natural reading** — US4's blank cell still yields 15, which is only reachable if the window reaches back a year. | 2026-08-13 |
| Per-year Loyalty vs single flag (Q4) | Keep per-year. Slide 18 shows Loyalty as a single column, but per-year is the superset and can represent either. | 2026-08-13 |
| Proposal Version representation | SHA-256 fingerprint of the officer data consumed, since DPC proposals are not sequentially versioned. | 2026-08-13 |
