# Milestone 2 — Architect Questions

Raised at design stage (pre-implementation). Each has a safe recommendation; none has been silently decided.

## Architect Decisions — Round 2 (2026-07-30)
- **Q1 District/LGD →** seed CG districts with LGD codes, `DirectoryProvider` seam, live LGD later, **store English + Hindi names**.
- **Q2 →** District master only (no block/village/local-body).
- **Q3 →** Cadres department-specific (`department_id`).
- **Q4 (modified) → hybrid:** **Enum:** Service Class only. **Masters:** Vacancy Type, DPC Type, Committee Type, Promotion Category, Reservation Category, Review Reasons. (Do not overuse enums for business-configurable values.)
- **Q5 →** migrate ALL M1 code into `app/Domain/*` + `app/Integrations/*` now (no mixed structure).
- **Q6 (modified) →** Class is stored on **Cadre**, not Designation. Designation = title only. Cadre owns class, recruitment rules, promotion rules, Schedule-IV.
- **New directives:** (1) **Recruitment Rules** configurable master — eligibility engine must never hardcode promotion rules; (2) **Reservation Master** + **Reservation Roster** masters; (3) **Committee Configuration** master; (4) **Promotion Category** master; (5) all business rules configuration-driven, never hardcode statutory rules.

> The new directives introduce masters that are **not fully specified by the PPT**. Q7–Q11 below capture those ambiguities. They block implementation of the affected masters — the rest of M2 (District, Designation, Cadre, Pay Scale, Review Reason, Vacancy/DPC Type, User Management, Activity Timeline, refactor) is unblocked.

---

## Q1 — LG Directory sourcing for the District master
**Title:** Seed Chhattisgarh districts with LGD codes now, or integrate the live LG Directory API in M2?

**Background:** Districts will align to the LG Directory (LGD codes). District data can be either seeded statically (a fixed CG list with LGD codes) or pulled from the LGD web service. The District master is modelled behind an `Integrations/Contracts/DirectoryProvider` either way.

**Why this decision is required:** Determines whether M2 needs live LGD API access/credentials, and how districts are kept current.

**Available options:**
1. Seed static CG districts + LGD codes now, behind the DirectoryProvider seam (live sync added later).
2. Integrate the live LGD web service in M2.

**Pros:**
1. No external dependency/credentials to obtain now; deterministic; provider seam keeps future sync a config/impl swap.
2. Always-current data straight from the source of truth.

**Cons:**
1. District list must be refreshed when LGD changes (rare for districts).
2. Requires LGD API access, auth, error/latency handling, and rate limits during M2; blocks progress if access is delayed.

**Recommendation:** Option 1 — seed static now, keep the DirectoryProvider seam.

**Future impact:** A live `LgdDirectoryProvider` can replace the seed provider later with no business-code change.

**Default action if you approve the recommendation:** Seed CG districts with LGD codes; expose `DirectoryProvider`; defer live LGD sync to a later milestone.

---

## Q2 — District master depth
**Title:** District only, or the full LGD hierarchy (sub-district / block / village / local body)?

**Background:** LGD models State → District → Sub-district → Block → Village → Local Body. The PPT (S6) uses only *district* plus a free-text *place*.

**Why this decision is required:** Sets the shape of the location master and how much LGD structure we ingest.

**Available options:**
1. District only (with a state reference).
2. Full LGD hierarchy.

**Pros:**
1. Matches the PPT exactly; minimal surface; fast.
2. Future-proof for any location-based reporting.

**Cons:**
1. If a later screen needs blocks/villages, extend then.
2. Significant data volume and UI not required by any current PPT screen (would be inventing structure).

**Recommendation:** Option 1 — District only.

**Future impact:** Hierarchy can be added as child tables later without breaking the District master.

**Default action if you approve the recommendation:** Build a `districts` master (name, lgd_code, state_lgd_code) only.

---

## Q3 — Cadre/Post master scope
**Title:** Per-department cadres, or a global cadre catalogue?

**Background:** Promotion posts (पद/संवर्ग) and their feeder mappings derive from each department's Schedule-4 recruitment rules (PPT S9), which are departmental.

**Why this decision is required:** Determines whether a cadre belongs to one department or is shared.

