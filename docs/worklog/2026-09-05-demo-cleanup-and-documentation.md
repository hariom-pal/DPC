# Demo Cleanup and Documentation — 2026-09-05

Cleared every transactional record from `dpc_dev`, preserved all master data and
all 34 user accounts, and produced the three demonstration documents plus a
register for the future Chairman/committee workflow.

**No code, database schema, business logic, API, screen, workflow or validation
was changed.** The one data correction made is stated in §4.

---

## 1. Cleanup

`php artisan dpc:reset-transactional-data --force`, plus a manual truncate of the
three Recruitment Rule tables (which the command classifies as **preserved**, but
which must be empty for a demonstration — a rule is projected into the master
when a Pre-DPC is approved, so a preserved rule and the one the demo creates
would collide as two active versions describing different ladders; the same
decision was taken on 2026-09-02).

### Transaction tables cleaned — 50, all now at 0 rows

`import_rows` · `import_batches` · `dpc_evaluation_cr_grades` ·
`dpc_evaluation_loyalty_flags` · `dpc_evaluation_property_flags` ·
`dpc_evaluation_record_gaps` · `dpc_evaluation_rows` · `dpc_seniority_entries` ·
`dpc_working_officers` · `dpc_vacancies` · `dpc_committee_members` ·
`dpc_proposal_review_reasons` · `dpc_proposal_revisions` ·
`dpc_calculation_results` · `dpc_calculation_runs` · `dpc_vacancy_calculations` ·
`dpc_circulation_documents` · `dpc_circulation_items` · `dpc_circulations` ·
`dpc_document_review_references` · `dpc_review_calculations` · `dpc_review_links` ·
`dpc_proceeding_documents` · `dpc_proceeding_holds` · `dpc_proceeding_overrides` ·
`dpc_proceedings` · `dpc_schedules` · `dpc_allotments` ·
`pre_dpc_source_qualifications` · `pre_dpc_promotion_sources` · `dpc_proposals` ·
`pre_dpc_proposals` · `workflow_transitions` · `activity_timelines` ·
`signatures` · `audits` · `user_notifications` · `self_inspection_accesses` ·
`dpc_id_sequences` · `otp_verifications` · `password_reset_tokens` · `sessions` ·
`jobs` · `job_batches` · `failed_jobs` · `cache` · `cache_locks` ·
`recruitment_rules` · `recruitment_rule_sources` · `rr_source_qualifications`

Every `AUTO_INCREMENT` on these tables is back to **1**, so the first Pre-DPC of
the demonstration will be `PDPC-2026-SE-0001` and its DPC `DPC-2026-SE-0001`.

**Files:** `storage/app` holds nothing but its three `.gitignore` files.
**Redis:** flushed — cache, queue and every stale browser session.

### Master tables preserved — 31, byte-for-byte identical

Proved by `CHECKSUM TABLE` taken before any work started and again at the end;
the diff is empty.

`users` (34) · `user_department_assignments` (25) · `roles` (7) · `permissions` ·
`model_has_roles` (32) · `model_has_permissions` · `role_has_permissions` ·
`departments` (9) · `department_posts` (9) · `department_post_orders` (9) ·
`record_reasons` (7) · `pay_scales` (6) · `service_classes` (3) ·
`designations` (23) · `districts` (28) · `qualifications` (17) ·
`reservation_categories` (5) · `reservation_policies` (5) ·
`reservation_rosters` (2) · `reservation_roster_points` (200) ·
`committee_types` (2) · `committee_configurations` (2) · `cgpsc_chairmen` (1) ·
`review_reasons` (6) · `vacancy_types` (4) · `dpc_types` (3) ·
`portal_notices` (0) · `migrations` (81) · and the three Recruitment Rule tables,
deliberately emptied as above.

---

## 2. End-to-end rehearsal

Rather than document from the source, the whole flow was **executed twice over
HTTP** against this database and the documents transcribe what came back. The
database was returned to the clean baseline afterwards, and the master checksums
re-verified.

Pre-DPC → submit → AH approve *(Recruitment Rule v1 projected)* → DPC raised →
committee composed *(CGPSC Chairman seated automatically)* → three CSV imports →
departmental calculation → submit → AH review S21–S25 → approve, sign, forward →
Chairman allots → Member accepts and schedules → sitting opened → calculated →
result approved → documents generated, signed and published → Circulation DPC
raised, approved, consented and signed, releasing the hold.

Also proved: the 9 demo logins (by e-mail and by mobile), the Promotion
Candidate password reissue and login, the forced password change,
Self-Inspection, the candidate's own view, Reports, and the Audit Trail.

Figures the supplied sample data produces:

| | |
|---|---|
| Sanctioned / Working | 4 / 3 |
| Base / Retirement / Deputation vacancy | 1 / 1 / 0 |
| **Total Vacancy** | **3** — Promotion 3, Direct 0 |
| Zone of Consideration | No cap (Class III → Class II) |
| Seniority List / Evaluation Chart | 9 / 9 |
| Calculation | 9 assessed · 6 eligible · Main **3** · Waiting 3 · Not listed 3 |
| Holds | 1 — SN 6, immovable property not recorded for 2025 |

The Main List of 3 equals the Total Vacancy the engine derived, with neither
number typed by anybody.

---

## 3. Documents produced

| File | Contents |
|---|---|
| `docs/demo/DEMO_USERS.md` | all 34 accounts with name, employee id, login id, e-mail, mobile, department, designation, roles, login method, demo password and status; grouped by role and by department; how to obtain a Promotion Candidate login on the day |
| `docs/demo/CURRENT_DEMO_FLOW.md` | 25 steps, each with purpose, login user, navigation path, exact click sequence, business explanation, validation, expected result, reports generated and next step; plus the exact figures and ten known limitations |
| `docs/demo/DEMO_DATA_GUIDE.md` | all six upload screens with navigation path, file name, format, required columns, sample record count and expected result; the verification record; the failure/fix table |
| `docs/FUTURE_BUSINESS_ENHANCEMENTS.md` | **FBE-001**, the future Chairman/committee workflow, marked *Not Implemented*, with a nine-point comparison against what exists today and seven points the business must settle before it is built |

`docs/PENDING_BUSINESS_QUESTIONS.md` gained one cross-reference line so a decided
future workflow is not filed as an open question.

`docs/demo/DEMO_CHEAT_SHEET.md`, `DEMO_FLOW.md` and `UPLOAD_FILES_GUIDE.md`
describe the deleted seeded database (`SE-0001`…`SE-0009`) and would have
misled the presenter. Each now carries a **SUPERSEDED** banner pointing at the
three current documents. They were not deleted.

---

## 4. The one data correction

`docs/demo/sample-data/demo-seniority-list.csv` had an empty
`DATE OF POSTING ON PROMOTION TO POST` column. Self-Inspection's default identity
factors are **name + that date + date of birth**, so with the column blank no
officer could ever match and the Self-Inspection step was unusable — confirmed
by an actual failed match before the fix, and an actual successful match after.

The column is now filled with each officer's feeder posting date, which is the
convention the project's own demo seeder uses (`DemoChart::postedOnFeederPost`).
**Sample data only** — no code, schema, validation or workflow was touched.

---

## 5. Known limitations carried into the demonstration

1. **`php artisan queue:work` must be running.** Notifications, OTPs and
   credential messages are queued listeners on Redis. Without a worker the
   notification bell stays empty for the whole demonstration; with one, a single
   pass produced 21 notifications.
2. **Department Registration cannot be completed on-screen.** Both OTPs are
   written to `storage/logs/laravel.log`; `DPC_OTP_FAKE_CODE` is unset and was
   left unset.
3. **23 Promotion Candidates have no known password.** They have no e-mail and
   no mobile, so the random password issued at provisioning went nowhere. The
   working route is the Authorized Officer's **Reissue password** on the
   proposal, which displays it once — verified. Administration → Users → Reset
   Password issues one but **does not display it**, so it is useless for a
   candidate.
4. **Self-Inspection closes once the sitting approves its result.** It must be
   shown between the Administrative Head's approval and the Member's result
   approval.
5. **Documents render as HTML, not PDF**, and the signature provider is the mock
   (`FEATURE_CSC_DIGISIGN=false`).
6. **No vhost.** The application is served by `php artisan serve`; port 8000 on
   this machine is taken by an unrelated project, so **8001** was used.
7. **`php artisan demo:credentials` must not be run.** Despite the name it
   mutates — it resets passwords and creates accounts.

---

## 6. Verification

- All 50 transactional tables at 0 rows; all their `AUTO_INCREMENT` at 1.
- 31 master and access tables byte-for-byte identical to the pre-work baseline
  (`CHECKSUM TABLE` diff empty), `users` included — the two passwords changed
  during the rehearsal were restored from the pre-work dump.
- `storage/app` holds 3 files, all `.gitignore`. Redis `DBSIZE` 0.
- All 9 demo accounts authenticate with `Demo@2026`, by e-mail and by mobile.
- Screens render 200 on the empty database for every role: 25 admin screens,
  9 Authorized Officer screens, 4 Administrative Head screens, 6 Chairman
  screens, 6 Member screens and 6 public screens.

**Rollback** available at
`…/scratchpad/dpc_dev_pre_demo_freeze_backup.sql` and
`storage_app_pre_demo_freeze.tgz`.

## Status

**Ready for the business demonstration.**
