# Worklog — 2026-07-30 — PPT Analysis & Implementation Plan

## Task
First implementation-phase task: read both PPTs completely, extract every screen/field/workflow/rule/etc., build a traceability matrix, and produce a full implementation plan. No application code.

## What was done
- Extracted full text of both decks (ODPC_NEW_FINAL = 52 slides, CONVENTIONAL DPC PROCESS = 7 slides) and read every slide.
- Verified the embedded images carry no hidden field detail (APPROVED stamp, a generic CGPSC login, and a folder view confirming the 3 Excel upload templates: CURRENTLY_WORKING_US, FINAL_SENIORITY_LIST_US, EVALUATION_CHART_US).
- Produced `docs/ppt-analysis-and-plan.md` containing: roles, 6 stages, 35-screen inventory, field inventory, scoring & eligibility rules, DPC types & coded values, status model, notifications, uploads/documents, master modules, out-of-scope list, traceability matrix, architecture, module list, DB entities, API surface, 11-milestone plan, risks, and 12 open questions.

## Key findings (copy-ready)
- **Actors:** Authorized Officer (L1), Administrative Head (L2), CGPSC Chairman, CGPSC Member, Individual under consideration. (No admin role shown — OQ#2.)
- **CR marks:** क+=4, क=3, ख=2, ग=1, घ=0; tribal service +1.
- **Eligibility:** any घ in 5 yrs → NE; CLASS-I→I last-year CR must not be ग.
- **Qualifying score:** I→I ≥15, II→I ≥13, II→II ≥11, others ≥10 (reserved relaxable to 10).
- **Super seat:** all-क+ (sum 20) → notional seniority; actual seniority breaks ties.
- **Zone:** I→I: 1 vac → top 5; >1 vac → (2×vac+4); reserved shortfall → 7×category vac. Others → all experience-qualified.
- **DPC types:** Fresh / Review (orig DPC ID + coded reason) / Circulation (held for missing CR/property).
- **DPC ID:** `<DeptCode><Year><Serial>` e.g. HE201711111.
- **#1 conflict:** PPTs use Aadhaar No. + Aadhaar OTP for login & e-signing everywhere — contradicts the earlier Aadhaar-removal decision. Blocks the Identity milestone.

## Blocking open questions (need answers before M1/M4)
OQ#1 auth/e-sign (Aadhaar vs email/SMS OTP), OQ#2 admin/provisioning of Chairman/Member accounts, OQ#6 tabulation loyalty/property per-year column layout, OQ#12 circulation trigger wording ("CR ≤ 4" vs "2+ years missing").

## Next action
Awaiting approval. On approval + answers to OQ#1/#2/#6/#12 → begin **Milestone 0 (scaffold dpc-api + dpc-web)**.