**Available options:**
1. Per-department cadres (`department_id` on `cadres`).
2. Global cadre catalogue shared across departments.

**Pros:**
1. Matches Schedule-4; each department manages its own posts; no cross-department leakage.
2. Less duplication if many departments share identical post names.

**Cons:**
1. Common posts (e.g., "Under Secretary") may be defined per department.
2. Doesn't reflect that rules/percentages differ by department.

**Recommendation:** Option 1 — per-department cadres.

**Future impact:** A shared catalogue can be layered on later; per-department keeps proposal post lists correctly scoped.

**Default action if you approve the recommendation:** `cadres.department_id` FK; cadre screens scoped by department.

---

## Q4 — Fixed constants as enums vs masters
**Title:** Keep classes (I/II/III), DPC types and vacancy types as enums, or make them admin-editable masters?

**Background:** These are statutory constants in the PPT/rules. Review reasons, by contrast, can change by notification and are proposed as a seeded lookup.

**Why this decision is required:** Affects whether admins can edit these values and how the config-driven principle (Q3 of M1) is applied.

**Available options:**
1. Enums for classes/DPC-types/vacancy-types; seeded lookup only for review reasons.
2. Master tables for all of them (fully admin-editable).

**Pros:**
1. Simpler, type-safe, no accidental edits to statutory constants; still swappable in code.
2. Maximum runtime configurability.

**Cons:**
1. Changing a constant needs a release (rare/never for these).
2. Over-engineering; risk of admins editing values that must not change.

**Recommendation:** Option 1 — enums for the statutory constants; lookup only for review reasons.

**Future impact:** If the customer later wants any of these admin-editable, it becomes a small master + migration.

**Default action if you approve the recommendation:** Enums for classes/DPC-types/vacancy-types; `review_reasons` seeded lookup.

---

## Q5 — Migrate existing M1 code into the new domain/integrations layout
**Title:** Reorganize M1 (Auth/Signature/SMS) into `app/Domain/*` + `app/Integrations/*` now, or only place new M2 code there?

**Background:** M2 adopts domain-oriented structure and `app/Integrations/{Contracts,Mock,CSC,AWS}`. M1 code currently lives under `app/Services`, `app/Signature`, `app/Sms`, `app/Events`, etc.

**Why this decision is required:** Determines whether the codebase has one consistent layout or a mixed old/new one.

**Available options:**
1. Migrate M1 code into the new structure as part of M2.
2. Leave M1 where it is; only new code follows the new structure.

**Pros:**
1. One consistent layout; easier to navigate and enforce going forward.
2. No churn/risk to already-approved M1 files.

**Cons:**
1. One-time namespace churn (imports, `bootstrap/app.php`, tests) — mitigated by re-running the green suite.
2. Permanent mixed layout; contradicts "do not keep growing app/Services".

**Recommendation:** Option 1 — migrate now.

**Future impact:** All later milestones inherit a clean, uniform structure.

**Default action if you approve the recommendation:** Move Auth → `Domain/Authentication`, Signature → `Domain/Signature`, SMS/DigiSign/S3 → `Integrations/*`; update namespaces; keep behaviour and tests unchanged.

---

## Q6 — Designation ↔ class link
**Title:** Should a Designation carry a class (I/II/III)?

**Background:** Designations (Secretary, Deputy Secretary, …) broadly map to service classes, but the PPT does not state a fixed mapping.

**Why this decision is required:** Determines whether `designations` stores a class and whether it's mandatory.

**Available options:**
1. Optional nullable `class_level` on Designation.
2. No class on Designation.

**Pros:**
1. Enables later filtering/validation without forcing data now.
2. Simplest; class stays solely on Cadre where the PPT uses it.

**Cons:**
1. Nullable field that may stay empty initially.
2. Loses a convenient attribute if needed later.

**Recommendation:** Option 1 — optional nullable `class_level`.

**Future impact:** Supports designation-based filtering later; harmless if unused.

**Default action if you approve the recommendation:** Add nullable `class_level` to `designations`.

---

## Q7 — What is "Promotion Category" (vs Reservation Category)?
**Title:** Define the Promotion Category master and how it differs from Reservation Category.

