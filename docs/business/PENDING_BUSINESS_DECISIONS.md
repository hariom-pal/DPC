# Pending Business Decisions

**Established 2026-08-13 by architect directive.** Business questions live here, not in `docs/ARCHITECT_REVIEW.md`. That document is for engineering review; this one is for decisions only CGPSC or the department can make.

Every item below is **blocked on a business answer**, not on engineering. None can be resolved by reading the PPT more carefully or by a design decision — each needs someone with authority over the promotion rules to say what the rule is.

**Standing rule while an item is open:** the engine applies the printed rule, flags every case the unruled rule could have changed, and never guesses in either direction. A flagged case is visible on screen and recorded on the calculation run.

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

## Closed items

Kept for the record, so a settled question is not reopened by someone reading old notes.

| Item | Resolution | Date |
|---|---|---|
| Ragged S18 rows (three rows list fewer CR grades than year columns) | Accepted as a documentation ambiguity. Natural interpretation stands; do not raise with CGPSC unless UAT proves a mismatch. **Slide 33 subsequently confirmed the natural reading** — US4's blank cell still yields 15, which is only reachable if the window reaches back a year. | 2026-08-13 |
| Per-year Loyalty vs single flag (Q4) | Keep per-year. Slide 18 shows Loyalty as a single column, but per-year is the superset and can represent either. | 2026-08-13 |
| Proposal Version representation | SHA-256 fingerprint of the officer data consumed, since DPC proposals are not sequentially versioned. | 2026-08-13 |
