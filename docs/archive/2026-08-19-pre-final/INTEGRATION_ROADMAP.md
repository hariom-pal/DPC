# Integration Roadmap

Every external system the ODPC Portal is designed to connect to: what switches it on, what implements it, what it listens to, and where it stands today.

**Created 2026-08-13 by architect directive. Documentation only.**

---

## The rule that applies to all of them

Every integration is **configuration-driven, disabled by default, and behind a Provider**. Business logic never calls an external system directly — only a Provider may. Enabling one must require three things and nothing else:

1. an environment variable
2. credentials
3. the provider registration already present in `AppServiceProvider`

**The application runs, and is fully demonstrable, with every integration switched off.** That is the UAT and early-production configuration, and it is what CI runs. A test asserts the entire M4 workflow — draft through signed approval — completes with all of them disabled.

Live state is visible to the CGPSC Admin at **`/admin/integrations`**, which shows each flag, its environment variable, its state, its documented fallback, and which provider each contract actually resolves to. A flag nobody can see is a flag nobody can trust.

---

## Status at a glance

| Integration | Flag | Status | Blocked on |
|---|---|---|---|
| HRMS | `FEATURE_HRMS` | ⚪ Not available | CGPSC API contract (BD-5) |
| CSC DigiSign | `FEATURE_CSC_DIGISIGN` | ⚪ Not available | CGPSC API contract (BD-4) |
| LG Directory | `FEATURE_LGD_SYNC` | ⚪ Not available | No API contracted |
| Government SMS | `FEATURE_SMS_GATEWAY` | ⚪ Not available | No gateway contracted |
| Government E-mail | `FEATURE_EMAIL_GATEWAY` | ⚪ Not available | No gateway contracted |
| Calendar | *(none yet)* | 📐 Designed, not built | Product decision |
| Notifications *(internal)* | `FEATURE_NOTIFICATIONS` | ✅ **On by default** | — |

Nothing in the roadmap blocks any completed milestone.

---

## 1. HRMS

| | |
|---|---|
| **Feature Flag** | `FEATURE_HRMS` — default `false` |
| **Provider** | `App\Integrations\Contracts\HrmsProvider` → `MockHrmsProvider` (off) / `RealHrmsProvider` (on, **unimplemented**) |
| **Events Used** | None. HRMS is a data source, not a subscriber. |
| **Dependencies** | CGPSC HRMS API contract, credentials, reservation-category vocabulary, test environment |
| **Current Status** | Not available. Every officer record is entered manually or imported from the S16–S18 Excel templates. |

**Behaviour while off:** `isAvailable()` returns false and `findEmployee()` returns null, so any caller falls back to manual entry. The mock **never fabricates an employee** — a plausible-looking fake officer flowing into a promotion proposal would be far worse than an empty result.

**Standing constraint (architect directive): HRMS may only enrich data. It must never modify workflow behaviour.** When implemented, it pre-fills a form the officer then confirms and saves; the record persists as a proposal-time snapshot exactly as a typed one would. A historical proposal must never change because HRMS changed.

Full contract: `docs/integration/HRMS.md`. Open question: **BD-5**.

---

## 2. CSC DigiSign / eSign

| | |
|---|---|
| **Feature Flag** | `FEATURE_CSC_DIGISIGN` — default `false` |
| **Provider** | `App\Integrations\Contracts\SignatureProvider` → `MockSignatureProvider` (off) / `CscDigiSignProvider` (on, **unimplemented**) |
| **Events Used** | None directly. Invoked synchronously by `SignatureService` during an approval transition. |
| **Dependencies** | CSC eSign API endpoint and version, ASP credentials, signer identification model, document format (**BD-4**), timestamping requirements, test certificates |
| **Current Status** | Not available. The abstraction has been in production use since M3. |

**Behaviour while off — deliberately asymmetric:**

- **Non-production** signs through the mock, so the full workflow stays demonstrable.
- **Production refuses to approve** and reports a business error. It never falls back to the mock.

A promotion order carrying a development signature would be worse than no signature: it would look valid while being legally worthless, and would only be discovered when challenged. Both behaviours are tested.

Full contract: `docs/integration/CSC-DigiSign.md`. Open question: **BD-4** — whether the API signs raw bytes or requires a PDF. This is the one integration question that could affect application code, since the signed proposal is currently plain text.

---

## 3. LG Directory (administrative areas)

| | |
|---|---|
| **Feature Flag** | `FEATURE_LGD_SYNC` — default `false` |
| **Provider** | `App\Integrations\Contracts\DirectoryProvider` → `SeedDirectoryProvider` (off) / none yet (on → throws) |
| **Events Used** | None. |
| **Dependencies** | An LGD API contract; none has been sought. |
| **Current Status** | Not available. The seeded Chhattisgarh district list is served. |

**Behaviour while off:** the seeded list is used, which is sufficient — districts are stable reference data, not transactional. Enabling the flag without an implementation throws a message naming the flag, so a misconfiguration fails immediately rather than silently serving nothing.

**Assessment:** the lowest-value integration on this list. A live LGD sync buys freshness for data that rarely changes. Worth doing only if CGPSC requires directory conformance for another reason.

