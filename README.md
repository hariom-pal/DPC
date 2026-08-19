# e-DPC Portal — Online Departmental Promotion Committee

A Laravel application for the **Chhattisgarh Public Service Commission (CGPSC)** that conducts the Departmental Promotion Committee process end to end: department registration → Pre-DPC proposal → detailed DPC proposal → CGPSC allotment → scheduling → the online sitting → result (main + waiting list) → signed, published, verifiable documents — plus Review DPC, Circulation DPC and Self-Inspection.

**Engineering complete, 2026-08-19. Development frozen pending business review.**

**This is a government system.** Its outputs are promotion orders that decide a serving officer's rank and pay and can be legally contested. Correctness, auditability and security take priority over speed of delivery.

---

## Documentation — four documents, one per audience

| Document | For | Read it to |
|---|---|---|
| **[docs/SYSTEM_USER_MANUAL.md](docs/SYSTEM_USER_MANUAL.md)** | Officers and Commission users. No technical knowledge needed | Operate the portal — every screen, button and workflow, with a demo walkthrough and troubleshooting |
| **[docs/BUSINESS_DECISION_REGISTER.md](docs/BUSINESS_DECISION_REGISTER.md)** | CGPSC / business | See the **eight** policy questions the system deliberately does not decide, each with options, a recommendation and its impact |
| **[docs/ARCHITECT_HANDBOOK.md](docs/ARCHITECT_HANDBOOK.md)** | Architect / technical lead | Architecture, the 25 decisions behind it, the freezes, the quality gate, technical debt, and what remains before production |
| **[docs/DEVELOPER_HANDOVER.md](docs/DEVELOPER_HANDOVER.md)** | A developer joining with no context | Get it running and change it safely — structure, conventions, database, testing, PPT and SRS mapping |

`docs/archive/2026-08-19-pre-final/` holds the working record of construction — the architect review, decision and debt registers, milestone documents, design set, deployment runbooks and worklog. **Archived, not deleted:** it is the audit trail of how the system came to be this way. See [docs/README.md](docs/README.md).

## Source of requirements

Business requirements come **only** from the two presentation decks:

1. `docs/ODPC_NEW_FINAL.pptx` — 52 slides, cited in code as `S1`–`S52`
2. `docs/CONVENTIONAL DPC PROCESS.pptx` — 7 slides, `C1`–`C7`, the scoring and eligibility rules

Code comments cite slide numbers throughout, and there is nowhere else to check them. On any conflict, the decks win. **An older SRS in the archive describes features that appear in neither deck — do not implement from it** (Developer Handover §14).

## Stack

PHP 8.4 · Laravel 13 · MySQL 8 · Redis (cache, queue, sessions) · Blade + Bootstrap 5.3 **vendored — no Node, no build step, no CDN**.

Thin controllers → Domain Services (`app/Domain/*`, 18 domains) → Eloquent models. **Business rules live in `config/dpc.php` and master-data tables, never in source.** Every external system sits behind a Provider contract and is flagged **off** by default, so the whole pipeline runs and demonstrates with nothing connected.

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
| 4 | `schema:verify-mysql` — builds every migration **on MySQL** | 53 migrations, 68 tables |
| 5 | PHPUnit | **939 passed / 3018 assertions** |

**Step 4 is not ceremony.** The suite runs on in-memory SQLite and the application runs on MySQL. A migration once passed 84 green tests while being impossible for MySQL to create — see Architect Handbook §11.

Run the suite bounded: `php -d memory_limit=2G vendor/bin/phpunit`.

## Contributing

Read **Developer Handover §4** first. In short: no business logic in controllers or Blade; all validation in Form Requests; all business logic in Domain Services; external systems behind a Provider contract; no hardcoded business values; migrations additive; evidence never overwritten.

**Do not add a screen or a workflow that is not in the two decks** without a recorded decision — and **never invent a business rule.** Where the decks do not settle a question, refuse and record it: that is what the Business Decision Register is for, and it is the single most important convention in this codebase.
