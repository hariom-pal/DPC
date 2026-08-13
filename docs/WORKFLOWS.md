# Workflows

Every state machine in the system, its allowed transitions, who may perform them, and what each one records.

**Created 2026-08-13 by architect directive.** Updated whenever a workflow is added or changed.

All workflows are **configuration-driven** (`config/dpc.php → workflows`) and executed by a single `WorkflowEngine`, which enforces transition legality and actor role, then records a ledger entry, a timeline entry, and a notification event. No workflow has its own bespoke engine.

**Standing directive:** workflow history must always be reproducible **entirely from Timeline records** — never reconstructed from current state.

---

## 1. Pre-DPC Proposal (`pre_dpc`) — M3, PPT S7–S11

### State diagram

```
        ┌──────────────── return ◄────────────────┐
        ▼                                         │
     DRAFT ──── submit ────► SUBMITTED ──── approve ────► APPROVED
        │                        │
        │                        └──── return ────► RETURNED ──┐
        │                                                       │
        └──── cancel ────► CANCELLED                submit ◄────┘
```

`APPROVED` projects the posts and feeders onto the M2 masters. Correction after approval is by **amendment**, which creates a new version rather than editing the approved one.

### Allowed transitions

| Event | From | To | Role | Signature |
|---|---|---|---|---|
| `submit` | draft, returned | submitted | Authorized Officer | no |
| `approve` | submitted | approved | Administrative Head | **yes** |
| `return` | submitted | returned | Administrative Head | no |
| `cancel` | draft, returned | cancelled | Authorized Officer | no |

---

## 2. DPC Proposal (`dpc_proposal`) — M4, PPT S12–S25

### State diagram

```
        ┌──────────────── return ◄────────────────┐
        ▼                                         │
     DRAFT ──── submit ────► SUBMITTED ──── approve ────► APPROVED
        │                        │                        (+ DPC ID generated,
        │                        │                         forwarded to CGPSC)
        │                        └──── return ────► RETURNED ──┐
        │                                                       │
        └──── cancel ────► CANCELLED                submit ◄────┘
```

**A single approval covers every section** (architect decision): one signature, one transition, one timeline entry, one audit event. The Administrative Head reviews all five sections through the S21–S25 walkthrough and approves once at the end.

**Submission is gated on completeness.** The PPT's S14→S18 sequence must be finished — committee named, vacancy breakdown recorded, seniority list and evaluation chart entered — or `submit` is refused naming the outstanding sections.

**Every section becomes immutable** outside DRAFT/RETURNED, enforced at both the Policy and Service layers.

### Allowed transitions

| Event | From | To | Role | Signature |
|---|---|---|---|---|
| `submit` | draft, returned | submitted | Authorized Officer | no |
| `approve` | submitted | approved | Administrative Head | **yes** |
| `return` | submitted | returned | Administrative Head | no |
| `cancel` | draft, returned | cancelled | Authorized Officer | no |

**On approval:** the full proposal (all five sections) is rendered and digitally signed, a DPC ID is generated race-safely, and `forwarded_at` is stamped — all inside one transaction. In production, approval is **refused** unless CSC DigiSign is enabled; it never falls back to the mock signature provider.

---

## 3. CGPSC Workflow (`dpc_cgpsc`) — M6, PPT S26–S30

Runs on the **DpcProposal as subject** under its own workflow name, so CGPSC-side state sits alongside the `dpc_proposal` workflow without touching M4's frozen status enum. The state is **derived** from the current allotment, never duplicated onto `dpc_proposals`.

### State diagram

```
   UNALLOTTED ──── allot ────► ALLOTTED
                                  │  ▲
                                  └──┘
                                reallot
                        (new record; previous superseded)

   ── Phase 2 ──────────────────────────────────────────────
   ALLOTTED ── accept ──► ACCEPTED ── schedule ──► SCHEDULED
                                                       │  ▲
                                                       └──┘
                                                   reschedule
```

**M6 is complete.** All five transitions — `allot`, `reallot`, `accept`, `schedule`, `reschedule` — cover S26–S30 in full.

