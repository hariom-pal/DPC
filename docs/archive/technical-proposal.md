# e-DPC Portal — Technical Proposal: Project Structure & Technology Stack (Draft 03)

Prepared from DPC-SRS.docx, the 14-screen UI mockup (Online DPC Module-.docx), and two process decks (CONVENTIONAL DPC PROCESS.pptx, ODPC_NEW_FINAL.pptx).

## 1. System Overview

e-DPC Portal digitizes the Departmental Promotion Committee process for the Chhattisgarh Public Service Commission (CGPSC) — from proposal submission through eligibility checks, committee review and voting, final decision, and order generation.

Actors: Chairman (CGPSC), DPC Committee Members, Department Nodal Officer, Department Establishment/Employee, IT Support Team, Admin (CGPSC).

## 2. Functional Requirements

| Area | Key capabilities |
|---|---|
| User & Access | RBAC, email+password login with OTP step-up on signing actions, dual email+SMS OTP verification at registration, admin create/deactivate users, department-wise user mapping |
| Service Records | HRMS import or manual entry; APAR/ACR, disciplinary, training, service history |
| Vacancy Data | Sanctioned/working/vacant posts per department & class; UR/SC/ST/OBC/EWS breakdown |
| Feeder Cadre | Promotion↔feeder post mapping, minimum service years, recruitment rule reference, quota % |
| Seniority List | Excel upload, effective date, upload status tracking |
| Proposal Intake | Nodal officer uploads proposal + documents; auto eligibility validation; digital signature & timestamp |
| Evaluation | APAR score, experience/training marks, integrity/disciplinary flag, auto-computed total |
| Legal/Vigilance | Court case or stay status, vigilance clearance status, certificate upload, remarks |
| Committee | Committee name, meeting date/venue, chairman & member assignment |
| Eligibility | Automated checklist → ELIGIBLE / NOT ELIGIBLE |
| DPC Meeting | Scheduling, video conferencing (WebRTC/Teams/Zoom), digital minutes, real-time voting |
| Proceedings | Remarks, recommend/defer/reject, signed proceedings upload, e-signatures |
| Approvals | Multi-level approval with status/date tracking at each level |
| Order Generation | PDF generation, e-Sign, publish, bulk letters, per-employee distribution status |
| Notifications | To departments & employees at every workflow stage |
| Reports | Metric cards, dept-wise vacancy chart, 3-yr promotion trend, status reports, PDF/Excel export |
| Audit Trail | Complete log of all actions — called out as its own requirement |

## 3. Non-Functional Requirements

> Noting: The SRS has no dedicated non-functional section. These are derived from the stated functional requirements and standard Indian e-governance norms — confirm or amend before treating as binding.

- Security: RBAC + password login, encrypted storage for service/disciplinary/vigilance data, OTP-confirmed sign-off (not PKI/DSC-grade) with an immutable audit entry standing in as the record of intent on legal instruments
- Auditability: Tamper-evident, append-only trail — promotion orders are routinely contested in CAT/High Court
- Data Integrity: Eligibility rules enforced server-side, not just in the UI
- Availability: DPC cycles are time-boxed statutory events — high uptime required during active cycles
- Scalability: Multiple departments and cadres running concurrent cycles statewide
- Interoperability: HRMS import, pluggable video-conferencing providers (WebRTC/Teams/Zoom)
- Accessibility: GIGW compliance; English/Hindi language switching (visible in the login mockup)
- Compliance: DoPT/state promotion guidelines; IT Act handling of PII and service records
- Backup & DR: Statutory records — loss is not an acceptable outcome
- Record Integrity: Clear separation between draft/working data and final, signed, immutable records

## 4. Additional Findings — Process Decks

CONVENTIONAL DPC PROCESS.pptx (7 slides) and ODPC_NEW_FINAL.pptx (52 slides) describe the real procedural workflow in more depth than the SRS. Folded into the stack/structure below.

Noting — Authentication & Signing (Revision 03): the process decks show Aadhaar No. + Aadhaar OTP as login/signing on every screen. Per instruction, Aadhaar is removed entirely and replaced across all roles as detailed below.

