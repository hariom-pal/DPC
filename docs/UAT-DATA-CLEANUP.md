# UAT Data Cleanup — `php artisan dpc:uat-cleanup`

> **Status (2026-10-17): IMPLEMENTED.**
>
> - `App\Domain\Maintenance\UatCleanupPlan` (classification), `UatCleanupService` (plan, pre-flight, delete, files) and `App\Console\Commands\UatCleanupCommand`.
> - Tests: `UatCleanupPlanTest`, `UatCleanupCommandTest`.
> - Run once on the **development** database `dpc_dev` on 2026-10-17 (§6). Not run on UAT or production.
>
> Do not use `dpc:reset-transactional-data` as a substitute: it truncates with
> foreign-key checks off and keeps every user account.

Purpose: return a UAT database to "nothing has happened yet" so business UAT can start
from the beginning, keeping only what the application needs to run.

---

## 1. What is preserved and what is cleaned

Source of truth: `app/Domain/Maintenance/UatCleanupPlan.php`. It extends the
classification `dpc:reset-transactional-data` already maintains, with one difference:
**user accounts are cleaned too**, except the CGPSC Administrators.

### 1.1 Which accounts are kept

- An account is kept when it holds a **preserved role**, read from `model_has_roles` joined to `roles`. No user id, email or username is assumed.
- The preserved roles are `dpc.uat_cleanup.preserve_roles`, from `DPC_UAT_CLEANUP_PRESERVE_ROLES` (comma-separated). The default is **`admin,chairman`**: the CGPSC Administrator and the CGPSC Chairman. `admin` is always added, even if the setting leaves it out.
- The application has **no separate "Super Admin" role**. `admin` is the only administrator role, so "Super Admin / Admin / CGPSC Admin" are all this one role.
- Member, Authorized Officer, Administrative Head, Individual and Promotion Candidate accounts are cleaned. So is any account with no role.
- The CGPSC Chairman **record** (`cgpsc_chairmen`) is preserved. Because the Chairman account is kept by default, its `user_id` link stays intact.
- If **no** account holds `admin`, the cleanup refuses to run.

### 1.2 Preserved — never touched (27 tables)

| Table | Why |
|---|---|
| `roles`, `permissions`, `role_has_permissions` | Role and permission definitions |
| `departments` | Departments |
| `department_posts`, `department_post_orders` | Posts (Department Post Mapping) and their Government Orders + PDFs |
| `recruitment_rules`, `recruitment_rule_sources`, `rr_source_qualifications` | Recruitment Rules (needed by Vacancy Master and every DPC) |
| `cgpsc_chairmen` | Required to compose any committee |
| `pay_scales`, `service_classes`, `designations`, `districts`, `qualifications` | Masters |
| `reservation_categories`, `reservation_policies`, `reservation_rosters`, `reservation_roster_points` | Reservation masters |
| `record_reasons`, `review_reasons`, `vacancy_types`, `dpc_types`, `committee_types`, `committee_configurations`, `portal_notices` | Reference masters and configuration |
| `migrations` | Migration history |

### 1.3 Trimmed — only rows of accounts not kept (4 tables)

`users`, `model_has_roles`, `model_has_permissions`, `user_department_assignments`.

### 1.4 Emptied (54 tables)

27 preserved + 4 trimmed + 54 emptied = all 85 tables of the schema. This is the same
set `dpc:reset-transactional-data` classifies, and its test proves that set complete.

| Group | Tables |
|---|---|
| DPC proposals / cases | `dpc_proposals`, `dpc_proposal_revisions`, `dpc_proposal_review_reasons`, `dpc_committee_members`, `dpc_vacancies`, `dpc_vacancy_calculations` |
| Employee and service records (snapshots inside proposals) | `dpc_working_officers`, `dpc_seniority_entries`, `dpc_evaluation_rows`, `dpc_evaluation_cr_grades`, `dpc_evaluation_loyalty_flags`, `dpc_evaluation_property_flags`, `dpc_evaluation_record_gaps`, `dpc_evaluation_documents` (employee-wise CR / IPR / PH undertaking documents) |
| Rule 1.1 | `dpc_proposal_verifications` |
| Vacancy Master | `vacancy_positions`, `vacancy_position_sanctioned_strengths`, `vacancy_position_working_strengths`, `vacancy_position_entries` |
| Pre-DPC | `pre_dpc_proposals`, `pre_dpc_promotion_sources`, `pre_dpc_source_qualifications` |
| Commission workflow | `dpc_allotments`, `dpc_schedules`, `dpc_proceedings`, `dpc_proceeding_documents`, `dpc_proceeding_holds`, `dpc_proceeding_overrides`, `dpc_candidate_calls` |
| Recommendations / decisions | `dpc_calculation_runs`, `dpc_calculation_results` |
| Review and circulation | `dpc_review_links`, `dpc_review_calculations`, `dpc_document_review_references`, `dpc_circulations`, `dpc_circulation_items`, `dpc_circulation_documents` |
| Workflow history | `workflow_transitions`, `activity_timelines`, `audits`, `signatures` |
| Notifications, imports, numbering | `user_notifications`, `self_inspection_accesses`, `import_batches`, `import_rows`, `dpc_id_sequences` |
| Transient state | `otp_verifications`, `password_reset_tokens`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` |

The application has **no separate employee master or employee service-record table**.
Officer data exists only as snapshots inside DPC proposals (the rows above). It has
**no stored report table** either: reports are computed on request. Generated
documents are the proceedings and circulation documents above.

### 1.5 Unknown tables

A table in none of the lists is **preserved and reported**, never deleted by
guesswork. The classification test must be updated when a migration adds a table.

---

## 2. Behaviour of the command

| Rule | Design |
|---|---|
| Default | **Dry run.** Prints every table with its class, row count and rows to delete, the administrators kept, foreign-key effects and blockers. Changes nothing. `--report=<file>` writes the same as JSON. |
| Environment | Refused when `APP_ENV=production`, with no override. `--apply` only where `APP_ENV` is in `dpc.uat_cleanup.allowed_environments` (`DPC_UAT_CLEANUP_ENVIRONMENTS`, default `uat`). |
| Database identity | `--apply` requires `--database=<name>` equal to the connected database, and a typed confirmation of the same name (or `--confirm=<name>`). |
| Backup | `--apply` requires `--backup=<file>`: a readable `mysqldump` (plain or `.gz`), younger than 24 h, whose header names the same database, which contains `CREATE TABLE \`users\`` and ends with `-- Dump completed`. |
| Concurrency | An exclusive file lock; a second run exits. |
| Method | `DELETE` only — no `TRUNCATE`, no `migrate:fresh`/`db:wipe`/`demo:seed`. **One transaction; foreign-key checks stay on.** Any error rolls everything back. |
| Order | Read from the schema's foreign keys. Nullable references between two emptied tables are set to NULL first (this breaks cycles such as `vacancy_positions` ⇄ `vacancy_position_working_strengths`). Then child-before-parent. Then the accounts not kept and their pivot rows. |
| Pre-flight | Every foreign key from a **surviving** row into a row that would be deleted is checked. `ON DELETE SET NULL` is reported: preserved masters lose `created_by`/`updated_by` attribution of deleted users. `CASCADE` or `RESTRICT` with a matching row is a **blocker**. On the current schema there is no blocker: every preserved master references `users` with `SET NULL`, and no preserved table references an emptied one. |
| Files | Collected from emptied rows (`UatCleanupPlan::FILE_REFERENCES`). Deleted **after** the transaction commits, and only if no remaining row in any table still names the same disk + path. Files of preserved rows — post orders, recruitment-rule GOs — are never candidates. |
| Repeatable | A second run finds nothing to delete and succeeds. |
| Report | Always written on `--apply` (to `--report`, or `storage/logs/uat-cleanup-<ts>.json`): before/after counts, rows deleted per table, references cleared, files deleted / kept-shared / missing. |