**There is no member-decline transition** (architect Decision 3). If an allottee cannot proceed, the Chairman re-allots.

### Allowed transitions — Phases 1–2

| Event | From | To | Role | Signature |
|---|---|---|---|---|
| `allot` | unallotted | allotted | Chairman | no |
| `reallot` | allotted | allotted | Chairman | no |
| `accept` | allotted | accepted | Member *(allottee only)* | no |
| `schedule` | accepted | scheduled | Member *(allottee only)* | no |
| `reschedule` | scheduled | scheduled | Member *(allottee only)* | no |

None is signed: allotment and scheduling are administrative acts, not legally significant approvals.

**"Allottee only" is a stronger check than the role gate.** The workflow config enforces the *role*; holding the Member role is not the same as being *this* DPC's member. That is enforced by `DpcAllotmentPolicy::act()` at the HTTP layer and re-checked in `SchedulingService`, so a console command or queued job is bound by it too.

### Entry criteria

| Transition | Entry criteria |
|---|---|
| `allot` | Proposal approved by its department **and** forwarded to CGPSC (S19 → S26); no current allotment exists; allottee holds a CGPSC role and is active |
| `reallot` | A current allotment exists; the new allottee differs from the present one; a reason is supplied |
| `accept` | Caller is the allottee; allotment status is `pending` |
| `schedule` | Caller is the allottee; allotment status is `accepted`; a date and a well-formed time are supplied |
| `reschedule` | Caller is the allottee; a current schedule exists; the new slot differs from it; a reason is supplied |

### Exit criteria

| Transition | Exit criteria |
|---|---|
| `allot` | New `dpc_allotments` row, status `pending`; ledger, timeline and notification recorded |
| `reallot` | New row created; the previous row marked `superseded` and linked by `superseded_by_id`; both the new and outgoing members notified |
| `accept` | Allotment status `accepted`, `accepted_at` stamped |
| `schedule` | New `dpc_schedules` row with date and time; allotment status `scheduled` |
| `reschedule` | New `dpc_schedules` row; the previous row marked superseded via `superseded_by_id`; both slots recorded on the timeline so old-beside-new needs no business table |

**The scheduled date is deliberately not validated against `default_dpc_date`** (architect Decision 4). The default is informational until CGPSC specifies a rule; enforcing one would be inventing a business rule. A past date is likewise permitted — CGPSC may record a DPC already held.

### Preconditions

- The proposal must be **approved by its department and forwarded** to CGPSC (S19 → S26).
- The allottee must hold the **Member** or **Chairman** role and be active. Members are resolved **dynamically from the role** — the PPT's M1–M5 columns are a layout, not a limit of five (architect Decision 1).
- Re-allotment requires a **reason** and a **different** allottee.

### Append-only history

**Allotments are never overwritten** (architect directive). Re-allotting writes a new row and marks the previous one `superseded`, linked by `superseded_by_id`. The model rejects any other update and rejects deletion outright, enforced at the model layer so the guarantee survives any caller.

`default_dpc_date` is **manual Chairman input** and is never derived from the proposal date (architect Decision 2). It is optional, because requiring it would itself be an invented business rule.

**Schedules are append-only on the same terms.** `dpc_schedules` carries `superseded_by_id` and `reschedule_reason` from Phase 2, though Phase 2 never populates them: they are the mechanism that makes overwriting impossible, and Phase 3 uses them. The model permits exactly one change — being superseded — and refuses deletion outright.

`dpc_time` is normalised to `H:i:s` on write. MySQL's `TIME` column returns `H:i:s` while SQLite returns whatever string was stored, and a schedule that reads differently per driver is one you cannot compare or export reliably.

---

## 4. Role matrix