- Login: Email + password. Registration captures both email and mobile, each independently confirmed by its own OTP (email OTP to the address, SMS OTP to the number) before the account activates. Password beats per-login OTP here since a DPC cycle runs for weeks with the same officials logging in repeatedly — including mid-meeting — and a password doesn't depend on SMS/email deliverability at that exact moment.
- Signing: Every legally significant action (proposal submission, DPC proceedings sign-off, order e-signing) requires a fresh OTP sent to the actor's verified email or mobile at that moment; the OTP event, channel, actor, and timestamp are written to the audit trail as the record of intent. A password re-confirmation was considered and rejected — it only proves the session is still authenticated, not deliberate intent on this specific record right now.
- Dept. Hierarchy: Two-tier accounts per department — an Authorized Officer prepares the proposal, an Administrative Head/Secretary approves it before it reaches CGPSC
- CGPSC Allotment: Chairman receives every proposal centrally, then allots each case to self or to a named Member, who conducts that DPC
- Three DPC Types: Fresh, Review (references the original DPC ID + a coded error reason), and Circulation (missing CR/property returns — that officer's case is held back and signed off via a circulation note instead of a full meeting)
- Vacancy Model: Four sub-types per reservation category — clear, expected-from-promotion, expected-from-retirement, expected-from-deputation
- Scoring Engine: 5-year CR grades (क+/क/ख/ग/घ = 4/3/2/1/0), qualifying score thresholds by class, zone-of-consideration formulas, tribal-service bonus, and "super seat" notional seniority
- Result Format: Tabulation chart output splits into a Main List and a Waiting/Supplementary list per category, with coded remarks (PUNISHMENT, C.CASE, CIR, SS, tribal-year, etc.)
- Self-Inspection: New employee-facing screen — the officer under consideration can view their own data and raise a time-boxed objection before the DPC date; late objections are auto-filed, not considered
- Cancellation: CGPSC can cancel a DPC case mid-process

## 5. Proposed Technology Stack

| Layer | Choice | Why |
|---|---|---|
| OS | Ubuntu 24.04 LTS | Long support window; every server (app, DB, Jitsi, queue) standardized on one image |
| Infrastructure | AWS EC2 + RDS (+ ALB) | No containers — plain EC2 behind an Application Load Balancer only if a second instance is added for availability/traffic |
| Backend | Laravel (latest stable) / PHP 8.3+ | Confirm the exact point release at build time; mature RBAC/workflow ecosystem either way |
| Database | MySQL (latest stable) on RDS | Managed backups/failover; broadest hosting/tooling familiarity for a Docker-free deployment |
| Cache/Queue | Redis (EC2 or ElastiCache) | Session store plus background jobs (PDF generation, notifications, HRMS sync) |
| Realtime | Laravel Reverb | WebSocket server for live committee voting/notes — plain PHP process, no Node/Docker |
| Frontend | Blade (server-rendered) | No SPA build step or separate frontend deploy |
| UI Kit | Bootstrap 5 | Closest plain-CSS match to the mockups' grids/forms/cards |
| Interactivity | Vanilla JS (+ Alpine.js where needed) | Dynamic bits (tabulation totals, live votes, repeatable rows) without an SPA framework |
| Charts | Chart.js | Drop-in script tag, matches the vacancy bar chart and promotion trend line chart |
| Auth & Signing | Laravel session auth (email+password) + OTP step-up | Password for routine login across multi-week DPC cycles; a fresh email/SMS OTP gates each legally significant action and is logged as the record of intent |
| Registration Verification | Email OTP + SMS OTP (independent) | Account stays inactive until both channels are confirmed — replaces Aadhaar identity verification at signup |
| File Storage | Amazon S3 | Encryption-at-rest, access-controlled URLs for certificates/proposals/signed proceedings |
| PDF Generation | DomPDF | Pure-PHP, no Chromium/Node install on the EC2 box |
| Video Conferencing | Self-hosted Jitsi Meet | Installs from Jitsi's own Ubuntu apt repo, no Docker needed |
| Excel I/O | Laravel Excel | Seniority list and tabulation-chart upload/export |
| Notifications | Queued + SMS gateway | Registration credentials and status updates reach officers/employees reliably |
| Audit Trail | laravel-auditing | Especially important for Review DPC's documented error trail |
| i18n | Laravel localization (lang/en, lang/hi) | Native to Blade via __(), matches the English/Hindi switch in the mockup |
| Testing | Pest, Playwright | Playwright drives a real browser regardless of server-rendered frontend |
| CI/CD & Deploy | GitHub Actions + SSH/rsync (or Laravel Forge) | Zero-downtime deploy to EC2 without containers |
| Monitoring | Laravel Telescope, Sentry, CloudWatch | CloudWatch comes free with the AWS move |

## 6. Project Structure

```
dpc/                               # single Laravel app
├── app/
│   ├── Models/
│   ├── Http/Controllers/         # grouped by role: Department/, Committee/, Admin/
│   ├── Http/Requests/
│   ├── Http/Middleware/          # role gate, EnsureActionIsSigned (step-up OTP check
│   │                              # on signing routes)
│   ├── Services/                 # EligibilityService, ScoringService (CR grades,
│   │                              # zone-of-consideration, super-seat rules),
│   │                              # VacancyCalculationService, OrderGenerationService,
│   │                              # ESignService (OTP-confirmed sign-off + audit entry),
│   │                              # OtpService (generate/send/verify email & SMS OTPs,
│   │                              # shared by registration and signing)
│   ├── Policies/                 # RBAC per role
│   ├── Jobs/                     # PDF gen, HRMS sync, notifications, OTP delivery (email/SMS)
│   ├── Notifications/
│   ├── Events/Listeners/         # audit trail hooks, Reverb broadcasts (live voting)
│   ├── Exports|Imports/          # Laravel Excel: seniority list, tabulation chart
│   └── Audit/
├── resources/
│   ├── views/                    # Blade templates
│   │   ├── layouts/
│   │   ├── auth/                 # email+password login, registration OTP verification
│   │   │                         # (email & SMS), signing-OTP prompt
│   │   ├── dashboard/
│   │   ├── department/           # registration, posts sanctioned, DPC proposal,
│   │   │                         # seniority list, tabulation chart
│   │   ├── committee/             # DPC formation, proceedings, live voting
│   │   ├── review-dpc/
│   │   ├── circulation-dpc/
│   │   ├── self-inspection/       # employee data view + objection
│   │   ├── orders/                # main list / waiting list, promotion orders
│   │   └── reports/
│   ├── css/                      # Bootstrap overrides
│   ├── js/                       # vanilla JS / Alpine components
│   └── lang/{en,hi}/             # Laravel localization
├── routes/
│   ├── web.php
│   └── channels.php              # Reverb broadcast channels
├── database/{migrations,seeders}/
├── tests/{Unit,Feature}/
├── config/
└── public/
infra/
├── nginx/                         # site config for the Ubuntu EC2 box
├── systemd/                       # queue worker + Reverb unit files
├── deploy/                        # SSH/rsync deploy script, or Forge recipe
└── ci-cd/                         # GitHub Actions workflow
docs/                              # existing SRS / mockups / process decks
```

Implementation withheld pending confirmation of this proposal.
