# Milestone 3 — Architect Questions (Pre-DPC Proposal Workflow)

Pre-implementation. These three decisions block coding of the Pre-DPC workflow. Each has a recommendation; approving the recommendation as-is is fine.

---

## Q1 (R1) — Pre-DPC posts vs the M2 Cadre / Recruitment-Rule masters
**Title:** Should the approved Pre-DPC Proposal drive the `cadres` + `recruitment_rules` masters, or store its own independent line items?

**Background:** The Pre-DPC "posts sanctioned for promotion" screen (PPT S9) captures post/cadre, class, pay scale, % direct, % promotion, and feeder cadres with experience — which largely duplicates the M2 `cadres` and `recruitment_rules` masters. We must decide the single source of truth.

**Why this decision is required:** It determines the data model for M3, whether department Pre-DPC data and CGPSC master data can diverge, and what M4 (DPC Proposal) reads from.

**Available options:**
1. **Approved Pre-DPC drives the masters** — the department AO authors posts in the Pre-DPC workflow; on AH approval the data creates/updates that department's `cadres` + `recruitment_rules`. The M2 admin CRUD becomes CGPSC oversight/correction. One source of truth.
2. **Independent line items** — Pre-DPC stores its own `pre_dpc_posts`/`pre_dpc_post_feeders`, consumed directly by M4; the M2 masters remain separate CGPSC reference data.
3. **Masters only (no department authoring)** — CGPSC admin maintains all cadres/rules; Pre-DPC becomes a read/confirm step, not data entry.

**Pros:**
1. Single source of truth; no divergence; department owns its data (matches PPT); admin retains oversight.
2. Clean workflow isolation; no coupling to master lifecycle.
3. Simplest data model; strong central control.

**Cons:**
1. Approval must reconcile into masters (mapping logic + versioning interplay).
2. Two sources of truth (department vs CGPSC) can diverge; M4 must pick one.
3. Contradicts PPT S7 (department AO enters the data, not CGPSC).

**Recommendation:** **Option 1** — approved Pre-DPC drives the department's `cadres` + `recruitment_rules`.

**Future impact:** M4/M5 read one authoritative set of promotion posts + recruitment rules per department; eligibility engine (M5) consumes the same config.

**Default action if you approve the recommendation:** Pre-DPC line items map to `cadres` + `recruitment_rules` on approval; admin CRUD stays for correction/oversight.

---

## Q2 (R2) — Approval signature requirement (CSC DigiSign not yet wired)
**Title:** Must AH approval hard-require a digital signature, using the Mock provider in non-production until CSC DigiSign is available?

**Background:** The `approve` transition (SUBMITTED → APPROVED) is a legally significant action. The signature abstraction exists (`SignatureProvider`: Mock dev / CSC DigiSign prod), but CSC's API is not yet integrated.

**Why this decision is required:** It determines whether approval can proceed in environments without CSC, and whether signature is mandatory.

**Available options:**
1. **Hard-require signature always** — Mock provider signs in dev/staging; production approval is blocked until CSC DigiSign is configured.
2. **Require in production only** — dev/staging approve without a signature; production requires CSC.
3. **Optional (configurable) signature** — a config flag toggles whether approval requires signing.

**Pros:**
1. Consistent flow everywhere; production is safe by construction.
2. Simpler non-prod testing.
3. Maximum flexibility.

**Cons:**
1. Non-prod approvals carry only a Mock signature (clearly marked).
2. Dev/prod behave differently (weaker test fidelity).
3. Risk of production being misconfigured to skip signing.

**Recommendation:** **Option 1** — always require a signature; Mock in non-prod, CSC hard-required in production.

**Future impact:** When CSC shares its API, only the provider implementation changes; the workflow is unchanged.

**Default action if you approve the recommendation:** `approve` calls `SignatureService`; non-prod uses Mock; production refuses to approve unless a real provider is configured.

---

## Q3 (R4) — Amending an approved Pre-DPC Proposal
**Title:** Should amending an APPROVED Pre-DPC create a new version, or re-open the existing record?

