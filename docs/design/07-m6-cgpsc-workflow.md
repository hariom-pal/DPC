# M6 — CGPSC Workflow: Design

**Status:** Submitted for architect approval. **Not implemented.**
**PPT scope:** S26–S30 · **Depends on:** M4 (complete, frozen), M5 (complete, engine frozen)
**Date:** 2026-08-13

Architect questions are in `docs/ARCHITECT_REVIEW.md`. This document is the design they refer to.

---

## 1. What this milestone covers

What CGPSC does with a proposal after the department forwards it, up to the point a DPC is scheduled. **The proceeding itself is M7.**

| Slide | Content |
|---|---|
| S26 | CGPSC fixes date and time; AH and AO are notified on registered mobile and e-mail |
| S27 | Chairman login |
| S28 | **Allotment** — Chairman allots each DPC to a member (M1–M5). Then an **Allotment Report**: department, DPC P.No, proposal date, post from/to, allotted-to, **STATUS**, **DEFAULT DPC DATE** |
| S29 | Member login (the member the DPC was allotted to) |
| S30 | **Acceptance and Scheduling** — member accepts and sets DPC date and time. **RE-ALLOTMENT** and **RE-SCHEDULING** are both shown, with the old date/time struck through beside the new |

### Explicitly out of scope

- Conducting the DPC, CALCULATE, and result generation — M7.
- Any change to the frozen calculation engine. **M6 schedules a DPC; it does not calculate one.** The only interaction is that a scheduled DPC becomes M7's entry point.
- Any change to M4's frozen proposal aggregate beyond reading it.

---

## 2. Workflow

The existing `WorkflowEngine` (M3, reused by M4) already provides config-driven transitions with role enforcement, audit, timeline and notification. **M6 adds a workflow definition, not a mechanism.**

```
FORWARDED_TO_CGPSC ──allot──► ALLOTTED ──accept──► ACCEPTED ──schedule──► SCHEDULED
                                  ▲                                          │
                                  └────── reallot ──────┬──── reschedule ────┘
                                                        │
                                            (history retained, never overwritten)
```

Proposed `config/dpc.php → workflows.dpc_cgpsc`:

| Transition | From | To | Role | Signature | Notify |
|---|---|---|---|---|---|
| `allot` | forwarded | allotted | chairman | no | allotted member |
| `accept` | allotted | accepted | member *(allottee only)* | no | chairman |
| `schedule` | accepted | scheduled | member *(allottee only)* | no | AH, AO |
| `reschedule` | scheduled | scheduled | member *(allottee only)* | no | AH, AO |
| `reallot` | allotted, accepted, scheduled | allotted | chairman | no | old + new member |

**"Allottee only" is a stronger check than the role gate.** The existing engine enforces *role*; being a member is not the same as being *this DPC's* member. That check belongs in a policy, and the design assumes a new `DpcAllotmentPolicy` rather than widening anything in M4.

**No signature is required on any M6 transition.** Allotment and scheduling are administrative acts, not legally significant approvals — unlike M4's AH approval or M7's result approval. Flagged so the omission is visibly deliberate.

---

## 3. Data model

### Principle: append-only, never overwritten

S30 displays the previous date beside the new one on a reschedule. That is only possible if history is kept. The same reasoning that produced immutable calculation runs applies here: *"who was this allotted to, and when did that change"* is a question an audit will ask, and a mutable `allotted_to` column cannot answer it.

**This supersedes the earlier sketch in `03-database-design.md` §C**, which proposed mutable fields on `dpc_proposals` (`allotted_to`, `allotment_status`, `default_dpc_date`, `dpc_date`, `dpc_time`). Those would also mean altering M4's frozen table.

### `dpc_allotments`

| Column | Notes |
|---|---|
| `id` | internal only |
| `uuid` | **public identifier** — standing ruling: never expose sequential ids |
| `dpc_proposal_id` | FK |
| `allotted_to` | FK users — the Chairman or a Member |
| `allotted_by` | FK users — the Chairman |
| `allotted_at` | |
| `default_dpc_date` | S28's DEFAULT DPC DATE — **see open point 2** |
| `status` | pending · accepted · scheduled · superseded |
| `accepted_at` | nullable |
| `superseded_by_id` | nullable self-FK — set when re-allotted |
| `reallotment_reason` | nullable |

