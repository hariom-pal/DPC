# Coder Instructions — implementation status (2026-10-21)

Every rule of *Understanding of DPC with Coder Instructions*, with where it is
implemented. Business decisions taken on 2026-10-21 are marked **(decided)**.
Migrations named are under `database/migrations/2026_10_*`.

| Rule | Status | Where |
|---|---|---|
| 1.1 Checklist before a DPC (sanction, Recruitment Rule, gradation list, previous minutes) | Done | Rule 1.1 checklist: `ProposalVerificationItem`, `ProposalVerificationService`; submission gate |
| 1.1 Previous DPC held offline, entered by Commission staff; uploads optional below 50 eligible | Done **(decided: new role CGPSC Staff)** | `Role::CgpscStaff`; `previous_dpc_records` (670001); `PreviousDpcRecordController` (`/previous-dpcs`); a department's rule 1.1 reconciliation can cite the record |
| 1.2 Higher-post vacancy only with undertaking + higher DPC number | Done | Vacancy Management entry: `higher_dpc_reference`, verification, `higher_post_undertaking_at` (610001) |
| 1.3 Rounding (.5 up; tie to promotion) | Done | `VacancyEngine::quotaPosition` |
| 1.4 Nothing before 2019 | Done | Pre-DPC year from `dpc.earliest_dpc_year` (2019) |
| 1.5 Effective 1 January; vacancies to 31 December | Done | `DpcProposal::effectiveDate()` shown on the proposal; vacancy year = calendar year |
| 1.6 Seniority list as on 1 April of the previous year | Done | `seniorityReferenceDate()`; import and manual gates |
| 2.1 PH 3 %, once in service, verification | Done **(decided: AH verifies; separate PH seats first)** | PH questionnaire with document for every PH employee; AH decision (650001); calculation sets aside PH seats for verified claims (`DpcCalculationService::placeOnLists`) |
| 2.2 Departmental enquiry → sealed cover | Done **(decided)** | Master `sealed_cover_reasons` (590001); Employee Details section; sealed officer keeps the seat, remark `SCOVER` |
| 2.3 Deputation | Done **(decided: question in Employee Details → NE)** | Vacancy entry date and eligibility; `unsanctioned_deputation` (620001) with order → Not Eligible |
| 3.1 CR (4 a year, average truncated, highest authority, NRC, channel) | Done | Employee Details CR section; points from Eligible CR Grades |
| 3.1 "Take previous year CR" (carry forward) | Not applied **(decided)** | A missing mandatory year needs its reason and document: CIR, else NE |
| 3.2 Missing CR reasons with documents | Done **(decided: document per reason)** | Record Reason master names the document(s); suspension needs suspension + reinstatement orders (630001) |
| 3.3 Class I → I merit-cum-seniority | NA in the document | — |
| 3.4 Grade → 9/7/5/3/1 via master | Done | Eligible CR Grades with validity and grade code |
| 3.5 Old grading intervals via master | Done | Class Master promotion rule: CR years, per-year minimum, qualifying total, tribal bonus (per year, not at the highest/lowest grade), fixed on the DPC when raised |
| 4.1 IPR, late IPR decision, duplicate check | Done | Employee Details Property section |
| 4.3 DPC order, link to officers and committee, seniority from order date | Done **(decided)** | `dpc_promotion_orders` (660001); issued by the AH on the approved result; PDF (dompdf, English) signed; signed link by SMS/e-mail/in-app; superseded, never rewritten |
| 4.3 Retrospective / joint DPC | NA in the document | — |
| 4.4 Two verifications per document | Done **(decided: AO + AH)** | `ao_verified_*`, `ah_verified_*` on employee documents (640001); approval refused until the AH has verified every document |
| 4.4 Working officers match the list 100 % | Done | Working Officers List reconciled with the Vacancy Management record |
| 4.4 DOB / joining order no., date, undertaking | Done **(decided: no PDF)** | Basic Information (620001) |
| 4.4 Representations / complaints with undertaking | Done | Rule 1.1 checklist item `representations`; uploads with summary (600001) |
| 4.4 Refused promotion | Done **(decided)** | Employee Details declaration; refusal letter + DPC order; refused last year → NE |
| 4.4 No duplicate CR undertaking | Done | CR document upload |
| 4.4 Originals in the Mul folder | Done | Rule 1.1 checklist item `original_documents` |
| 4.4 Multiple cadres / % from another cadre / vertical | As before **(decided)** | One DPC per feeder; the feeder share is recorded |
| 4.4 Qualification vs Schedule 4 | Advisory **(decided)** | `dpc.merit.qualification_gate = advise` |
| 4.4 Clauses 8–11 undertaking; latest RR notification date | Done | Rule 1.1 checklist item `recruitment_rule_conformity` with `rr_notified_on` |
| 4.4 Minutes with consent of officers | NA in the document | — |

## Open points

- Opening a sealed cover once the enquiry ends (which order then promotes the officer) is not built.
- The earlier-PH check reads the same department's proposals only.
- "Every inserted and calculated data matches a version" is met by revisions and snapshots; entered data is not compared against document contents.
- dompdf cannot shape Devanagari, so the promotion order PDF is in English.
