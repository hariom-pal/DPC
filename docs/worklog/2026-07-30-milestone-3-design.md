# Milestone 3 — Design: Pre-DPC Proposal Workflow

**Date:** 2026-07-30 · **Status:** Awaiting architect approval — no implementation code yet.
**PPT source:** ODPC S7–S11 (stage 2 of 6: "Posts Sanctioned for Promotion" — Authorized Officer entry, Administrative Head approval).
**Nature:** First **workflow** milestone. Introduces a reusable, configuration-driven workflow engine (states, transitions, guards, effects) that later milestones (DPC Proposal M4, Proceedings M7, Review M8, Circulation M9) reuse.

---

## 1. Workflow overview
The department's **Authorized Officer (AO, Level-1)** captures the department's posts sanctioned for promotion with their recruitment-rule detail (pay scale, % direct, % promotion, feeder cadres, experience — per Schedule-IV, PPT S9). The **Administrative Head (AH, Level-2)** reviews and **approves** or **returns** it (S11). Approved Pre-DPC data is the department's authoritative promotion-post basis consumed by the DPC Proposal (M4). Editing is AO-only and permitted while DRAFT/RETURNED; once APPROVED the record is immutable and amendments are made by a new version.

**Aggregate:** `PreDpcProposal` (one current version per department) → `PreDpcPost` line items (post/cadre, class, pay scale, % direct, % promotion) → `PreDpcPostFeeder` (feeder cadre, %, min experience, min qualification). Mirrors the S9 table.

---

## 2. Complete state machine
States (enum `PreDpcStatus`):
- **DRAFT** — AO editing (initial).
- **SUBMITTED** — AO has submitted; awaiting AH.
- **RETURNED** — AH returned for correction (AO-editable again).
- **APPROVED** — AH approved (terminal for the version; immutable).
- **CANCELLED** — withdrawn by AO before approval (terminal).

```
DRAFT ──submit──▶ SUBMITTED ──approve──▶ APPROVED (terminal)
  ▲                    │
  │                    └──return──▶ RETURNED ──resubmit──▶ SUBMITTED
  │                                     │
DRAFT/RETURNED ──cancel──▶ CANCELLED (terminal)
```

Editable states: DRAFT, RETURNED. Terminal states: APPROVED, CANCELLED.

---

## 3. Allowed transitions
| # | Event | From → To | Actor role | Signature |
|---|---|---|---|---|
| T1 | `submit` | DRAFT → SUBMITTED | AO | no |
| T2 | `resubmit` | RETURNED → SUBMITTED | AO | no |
| T3 | `approve` | SUBMITTED → APPROVED | AH | **yes** (step-up) |
| T4 | `return` | SUBMITTED → RETURNED | AH | no |
| T5 | `cancel` | DRAFT → CANCELLED | AO | no |
| T6 | `cancel` | RETURNED → CANCELLED | AO | no |

Any event not listed for the current state is rejected (illegal transition). Transitions are declared in `config/dpc.php → workflows.pre_dpc` (from-state, event, to-state, role, guards[], notifies[]) so they are **configuration-driven**; a generic `WorkflowService` executes them atomically.

---

## 4. Transition validations (guards)
Enforced before any state change (all wrapped in a DB transaction):
- **Role + ownership:** actor holds the required role and belongs to the proposal's department (AO/AH of that department).
- **State precondition:** current status matches the transition's `from`.
- **submit / resubmit (T1/T2):** ≥ 1 post line; every line has post/cadre, class, pay scale; `0 ≤ %direct,%promotion ≤ 100`; each feeder cadre belongs to the same department; `min_experience ≥ 0`; (configurable) `%direct + %promotion = 100` per post.
- **approve (T3):** proposal is SUBMITTED; **fresh signature** obtained via `SignatureService` (Mock in dev, CSC DigiSign in prod) — the transition records the signature reference; on success, the approved posts/recruitment-rule data is activated (see §9 / Risks R1 for the M2 masters linkage).
- **return (T4):** `return_remarks` required (non-empty).
- **cancel (T5/T6):** only from DRAFT/RETURNED.
Illegal transitions and failed guards abort with a domain exception; no partial writes.

---

## 5. Notification points (queued, via events → listeners)
| Transition | Recipients | Channels |
|---|---|---|
| submit / resubmit | Department AH | email + SMS |
| approve | Department AO (+ AH copy) | email + SMS |
| return | Department AO | email + SMS (includes remarks) |
| cancel | Department AH | email + SMS |
Each transition dispatches a single `WorkflowTransitioned` event; a queued listener resolves recipients + channels from the transition's `notifies[]` config and sends via the existing `SmsSender` + Mail pipeline. No notification is hardcoded in controllers/services.