| Workflow | Event | Authorized Officer | Administrative Head | Chairman | Member | Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| `pre_dpc` | submit | ✅ | | | | |
| `pre_dpc` | approve | | ✅ | | | |
| `pre_dpc` | return | | ✅ | | | |
| `pre_dpc` | cancel | ✅ | | | | |
| `dpc_proposal` | submit | ✅ | | | | |
| `dpc_proposal` | approve | | ✅ | | | |
| `dpc_proposal` | return | | ✅ | | | |
| `dpc_proposal` | cancel | ✅ | | | | |
| `dpc_cgpsc` | allot | | | ✅ | | |
| `dpc_cgpsc` | reallot | | | ✅ | | |
| `dpc_cgpsc` | accept | | | | ✅ *(allottee)* | |
| `dpc_cgpsc` | schedule | | | | ✅ *(allottee)* | |
| `dpc_cgpsc` | *view allotment / schedule* | | | ✅ | ✅ *(own)* | |

**Ownership scoping is separate from the role.** Department workflows additionally require the actor to belong to the proposal's department. CGPSC users hold **no** department — a DPC reaches CGPSC precisely by leaving its department's control — so `dpc_cgpsc` scopes on role, and from Phase 2 on whether a Member is *this* DPC's allottee.

Admin provisions accounts and master data; it performs no workflow transition.

---

## 5. Audit events

Two independent records, answering different questions.

| Mechanism | Question it answers | Storage |
|---|---|---|
| **Workflow ledger** | What transition happened, from which state to which, by whom, from which IP and user agent | `workflow_transitions` |
| **Field-level audit** (`owen-it/laravel-auditing`) | Which column changed, from what value to what | `audits` |
| **Activity Timeline** | What happened, in order, with domain detail | `activity_timelines` |

Audited models: `DpcProposal`, `DpcCommitteeMember`, `ImportBatch`, `DpcCalculationRun`, `DpcAllotment`, and the M2 masters.

`AUDIT_CONSOLE=true` is set in `phpunit.xml` — auditing is otherwise inert in console, which had silently disabled every audit assertion until M4 closeout.

---

## 6. Timeline events

The timeline is the **reproducible history**. Every event carries enough domain detail to reconstruct what happened without reading current state.

| Event | Recorded when | Key properties |
|---|---|---|
| `pre_dpc.*` | Any Pre-DPC transition | from, to, remarks |
| `dpc_proposal.*` | Any DPC Proposal transition | from, to, remarks |
| `dpc_proposal.committee_updated` | Committee saved | member_count |
| `dpc_proposal.working_officers_updated` | Working officers saved | row_count |
| `dpc_proposal.seniority_list_updated` | Seniority list saved | row_count |
| `dpc_proposal.evaluation_chart_updated` | Evaluation chart saved | row_count |
| `dpc_proposal.import_previewed` | Excel file staged | batch_id, template, valid, rejected |
| `dpc_proposal.import_committed` | Import committed | batch_id, imported, rejected |
| `dpc_proposal.import_cancelled` | Import cancelled | batch_id |
| `dpc_proposal.calculation_completed` | CALCULATE run | calculation_uuid, rule_version, has_pending_decisions |
| `dpc_cgpsc.allot` | DPC allotted | allotment_uuid, allotted_to_id/name, allotted_by_id/name, default_dpc_date |
| `dpc_cgpsc.reallot` | DPC re-allotted | as above, plus superseded_allotment_uuid, previous_allotted_to_name, remarks (reason) |
| `dpc_cgpsc.accept` | Allottee accepted | allotment_uuid, accepted_by_id/name, accepted_at |
| `dpc_cgpsc.schedule` | DPC date and time set | allotment_uuid, schedule_uuid, dpc_date, dpc_time, scheduled_for (ISO-8601), timezone, scheduled_by_id/name, default_dpc_date |
| `dpc_cgpsc.reschedule` | DPC moved | as above, plus superseded_schedule_uuid, previous_dpc_date, previous_dpc_time, remarks (reason) |

**The `dpc_cgpsc` properties are deliberately rich.** `AllotmentService::historyFromTimeline()` builds the allotment history screen from these records **only** — it touches no allotment row, so if the timeline could not answer the question, it would visibly fail rather than quietly fall back to current state. A test asserts the reconstruction.

---