**Background:** The directives list both a **Reservation Category** master (clearly the social categories UR/SC/ST/OBC/EWS) and a **Promotion Category** master. "Promotion Category" is not a named concept in the PPT. Result lists (S33–S34) are split by *reservation* category (UR/ST/…), and promotions are typed as Fresh/Review/Circulation (already a DPC-Type master).

**Why this decision is required:** Without a definition I would be inventing the fields/semantics of a master — which the rules forbid.

**Available options:**
1. Promotion Category = **mode of promotion** (e.g., "By Selection", "By Seniority-cum-Merit", "Non-Selection") — a lookup describing how a post is filled by promotion.
2. Promotion Category = the **category dimension of the promotion result** — i.e., the same social categories as Reservation Category (then they should be one master, not two).
3. Promotion Category = a customer-specific grouping you will define.

**Pros:** (1) Captures a real DoPT concept distinct from reservation; useful for recruitment rules. (2) Avoids a duplicate master. (3) Matches exact customer intent.

**Cons:** (1) Not shown in the PPT. (2) Contradicts the directive that lists them separately. (3) Needs your field list.

**Recommendation:** Option 1 — model **Promotion Category** as the *mode of promotion* lookup (`code`, `name_en`, `name_hi`), referenced by Recruitment Rules; keep **Reservation Category** as the social categories. If you meant something else, please specify its fields.

**Future impact:** Referenced by Recruitment Rules and (later) proposal/eligibility logic.

**Default action if you approve the recommendation:** Create `promotion_categories` (mode-of-promotion lookup) + `reservation_categories` (social categories) as two distinct masters.

---

## Q8 — Reservation Master vs Reservation Category vs Reservation Roster
**Title:** Confirm the three reservation-related structures and the roster model.

**Background:** Directives ask for a **Reservation Master** and a **Reservation Roster**; Q4 adds **Reservation Category**. The PPT references roster points (रोस्टर बिंदु), roster position and the roster register (S16, S35) and category-wise vacancy allocation.

**Why this decision is required:** Determines how vacancies are allocated to categories later, and how much roster structure to build now.

**Available options:**
1. Three structures: **Reservation Category** (UR/SC/ST/OBC/EWS, `is_reserved`); **Reservation Master** = reservation **percentage** per category (optionally per service class); **Reservation Roster** = ordered **roster points** (`point_no → category`) used to allocate vacancies.
2. Two structures: fold percentages into Reservation Category; keep a separate Roster.
3. Roster deferred to the vacancy/proposal milestone; M2 builds only Category (+ percentages).

**Pros:** (1) Cleanest separation, matches roster-register concept. (2) Fewer tables. (3) Keeps M2 bounded.

**Cons:** (1) More tables now, some consumed only later. (2) Percentage placement less explicit. (3) Delays roster config.

**Recommendation:** Option 1, with the roster modelled as ordered points. **Please confirm the roster scope:** is the roster **per department + cadre** (typical), and is it the **100-point roster**? I will not assume the point count or scope.

**Future impact:** Roster drives category-wise vacancy computation (clear/expected) in the proposal milestone.

**Default action if you approve the recommendation:** Build `reservation_categories`, `reservations` (category %), `reservation_rosters` (+ `reservation_roster_points`) — roster scope/point-count per your confirmation.

---

## Q9 — Committee Type vs Committee Configuration
**Title:** Define the Committee Type lookup and the Committee Configuration master.

**Background:** Q4 lists a **Committee Type** master; directive #3 asks for a **Committee Configuration** master. PPT S15/S22 show a committee of a Chairman + members (capacity = Chairman/Member); it does not show multiple named committee types or explicit composition rules.

**Why this decision is required:** I need the parameters of each master; the PPT does not enumerate committee types or configuration rules.

**Available options:**
1. **Committee Type** = lookup of committee kinds (e.g., by class/service — "Class-I DPC", "Class-II DPC"); **Committee Configuration** = composition rules per type (min/max members, chairman mandatory, quorum).
2. Single Committee Configuration master keyed by service class/DPC type, no separate Type lookup.
3. Minimal now: Committee Type lookup only; defer configuration rules to the committee/proposal milestone.

**Pros:** (1) Explicit and flexible. (2) Fewer tables. (3) Keeps M2 lean.

**Cons:** (1) Types/params not defined by PPT — needs your list. (2) Couples type and config. (3) Delays config.

