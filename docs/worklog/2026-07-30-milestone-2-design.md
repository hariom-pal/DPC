# Milestone 2 — Design: Master Data & User Management

**Date:** 2026-07-30 · **Status:** Awaiting approval (no code yet) · **Scope guard:** masters required *before* DPC proposal creation only — no Proposal/Committee-workflow/Recommendation/Order work.

## 1. Objective
Provide the reference data and administrative capability the proposal flow depends on: the posts/cadres and pay scales used in the Pre-DPC and proposal screens, reservation categories, the review-reason lookup, department-master administration (incl. DPC-ID code), and full user management (CGPSC Admin provisions Chairman/Member; activate/deactivate; role assignment). Introduce a reusable **Activity Timeline** (distinct from the audit log) and adopt the **domain** + **integrations** folder structure.

## 2. PPT references
- **S6** — department fields (name/district/place/PIN/email) and officer designations → Department admin, Designation on users (free text, as PPT).
- **S7, S9, S11** — Pre-DPC posts: post name, pay scale, % direct/promotion recruitment, **feeder cadre** (name, %, experience yrs), "as per Schedule-4 of recruitment rules" → **Cadre/Post Master** + **Pay-Scale Master**, per department.
- **S14, S16–S18, S23** — post class (I/II/III), reservation categories (UR/SC/ST/OBC), vacancy sub-types → **Category Master**, class + vacancy-type lookups.
- **S28–S29, S32** — Chairman/Member accounts conduct DPC → **User Management** provisions these (Q2 CGPSC Admin, approved).
- **S35/S37** — six review-error reasons → **Review-Reason lookup** (config-driven, Q3).

## 3. Domain Model
- **District** (master, **LG Directory-aligned**): `lgd_code`, `state_lgd_code`, `name`, active. Sourced from LG Directory; **Place stays free text**.
- **Designation** (master): `name`, `code?`, `class_level?` (I/II/III), active. Referenced by users (AO/AH) and CGPSC members.
- **Cadre** (post/संवर्ग): `department_id`, `name`, `class_level` (I/II/III), `pay_scale_id?`, active. Later used as post_to/post_from and feeder cadres.
- **PayScale**: `label` (e.g., "Level-11 / PB-3"), `code?`, active — **admin-managed**.
- **Category**: `code` (UR/SC/ST/OBC), `name`, `is_reserved`.
- **ReviewReason** (lookup): `code`, `label_en`, `label_hi`, active.
- **Department** (from M1): admin CRUD + `code` assignment (Q9); `district_id` FK (replaces free-text district).
- **User** (from M1): `designation_id` FK (replaces free-text designation); admin CRUD, role assignment, activate/deactivate; provision Chairman/Member.
- **ActivityTimeline** (new, cross-cutting): morphable timeline entries (`subject`, `event`, `description`, `causer`, `properties`, `occurred_at`) for future workflow tracking — **separate** from owen-it audit (which records field-level model changes).

Relationships: `District 1—* Department`, `Designation 1—* User`, `Department 1—* Cadre`, `PayScale 1—* Cadre`, `User *—1 Department`, roles via spatie. Classes, DPC types and vacancy types stay **enums** (fixed constants); review reasons are a **seeded lookup**.

## 4. Database Impact
New tables: `districts`, `designations`, `cadres`, `pay_scales`, `categories`, `review_reasons`, `activity_timelines`. Every master gains `created_by`, `updated_by`, `deleted_by` (nullable, FK→users), `timestamps`, `soft deletes`; FKs + indexes on every relation/lookup column. `departments`: add `district_id` FK (drop free-text `district`), plus `deleted_by`. `users`: add `designation_id` FK (drop free-text `designation`). Seeders: categories, review reasons, pay scales, a starter cadre set, secretariat **designations**, and **Chhattisgarh districts with LGD codes**. No denormalization.

## 5. UI Impact
Admin area (role: `admin`) with Bootstrap 5.3 responsive government styling: list/create/edit/soft-delete screens for District, Designation, Cadre, Pay Scale, Category, Review Reason, Department; **User Management** (list, create Chairman/Member, assign role, activate/deactivate); shared master-table + form components and a left-nav admin layout. Department registration (S6): **district becomes a dropdown** (District master), **designation becomes a dropdown** (Designation master), place stays free text. AO/AH/Chairman/Member see no master screens. Bilingual where the PPT is bilingual; no invented fields.

## 6. Workflow
CGPSC Admin logs in → manages masters → provisions Chairman/Member accounts (credentials issued via existing queued notification pipeline; forced first-login password change applies). Masters become the option sources consumed by Pre-DPC (M3) and Proposal (M4). Every master create/update/delete is audited (owen-it) **and** appended to the Activity Timeline.

## 7. External Integrations
- **LG Directory (new):** District data sourced from LGD. Modelled behind `Integrations/Contracts/DirectoryProvider`; M2 ships a **seed/mock provider** (Chhattisgarh districts + LGD codes) so nothing depends on a live API yet; a real LGD-API provider can replace it later with no business-code change (see Q's for sourcing).
- Existing provider architecture is **relocated** to `app/Integrations/{Contracts,Mock,CSC,AWS}` (SmsSender/SignatureProvider contracts; Log/Mock providers; CSC DigiSign; AWS S3 wrapper). Business code depends only on contracts. Activity Timeline is internal.

## 8. Risks
- **Refactor risk:** moving M1 code into `app/Domain/*` + `app/Integrations/*` changes namespaces broadly; mitigated by the green test suite re-run after each move.
- **Scope creep:** scoring/threshold/zone/remark-code masters are deferred to M5 (used at CALCULATE, not at proposal creation) — explicitly out of M2.
- **PPT fidelity:** cadre vs designation modelling (see Q's) — must not invent hierarchy the PPT doesn't show.

## 9. Open Questions
*(Resolved by architect on 2026-07-30: District → **master (LG Directory)**, Place free text; Designation → **master**; Pay Scale → **admin-managed**.)*

1. **LGD district sourcing** — seed a **static Chhattisgarh district list with LGD codes now** (behind a DirectoryProvider seam for later LGD-API sync), or integrate the live LGD web service in M2? **Recommendation:** seed static now + provider seam. **Impact:** no dependency on LGD API access/credentials during M2; live sync added later without business-code change.
2. **District master depth** — LGD models State→District→Sub-district→Block→Village→Local-body. PPT needs only district (+ free-text place). **Recommendation:** **District only** (with a state reference), not the full hierarchy. **Impact:** avoids inventing structure the PPT doesn't use.
3. **Cadre scope — per-department or global?** Schedule-4 recruitment rules are departmental. **Recommendation:** **per-department** cadres (`department_id`). **Impact:** matches Schedule-4; a shared catalogue can be layered later.
4. **Fixed constants as enums vs masters** — classes (I/II/III), DPC types, vacancy types. **Recommendation:** **enums** (statutory constants); only review reasons as a seeded lookup. **Impact:** simpler, still swappable.
5. **Domain/Integrations refactor of existing M1 code** — migrate M1 (Auth/Signature/SMS) into `app/Domain/*` + `app/Integrations/*` now. **Recommendation:** yes, as part of M2. **Impact:** one-time namespace churn; behaviour unchanged, tests green.
6. **Designation ↔ class link** — should a Designation carry a class (I/II/III)? **Recommendation:** optional `class_level` on Designation (nullable). **Impact:** supports later filtering without forcing it.

---
**Next:** on approval I implement the above (masters + user management + timeline + domain/integrations restructure), run the full quality gate, then produce `milestone-2-review.md` and `milestone-2-questions.md` and stop.