## 6b. Timeline schema, versioning and event identity

The Activity Timeline is the **legal workflow history** (architect ruling). Business tables must never be required to reconstruct what happened, and a future PDF, CSV or audit export must be producible from the timeline alone.

### Row schema — version 1

| Column | Purpose |
|---|---|
| `uuid` | Public identifier. An export that can be regenerated must identify its rows stably, and without leaking sequential ids. |
| `schema_version` | The shape `properties` are written in. **Currently 1.** |
| `subject_type` / `subject_id` | What the event happened to |
| `event` | Dotted name, `<workflow>.<transition>` or `<domain>.<action>` |
| `description` | Human-readable one-liner |
| `causer_type` / `causer_id` | Who did it — a live reference |
| `actor_uuid` | **Snapshot** of the actor's stable public identity |
| `actor_name` | **Snapshot** of the actor's display name at the moment of the event |
| `properties` | Domain detail — the payload an exporter reads |
| `occurred_at` | When |

### Event versioning

`schema_version` starts at **1** and is stamped on every row by `ActivityTimelineService`.

**Bump it when the meaning of an existing key changes — never for an added key.** An exporter written against version 1 must keep working against rows that merely gained a field; it must *not* silently misread a row where, say, `dpc_time` changed from `H:i` to `H:i:s`. Without the stamp an exporter would have to guess which shape a row is in, and a legal export that guesses is not a legal export.

When version 2 arrives, the exporter branches explicitly on `schema_version`; version 1 rows keep rendering exactly as they did.

### Event UUID

Every timeline row and **every workflow transition** carries its own UUID (architect directive). A transition is a legal record: referring to one by sequential id in an export or a document discloses how many exist and invites guessing neighbours — the same reasoning already applied to calculation runs, allotments and schedules.

Existing rows were backfilled when the columns were added, so no record is left without provenance.

### Actor snapshot

`causer_type`/`causer_id` is a **reference**; `actor_uuid`/`actor_name` is a **snapshot**. Both are stored, and they answer different questions.

A reference tells you who the actor is *now*. A snapshot tells you who acted *then*. They diverge in exactly the situations that matter:

- **A user is renamed.** The reference now resolves to a different name than the one who acted. A test asserts the snapshot does not follow the rename.
- **A user is removed.** The reference resolves to nothing, and an event with no attributable actor is not usable as evidence.
- **A replay reads the timeline alone.** It cannot join to `users`, and a sequential `causer_id` would neither be stable across environments nor safe to expose.

`actor_uuid` is `users.uuid`, added for this purpose. Both columns are nullable, because a system-generated event genuinely has no human actor and recording that honestly is better than inventing one.

### Timeline replay

**Workflow events must remain replayable** (architect directive): a future integration regenerates notifications and calendar events from the timeline *only*, never from business tables.

What makes a replay possible, per event:

| Need | Source |
|---|---|
| Which event, in what order | `event`, `occurred_at` |
| Who acted | `actor_uuid`, `actor_name` — snapshots, so still true years later |
| Which record it concerns | domain UUIDs in `properties` (`allotment_uuid`, `schedule_uuid`, `calculation_uuid`, …) |
| What changed | `properties`, including *previous* values on supersession events |
| How to interpret the row | `schema_version` |
| Idempotency | the event's own `uuid` — a replay can tell whether it has already emitted this one |

A test replays a full allot → accept → schedule → reschedule sequence from the timeline and rebuilds both a notification and a calendar entry without reading a single business table.

**This is why supersession events record the previous values.** A reschedule stores `previous_dpc_date` and `previous_dpc_time`, and a re-allotment stores `previous_allotted_to_name`, so the old-beside-new display S30 requires is reproducible from the timeline rather than by walking a `superseded_by_id` chain through business tables.

### Future time-zone support

**Preparation only** (architect directive). The default is and remains **Asia/Kolkata**, configured at `dpc.timezone`. Nothing converts between zones today.

