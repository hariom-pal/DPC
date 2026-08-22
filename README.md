# e-DPC Portal — Online Departmental Promotion Committee

A Laravel application for the **Chhattisgarh Public Service Commission (CGPSC)** that conducts the Departmental Promotion Committee process end to end: department registration → Pre-DPC proposal → detailed DPC proposal → CGPSC allotment → scheduling → the online sitting → result (main + waiting list) → signed, published, verifiable documents — plus Review DPC, Circulation DPC and Self-Inspection.

**Engineering complete, 2026-08-22. Development frozen pending business review.**

**This is a government system.** Its outputs are promotion orders that decide a serving officer's rank and pay and can be legally contested. Correctness, auditability and security take priority over speed of delivery.

---

## Documentation — five documents, one per audience

| Document | For | Read it to |
|---|---|---|
| **[CGPSC DPC User Guide](docs/CGPSC_DPC_USER_GUIDE.md)** | Business users and departmental officers. No technical knowledge needed | Operate the portal — every role, screen, button and workflow, with reports, notifications, a demonstration walkthrough and troubleshooting |
| **[CGPSC DPC Architecture Handbook](docs/CGPSC_DPC_ARCHITECTURE_HANDBOOK.md)** | Architects and Technical Leads | Understand **why** it is built this way — the 25 decisions, the workflow engine, the data and security architecture, the freezes, the debt, and what the gate cannot prove |
| **[CGPSC DPC Developer Handbook](docs/CGPSC_DPC_DEVELOPER_HANDBOOK.md)** | Developers taking handover | Get it running and change it safely — structure, conventions, database, services, policies, events, validation, testing, deployment, debugging, PPT and SRS mapping |
| **[Pending Business Decisions](docs/CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md)** | CGPSC / business | See the **eight** policy questions the system deliberately does not decide, each with options, a recommendation and its impact |
| **[Blockers](docs/CGPSC_DPC_BLOCKERS.md)** | Sponsor and production owner | See what stands between *working* and *live*. **One true blocker**, and it is a procurement |

Everything else that was ever written about this project is under `docs/archive/` — the architect review, decision and debt registers, milestone documents, the design set, deployment runbooks, UAT and demo scripts, and the worklog. **Archived, never deleted:** it is the audit trail of how the system came to be the way it is, including the reasoning behind decisions that would otherwise look arbitrary.

## Source of requirements

Business requirements come **only** from the two presentation decks, which is why they sit in `docs/` rather than the archive:

1. `docs/ODPC_NEW_FINAL.pptx` — 52 slides, cited in code as `S1`–`S52`
2. `docs/CONVENTIONAL DPC PROCESS.pptx` — 7 slides, `C1`–`C7`, the scoring and eligibility rules

Code comments cite slide numbers throughout, and there is nowhere else to check them. All 59 slides are accounted for in the Developer Handbook §13. On any conflict, the decks win. **An older SRS in the archive describes features that appear in neither deck — do not implement from it** (Developer Handbook §14).

## Stack

PHP 8.4 · Laravel 13 · MySQL 8 · Redis (cache, queue, sessions) · Blade + Bootstrap 5.3 **vendored — no Node, no npm, no build step, no CDN**.

Thin controllers → Domain Services (`app/Domain/*`, 18 domains) → Eloquent models. **Business rules live in `config/dpc.php` and master-data tables, never in source.** Every external system sits behind a Provider contract and is flagged **off** by default, so the whole pipeline runs and demonstrates with nothing connected.

Having no CDN and no bundler is what makes a strict `Content-Security-Policy` affordable rather than aspirational — it is **enforced**, not report-only.

## Local setup

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan demo:seed     # rebuilds the demo database and prints every credential
php artisan serve
```

Requires MySQL (`dpc_dev`) and Redis; both are load-bearing. `demo:seed` prints the accounts, the DPC identifiers, the three facts that open Self-Inspection, and where to find each screen — read from the database it just built, so it cannot drift. It refuses to run in production.

## Quality gate

```bash
composer gate
```

Five parts, all mandatory:

| | Check | Current |
|---|---|---|
| 1 | Laravel Pint (PSR-12 + strict types) | pass |
| 2 | PHPStan / Larastan **level 6** | **0 errors**, no baseline, no ignores |
| 3 | `security:audit` — `composer audit` with retry | no advisories |
| 4 | `schema:verify-mysql` — builds every migration **on MySQL** | 54 migrations, 68 tables |
| 5 | PHPUnit | **960 passed / 3135 assertions** |

**Step 4 is not ceremony.** The suite runs on in-memory SQLite and the application runs on MySQL. A migration once passed 84 green tests while being impossible for MySQL to create — see Architecture Handbook §11.

Run the suite bounded: `php -d memory_limit=2G vendor/bin/phpunit`.

**And the gate is necessary, not sufficient.** Every significant defect in this project was found by applying a discipline — demoing the phase, comparing against the deck's own worked answer, or driving the application in a real browser — not by running the gate. Architecture Handbook §15 lists them.

## Contributing

Read **Developer Handbook §4** first. In short: no business logic in controllers or Blade; all validation in Form Requests; all business logic in Domain Services; external systems behind a Provider contract; no hardcoded business values; migrations additive; evidence never overwritten; every route-bound model addressed by UUID; **no inline script or event handler in a view** — the CSP is enforced and a refused handler fails silently.

**Do not add a screen or a workflow that is not in the two decks** without a recorded decision — and **never invent a business rule.** Where the decks do not settle a question, refuse and record it: that is what the Pending Business Decisions register is for, and it is the single most important convention in this codebase.