**Background:** Once APPROVED, the Pre-DPC is immutable. Departments will occasionally need to amend sanctioned posts later. Recruitment rules (M2) already use a never-overwrite versioning model.

**Why this decision is required:** It sets the amend model and how history is preserved.

**Available options:**
1. **New version** — approving is final; amendments create a new DRAFT version (v2, v3…) that goes through the workflow again; previous versions retained.
2. **Re-open** — an APPROVED proposal can be re-opened to DRAFT for editing, overwriting in place (history via audit only).

**Pros:**
1. Full history; consistent with recruitment-rule versioning; tamper-evident; safe for contested orders.
2. Simpler UX; fewer records.

**Cons:**
1. More records; must define which version is "current/active".
2. Loses the clean immutability guarantee; weaker audit story.

**Recommendation:** **Option 1** — versioned amendments (never overwrite an approved proposal).

**Future impact:** M4 always consumes the latest APPROVED version; history is preserved for audit/legal review.

**Default action if you approve the recommendation:** APPROVED is terminal; "Amend" clones to a new DRAFT version through the same workflow.

---

### Quick-answer block (optional — copy, fill, return)
```
Q1 (masters linkage): Option ___   notes: ______________________________
Q2 (approval signature): Option ___   notes: ______________________________
Q3 (amend model): Option ___   notes: ______________________________
```

---

## Closeout — Decisions applied & new questions (2026-07-30)

**Approved & implemented:** Q1 → approved Pre-DPC is the single source of truth and projects to Cadre + Recruitment-Rule versions (new RR version each approval); Q2 → signature mandatory, Mock in dev/UAT, CSC-only in prod (approval fails if unavailable, never bypassed); Q3 → approved versions immutable forever, amend = new Draft version (parent linked). Every transition writes audit-ledger + timeline + notification.

### CQ1 — Feeder-cadre resolution on projection
**Title:** How to project Pre-DPC feeders that don't match an existing department cadre?

**Background:** On approval, recruitment-rule feeders require a `feeder_cadre_id`. Feeders are resolved to a department cadre by id or by name (posts in the same proposal create their cadres first). A feeder naming a post not present as a cadre cannot be projected into a recruitment-rule feeder.

**Why this decision is required:** Determines completeness of the projected recruitment rules used by the M5 eligibility engine.

**Available options:**
1. Resolve by id/name; skip unresolved feeders in the RR projection (full detail retained on the Pre-DPC as source of truth). *(implemented)*
2. Auto-create a cadre for every unresolved feeder (requires inventing its service class).
3. Block approval until every feeder resolves to a cadre.

**Pros:** (1) No invented data; Pre-DPC keeps everything. (2) Complete RR feeders. (3) Guarantees complete rules.
**Cons:** (1) RR may omit some feeders until cadres exist. (2) Invents class data. (3) Friction; blocks approval.

**Recommendation:** Option 1 (implemented). Revisit if M5 requires all feeders as cadres.

**Future impact:** M5 reads recruitment-rule feeders; unresolved feeders are visible on the Pre-DPC for reconciliation.

**Default action if you approve the recommendation:** Keep resolve-or-skip; Pre-DPC remains authoritative.

### CQ2 — GO number + effective date on the Pre-DPC proposal
**Title:** Confirm capturing Schedule-IV GO number + effective date at the Pre-DPC proposal level.

**Background:** PPT S9 lists posts/%/feeders but not a GO number or effective date. A valid `recruitment_rule` needs both. These are captured on the Pre-DPC proposal (required before submit).

**Why this decision is required:** They are fields beyond the literal S9 mockup.

**Available options:**
1. Capture GO number + effective date on the proposal (required at submit). *(implemented)*
2. Capture them per post (different Schedule-IV notification per post).

**Pros:** (1) Simple; one notification per submission. (2) Per-post precision.
**Cons:** (1) Assumes one GO per submission. (2) More data entry.

**Recommendation:** Option 1 (implemented); move to per-post only if departments cite different GOs per post.

**Future impact:** Recruitment rules created on approval carry this GO/effective window.

**Default action if you approve the recommendation:** Keep proposal-level GO number + effective date.
