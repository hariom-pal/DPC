# Event Catalog

Every event the system publishes, and everything a subscriber needs to consume one.

**Created 2026-08-13 by architect directive.** Updated whenever an event is added or its payload changes.

This is the contract for anything that listens to the system — the notifier today; a calendar integration, an audit exporter, or an HRMS sync tomorrow. `docs/WORKFLOWS.md` describes the state machines; this describes what they emit.

---

## Three independent versions

Do not conflate them. They change for different reasons, at different times, and a consumer may care about one and not the others.

| Version | Current | What it describes | Where it lives | Bump when |
|---|---|---|---|---|
| **Workflow Version** | **1** | The shape of the state machines themselves — which transitions exist, and what each means | Documentation only (this file and `docs/WORKFLOWS.md`) | A transition is added, removed, or its meaning changes |
| **Schema Version** | **1** | The shape of a timeline row's `properties` | `activity_timelines.schema_version` | The meaning of an existing property key changes |
| **Notification Payload Version** | **1** | The shape of the `WorkflowTransitioned` event object | `WorkflowTransitioned::PAYLOAD_VERSION` | The meaning of an existing event property changes |

**Why independent:** adding a transition (Workflow Version) does not change how an existing timeline row reads (Schema Version) and does not change the event object's shape (Payload Version). Tying them together would force consumers to re-verify against changes that cannot affect them.

**Workflow Version 1 is documentation only**, per architect directive — there is no constant to read. It becomes operational when something needs to branch on it; at that point it belongs alongside the other two, not inside them.

**None of the three is bumped for an *added* key.** A subscriber written against version 1 must keep working against a record that merely gained a field. Only a change in the meaning of something that already existed warrants a bump.

---

## Published events

### `WorkflowTransitioned`

The one event class every workflow publishes. Payload version **1**.

| Property | Type | Notes |
|---|---|---|
| `workflow` | string | `pre_dpc`, `dpc_proposal`, `dpc_cgpsc` |
| `event` | string | The transition name |
| `toState` | string | Resulting state |
| `departmentId` | int\|null | Scopes the role-based recipients |
| `notifyRoles` | list\<string\> | Roles to notify **within that department** |
| `remarks` | string\|null | Actor-supplied reason, where the transition takes one |
| `notifyUserIds` | list\<int\> | Specific users, regardless of department |
| `payloadVersion` | int | **1** |

`notifyUserIds` exists because a CGPSC Chairman or Member holds no department, so no role-plus-department query can reach them.

**Dispatch is isolated:** a subscriber that throws is logged and ignored. It can never roll back the transition that published it.

---

## Event reference

Payload = the timeline `properties` written for the event. Every timeline entry additionally carries `uuid`, `schema_version`, `occurred_at`, `actor_uuid` and `actor_name`.

### Pre-DPC Proposal (`pre_dpc`) — M3

| Event | Trigger | Payload | Timeline | Audit | Future subscribers |
|---|---|---|---|---|---|
| `pre_dpc.submit` | AO submits | from, to, remarks | `pre_dpc.submit` | ledger + model audit | Notifications |
| `pre_dpc.approve` | AH approves (signed) | from, to, remarks | `pre_dpc.approve` | ledger + signature record | Notifications |
| `pre_dpc.return` | AH returns | from, to, remarks | `pre_dpc.return` | ledger | Notifications |
| `pre_dpc.cancel` | AO cancels | from, to, remarks | `pre_dpc.cancel` | ledger | Notifications |

### DPC Proposal (`dpc_proposal`) — M4 *(frozen)*

| Event | Trigger | Payload | Timeline | Audit | Future subscribers |
|---|---|---|---|---|---|
| `dpc_proposal.submit` | AO submits a complete proposal | from, to | `dpc_proposal.submit` | ledger + model audit | Notifications |
| `dpc_proposal.approve` | AH approves — signs the full proposal, generates the DPC ID, forwards to CGPSC | from, to | `dpc_proposal.approve` | ledger + signature | Notifications; **M6 allotment becomes possible** |
| `dpc_proposal.return` | AH returns | from, to, remarks | `dpc_proposal.return` | ledger | Notifications |
| `dpc_proposal.cancel` | AO cancels | from, to | `dpc_proposal.cancel` | ledger | Notifications |

**Section events** (not workflow transitions — no `WorkflowTransitioned` is published):

| Event | Trigger | Payload |
|---|---|---|
| `dpc_proposal.committee_updated` | Committee saved | member_count |
| `dpc_proposal.working_officers_updated` | Working officers saved | row_count |
| `dpc_proposal.seniority_list_updated` | Seniority list saved | row_count |
| `dpc_proposal.evaluation_chart_updated` | Evaluation chart saved | row_count |
| `dpc_proposal.import_previewed` | Excel file staged | batch_id, template, valid, rejected |
| `dpc_proposal.import_committed` | Import committed | batch_id, imported, rejected |
| `dpc_proposal.import_cancelled` | Import cancelled | batch_id |
| `dpc_proposal.calculation_completed` | CALCULATE run (M5) | calculation_uuid, rule_version, has_pending_decisions |

