# UAT Migration, Backup and Rollback — CGPSC ODPC Portal

Database preparation, safe migration, backup, restore and rollback for the UAT MySQL
database.

> **Never run on UAT:**
> - `php artisan migrate:fresh`
> - `php artisan migrate:refresh`
> - `php artisan migrate:reset`
> - `php artisan db:wipe`
> - `php artisan demo:seed` (runs `migrate:fresh`)
> - `php artisan dpc:reset-transactional-data` (truncates the workflow tables)
> - `php artisan test` (with a cached config it runs against the real database)
> - Any `TRUNCATE` or `DROP` by hand.

---

## 1. What is in the migration set

- **86 migrations** in `database/migrations/`. On an empty MySQL 8.0 database they build 83 tables (verified with `php artisan schema:verify-mysql` on MySQL 8.0.46, 2026-10-15).
- **32 of them are not yet in any commit.** They exist only in the development working tree (see `UAT-APPLICATION-DEPLOYMENT.md` §0). They reach UAT only through the release procedure.
- The development database is at batch level with every migration except the two newest:
  - `2026_10_15_440001_create_dpc_proposal_verifications_table` — Rule 1.1
  - `2026_10_15_450001_create_vacancy_master_tables` — Vacancy Master

How many are pending on UAT depends on what UAT already has. **Read it from UAT
itself:**

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan migrate:status
sudo -u <DEPLOY_USER> php artisan migrate --pretend     # prints the SQL of every pending migration; changes nothing
```

### 1.1 Migrations that drop or move data

They matter only if they are **pending on an existing UAT** that holds data. Each one
carries its data forward before dropping, except where noted.

| Migration | What it removes | Data |
|---|---|---|
| `2026_07_30_090010_link_departments_and_users_to_masters` | free-text `departments.district`, `users.designation` | replaced by master FKs |
| `2026_08_24_360001`, `360002` | renames `cadres` → `department_posts` and its columns | renamed, nothing lost |
| `2026_08_24_360003_create_user_department_assignments_table` | `users.department_id`, `users.level` | **backfilled** into `user_department_assignments` first |
| `2026_08_24_360006_reshape_pre_dpc_as_a_post_pair_exercise` | `pre_dpc_posts`, `pre_dpc_post_feeders`, `is_current` | **backfilled** into the new shape first |
| `2026_08_25_390001_restore_multiple_promotion_sources_on_pre_dpc` | single-pair columns on `pre_dpc_proposals` | **backfilled** into `pre_dpc_promotion_sources` first |
| `2026_08_26_400003_remove_commission_member_master` | table `commission_members` | **dropped by business decision** (register 2026-08-26 item 5); not carried |
| `2026_08_26_400005_create_department_post_orders` | `department_post_documents`, `department_posts.government_order_*` | **carried** into `department_post_orders` (`carryForward()`) |
| `2026_08_27_410002_add_retirement_and_deputation_period_to_working_officers` | renames `deputation_date` → `deputation_from` | renamed |

Several others rewrite rows in place (`->update(...)`, for example UUID and sequence
backfills): `270001`, `280003`, `280004`, `310003`, `350001`, `360005`. They do not
delete data.

**If any of these is pending on an existing UAT, the pre-migration backup (§3) is
mandatory, not optional.**

### 1.2 Review — `2026_10_15_450001_create_vacancy_master_tables`

| Check | Result |
|---|---|
| Ordering | Runs after `2026_10_15_440001`. It needs `departments`, `department_posts`, `recruitment_rules`, `users` and `dpc_proposals`, which all exist from earlier migrations. |
| What it creates | `vacancy_positions`, `vacancy_position_working_strengths`, `vacancy_position_entries` |
| What it changes | **One** nullable column on an existing table: `dpc_proposals.vacancy_position_id` (FK, `ON DELETE SET NULL`). Existing proposals get `NULL`, which means "submitted without a Vacancy Master position". |
| Existing data | No existing row is read, rewritten or deleted. No data migration is needed. |
| Keys | `vacancy_positions` is unique on `(department_id, post_name, vacancy_year, revision)` and indexed on `(department_id, vacancy_year, status)`. Every table has a unique `uuid`. FKs use `restrictOnDelete` to departments, `cascadeOnDelete` from a position to its working strengths and entries, and `nullOnDelete` elsewhere. |
| Idempotency | Like every Laravel migration it runs once (the `migrations` table records it). MySQL DDL is **not transactional**. If it fails half-way, some tables may exist without a `migrations` row, and a re-run fails with "table already exists". See §5.3. |
| Rollback | `down()` drops `dpc_proposals.vacancy_position_id` and all three tables. **Every Vacancy Master record is lost.** The uploaded PDFs stay on disk, orphaned. |

`2026_10_15_440001_create_dpc_proposal_verifications_table` (Rule 1.1) has the same
profile: one new table, nothing existing touched. Rollback drops the table and every
Rule 1.1 confirmation.

---

## 2. Create the UAT database and users

On a **new** UAT only. Run as a MySQL administrator.

```bash
sudo mysql
```

```sql
CREATE DATABASE <DB_NAME> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Application account: what the running portal and `php artisan migrate` use.
-- Migrations need DDL rights (CREATE/ALTER/DROP/INDEX/REFERENCES); the portal itself
-- only needs SELECT/INSERT/UPDATE/DELETE. Option 2 below separates them.
CREATE USER '<DB_USER>'@'<APP_HOST_OR_localhost>' IDENTIFIED BY '<DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
   ON <DB_NAME>.* TO '<DB_USER>'@'<APP_HOST_OR_localhost>';
