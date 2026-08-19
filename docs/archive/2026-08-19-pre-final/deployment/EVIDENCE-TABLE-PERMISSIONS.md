# Evidence Tables — Production Database Permissions

**Status:** Required for production deployment. Architect ruling, 2026-08-13 (M5 Phase 3 review, Decision 2).

---

## The requirement

In production, the **application's database user must hold no `UPDATE` or `DELETE` permission** on:

- `dpc_calculation_runs`
- `dpc_calculation_results`

Migration and administrative users may retain elevated permissions.

## Why this is a deployment control and not application code

Calculation runs and results are immutable, and that is enforced in the application: both models reject `update` and `delete`, a run accepts exactly one transition (`Draft → Completed`), and recalculating creates a new run rather than replacing an old one. Tests cover all of it.

That enforcement lives in Eloquent's model events, and **a query-builder mass update bypasses model events entirely**:

```php
DpcCalculationResult::query()->where(...)->update([...]);  // model guards do not fire
```

This is a characteristic of the ORM, not a defect. It cannot be closed from inside the application, because any guard added lives in the same layer being bypassed.

So the honest statement of the guarantee is:

> No application code path can edit a completed calculation, and no developer can do so accidentally — but a developer with database access, or one who deliberately writes a query-builder update, can.

For a promotion record that may be produced in CAT/HC, that distinction matters. **Database permissions close it.** Database triggers were considered and explicitly rejected by the architect: they would make the tables un-migratable without dropping the triggers first, and the syntax differs between MySQL and SQLite.

## Applying the permissions (MySQL)

Assuming an application user `odpc_app` and a separate migration user `odpc_migrator`:

```sql
-- Application user: read and insert only on the evidence tables.
REVOKE UPDATE, DELETE ON odpc.dpc_calculation_runs    FROM 'odpc_app'@'%';
REVOKE UPDATE, DELETE ON odpc.dpc_calculation_results FROM 'odpc_app'@'%';

GRANT SELECT, INSERT ON odpc.dpc_calculation_runs    TO 'odpc_app'@'%';
GRANT SELECT, INSERT ON odpc.dpc_calculation_results TO 'odpc_app'@'%';

FLUSH PRIVILEGES;
```

**`INSERT` must be retained** — the application creates runs and results. Only modification and removal are withheld.

### One consequence to plan for

The application performs exactly one legitimate `UPDATE` on `dpc_calculation_runs`: the `Draft → Completed` transition at the end of a calculation. With `UPDATE` revoked, that write fails and the surrounding transaction rolls back, so **no calculation can complete**.

Two ways to accommodate it, to be settled before go-live:

1. **Grant column-level `UPDATE` on `status` only:**
   ```sql
   GRANT UPDATE (status) ON odpc.dpc_calculation_runs TO 'odpc_app'@'%';
   ```
   Keeps every other column immutable at the database while allowing the one transition the lifecycle requires. **Recommended.**

2. **Write runs as `Completed` in a single insert**, removing the transition — an application change, and one that loses the "a `Draft` run is a calculation that did not finish" signal.

Option 1 preserves both properties and is a permission change, not a code change.

`dpc_calculation_results` needs no `UPDATE` at all: results are written once and never transition.

## Verifying it

After deployment, as the application user:

```sql
-- Must fail.
UPDATE dpc_calculation_results SET remark = 'TAMPERED' WHERE id = 1;
DELETE FROM dpc_calculation_runs WHERE id = 1;

-- Must succeed.
SELECT COUNT(*) FROM dpc_calculation_runs;
```

Run this as part of the production readiness checklist, not only at first deployment — a permission grant can be widened later by an unrelated change.

## Scope

This applies to the calculation evidence tables. Other tables carrying legal weight — `signatures`, `audits`, `workflow_transitions`, `import_batches` — are **not** covered by this ruling and remain under normal application permissions. Whether they should receive the same treatment is a question for the UAT & Security Hardening sprint; it is recorded in `docs/ARCHITECT_REVIEW.md` rather than decided here.
