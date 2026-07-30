# 03 — Database Design (MySQL / Laravel Migrations)

Conventions: snake_case tables (plural); `id` BIGINT unsigned PK; `created_at`/`updated_at` on all; **soft deletes** (`deleted_at`) on business entities; **audit columns** `created_by`/`updated_by` (FK→users) on business entities; FKs with explicit `on delete` behavior; indexes on every FK and on columns used in lookups/filters; `utf8mb4_unicode_ci`. Rule values live in master/config tables (not hardcoded). Documents: only metadata in DB; binaries on S3.

> This is the entity model for review. Exact column types/migration order are finalized inside each milestone (migrations only — never hand-edited schema).

---

## A. Identity & Access

**departments** — `code` (unique, e.g. `HE`), `name`, `district`, `place`, `pin`, `email` (.gov), `status`, timestamps, soft deletes, audit.
**users** — `name`, `designation`, `mobile` (unique), `email` (unique), `password`, `role` (enum: authorized_officer, administrative_head, chairman, member, individual, admin), `level` (nullable: 1/2), `department_id` (nullable FK), `is_active`, `email_verified_at`, `mobile_verified_at`, timestamps, soft deletes. *(Auth fields depend on Q1.)*
**roles / permissions / model_has_roles …** — if Spatie laravel-permission adopted (Q14); else `role` enum on users + Policies.
**otp_verifications** — `identifier` (mobile/email/aadhaar per Q1), `channel`, `purpose` (registration/login/sign), `code_hash`, `expires_at`, `consumed_at`, `attempts`. Index (`identifier`,`purpose`).
**sign_events** — `user_id`, `signable_type`, `signable_id`, `method`, `channel`, `signed_at`, `ip`, `meta` (JSON). Record-of-intent for legally significant actions.
**audit_logs** — `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values` (JSON), `new_values` (JSON), `ip`, `user_agent`, `created_at`. (Package per Q15.)

## B. Pre-DPC (posts sanctioned for promotion)

**promotion_posts** — `department_id` FK, `post_name`, `pay_scale`, `direct_recruitment_pct`, `promotion_recruitment_pct`, `class_level` (I/II/III), `status` (draft/approved), `approved_by`, `approved_at`, timestamps, soft deletes, audit.
**promotion_post_feeders** — `promotion_post_id` FK, `feeder_post_name`, `percentage`, `experience_years`, `sort_order`.

## C. DPC Proposal (core aggregate)

**dpc_proposals** — `dpc_code` (unique), `department_id` FK, `type` (fresh/review/circulation), `post_to`, `class_to`, `post_from`, `class_from`, `legal_stay` (bool), `proposal_date`, `status` (see 01 §7), `sanctioned_post_count`, `original_dpc_proposal_id` (nullable self-FK, for review/circulation), `review_reason` (nullable enum), `created_by` (AO), `approved_by` (AH), `forwarded_at`, `allotted_to` (nullable FK→users), `allotment_status`, `default_dpc_date`, `dpc_date`, `dpc_time`, `conducted_at`, `result_approved_at`, `cancelled_at`, `cancel_reason`, timestamps, soft deletes, audit. Indexes: `dpc_code` (unique), `department_id`, `type`, `status`, `allotted_to`, `original_dpc_proposal_id`.
**committee_members** — `dpc_proposal_id` FK, `name`, `designation`, `mobile`, `email`, `capacity` (chairman/member), `sort_order`.
**vacancies** — `dpc_proposal_id` FK, `type` (clear/from_promotion/from_retirement/from_deputation), `category` (FK→categories), `count`. Unique (`dpc_proposal_id`,`type`,`category`).
**working_officers** — (officers on target post, S16) `dpc_proposal_id` FK, `sn`, `name`, `dob`, `qualification`, `date_of_joining_service`, `date_of_posting_on_post`, `category`, `roster_position`, `deputation_date` (nullable).
**seniority_entries** — (feeder seniority list, S17) `dpc_proposal_id` FK, `sn`, `name`, `dob`, `qualification`, `date_of_joining_service`, `date_of_posting_on_feeder`, `category`. Index (`dpc_proposal_id`,`sn`).