### `dpc_schedules`

| Column | Notes |
|---|---|
| `id` | internal only |
| `uuid` | public identifier |
| `dpc_allotment_id` | FK |
| `dpc_date`, `dpc_time` | |
| `scheduled_by`, `scheduled_at` | |
| `superseded_by_id` | nullable self-FK — set when rescheduled |
| `reschedule_reason` | nullable |

Re-allotment and re-scheduling **create a new row and mark the old one superseded**, exactly as recalculation creates a new run. S30's old-beside-new display then reads directly from the superseded row.

### Status derivation

The proposal's CGPSC-side state is derived from its current (non-superseded) allotment, not duplicated onto `dpc_proposals`. That keeps M4's table untouched and removes the risk of two sources of truth disagreeing.

---

## 4. Screens

| Screen | Slide | Contents |
|---|---|---|
| Chairman — Allotment | S28 | Proposals awaiting allotment; allot to a member; **Allotment Report** with status and default DPC date |
| Member — Acceptance & Scheduling | S30 | DPCs allotted to the signed-in member; accept; set date/time; reschedule showing the previous value |
| Re-allotment | S30 | Chairman moves a DPC to a different member, with a reason |

Both actors see only what belongs to them: the Chairman sees proposals forwarded to CGPSC; a member sees only DPCs currently allotted to them.

---

## 5. Points requiring a ruling before implementation

These are in `docs/ARCHITECT_REVIEW.md` as questions. Repeated here so the design is self-contained.

1. **Members M1–M5 — master data or role?**
   S28 shows exactly five columns. Proposal: provisioned users holding the existing `member` role, listed dynamically rather than hardcoded to five — the five columns look like a layout, not a rule. *Confirm.*

2. **Does the Chairman set the DEFAULT DPC DATE, or is it derived?**
   S28 shows `10/07/26` against a proposal dated `25/06/2026` — a 15-day gap. If that is a rule (proposal date + N days), it is configuration; if the Chairman types it, it is input. **This is the one place M6 could hide a business rule**, so it is asked rather than assumed. If it is a rule, it is configuration-driven with a golden test.

3. **Can a member decline?**
   S30 shows acceptance and re-allotment, but not refusal. Is re-allotment the Chairman's remedy for an inactive member, or can a member actively decline?

4. **May a DPC be scheduled before its default date?** And is the default date a deadline that drives escalation, or purely a display value?

5. **Notification recipients on re-events.**
   S26 says AH and AO are notified. On a *re*-schedule, are they notified again? Is the outgoing member notified on re-allotment?

Points 2 and 3 may prove to be Category C (business clarification) rather than engineering decisions. No answers have been assumed.

---

## 6. Proposed phasing

Stopping after each phase for review, as with M4 and M5.

| Phase | Content |
|---|---|
| 1 | Workflow definition · `dpc_allotments` · Chairman allotment screen · Allotment Report (S27–S28) |
| 2 | Member acceptance · scheduling · `dpc_schedules` (S29–S30) |
| 3 | Re-allotment · re-scheduling, with history displayed old-beside-new |
| 4 | Notifications (S26) · closeout |

---

## 7. Worked examples

Per the standing directive, PPT worked examples become golden tests. **S28 and S30 contain specimen allotment and scheduling rows, not worked calculations** — they illustrate a screen rather than state an arithmetic result, so they become **UI test fixtures**, not golden tests.

If the ruling on open point 2 makes the default DPC date a derived rule, **that rule gets a golden test** using S28's own figures (proposal `25/06/2026` → default `10/07/26`).

---

## 8. Quality gate

The standard gate applies before requesting approval: Pint · PHPStan · PHPUnit · `composer audit` · `migrate:fresh --seed`, plus Security, Performance, VAPT self-review, PPT comparison, and the 14-point Demo Readiness checklist. M6 has UI from Phase 1, so Demo Readiness applies in full from the first phase.