### 2.1 Usage

```bash
cd <APP_DIR>
php artisan dpc:uat-cleanup                                   # dry run
php artisan dpc:uat-cleanup --report=cleanup-report.json      # dry run + JSON
php artisan dpc:uat-cleanup --apply --database=<DB_NAME> \
    --backup=<BACKUP_DIR>/db-<DB_NAME>-<TS>.sql.gz            # asks you to type the database name
```

---

## 3. Before applying

1. Take and verify the backup exactly as in `UAT-MIGRATION-AND-ROLLBACK.md` §3:
   - database dump
   - `storage/app` archive
   - `.env` copy
   - row counts
2. Put the site in maintenance mode, and stop the queue worker:
   ```bash
   php artisan down --retry=60
   sudo systemctl stop odpc-queue
   ```
3. **Evidence-table grants.** If the DBA applied `php artisan security:evidence-grants` on UAT, the application account cannot `DELETE` from the audit/evidence tables. The cleanup then fails and rolls back completely. Run the cleanup with a database account that has `DELETE` on the schema:
   - pass the credentials through the environment for this one run, with configuration **not** cached;
   - afterwards, re-run `config:cache`.
4. Dry run first; read the report; then `--apply`.
5. Afterwards:
   ```bash
   php artisan optimize:clear && php artisan config:cache
   sudo systemctl start odpc-queue
   php artisan up
   ```
   Log in as an administrator: every other session has been removed.

## 4. Recovery

Restore the backup taken in step 1 with `UAT-MIGRATION-AND-ROLLBACK.md` §6.2 —
database dump and `storage/app` archive. Because the cleanup is one transaction, a
**failed** run needs no recovery: nothing was deleted. Files are touched only after a
successful commit.

## 5. Safety summary

- No `TRUNCATE`, no global foreign-key disabling, no `migrate:fresh`/`db:wipe`/`demo:seed`.
- Never in production; `--apply` only in an allow-listed environment.
- Explicit database-name match and confirmation, plus a verified recent backup.
- Administrator and Chairman accounts, departments, posts, masters, roles and migration history preserved.
- Unknown tables preserved.
- Shared files preserved.
- One transaction; full rollback on error.

## 6. Run log

### 2026-10-17 — development database `dpc_dev` (APP_ENV=local)

Requested by the project owner: keep only the Super Admin (`admin`) and the Chairman.
`local` was allowed for this one run only, through `DPC_UAT_CLEANUP_ENVIRONMENTS=local`
on the command line; configuration was not cached.

1. Backup: `mysqldump --single-transaction` of `dpc_dev`, plus a `storage/app` archive. Both are kept outside the repository.
2. Dry run: no blockers. Three `SET NULL` effects: `department_posts.updated_by` (6 rows), `recruitment_rules.created_by` / `updated_by` (2 rows each).
3. `--apply --database=dpc_dev --confirm=dpc_dev --backup=…`
   - 393 rows deleted;
   - 3 references cleared;
   - 4 file references, all already absent from disk (demo imports and a mock signature).
4. After:
   - users: 2 — #1 CGPSC Administrator (`admin`) and #2 the Chairman (`chairman`), both with their roles; `cgpsc_chairmen.user_id` is still 2;
   - every proposal, Pre-DPC, Vacancy Master, workflow and notification table is at 0;
   - unchanged: departments 9, department posts 9, post orders 9, recruitment rules 2, roles 7, migrations 88.
5. A second dry run found nothing to delete.