## D. Evaluation / Tabulation

**evaluation_rows** — `dpc_proposal_id` FK, `seniority_entry_id` (nullable FK), `sn`, `name`, `dob`, `qualification`, `date_of_promotion`, `category`, `note` (nullable: PUNISHMENT/C.CASE/…), `tribal_service_year` (nullable), `computed_mark` (nullable), `computed_remark` (nullable), `list_type` (nullable: main/waiting), `rank_in_list` (nullable). Index (`dpc_proposal_id`).
**evaluation_cr_grades** — `evaluation_row_id` FK, `year` (smallint), `grade` (क+/क/ख/ग/घ), `mark` (derived, snapshot). Unique (`evaluation_row_id`,`year`).
**evaluation_loyalty_flags** — `evaluation_row_id` FK, `year`, `value` (bool). Unique (`evaluation_row_id`,`year`).
**evaluation_property_flags** — `evaluation_row_id` FK, `year`, `value` (bool). Unique (`evaluation_row_id`,`year`).

*(Per Q4, loyalty & property are per-year. If ruled single-flag, these collapse to columns on `evaluation_rows`.)*

## E. Results, Circulation, Objection

**dpc_results** — `dpc_proposal_id` FK, `evaluation_row_id` FK, `list_type` (main/waiting), `rank`, `category_allotment`, `remark`, `mark`, `approved_by`, `approved_at`. Index (`dpc_proposal_id`,`list_type`,`rank`).
**circulation_holds** — `dpc_proposal_id` FK, `evaluation_row_id` FK, `reason` (cr_missing/property_missing), `held_at`, `resolved_at` (nullable), `resolution_note`, `resolved_by`.
**objections** — `dpc_proposal_id` FK (nullable), `individual_user_id` FK, `text`, `status` (raised/filed_late/resolved), `raised_at`, `deadline_at`, `is_late` (bool).

## F. Documents & Notifications

**documents** — `documentable_type`, `documentable_id` (morph), `category` (working_list/seniority/evaluation/proceeding/order/other), `disk` (s3), `path`, `original_name`, `mime`, `size`, `uploaded_by`, timestamps, soft deletes. Index (`documentable_type`,`documentable_id`).
**notifications** — Laravel `notifications` table (morphable) + a **notification_logs** (`user_id`, `event`, `channel`, `payload` JSON, `status`, `sent_at`, `error`) for the mobile+email delivery audit required by S5/S26.

## G. Master / Config tables (seeded, PPT values as defaults)

**categories** (UR/SC/ST/OBC, `is_reserved`) · **cr_grade_marks** (`grade`,`mark`) · **qualifying_thresholds** (`class_from`,`class_to`,`min_marks`,`reserved_min_marks`) · **zone_rules** (`class_from`,`class_to`,`single_vacancy_count`,`multiplier`,`addend`,`reserved_expansion_factor`) · **review_reasons** (`code`,`label_en`,`label_hi`) · **remark_codes** (`code`,`label`) · **vacancy_types** · **dpc_types** · **pay_scales** · **posts/cadres** (`department_id`,`name`,`class_level`). Rule params also mirrored in `config/dpc.php` where a single source is cleaner (Q3).

---

## H. Key relationships (summary)

- `departments 1—* users`, `departments 1—* promotion_posts`, `departments 1—* dpc_proposals`
- `dpc_proposals 1—* committee_members | vacancies | working_officers | seniority_entries | evaluation_rows | dpc_results | circulation_holds | objections | documents`
- `evaluation_rows 1—* evaluation_cr_grades | evaluation_loyalty_flags | evaluation_property_flags`; `evaluation_rows 1—1 dpc_results`
- `dpc_proposals *—1 users (allotted_to)`; `dpc_proposals 0..1—1 dpc_proposals (original)` for review/circulation
- `promotion_posts 1—* promotion_post_feeders`

## I. Integrity & audit

FKs enforced; unique on `dpc_code`, `departments.code`, `users.mobile`/`email`; soft deletes on all business entities (statutory records — never hard-deleted in normal flow); `created_by`/`updated_by` on business entities; append-only `audit_logs` + `sign_events` for tamper-evident history (orders are contested in CAT/HC).
