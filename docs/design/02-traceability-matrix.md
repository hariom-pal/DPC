# 02 — Traceability Matrix

Every feature/module traced to its PPT source. `S<n>` = ODPC_NEW_FINAL.pptx; `C<n>` = CONVENTIONAL DPC PROCESS.pptx.

| # | Feature / Capability | Module | PPT source | Milestone |
|---|---|---|---|---|
| 1 | Department registration (two-tier AO L1 / AH L2) | Registration | S5–S6 | M1 |
| 2 | Credential issuance to AO & AH (mobile+email) | Registration/Notify | S5 | M1 |
| 3 | Login & session; e-signing statement | Auth | S8,S10,S13,S20,S27,S29,S32,S36,S39,S42,S46,S50 | M1 |
| 4 | Role-based access (AO/AH/Chairman/Member/Individual) | RBAC | all login slides | M1 |
| 5 | Pre-DPC posts sanctioned — entry | Pre-DPC | S7,S9 | M3 |
| 6 | Pre-DPC posts sanctioned — approval | Pre-DPC | S10–S11 | M3 |
| 7 | Feeder cadre mapping (%, experience, Schedule-4) | Pre-DPC/Master | S9 | M2,M3 |
| 8 | DPC proposal — post selection (to/from, class, type, legal-stay, date) | Proposal | S14 (approve S21) | M4 |
| 9 | DPC committee capture | Proposal | S15 (approve S22) | M4 |
| 10 | Working officers under consideration + vacancy sub-types | Proposal | S16 (approve S23) | M4 |
| 11 | Seniority list (feeder) + zone count display | Proposal | S17 (approve S24) | M4 |
| 12 | Tabulation/evaluation chart (loyalty, property, year CR, note) | Proposal | S18 (approve S25) | M4 |
| 13 | Excel uploads (3 templates) to S3 | Proposal/Docs | S16–S18 + folder image | M4 |
| 14 | AH approval + forward to CGPSC | Proposal/Workflow | S19–S25 | M4 |
| 15 | CR grade → marks | Scoring engine | C4; S33 | M5 |
| 16 | Tribal-area service +1 | Scoring engine | C4; S33 | M5 |
| 17 | Eligibility gates (no घ; I→I last ≠ ग) | Scoring engine | C4–C5 | M5 |
| 18 | Qualifying score by class pair + reserved relaxation | Scoring engine | C5 | M5 |
| 19 | Super seat (notional seniority) | Scoring engine | C5; S33 | M5 |
| 20 | Zone of consideration ((2×vac+4), 5, 7× expansion) | Scoring engine | C6; S17 | M5 |
| 21 | CALCULATE → MARK, REMARK | Scoring engine | S16,S17,S33 | M5 |
| 22 | Chairman allotment + allotment report (status, default date) | CGPSC workflow | S26–S28 | M6 |
| 23 | Member acceptance / scheduling / reschedule / re-allotment | CGPSC workflow | S29–S30 | M6 |
| 24 | Scheduling notifications | Notify | S26 | M6 |
| 25 | DPC proceeding — conduct, calculate | Proceeding | S31–S33 | M7 |
| 26 | Main List + Waiting List generation | Proceeding | S33–S34 | M7 |
| 27 | Approve by CGPSC | Proceeding | S34 | M7 |
| 28 | Cancel DPC | Proceeding | S33/S43 | M7 |
| 29 | Proceedings/result document (PDF) | Proceeding/Docs | S4,S31,S34 | M7 |
| 30 | Review DPC (reasons, original DPC ref, correction, re-run) | Review | S35–S44 | M8 |
| 31 | Circulation DPC (held officers, resolve, sign-off) | Circulation | C-deck; S45–S48 | M9 |
| 32 | Self-inspection view + PRINT + objection (time-boxed, late auto-filed) | Self-service | S49–S51 | M10 |
| 33 | DPC ID generation `<DeptCode><Year><Serial>` | Proposal/Workflow | S28,S32,S37 | M4 |
| 34 | Master data (dept, post, category, thresholds, codes, pay scale) | Master | S6,S9,S18,C4–C6 | M2 |
| 35 | Audit trail of all actions | Cross-cutting | implied by e-sign statements & govt norms | M1→ongoing |
| 36 | Dashboards / reports (Chart.js) | Reports | secondary docs only (Q) | M11 |
