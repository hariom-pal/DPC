# HRMS — Integration Document

**Status:** Not available. CGPSC has not supplied an HRMS API contract or credentials. The integration is architected, flagged off, and inert. Nothing in the application depends on it.

**Flag:** `FEATURE_HRMS` · **Default:** `false` · **Live state:** visible to the CGPSC Admin at `/admin/integrations`.

---

## 1. Why this exists

Working officers, seniority entries, and evaluation-chart rows are all officer records. In a mature deployment those would come from the state HRMS rather than being typed in. No such API exists today, so every officer record is entered manually or imported from the PPT Excel templates (S16–S18).

This document exists so that when an HRMS API arrives, the change is a provider implementation and a credential — not a redesign.

## 2. Provider architecture

```
Business logic  ──►  HrmsProvider (interface)  ──►  MockHrmsProvider   (FEATURE_HRMS=false)
                                               └─►  RealHrmsProvider   (FEATURE_HRMS=true)
```

- `App\Integrations\Contracts\HrmsProvider` — the contract.
- `App\Integrations\Mock\MockHrmsProvider` — bound while the flag is off.
- `App\Integrations\HRMS\RealHrmsProvider` — the production target; **unimplemented**, throws if called.
- Bound in `AppServiceProvider::register()`, selected solely by `Feature::Hrms`.

**Business logic must never call an HRMS endpoint directly.** Only the provider may. This is an architectural rule, not a convention.

```php
interface HrmsProvider
{
    public function name(): string;
    public function isAvailable(): bool;

    /** @return array<string, mixed>|null */
    public function findEmployee(string $employeeNumber): ?array;
}
```

The contract is deliberately narrow. It returns the standard ten-field employee snapshot the proposal tables already use, because that is the only shape the application has any use for. A wider speculative contract would be guesswork about an API nobody has seen.

Note that `findEmployee()` returns the reservation category as a **code** (`UR`/`SC`/`ST`/`OBC`), not an id — an external system has no knowledge of this application's primary keys. The Excel importer already makes the same assumption, so both external inputs share one convention.

## 3. Enable / disable configuration

```dotenv
FEATURE_HRMS=false          # default; manual entry and Excel import
FEATURE_HRMS=true           # requires RealHrmsProvider to be implemented first
```

Nothing else switches this integration. There is no second toggle, no provider-name string, and no code path that bypasses the flag.

## 4. Integration flow (when implemented)

1. Officer opens Working Officers (S16) or Seniority List (S17).
2. Officer enters an employee number.
3. The screen asks `HrmsProvider::isAvailable()`. If false, it does nothing — manual entry proceeds exactly as today.
4. If available, `findEmployee()` returns the snapshot and pre-fills the row.
5. **The officer confirms and saves.** The record is persisted as a proposal-time snapshot, exactly as a manually typed one.

Step 5 is the important one and is not negotiable. Historical proposal snapshots are immutable by architect directive: no HRMS synchronisation may ever alter a stored proposal. HRMS is a *typing aid at entry time*, never a live reference. A proposal approved in 2026 must read identically in 2031 even if the officer's HRMS record changed in between, because that proposal may be evidence in a service matter.

## 5. Failure behaviour

| Condition | Behaviour |
|---|---|
| `FEATURE_HRMS=false` | `isAvailable()` returns false, `findEmployee()` returns null. Manual entry and Excel import work normally. **No workflow fails.** |
| Flag on, provider unimplemented | `RuntimeException` naming the flag and how to turn it off. Loud and immediate — never a silent empty result that would read as "this officer is not in HRMS". |
| Flag on, API unreachable (future) | The implementation must return `isAvailable() === false` and let the screen fall back to manual entry. An HRMS outage must never block a DPC. |
| Employee not found | `null`. The officer types the record. Not an error. |

`MockHrmsProvider` never fabricates employee data. A plausible-looking fake officer flowing into a promotion proposal would be far worse than an empty result.

## 6. Future API requirements

To implement `RealHrmsProvider`, CGPSC must supply:

1. **Base URL** per environment, and the authentication scheme (OAuth2 client credentials, API key, or mTLS).
2. **Credentials**, delivered out of band and stored as environment variables — never committed.
3. **Employee lookup endpoint**: request by employee/service number; response containing name, date of birth, qualification, current designation, feeder designation, reservation category code, date of joining service, date of posting on current post, date of posting on feeder post.
4. **Reservation category vocabulary**, so their codes can be mapped to the M2 master. If the vocabularies differ, that mapping belongs in the provider, not in business logic.
5. **Rate limits and SLA**, so timeout and retry behaviour can be set deliberately.
6. **Test environment** with non-production data.

Open questions to settle with CGPSC before implementation: whether lookup is by employee number only or also by name/DOB; whether bulk fetch by cadre is available (which would change the Excel-import story); and what the authoritative source is when HRMS and the department's own records disagree.

## 7. Production considerations

- **Timeouts must be short and failure must be soft.** A DPC proposal being prepared against a deadline cannot hang because HRMS is slow.
- **Log every lookup** — employee number, timestamp, actor, outcome. Officer data retrieval in a promotion process should be auditable.
- **Do not cache officer data** beyond the request. Snapshots are taken at save time by design; a cache would create a second, staler source.
- **PII.** Responses contain personal data. They must not be written to application logs, and the credential must be environment-scoped.
- Enabling in production requires only: environment variables, credentials, and the provider registration already present in `AppServiceProvider`.

## 8. Current test coverage

`tests/Feature/FeatureFlagTest.php` asserts that the flag defaults off, that the mock is bound while it is off, that the mock reports unavailable and never fabricates an employee, and that enabling the flag without an implementation fails loudly.

`tests/Feature/Dpc/DpcProposalClosureTest.php` asserts the entire M4 workflow — draft through signed approval — runs with every integration disabled, which is the guarantee that HRMS being absent breaks nothing.
