# Pending Business Questions

**e-DPC Portal · Chhattisgarh Public Service Commission**
**Phase:** Business User Acceptance Testing · **Opened:** 2026-08-28

---

## Standing rule for this file

**Where a business rule is unclear, the answer is never assumed and never coded.** The question is recorded here and the application either declines the action by name or continues under a stated, visible default. Entries are **appended, never overwritten**.

## Relationship to the existing decisions register

`docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` already holds the construction-phase questions **BD-2 to BD-49**. That file is not superseded and is not edited by this one.

- A question found during UAT that is **already** recorded as a `BD-n` is **not duplicated here**. It is cross-referenced instead, so the Commission answers it once.
- A genuinely new question raised during UAT gets a `UAT-Q-nnn` ID in this file.
- A workflow the business has **already decided** and wants built later is not a question at all. It is recorded in `docs/FUTURE_BUSINESS_ENHANCEMENTS.md` as an `FBE-nnn` entry, marked *Not Implemented*, and is not duplicated here.

## Fields recorded for every question

| Field | Notes |
|---|---|
| **Question ID** | `UAT-Q-nnn`, issued in the order found. Never reused |
| **Module** | One of the twenty modules in the agreed test order |
| **Business Area** | The rule area — eligibility, vacancy, committee composition, reservation, workflow, identity, retention |
| **Screen / Page URL** | Where in the application the ambiguity is met, e.g. `/dpc-proposals/{uuid}/working-officers` |
| **Evidence** | The file, class or method that embodies the current understanding — and the screenshot filename where one was taken |
| **Current Understanding** | What the application does today, in the absence of a ruling |
| **Clarification Required** | The question, phrased so it can be answered yes/no or by choosing a stated option |
| **Reason** | Why the code cannot settle it without the business |
| **Impact if Incorrect** | What a wrong assumption would do to a real promotion |
| **Related BD** | The existing `BD-n` this corresponds to, or `—` if genuinely new |
| **Status** | `Open` / `Answered` / `Deferred` / `Closed` |

---

## Questions

### UAT-Q-001 · Which Status transitions are legal for a reference master, and what should a new record default to?

| Field | Value |
|---|---|
| **Question ID** | UAT-Q-001 |
| **Module** | 1 — Masters |
| **Business Area** | Reference-data governance |
| **Screen / Page URL** | `/admin/districts/create`, `/admin/districts/{id}/edit` — and every other master form |
| **Evidence** | `app/Enums/MasterStatus.php` — four states, no transition method; `DistrictRequest::rules()` — `'status' => ['required', new Enum(MasterStatus::class)]`, which accepts any of the four in any direction; migration `2026_07_30_090001_create_districts_table.php` — column default `'draft'`; `resources/views/admin/districts/form.blade.php` defaults a new record's dropdown to `draft` |
| **Current Understanding** | The lifecycle has four states — `Draft`, `Active`, `Inactive`, `Archived`. **Any state can be set from any other state at any time**, including `Archived` back to `Active`, because the rule only checks that the value is one of the four. A new record defaults to `Draft`. Every downstream consumer filters on `status = active`, so `Draft`, `Inactive` and `Archived` behave identically to each other and to a record that does not exist |
| **Clarification Required** | (a) Which transitions are permitted, and are any of them one-way? Specifically, may an `Archived` master be brought back to `Active`? (b) Should a newly created master be born `Draft` (and require a second deliberate step) or `Active`? (c) Do `Inactive` and `Archived` differ in any way the business cares about, or is one of them redundant? |
| **Reason** | The code cannot settle this. Three of the four states are functionally identical today, and the difference between "retire this district" and "this district was a mistake" is a records-management policy, not a technical choice. The answer also decides whether **UAT-OBS-002** is fixed by refusing delete in favour of `Inactive` |
| **Impact if Incorrect** | An administrator saving a new district without touching the dropdown creates a record that appears in the master list, looks saved, and cannot be selected anywhere in the portal — the failure surfaces later, in another module, to a different user. Conversely, if records are born `Active`, a half-entered master becomes immediately selectable on a live DPC |
| **Related BD** | — (genuinely new; the register BD-2 to BD-49 contains no master-lifecycle item) |
| **Status** | Open |

---

### UAT-Q-002 · When a district is bifurcated, what happens to departments already registered under the parent district?

