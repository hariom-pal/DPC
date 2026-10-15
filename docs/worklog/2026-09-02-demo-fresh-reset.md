# Complete Fresh Reset for the Demo — 2026-09-02 (second pass)

The earlier UAT cleanup preserved the Recruitment Rules master, as instructed.
Running the flow afterwards exposed why that could not stand for a demo, so the
rules were cleared too on an explicit decision.

## Why the rules had to go

A Recruitment Rule is not typed in — approving a **Pre-DPC projects it into the
master**. So the preserved rules and the ones the demo creates are the same kind
of record, and they collide:

| | Rule 4 (v1, preserved) | Rule 9 (v2, created by the test run) |
|---|---|---|
| Government Order | `F-2-14/2025/20-Three` | `GOVOD1394/F/2026` |
| Effective from | 01-01-2026 | 02-09-2026 |
| **Promotion From Post** | **Principal, Higher Secondary School** | **Head Master, Middle School** |
| **Minimum experience** | **5 years** | **10 years** |
| Qualifications | Post-Graduate | PG / PG with B.Ed. / Ph.D. |

Both `active`, both for Block Education Officer, and describing **two different
promotion ladders**. Not a duplicate version — a contradiction. And it was not a
one-off: every Pre-DPC approval mints a new version, so the same clash would have
reappeared live during the demo.

## What was cleared

- `dpc:reset-transactional-data --force` — 44 rows across 14 tables, 2 stored
  files. This removed the test run's `PDPC-2026-SE-0001`, `DPC-2026-SE-0001`,
  three working officers, one import batch, signature, timeline and ledger rows.
- `recruitment_rules` (9), `recruitment_rule_sources` (10),
  `rr_source_qualifications` (15) — truncated, so the demo's first rule is `v1`.
- Three **orphan PDFs** whose owning rows do not exist (`department_posts` ids 10
  and 11 are absent from a master holding ids 1–9):
  `private/recruitment-rules/11/1/…`, `private/private/department-posts/10/…`
  and `…/11/…`. `storage/app` now holds nothing but its three `.gitignore` files.
- Redis: application cache and every stale session.

Every `AUTO_INCREMENT` is back to 1 — `recruitment_rules`, `pre_dpc_proposals`,
`dpc_proposals` and `dpc_id_sequences` included.

## What was preserved

Everything else, untouched: departments (9), department posts (9), department
post orders (9), pay scales (6), designations (23), districts (28),
qualifications (17), service classes (3), reservation categories (5) / policies
(5) / rosters (2) / roster points (200), committee types (2) and configurations
(2), CGPSC chairman (1), record reasons (7), review reasons (6), vacancy types
(4), DPC types (3), users (25), roles (7), role assignments (23) and department
assignments (16).

## Verification

- All 47 transactional tables at 0 rows; recruitment rules and both child tables
  at 0.
- All 9 demo accounts authenticate with `Demo@2026`.
- Screens render 200 on the empty database: `/admin`,
  `/admin/recruitment-rules`, `/admin/department-posts`, `/admin/departments`,
  `/admin/cgpsc-chairman`, `/admin/committee-configurations`,
  `/admin/audit-trail`, `/dashboard`, `/pre-dpc`, `/pre-dpc/create`,
  `/dpc-proposals` — as Admin, AO, AH and Chairman.

## The demo now tells a clean story

The Recruitment Rules master starts **empty**. Approving the Pre-DPC creates
`v1` in front of the audience, and the DPC screen draws its promotion share from
that one rule. One rule, one version, no contradiction.

## Sample sheets

`docs/demo/sample-data/` carries three sheets, each validated through the real
importer at 0 rejected rows: `demo-working-officers.csv` (3 rows, 15 columns),
`demo-seniority-list.csv` (9 candidates, 11 columns) and
`demo-evaluation-chart.csv` (9 rows, 35 columns). They assume one specific
promotion pair — Block Education Officer from Head Master, Middle School, 10
years' experience, PG / PG with B.Ed. / Ph.D., 100% promotion, DPC Year 2026 —
which `docs/demo/UPLOAD_FILES_GUIDE.md` now states up front.

## Status

**Ready for a complete, from-scratch demo.**

Rollback: `…/scratchpad/dpc_dev_backup_pre_demo_fresh.sql` and
`storage_app_backup_pre_demo_fresh.tgz` (state immediately before this reset);
`dpc_dev_backup_pre_uat_cleanup.sql` (state before the first cleanup).
