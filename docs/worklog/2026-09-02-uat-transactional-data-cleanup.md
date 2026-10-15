# Transactional Data Cleanup for Business UAT — 2026-09-02

Cleared every Pre-DPC/DPC workflow record from `dpc_dev` so the final Business UAT
can run the complete flow from scratch. No master data, user account, role or
master document was deleted, modified or recreated.

## How it was done

`php artisan dpc:reset-transactional-data --force`.

The command already existed and classifies **all 78 tables** into exactly one of
two exhaustive lists (47 transactional / 31 preserved) and refuses to run if any
table is in neither. That guard was confirmed to fire correctly on MySQL before
the run. A full `mysqldump` and a `storage/app` tarball were taken first.

## Tables cleaned — 2,004 rows deleted

| Table | Rows |
|---|---|
| dpc_evaluation_cr_grades | 420 |
| dpc_evaluation_loyalty_flags | 420 |
| dpc_evaluation_property_flags | 420 |
| activity_timelines | 164 |
| user_notifications | 115 |
| dpc_evaluation_rows | 70 |
| dpc_seniority_entries | 70 |
| workflow_transitions | 58 |
| dpc_calculation_results | 55 |
| dpc_committee_members | 36 |
| dpc_working_officers | 35 |
| signatures | 25 |
| dpc_vacancies | 18 |
| pre_dpc_source_qualifications | 12 |
| dpc_proposals | 9 |
| dpc_proposal_revisions | 9 |
| pre_dpc_promotion_sources | 9 |
| pre_dpc_proposals | 8 |
| dpc_vacancy_calculations | 8 |
| dpc_allotments | 6 |
| dpc_proceeding_documents | 6 |
| dpc_calculation_runs | 5 |
| dpc_schedules | 5 |
| dpc_proceedings | 4 |
| dpc_document_review_references | 4 |
| self_inspection_accesses | 2 |
| dpc_circulation_documents | 2 |
| dpc_id_sequences | 2 |
| dpc_circulations, dpc_circulation_items, dpc_review_links, dpc_review_calculations, dpc_proceeding_holds, dpc_proceeding_overrides, dpc_proposal_review_reasons | 1 each |
| import_rows, import_batches, dpc_evaluation_record_gaps, audits, otp_verifications, password_reset_tokens, sessions, jobs, job_batches, failed_jobs, cache, cache_locks | 0 (already empty) |

All 47 transactional tables now hold 0 rows and every `AUTO_INCREMENT` is reset to
1, so the first Pre-DPC of the UAT will be numbered `0001`.

**Files:** 36 generated/uploaded transactional files removed from
`private/proceedings`, `private/circulations`, `private/signatures`,
`private/imports`.

**Redis:** application cache flushed, queue cleared, and 4 stale browser sessions
deleted (one still pointed at a now-deleted DPC proposal URL).

## Master tables preserved — 31, byte-for-byte identical

Verified by comparing `CHECKSUM TABLE` before and after: `users` (25),
`user_department_assignments` (16), `roles` (7), `permissions`, `model_has_roles`
(23), `model_has_permissions`, `role_has_permissions`, `departments` (9),
`department_posts` (9), `department_post_orders` (9), `record_reasons` (7),
`pay_scales` (6), `service_classes` (3), `designations` (23), `districts` (28),
`qualifications` (17), `reservation_categories` (5), `reservation_policies` (5),
`reservation_rosters` (2), `reservation_roster_points` (200), `committee_types`
(2), `committee_configurations` (2), `cgpsc_chairmen` (1), `recruitment_rules`
(8), `recruitment_rule_sources` (9), `rr_source_qualifications` (12),
`review_reasons` (6), `vacancy_types` (4), `dpc_types` (3), `portal_notices` (0),
`migrations` (79).

Master documents on disk untouched: `private/recruitment-rules/` and
`private/private/department-posts/`. `docs/demo/sample-data/` upload templates
intact.

## Warnings

1. **Recruitment Rules now have no originating Pre-DPC.** Rules v1 for posts 2–9
   are preserved, but the approved Pre-DPCs that projected them are gone. A fresh
   UAT Pre-DPC approval mints **v2** of the rule for that post; both resolvers
   (`VacancyEngine::recruitmentRule`, `DpcProposalService::currentRuleFor`) order
   by `version DESC`, so the new rule wins. Not a blocker — but the master list
   will show two active versions per post.
2. **14 Promotion Candidate accounts (ids 11–24) are preserved but currently have
   no candidature**, because candidature is derived from Seniority Lists which are
   now empty. By design they re-acquire it when a new list names their employee
   number (`CG100037` … `CG100518`). They have random, unknown passwords and
   `must_change_password = 1` — they were never usable with the demo password.
3. **3 orphan PDFs on disk predate this cleanup** and were left alone:
   `private/private/department-posts/10/…`, `…/11/…` and
   `private/recruitment-rules/11/1/…`. They reference `department_posts` ids 10
   and 11, which do not exist (the master holds ids 1–9). Note also the doubled
   `private/private/` path segment for department-post orders.
4. **`storage/app/private/private/department-posts`** — the doubled path segment
   suggests the upload path is prefixed with the disk root as well as the disk
   name. Left as-is; not in scope.

## Verification

- All 47 transactional tables at 0 rows; all transactional `AUTO_INCREMENT` = 1.
- 31 preserved tables byte-for-byte identical (`CHECKSUM TABLE` diff clean).
- All 9 demo accounts authenticate with `Demo@2026` via `Auth::attempt`.
- Screens render 200 on empty data: `/admin`, `/admin/departments`,
  `/admin/department-posts`, `/admin/pay-scales`, `/admin/designations`,
  `/admin/districts`, `/admin/committee-types`,
  `/admin/committee-configurations`, `/admin/cgpsc-chairman`, `/admin/dpc-types`,
  `/admin/audit-trail`, `/dashboard`, `/pre-dpc`, `/pre-dpc/create` (as Admin, AO,
  AH, Chairman and Member).
- `migrate:status` — 0 pending.

## Incident during the run

`php artisan demo:credentials` was invoked expecting it to *list* credentials; it
in fact **resets passwords, creates missing demo users and writes a credential
sheet**. It created users 34/35 (Higher Education AO/AH), 2 role rows, 2
department assignments, flipped department `HE` to `registered`, re-hashed all
passwords, wrote 2 timeline rows and generated
`docs/demo/DEMO_LOGIN_CREDENTIALS.{md,pdf}`. **All of it was reverted** from the
pre-cleanup dump; the preserved-table checksum diff above is the proof. The two
generated credential files were deleted; the pre-existing `docs/demo/` content was
not touched.

## Status

**Ready for Fresh Business UAT.**

Rollback available at
`…/scratchpad/dpc_dev_backup_pre_uat_cleanup.sql` and `storage_app_backup.tgz`.