**Recommendation:** Option 1, **but I need the list of committee types and the configuration parameters** (e.g., min/max members, quorum, whether Chairman is mandatory). Absent that, I will build the tables with the parameters listed here and leave the type list to be seeded from your input.

**Future impact:** Validates committee composition at proposal time (M4+).

**Default action if you approve the recommendation:** Create `committee_types` + `committee_configurations` (min_members, max_members, chairman_required, quorum) — seeded from the type list you provide.

---

## Q10 — Recruitment Rules master: scope and M2/M5 boundary
**Title:** How much of Recruitment Rules to build in M2, and where the eligibility computation lives.

**Background:** Directive #1: Recruitment Rules is a configurable master; Cadre owns recruitment rules, class, promotion rules, Schedule-IV. This overlaps the Pre-DPC "posts sanctioned" data (S9: direct %, promotion %, feeder cadres, experience) and the eligibility engine (M5: CR-grade marks, qualifying thresholds, zone-of-consideration).

**Why this decision is required:** To keep M2 bounded and avoid duplicating what M3 (Pre-DPC) or M5 (engine) will build.

**Available options:**
1. M2 builds the **Recruitment Rule master structure** per target cadre — direct %, promotion %, promotion category, feeder cadres (with %, min experience, min qualification), Schedule-IV reference/upload — as configurable data. Eligibility **computation** (marks/thresholds/zone) stays in M5 and reads config. The Pre-DPC screen (M3) becomes a view/editor over this master.
2. M2 builds Recruitment Rules **and** the full eligibility rule config (CR-grade marks, thresholds, zone parameters) now.
3. Defer Recruitment Rules entirely to M3 (Pre-DPC).

**Pros:** (1) Clear boundary; engine reads config (never hardcodes); no duplication with M3. (2) All rule config in one place early. (3) Simplest M2.

**Cons:** (1) Pre-DPC screen deferred to M3 as planned. (2) Larger M2; some config unused until M5. (3) Leaves rule config scattered.

**Recommendation:** Option 1 — build the Recruitment Rule master (structure + admin CRUD + seed example) in M2; keep CR-grade/threshold/zone config and all computation in M5 (config-driven). Pre-DPC entry UI remains M3.

**Future impact:** The eligibility engine (M5) consumes this master; nothing statutory is hardcoded.

**Default action if you approve the recommendation:** Create `recruitment_rules` (+ `recruitment_rule_feeders`) linked to Cadre; defer marks/threshold/zone config and computation to M5.

---

## Q11 — M2 sequencing/boundary confirmation
**Title:** Confirm that M2 delivers master *definitions* (schema + admin CRUD + seeders), with their *consumption* in later milestones.

**Background:** Several requested masters (Recruitment Rules, Reservation Roster, Committee Configuration) are consumed by M3–M5, not by M2 itself.

**Why this decision is required:** To bound M2 and avoid pulling proposal/eligibility behaviour forward.

**Available options:**
1. M2 = master definitions + admin management + seeders only; consumption wired in M3–M5.
2. M2 also wires some consumption (e.g., roster→vacancy) now.

**Pros:** (1) Bounded, testable, matches "no Proposal/Committee-workflow/Recommendation/Order in M2". (2) Fewer later dependencies.

**Cons:** (1) Masters sit unused until later milestones. (2) Larger M2.

**Recommendation:** Option 1.

**Future impact:** Keeps milestone boundaries clean.

**Default action if you approve the recommendation:** Deliver masters + admin CRUD + seeders in M2; wire consumption in M3–M5.

---

## Round 3 — Final Architect Decisions (2026-07-30)
- **Q7 →** Do NOT implement Promotion Category (undefined in PPT). Defer; do not invent.
- **Q8 →** Implement `ReservationCategory → ReservationPolicy → ReservationRoster → ReservationRosterPoint`. Roster belongs to **Department + Cadre**. Roster size **configurable** (no 100-point assumption). Reservation % never hardcoded (config-driven, effective-dated).
- **Q9 →** Implement `CommitteeType` (do NOT seed — customer defines) + `CommitteeConfiguration` supporting: Minimum Members, Maximum Members, Chairman Mandatory, Quorum, Voting Method, Majority Type, Tie Break Rule, Digital Signature Required, Remarks Required, External Member Allowed, Secretary Required.
- **Q10 →** Recruitment Rules fully configuration-driven; eligibility engine (M5) consumes config, never hardcodes.
- **Q11 →** M2 delivers only: DB schema, CRUD, validation, admin UI, audit, seeders. Consumption in later milestones.