---

## 4. Government SMS Gateway

| | |
|---|---|
| **Feature Flag** | `FEATURE_SMS_GATEWAY` — default `false` |
| **Provider** | `App\Integrations\Contracts\SmsSender` → `LogSmsSender` (off) / none yet (on → throws) |
| **Events Used** | `WorkflowTransitioned` (via `SendWorkflowNotification`), `OtpRequested`, `CredentialsIssued` |
| **Dependencies** | Gateway contract, sender ID registration (`DPC_SMS_SENDER_ID`, default `CGPSC`), DLT template registration if required |
| **Current Status** | Not available. Messages are written to the application log. |

**This is the highest-value outstanding integration.** The PPT requires it in two places, and the system currently satisfies neither in production:

- **S26** — allotment and scheduling information must reach the Administrative Head and Authorized Officer on their **registered mobile**.
- **Registration and login (S5, M1)** — OTP delivery. Without a gateway, no officer can complete verification in production.

**OTP delivery does not depend on `FEATURE_NOTIFICATIONS`** — it is authentication infrastructure, not the notification matrix — but it does depend on this gateway.

**Recommendation:** contract this before production. It is the one integration whose absence stops the system being usable at all, as opposed to merely less convenient. Raised here rather than left implicit.

---

## 5. Government E-mail Gateway

| | |
|---|---|
| **Feature Flag** | `FEATURE_EMAIL_GATEWAY` — default `false` |
| **Provider** | Laravel's mail driver (framework-managed; no bespoke Provider needed) |
| **Events Used** | Same as SMS |
| **Dependencies** | SMTP relay or gateway API, a sending domain, SPF/DKIM alignment for `.gov.in` |
| **Current Status** | Not available. Mail uses the framework's configured non-delivering driver. |

S26 requires the same information by **e-mail as well as SMS**, and registration issues credentials by both. The same production-readiness argument as SMS applies, slightly less acutely — SMS reaches an officer who is not at a desk.

**Note for deployment:** department e-mail addresses are validated as `.gov.in`. Deliverability to government mail infrastructure should be tested during UAT rather than assumed.

---

## 6. Calendar

| | |
|---|---|
| **Feature Flag** | Not yet allocated — `FEATURE_CALENDAR` when built |
| **Provider** | Not yet defined. Must follow the Provider Pattern like every other. |
| **Events Used** | **`dpc_cgpsc.schedule`**, **`dpc_cgpsc.reschedule`** |
| **Dependencies** | A product decision on which calendar (ICS feed, Google, Outlook/Exchange); nothing technical is blocking |
| **Current Status** | **Designed, not built.** The events it needs already carry everything required. |

**The architectural rule is that a calendar is a consumer of workflow events, never a participant** (architect directive). Nothing in `SchedulingService` may learn a calendar exists.

Everything a calendar entry needs is already published:

| Need | Source |
|---|---|
| When | `scheduled_for` (resolved ISO-8601) plus `dpc_date` / `dpc_time` |
| In which zone | `timezone` — currently `Asia/Kolkata` |
| Who | the allottee, snapshotted on the event |
| Stable external key | `schedule_uuid` — so a reschedule **updates** rather than duplicating |
| Idempotency | the timeline event's own `uuid` |

**Backfill is already possible.** Because the timeline is replayable, a calendar turned on later can be populated from history without touching a business table. Do this through the planned **Replay Dry Run** first — enabling a calendar against months of history would otherwise mean discovering its behaviour by sending real invitations.

Contract: `docs/EVENT_CATALOG.md`.

---

## 7. Notifications (internal capability)

| | |
|---|---|
| **Feature Flag** | `FEATURE_NOTIFICATIONS` — default **`true`** |
| **Provider** | `SendWorkflowNotification` listener (queued, `afterCommit`) |
| **Events Used** | `WorkflowTransitioned` — payload version 1 |
| **Dependencies** | Its transports (SMS, e-mail) are separately flagged and fall back to log drivers |
| **Current Status** | ✅ Working, with log-driver transports |

Listed because it is the reference implementation every future subscriber should copy: **a failing subscriber can never roll back a workflow transition**, enforced at three layers — isolated dispatch, `afterCommit` queueing, and best-effort delivery per recipient.

**`FEATURE_NOTIFICATIONS` gates workflow notifications only.** OTP and credential delivery are authentication infrastructure and are never gated by it — nobody could log in.

---

## Adding an integration

1. Add a flag to `config/features.php` and a case to `App\Enums\Feature`, with its documented fallback. Default **off**.
2. Define a contract in `App\Integrations\Contracts`.
3. Write a Mock that is **honest** — it reports unavailability rather than fabricating data.
4. Write the Real implementation. Until it exists, make enabling the flag **throw**, so a misconfiguration fails loudly.
5. Register both in `AppServiceProvider::register()`, selected by the flag.
6. Write `docs/integration/<NAME>.md`: architecture, enable/disable, flow, failure behaviour, future API requirements, production considerations.
7. Add it here and, if it subscribes to events, to `docs/EVENT_CATALOG.md`.
8. Test that the application works with it **off** — that is the configuration that ships.