Every scheduling event records the zone it was entered in, because **a stored `14:00` is meaningless without it**. When time-zone support is built, events written before it can still be resolved to an absolute instant correctly — which would be impossible if the zone had only ever been implicit.

`scheduled_for` is already stored as a resolved ISO-8601 instant alongside the wall-clock `dpc_date`/`dpc_time`, so a consumer can take either the literal local time or the absolute moment, whichever it needs.

When the feature arrives, the likely shape is a per-user or per-department display zone, with storage unchanged: the wall clock is what the Commission agreed, and it should not move because a viewer is elsewhere.

### Three independent versions

| Version | Current | Describes | Lives in |
|---|---|---|---|
| **Workflow Version** | **1** | The state machines — which transitions exist and what each means | Documentation only |
| **Schema Version** | **1** | The shape of a timeline row's `properties` | `activity_timelines.schema_version` |
| **Notification Payload Version** | **1** | The shape of the `WorkflowTransitioned` event | `WorkflowTransitioned::PAYLOAD_VERSION` |

**They are deliberately independent** (architect directive). Adding a transition changes the Workflow Version but cannot change how an existing timeline row reads, nor the event object's shape. Tying them together would force consumers to re-verify against changes that cannot affect them.

**None is bumped for an added key** — only for a change in the meaning of something that already existed.

Workflow Version 1 is documentation only for now; it becomes operational when something needs to branch on it. Full detail in `docs/EVENT_CATALOG.md`.

### Export ordering

**Always `occurred_at`, then `uuid`. Never the sequential id** (architect directive).

`occurred_at` has second granularity, so events written inside one transaction share a timestamp and would otherwise come back in whatever order the database chose. A history that reorders itself between reads is not a history. A test asserts repeated reads return an identical order.

Applied to both the timeline and the workflow ledger, everywhere either is read.

### Replay dry run — planned, not built

**Documented per architect directive; no implementation.** A dry run replays a timeline range and reports what *would* be emitted, with no side effects — no SMS, no mail, no calendar call, no write.

It exists for three situations: before enabling a new subscriber against months of history; after a bug that dropped notifications, to see what would be re-sent before anything reaches an officer; and to verify an audit export covers what it should before it becomes evidence.

Design and reporting shape are in `docs/EVENT_CATALOG.md`. The most useful thing it produces is the list of events it *cannot* replay.

### Export readiness

A PDF, CSV or audit export can be produced from `activity_timelines` alone:

- **Who** — `causer_*`, plus the actor's name captured in `properties` at the time, so a renamed or deleted user does not corrupt an old record.
- **What** — `event`, `description`, and the full domain payload.
- **When** — `occurred_at`.
- **Which record** — the domain UUIDs carried in `properties` (`allotment_uuid`, `schedule_uuid`, `calculation_uuid`, …).
- **How to read it** — `schema_version`.

This is why M6's transitions record allottee *names* and not only ids, and why scheduling records `scheduled_for` as a resolved ISO-8601 instant rather than leaving a consumer to join date and time.

### Future calendar integration

**Do not couple the workflow to a calendar** (architect directive). A calendar is a consumer of workflow events, never a participant in them.

When calendar integration arrives, it subscribes to `WorkflowTransitioned` — exactly as `SendWorkflowNotification` does — and reads what it needs from the event and the timeline payload. Specifically:

- `dpc_cgpsc.schedule` already carries `scheduled_for` (ISO-8601), `dpc_date`, `dpc_time`, `schedule_uuid` and the allottee, which is everything an ICS entry or a Google/Outlook event needs.
- `schedule_uuid` is the stable external key for creating, updating or cancelling the calendar entry — so a reschedule (Phase 3) supersedes rather than duplicates.
- It must be behind a `FEATURE_*` flag and a Provider, like every other external integration.

Nothing in `SchedulingService` may learn that a calendar exists. If a calendar API fails, the same guarantee applies as for notifications: the workflow completes regardless.

---

## 7. Notifications