| Field | Value |
|---|---|
| **Question ID** | UAT-Q-002 |
| **Module** | 1 — Masters |
| **Business Area** | Reference-data governance · identity |
| **Screen / Page URL** | `/admin/districts` |
| **Evidence** | `departments.district_id` FK (`2026_07_30_090010_link_departments_and_users_to_masters.php:21`); no supersession, merge or re-map route exists on the District Master — `php artisan route:list --path=admin/districts` returns 6 plain resource routes. Contrast `department-posts`, which **does** model supersession by Government Order |
| **Current Understanding** | The District Master has no concept of one district succeeding another. Chhattisgarh has bifurcated districts twice in recent memory, most recently in September 2022 (see **UAT-OBS-001**). If those five districts are now added, the application offers no way to state that *Sakti* was carved out of *Janjgir-Champa*, and no way to move the departments that sit in the new territory. The Administrator would edit each department by hand, with no record of why |
| **Clarification Required** | On a future bifurcation: (a) do departments in the new territory get re-pointed to the new district, or do they stay under the parent for continuity? (b) Must the portal retain which district a department was in **at the time a DPC was held**, or is only the current district ever needed? (c) Is a district's history required on any published document? |
| **Reason** | This is a State administrative act with no technical default. Answer (b) in particular decides whether `district_id` may simply be edited in place or whether it needs the same effective-dated treatment the Department Post Mapping already has |
| **Impact if Incorrect** | If history matters and the portal overwrites in place, a DPC proceeding reprinted years later would show a district that did not exist when the DPC was held. If history does not matter and the portal models it anyway, the master gains complexity nobody needs |
| **Related BD** | — |
| **Status** | Open |

---

### UAT-Q-003 · Must district LGD codes be populated and reconciled before go-live?

| Field | Value |
|---|---|
| **Question ID** | UAT-Q-003 |
| **Module** | 1 — Masters |
| **Business Area** | Reference-data governance · interoperability |
| **Screen / Page URL** | `/admin/districts/create` — the *District LGD Code* field |
| **Evidence** | `DistrictRequest::rules()` — `'lgd_code' => ['nullable', ...]`; `SeedDirectoryProvider` docblock, which states the codes are "intentionally left null here and are populated by the live LGD provider later"; verified live — **`lgd_code` is NULL on all 28 rows**; `AppServiceProvider.php:143` throws if `FEATURE_LGD_SYNC` is enabled, because no live LGD provider is implemented |
| **Current Understanding** | The field exists, is uniqueness-checked, and is optional. It has never been populated. The seeder's own comment defers it to a live LG Directory sync that is **not built** — the feature flag currently raises a `RuntimeException` if switched on |
| **Clarification Required** | (a) Is the LGD code required for any statutory return, inter-departmental exchange or reporting obligation the Commission has? (b) If yes, must all 33 be populated before go-live, and who supplies the authoritative mapping? (c) If no, should the field be removed from the form rather than shown to administrators as an optional box nobody fills? |
| **Reason** | Whether a government identifier is mandatory is a compliance question, not a design preference. The application cannot infer it, and the field's present state — visible, optional, always empty — is the one outcome that serves nobody |
| **Impact if Incorrect** | If the codes are needed and absent, the eventual LGD integration becomes a manual re-keying exercise across 33 districts rather than a reconciliation, and any external report keyed on LGD code cannot be produced |
| **Related BD** | — |
| **Status** | Open |

---

### UAT-Q-004 · Must every Department Post Mapping cite at least one Government Order?

| Field | Value |
|---|---|
| **Question ID** | UAT-Q-004 |
| **Module** | 3 — Department Post Mapping |
| **Business Area** | Reference-data governance · evidence |
| **Screen / Page URL** | `/admin/department-posts/create` |
| **Evidence** | `app/Domain/Master/Requests/DepartmentPostRequest::rules()` — `'orders' => ['nullable', 'array', 'max:20']`, with an inline comment stating the position explicitly: *"A mapping created from now on should carry at least one, and the screen says so — but the rule is left to the Commission rather than enforced here, because 'every mapping must cite an order' is a business rule and not this form's to invent."* Verified live: a mapping saves successfully with the order row left empty |
| **Current Understanding** | The list of Government Orders is optional. The screen advises that a new mapping should carry one, but nothing refuses a mapping without one. The stated reason is backward compatibility — the master holds mappings registered before orders were captured, and making the field mandatory would leave those rows unsaveable on any subsequent edit |
| **Clarification Required** | (a) Must a **newly created** mapping cite at least one Government Order? (b) If yes, should an **existing** mapping without one be editable, or must the order be supplied before any further change is accepted? (c) Is a departmental circular acceptable where no Government Order exists, or must the authority always be a Government Order? |
| **Reason** | This is an evidence-standard question, not a technical one. The mapping is what a DPC's post terms are justified by; whether that justification may be absent is the Commission's to rule on. The code has deliberately declined to decide, which is the correct posture but leaves the rule unset |
| **Impact if Incorrect** | If an order is required and the portal does not insist, a promotion's post terms may rest on nothing citable, and that is precisely what would be challenged in a tribunal. If an order is not required and the portal insists, historic mappings become uneditable |
| **Related BD** | — (genuinely new. BD-18 concerns *who* registers posts first, not what authority a mapping must cite) |
| **Status** | Open |

---

## Running summary

| | Open | Answered | Deferred | Closed | Total |
|---|---:|---:|---:|---:|---:|
| New UAT questions | 4 | 0 | 0 | 0 | 4 |
| Cross-referenced to an existing BD | 0 | 0 | 0 | 0 | 0 |