`dpc_proposal.calculation_completed` is the hook a results-publication or certificate feature would subscribe to.

### CGPSC Workflow (`dpc_cgpsc`) — M6

| Event | Trigger | Payload | Timeline | Audit | Future subscribers |
|---|---|---|---|---|---|
| `dpc_cgpsc.allot` | Chairman allots a forwarded proposal | allotment_uuid, allotted_to_id/name, allotted_by_id/name, default_dpc_date | `dpc_cgpsc.allot` | ledger + `DpcAllotment` audit | Notifications (AO, AH, allottee) |
| `dpc_cgpsc.reallot` | Chairman moves it to another allottee | as above, plus superseded_allotment_uuid, previous_allotted_to_name, remarks | `dpc_cgpsc.reallot` | ledger + audit | Notifications (AO, AH, new **and outgoing** member) |
| `dpc_cgpsc.accept` | Allottee accepts | allotment_uuid, accepted_by_id/name, accepted_at | `dpc_cgpsc.accept` | ledger + audit | Notifications |
| `dpc_cgpsc.schedule` | Allottee sets date and time | allotment_uuid, schedule_uuid, dpc_date, dpc_time, scheduled_for, timezone, scheduled_by_id/name, default_dpc_date | `dpc_cgpsc.schedule` | ledger + `DpcSchedule` audit | Notifications; **calendar integration** |
| `dpc_cgpsc.reschedule` | Allottee moves the DPC | as `schedule`, plus superseded_schedule_uuid, previous_dpc_date, previous_dpc_time, remarks | `dpc_cgpsc.reschedule` | ledger + audit | Notifications; **calendar update, keyed on `schedule_uuid`** |

**`scheduled_for`** is a resolved ISO-8601 instant; **`timezone`** is the zone the wall clock was entered in. A consumer takes whichever it needs, and events written before time-zone support exists remain correctly interpretable.

**Supersession events carry the previous values** — `previous_dpc_date`, `previous_dpc_time`, `previous_allotted_to_name` — so old-beside-new is reproducible from the timeline without walking a `superseded_by_id` chain through business tables.

---

## Consuming events

### Ordering

**Always `occurred_at`, then `uuid`. Never the sequential id** (architect directive).

`occurred_at` has second granularity, so events written in one transaction share a timestamp and would otherwise return in whatever order the database chose. A history that reorders itself between reads is not a history. A test asserts repeated reads return an identical order.

### Idempotency

Every timeline row and every workflow transition has its own UUID. A subscriber records the UUIDs it has processed and skips those it has seen, so a replay does not duplicate a notification or a calendar entry.

### Replay

A subscriber can be rebuilt entirely from `activity_timelines`, with no business table:

```
SELECT * FROM activity_timelines
 WHERE subject_type = ? AND subject_id = ?
 ORDER BY occurred_at, uuid
```

Each row carries who acted (snapshotted, so a renamed or deleted user cannot corrupt it), what changed, which record it concerns, and the schema version telling the consumer how to read it. A test replays a full allot → accept → schedule → reschedule sequence and rebuilds both a notification and a calendar entry this way.

### Replay Dry Run — planned, not built

**Documented per architect directive; no implementation.**

A dry run replays a timeline range and reports what *would* be emitted, without sending anything. The cases it exists for:

- **Before enabling a new subscriber.** Turning on a calendar integration against months of history would otherwise mean discovering its behaviour by sending real invitations.
- **After a bug.** If notifications were dropped for a period, a dry run shows exactly which would be re-sent before anything reaches an officer.
- **Verifying an export.** Confirming an audit export covers what it should, before it becomes evidence.

The shape it should take:

| Aspect | Design |
|---|---|
| Input | subject filter, event filter, `occurred_at` range |
| Output | ordered list of events with the resolved recipients and rendered payload for each |
| Guarantee | **no side effects** — no SMS, no mail, no calendar call, no database write |
| Mechanism | resolve recipients exactly as the live listener does, then collect instead of dispatch |
| Reporting | count per event type, per recipient, plus anything unresolvable (a deleted user, a missing property) |

The last row is the point: a dry run is most useful when it surfaces what *cannot* be replayed.

### Failure

A subscriber that throws is logged and ignored. It cannot roll back the transition, and it cannot stop other subscribers or other recipients. Delivery is best-effort per recipient.

---

## Adding an event

1. Add the transition to `config/dpc.php → workflows`, or record a domain event through `ActivityTimelineService`.
2. Record a payload rich enough to replay it — including any **previous** values if it supersedes something.
3. Add it to this catalog and to `docs/WORKFLOWS.md`.
4. Bump **Workflow Version** if the state machine changed. Leave Schema and Payload versions alone unless the meaning of an existing key changed.
5. Add a test asserting the event is published and its payload is complete.
