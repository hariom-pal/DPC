# 06 — Ambiguities & Open Questions (need your ruling)

PPT is the default source of truth. Where the PPT is silent or internally inconsistent, or where a feature exists only in secondary docs, it is listed here rather than assumed.

> **RULED 2026-07-30 (post-reset):**
> - **Q1 → Email/mobile + password + OTP (no Aadhaar).** Username = email or mobile; password login; verify BOTH email & mobile via OTP at registration; fresh OTP step-up at each legally-significant signing action, written to the audit trail as record of intent. Deliberate, approved override of the PPT Aadhaar screens (auth only).
> - **Q2 → Yes, add a CGPSC Admin role** (manages masters + provisions Chairman/Member accounts).
> - **Q3 → Yes, config-driven rules** (config + master tables; PPT values seeded as defaults).
> - **Q13 → Redis for cache, queue AND sessions** in V1 (fully shared-state ready).
>
> Remaining open items below (Q4–Q12, Q14–Q16) are resolved as their milestones are reached.

| # | Question | Why it matters | PPT default (if unruled) | Blocks |
|---|---|---|---|---|
| **Q1** | **Auth & e-signing.** PPT uses **Aadhaar No. + Aadhaar OTP** for login and as the digital-signing statement on every screen. Keep Aadhaar exactly, or replace with email/mobile + password (+ OTP verification at registration, OTP step-up at signing)? | Legal identity + signing of promotion orders (contested in CAT/HC) | Aadhaar as shown | M1 |
| **Q2** | **Admin role.** Who seeds masters and provisions Chairman/Member logins? Add a **CGPSC Admin** role (not in PPT)? | Nothing in the PPT creates these accounts | none — needs approval | M1,M2 |
| **Q3** | **Config-driven rules.** Implement scores/thresholds/zone/circulation as config + master data (recommended) with PPT values as defaults? | Rules change by govt notification; hardcoding = risky | config-driven, PPT defaults | M2,M5 |
| **Q4** | **Tabulation layout.** Loyalty & Immovable-Property per-year Y/N (mockup shows year columns) or a single flag each? | Sets the evaluation schema | per-year Y/N | M4 |
| **Q5** | **Reserved relaxation / 7× zone expansion** — applied automatically by the engine, or a committee-discretion action logged at proceeding time? | Changes engine behavior & audit | automatic + logged | M5 |
| **Q6** | **Qualifying-score relaxation to 10** — only CLASS-I→I (as printed) or all class pairs? | Eligibility outcomes | as printed (I→I only) | M5 |
| **Q7** | **Proceedings/order PDF** — is a formal PDF generated, and what layout? PPT only says "proceedings issued." | Legal output document | generate PDF, layout TBD | M7 |
| **Q8** | **Bilingual UI** — Hindi-only, English-only, or both with a toggle? | Every screen's labels | both + toggle | M0/M11 |
| **Q9** | **DPC ID** — confirm `<DeptCode><Year><Serial>`; who assigns the dept code? | Unique identifier, login ID | admin assigns code at registration | M4 |
| **Q10** | **Committee size** — hard cap (mockup shows Chairman + 4) or unlimited? | Form design & validation | configurable cap, default 5 | M4 |
| **Q11** | **Objection** — deadline configurable? Does raising it notify the department, block the DPC, or only log? | Self-service workflow | configurable deadline; late auto-filed; notify + log | M10 |
| **Q12** | **Circulation trigger** — Conventional deck says "2+ years CR missing OR immovable-property missing"; S14 label says "CR ≤ 4". Which governs (implemented configurably)? | Determines who is held | 2+ yrs CR / property missing; "CR ≤ 4" as configurable extra | M5,M9 |
| **Q13** | **Redis in V1** — required now (cache/queue/sessions) or file/db driver for V1 with Redis wired later? Stack says "if required." | Infra footprint of V1 | Redis for queue; sessions/cache configurable | M0 |
| **Q14** | **RBAC package** — Spatie `laravel-permission`, or native `role` enum + Policies? | Dependency & flexibility | Spatie (production standard) | M1 |
| **Q15** | **Audit package** — `owen-it/laravel-auditing` / Spatie `activitylog`, or custom `audit_logs`? | Auditability requirement | a maintained package | M1 |
| **Q16** | **Out-of-scope confirm** — video conferencing, real-time multi-member voting, HRMS import appear only in secondary docs, not the PPT. Confirm excluded from V1? | Scope | excluded | M11 |

**Recommended answers to unblock M0–M2 quickly:** Q1 → email/mobile + password with dual OTP at registration and OTP step-up at signing (your earlier lean; confirm); Q2 → yes, add CGPSC Admin; Q3 → yes, config-driven; Q13 → Redis for queue in V1, sessions/cache via config; Q14 → Spatie; Q15 → a maintained audit package.

---

## Business Questions

**Moved 2026-08-13 by architect directive.** Questions that need a business answer — not an engineering or design decision — now live in a single register:

### → `docs/business/PENDING_BUSINESS_DECISIONS.md`

That includes Q5, Q6, Q7, Q11 and Q12 from the table above, plus the CSC eSign document-format question, the HRMS API contract, and the `PUNISHMENT` disqualification question raised by PPT slide 33.

The table above remains the record of what each ambiguity *was* and which milestone it affects. The business register is the record of what is still **blocked on someone with authority over the promotion rules**, and what the system does in the meantime.

**Standing rule while a business question is open:** the engine applies the printed rule, flags every case the unruled rule could have changed, and never guesses in either direction.