FLUSH PRIVILEGES;
```

**Option 2 — tighter.** Create a separate `<DB_MIGRATOR>` account with the DDL rights,
and give `<DB_USER>` only DML (`SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY
TABLES, LOCK TABLES`). Run migrations with that account:

```bash
DB_USERNAME=<DB_MIGRATOR> DB_PASSWORD='<DB_MIGRATOR_PASSWORD>' php artisan migrate --force
```

Do this **only with config not cached**: run `php artisan config:clear` first and
`config:cache` afterwards.

Then apply the evidence-table grants the application prints
(`UAT-APPLICATION-DEPLOYMENT.md` §7). They remove UPDATE/DELETE on the audit and
evidence tables from `<DB_USER>`.

Test connectivity:

```bash
mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME> -e "SELECT VERSION(), @@character_set_database, @@collation_database;"
cd <APP_DIR> && sudo -u <DEPLOY_USER> php artisan db:show
```

---

## 3. Backup — before every migration on an existing UAT

```bash
TS=$(date +%Y%m%d-%H%M%S)
cd <APP_DIR>
sudo -u <DEPLOY_USER> git describe --tags --always > <BACKUP_DIR>/release-before-$TS.txt

# 1. Database: consistent InnoDB snapshot, no table locks. You are prompted for the password.
mysqldump -h <DB_HOST> -u <DB_USER> -p --single-transaction --quick --routines --triggers --events \
          --hex-blob --default-character-set=utf8mb4 --set-gtid-purged=OFF \
          <DB_NAME> | gzip > <BACKUP_DIR>/db-<DB_NAME>-$TS.sql.gz
gunzip -t <BACKUP_DIR>/db-<DB_NAME>-$TS.sql.gz && echo "dump OK"

# 2. Uploaded documents (all under storage/app).
sudo tar -C <APP_DIR>/storage -czf <BACKUP_DIR>/storage-app-$TS.tar.gz app
sudo tar -tzf <BACKUP_DIR>/storage-app-$TS.tar.gz > /dev/null && echo "storage archive OK"

# 3. The environment file (contains secrets — keep the backup directory 0750).
sudo cp -p <APP_DIR>/.env <BACKUP_DIR>/env-$TS
sudo chmod 0600 <BACKUP_DIR>/env-$TS

# 4. Row counts, to prove afterwards that nothing was lost.
sudo -u <DEPLOY_USER> php artisan tinker --execute='foreach (DB::select("SHOW TABLES") as $t) { $n = array_values((array) $t)[0]; echo $n, " ", DB::table($n)->count(), PHP_EOL; }' \
   | sort > <BACKUP_DIR>/rowcounts-before-$TS.txt
```

`--set-gtid-purged=OFF` is accepted by MySQL 8.0's `mysqldump`. Omit it if your
client rejects it.

---

## 4. Run the migrations

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan down --retry=60          # existing UAT only
sudo -u <DEPLOY_USER> php artisan config:clear
sudo -u <DEPLOY_USER> php artisan migrate:status
sudo -u <DEPLOY_USER> php artisan migrate --pretend         # review
sudo -u <DEPLOY_USER> php artisan migrate --force
sudo -u <DEPLOY_USER> php artisan migrate:status | grep -c Pending    # 0
sudo -u <DEPLOY_USER> php artisan config:cache
sudo -u <DEPLOY_USER> php artisan up
```

### 4.1 Verify the new tables

```bash
sudo -u <DEPLOY_USER> php artisan db:table vacancy_positions
sudo -u <DEPLOY_USER> php artisan db:table vacancy_position_working_strengths
sudo -u <DEPLOY_USER> php artisan db:table vacancy_position_entries
sudo -u <DEPLOY_USER> php artisan db:table dpc_proposal_verifications
sudo -u <DEPLOY_USER> php artisan tinker --execute='dump(Schema::hasColumn("dpc_proposals","vacancy_position_id"));'   # true
```

### 4.2 Confirm no data was lost

```bash
sudo -u <DEPLOY_USER> php artisan tinker --execute='foreach (DB::select("SHOW TABLES") as $t) { $n = array_values((array) $t)[0]; echo $n, " ", DB::table($n)->count(), PHP_EOL; }' \
   | sort > <BACKUP_DIR>/rowcounts-after-$TS.txt
diff <BACKUP_DIR>/rowcounts-before-$TS.txt <BACKUP_DIR>/rowcounts-after-$TS.txt
```