Dispatched as `WorkflowTransitioned` and delivered by `SendWorkflowNotification` (queued) over SMS and e-mail. Listener registration is gated by **`FEATURE_NOTIFICATIONS`**; transports fall back to log drivers when `FEATURE_SMS_GATEWAY` / `FEATURE_EMAIL_GATEWAY` are off, so nothing fails because a gateway is absent.

Recipients come from two independent sources:

- **`notifyRoles`** — roles resolved *within the subject's department*.
- **`notifyUserIds`** — specific users, regardless of department. Added for M6, because a CGPSC Chairman or Member holds no department and no role-plus-department query can ever reach them.

| Workflow | Event | Notified |
|---|---|---|
| `pre_dpc` | submit | Administrative Head |
| `pre_dpc` | approve / return | Authorized Officer |
| `pre_dpc` | cancel | Administrative Head |
| `dpc_proposal` | submit | Administrative Head |
| `dpc_proposal` | approve / return | Authorized Officer |
| `dpc_proposal` | cancel | Administrative Head |
| `dpc_cgpsc` | allot | Authorized Officer, Administrative Head, **allotted Member** |
| `dpc_cgpsc` | reallot | Authorized Officer, Administrative Head, **new Member, and the outgoing Member** |
| `dpc_cgpsc` | accept | Authorized Officer, Administrative Head, allottee |
| `dpc_cgpsc` | schedule | Authorized Officer, Administrative Head, allottee |
| `dpc_cgpsc` | reschedule | Authorized Officer, Administrative Head, allottee |

The outgoing member is told on re-allotment because they may already have been preparing for a DPC that is no longer theirs.

### Notification payload versioning

`WorkflowTransitioned::PAYLOAD_VERSION` is **1** and is carried on every event.

Bump it when the meaning of an existing property changes, **never** when one is added: a subscriber written against version 1 — the notifier today, a calendar integration tomorrow — must keep working against an event that merely gained a field. A subscriber that cannot handle a version it does not recognise should decline rather than guess.

### A workflow never fails because a notification failed

Architect directive. Enforced at three layers, because one is not enough:

1. **`WorkflowEngine::record()` isolates the dispatch.** The ledger and timeline rows are already written; the event dispatch is the last step and is wrapped, so a broken listener — a dead SMS gateway, a misconfigured mailer, a third-party subscriber throwing — degrades to a logged warning instead of rolling back a completed transition.
2. **`SendWorkflowNotification` is queued and `afterCommit`.** Without `afterCommit`, a synchronous queue would run delivery *inside* the workflow's transaction, where a failed SMS could roll back the transition itself.
3. **Delivery is best-effort per recipient.** One unreachable mobile must not stop the next person being told. Failures are logged so they stay visible.

A test asserts this directly: a listener that throws does not prevent the transition from completing.

---

## 8. Rate limiting

| Limiter | Applies to | Default | Rationale |
|---|---|---|---|
| `login` | `POST /login` | 5 / min | Brute-force protection |
| `otp-verify` | `POST /verify` | 5 / min | Brute-force protection |
| `calculate` | `POST .../calculate` | 10 / min | Not brute force — a calculation writes permanent evidence no code path can delete, so an unthrottled button could bury the run the Commission acted on |

All config-driven via `config/dpc.php → rate_limiting`.

---

## 9. Design rules that apply to every workflow

1. **Configuration-driven.** A transition is a config entry, not a method. Legality and role come from config; the engine enforces them.
2. **One engine.** No workflow gets its own bespoke implementation.
3. **Declare only what is implemented.** A transition nothing can perform reads as supported behaviour when it is not; each phase adds its own.
4. **Append-only where the record matters.** Allotments, schedules, calculation runs, import batches and signatures are never overwritten. Superseding creates a new record and links back.
5. **Row-locked transitions.** Each transition re-fetches its subject with `lockForUpdate()` before reading state, closing double-submit and double-approve races.
6. **Service-layer enforcement.** Guards live in the service, not only the controller, so a console command or queued job is bound by the same rules.
7. **History from the timeline.** Never reconstruct workflow history from current state.
8. **Future HRMS enriches data only.** It must never alter the workflow engine (architect directive).