---

## 6. Timeline events (Activity Timeline)
One `ActivityTimeline` entry per transition (subject = `PreDpcProposal`, causer = actor):
`pre_dpc.submitted` · `pre_dpc.resubmitted` · `pre_dpc.approved` · `pre_dpc.returned` · `pre_dpc.cancelled`. Properties capture `{from, to, remarks?, signature_reference?}`.

---

## 7. Audit events
Two layers, both append-only:
- **owen-it audit** on `PreDpcProposal` (and line items) captures field-level before/after on every persisted change (incl. `status`).
- **`workflow_transitions` ledger** (new, morphable) records each transition authoritatively: `subject`, `event`, `from_state`, `to_state`, `actor_id`, `remarks`, `signature_id`, `ip`, `user_agent`, `occurred_at`. This is the tamper-evident workflow history for legal defensibility (orders contested in CAT/HC).
Thus every transition writes: **audit (owen-it + ledger) + timeline + notification** — as mandated.

---

## 8. External integrations (all via existing contracts)
- **SignatureService** → `SignatureProvider` (Mock dev / CSC DigiSign prod) for the AH approval sign-off; signature metadata persisted, optional signed PDF of the approved Pre-DPC stored on the documents disk (S3 in prod).
- **SmsSender** + Mail (queued) for notifications.
- No new external systems. LGD not involved.

---

## 9. Database impact
New tables (all with `created_by/updated_by/deleted_by`, timestamps, soft deletes; FKs + indexes):
- **`pre_dpc_proposals`** — `department_id`, `version`, `status`, `prepared_by`, `submitted_at`, `approved_by`, `approved_at`, `returned_at`, `return_remarks`, `signature_id?`, `cancelled_at`. Unique `(department_id, version)`.
- **`pre_dpc_posts`** — `pre_dpc_proposal_id`, `cadre_id?`/`post_name`, `class_level`, `pay_scale_id?`, `direct_recruitment_pct`, `promotion_recruitment_pct`, `sort_order`.
- **`pre_dpc_post_feeders`** — `pre_dpc_post_id`, `feeder_cadre_id?`/`feeder_name`, `promotion_percentage`, `min_experience_years`, `min_qualification`, `sort_order`.
- **`workflow_transitions`** — generic morphable ledger (§7).
Config: `config/dpc.php → workflows.pre_dpc` (states, transitions, guards, notifications). No changes to M2 tables except a possible link from approved Pre-DPC posts to `cadres`/`recruitment_rules` (pending R1 decision).

---

## 10. Risks
- **R1 — M2 ↔ M3 overlap (key decision).** Pre-DPC posts (S9) largely duplicate the M2 `cadres` + `recruitment_rules` masters. Options: (a) Pre-DPC is the department-facing **authoring workflow** whose APPROVED output **creates/updates** the department's `cadres` + `recruitment_rules` (single source of truth; admin CRUD becomes oversight/correction); (b) Pre-DPC stores its own line items independent of the masters, consumed directly by M4. **Recommendation: (a)** — approved Pre-DPC drives the masters, avoiding two sources of truth. *Needs your ruling before coding.*
- **R2 — Approval signature.** T3 requires a signature; CSC DigiSign isn't wired yet. Dev uses the Mock provider; production blocks approval until CSC is configured. Confirm the Mock is acceptable for non-prod and that approval must hard-require signature.
- **R3 — Workflow-engine scope.** Building a reusable config-driven engine now adds up-front effort but pays off across M4–M9. Kept minimal (linear + return loop); no parallel/branching states in M3.
- **R4 — Versioning of approved Pre-DPC.** Amending an APPROVED proposal creates a new version (like recruitment rules). Confirm this is the desired amend model vs. in-place re-open.
- **R5 — One-per-department assumption.** Assumes a single current Pre-DPC proposal per department. If multiple concurrent submissions per department are required, the aggregate/uniqueness changes.

---
**Open questions to resolve before coding:** R1 (masters linkage), R2 (signature hard-requirement + Mock in non-prod), R4 (versioned amend). These will be carried into `milestone-3-questions.md`. On approval of this state machine I will implement M3 (workflow engine + Pre-DPC screens for AO/AH) and produce the mandatory review + questions documents, then stop.