Expected differences:
- **new tables** (with 0 rows)
- tables dropped by a migration from §1.1 that was pending
- `migrations` (more rows)
- `activity_timelines` / `audits`, if anyone used the site meanwhile (it should be in maintenance mode)

**Any existing table whose count went DOWN is a defect.** Stop and restore (§6).

---

## 5. When a migration fails

### 5.1 Read the error

```bash
tail -n 100 <APP_DIR>/storage/logs/laravel-$(date +%F).log
sudo -u <DEPLOY_USER> php artisan migrate:status
```

### 5.2 Common causes

| Symptom | Cause | Action |
|---|---|---|
| `Access denied ... command denied to user` | `<DB_USER>` lacks a DDL right | Grant per §2, re-run |
| `Specified key was too long` | Database not `utf8mb4` / InnoDB `DYNAMIC` | `ALTER DATABASE <DB_NAME> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` (MySQL 8 default row format is DYNAMIC) |
| `Base table or view already exists` | An earlier half-applied run | §5.3 |
| `Cannot add foreign key constraint` | Referenced table missing or a type mismatch | Do not edit the migration on UAT; restore and report |

### 5.3 A half-applied `create_*` migration

MySQL has no transactional DDL. A failure in the middle of a migration can leave some
of its tables behind.

For the two new migrations only: drop the tables they created, but **only if they hold
no rows**, then re-run.

```bash
sudo -u <DEPLOY_USER> php artisan tinker --execute='foreach (["vacancy_position_entries","vacancy_position_working_strengths","vacancy_positions","dpc_proposal_verifications"] as $t) { echo $t, " ", Schema::hasTable($t) ? DB::table($t)->count() : "absent", PHP_EOL; }'
```

If every table present shows `0`, run `php artisan migrate:rollback --step=1` only
when `migrate:status` lists that migration as "Ran". Otherwise ask the DBA to drop the
empty tables, and to drop the `vacancy_position_id` column from `dpc_proposals` if it
was added. Then `migrate --force` again.

For any other migration: **restore the backup** (§6.2). Do not improvise.

---

## 6. Rollback

### 6.1 Limits — read before rolling back

- Rolling back a migration runs its `down()`. For the two new migrations this **drops every Vacancy Master and Rule 1.1 record**. For the destructive migrations in §1.1, `down()` recreates *empty* structures: data that was moved or dropped does not come back.
- Records written **after** the deployment (new proposals, uploads, audit rows) are lost when you restore a backup taken **before** it.
- `audits`, `activity_timelines` and the workflow ledger are evidence. Restoring a backup rewinds them, so record why in the UAT log.

**Prefer this order:**
1. Code-only rollback (the new tables are harmless to the old code — it never reads them).
2. Full restore of the pre-deployment backup.
3. `migrate:rollback` — only for the two newest migrations, and only if their tables are empty or disposable.

### 6.2 Full restore (database + documents)

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan down --retry=60
sudo systemctl stop odpc-queue

# Database — into the same schema (it is replaced table by table by the dump's DROP/CREATE statements)
gunzip -c <BACKUP_DIR>/db-<DB_NAME>-<TS>.sql.gz | mysql -h <DB_HOST> -u <DB_USER> -p <DB_NAME>

# Documents
sudo mv <APP_DIR>/storage/app <APP_DIR>/storage/app.failed-$(date +%s)
sudo tar -C <APP_DIR>/storage -xzf <BACKUP_DIR>/storage-app-<TS>.tar.gz
sudo chown -R <DEPLOY_USER>:<WEB_GROUP> <APP_DIR>/storage/app
sudo find <APP_DIR>/storage/app -type d -exec chmod 2770 {} \; ; sudo find <APP_DIR>/storage/app -type f -exec chmod 0660 {} \;

# Code back to the previous release
sudo -u <DEPLOY_USER> git checkout --detach "$(cat <BACKUP_DIR>/release-before-<TS>.txt)"
sudo -u <DEPLOY_USER> composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u <DEPLOY_USER> php artisan optimize:clear
sudo -u <DEPLOY_USER> php artisan config:cache && sudo -u <DEPLOY_USER> php artisan route:cache && sudo -u <DEPLOY_USER> php artisan view:cache

sudo systemctl start odpc-queue
sudo systemctl reload php8.4-fpm        # RHEL: php-fpm
sudo -u <DEPLOY_USER> php artisan up
```

Then run the validation in `UAT-APPLICATION-DEPLOYMENT.md` §13.

### 6.3 Rolling back only the Vacancy Master migration

Only if no Vacancy Master data needs keeping:

```bash
cd <APP_DIR>
sudo -u <DEPLOY_USER> php artisan migrate:status | tail -3     # 450001 must be the last "Ran" migration
sudo -u <DEPLOY_USER> php artisan migrate:rollback --step=1 --pretend
sudo -u <DEPLOY_USER> php artisan migrate:rollback --step=1 --force
```

The code that uses these tables must be rolled back at the same time, or every
Vacancy Master screen returns HTTP 500.