### Additional directives (final)
1. **Recruitment Rules are versioned** — Version, Effective From, Effective To, Government Order Number, PDF (AWS S3). Never overwrite previous rules.
2. Every reference master supports a **lifecycle status: Draft / Active / Inactive / Archived**.
3. Every reference master supports **Effective From / Effective To** where applicable.
4. Do not create business masters not explicitly defined by the PPT or approved by the architect.

### Final M2 master set (locked)
District (LGD, EN+HI) · Designation (title only) · PayScale · Cadre (dept-specific, owns Service Class) · ReviewReason · VacancyType · DpcType · ReservationCategory · ReservationPolicy · ReservationRoster · ReservationRosterPoint · CommitteeType · CommitteeConfiguration · RecruitmentRule (+ feeders, versioned, GO + S3 PDF) · User Management · Activity Timeline. **Service Class = enum.** **No Promotion Category.**

---

## Milestone 2 — Closeout Questions (arising during implementation)

### CQ1 — District LGD numeric codes
**Title:** Populate real LGD district codes now, or on live LGD sync?

**Background:** The 28 Chhattisgarh districts are seeded with authoritative English + Hindi names and `state_lgd_code=22`, but each district's numeric `lgd_code` is left null (the `SeedDirectoryProvider` does not hand-enter them to avoid asserting unverified official codes).

**Why this decision is required:** Downstream reporting/integration may key on the numeric LGD code.

**Available options:**
1. Populate `lgd_code` when the live `DirectoryProvider` (LGD API) is integrated.
2. Hand-enter the numeric codes now from an official LGD export you provide.

**Pros:** (1) Authoritative, no transcription risk. (2) Codes available immediately.
**Cons:** (1) Codes null until live sync. (2) Manual entry risks stale/incorrect codes.

**Recommendation:** Option 1 — populate on live LGD sync; keep names authoritative now.

**Future impact:** Adding the live provider fills the codes with no schema change.

**Default action if you approve the recommendation:** Leave `lgd_code` null until the live LGD provider is wired.

### CQ2 — Seeded reservation percentages
**Title:** Confirm the default reservation percentages (SC 12 / ST 32 / OBC 14 / EWS 10).

**Background:** `ReservationPolicySeeder` seeds provisional, **editable** default percentages effective 2020-01-01. These are configuration data, not hardcoded rules.

**Why this decision is required:** They influence category-wise vacancy computation in later milestones.

**Available options:**
1. Keep these defaults; admin amends as needed.
2. Replace with figures from an official CG reservation notification you provide.

**Pros:** (1) Working defaults now. (2) Guaranteed-correct statutory figures.
**Cons:** (1) May not match the exact current notification. (2) Needs the source document.

**Recommendation:** Option 1 now; amend when the official notification is supplied.

**Future impact:** Values are effective-dated, so corrections create a new policy row without losing history.

**Default action if you approve the recommendation:** Retain the seeded defaults as editable values.

### CQ3 — Per-master authorization policies
**Title:** Are per-master Laravel Policies required, or is the `role:admin` gate sufficient for M2?

**Background:** The entire `/admin` area is gated by `auth` + password-changed + `role:admin`. No per-model Policies were added, since only the Admin role reaches these screens.

**Why this decision is required:** Determines whether finer-grained authorization (e.g., separate roles for specific masters) is needed.

**Available options:**
1. Keep the single `role:admin` gate for all masters.
2. Add per-master Policies/Gates for finer-grained delegation.

**Pros:** (1) Simple, matches current single-admin model. (2) Supports delegating specific masters to specific roles later.
**Cons:** (1) All-or-nothing admin access. (2) More surface to maintain now with no current requirement.

**Recommendation:** Option 1 for M2; introduce Policies if/when master administration is delegated to sub-roles.

**Future impact:** Policies can be layered without changing controllers materially.

**Default action if you approve the recommendation:** Retain the `role:admin` gate; revisit if delegation is required.
