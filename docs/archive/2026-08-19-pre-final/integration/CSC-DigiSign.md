# CSC DigiSign / CSC eSign — Integration Document

**Status:** Not available. CGPSC has not supplied the CSC eSign API contract or credentials. The integration is architected, flagged off, and the production target is deliberately unimplemented.

**Flag:** `FEATURE_CSC_DIGISIGN` · **Default:** `false` · **Live state:** visible to the CGPSC Admin at `/admin/integrations`.

---

## 1. Why this exists

Every legally significant action in the PPT (S8, S10, S13, S20, S27, S29, S32, S36, S39, S42, S46, S50 — *"By providing Aadhar No. And Aadhar OTP you are digitally signing the content"*) requires a digital signature. Decision Q1 (2026-07-30) replaced Aadhaar-OTP **login** with email/mobile + password + OTP, but the **signing** step for approvals is planned to use CSC (Common Services Centre) eSign / DigiSign — the standard government digital-signature service — rather than a bespoke mechanism.

## 2. Provider architecture

```
Business logic  ──►  SignatureService  ──►  SignatureProvider (interface)
                                                 ├─►  MockSignatureProvider   (FEATURE_CSC_DIGISIGN=false)
                                                 └─►  CscDigiSignProvider     (FEATURE_CSC_DIGISIGN=true)
```

- `App\Integrations\Contracts\SignatureProvider` — the contract.
- `App\Domain\Signature\Services\SignatureService` — the only thing business logic talks to. It signs, stores the signed file on the configured disk, and persists signature metadata to the `signatures` table.
- `App\Integrations\Mock\MockSignatureProvider` — bound while the flag is off. Signs deterministically, no external call.
- `App\Integrations\CSC\CscDigiSignProvider` — the production target; **unimplemented**, throws if called.
- Bound in `AppServiceProvider::register()`, selected solely by `Feature::CscDigiSign`.

**Business logic never names a provider.** Modules call `SignatureService::sign()` and receive a `Signature`.

```php
interface SignatureProvider
{
    public function name(): string;
    public function sign(SignatureRequest $request): SignatureResult;
}
```

- **`SignatureRequest`** — the signing `User`, the `Model` being signed, the document bytes and name, and a free-form `context` array.
- **`SignatureResult`** — provider name, `transactionReference`, signed document bytes, `signedAt`, and optional certificate metadata (`certificateSubject`, `certificateSerial`, `certificateIssuer`), all persisted for audit.

## 3. Enable / disable configuration

```dotenv
FEATURE_CSC_DIGISIGN=false   # default; mock provider outside production
FEATURE_CSC_DIGISIGN=true    # requires CscDigiSignProvider to be implemented first
```

Storage of signed documents follows `FEATURE_S3_STORAGE` (local disk when off, S3 when on) and is independent of which provider signs.

## 4. Integration flow

1. An Administrative Head completes the S21–S25 review and approves.
2. `DpcProposalService::approve()` first calls `assertSignatureAvailable()` — see failure behaviour below.
3. The full proposal is rendered as the document to be signed. **All five sections**, not a header: a single approval covers every section, so the signed artefact must contain every section, or the signature attests to nothing useful.
4. `SignatureService::sign()` calls the bound provider.
5. The signed bytes are stored on the configured disk; provider, transaction reference, and certificate metadata are persisted to `signatures`.
6. The proposal is marked approved, the DPC ID is generated, and it is forwarded to CGPSC — all inside one transaction with the signature.

Steps 3–6 are already implemented and tested. Only step 4's provider is outstanding.

## 5. Failure behaviour

This is the part that matters most, and it is deliberately asymmetric.

| Environment | Flag | Behaviour |
|---|---|---|
| Non-production | off | Signs through the mock provider. The full workflow stays demonstrable in UAT while CSC's API is outstanding. |
| **Production** | **off** | **Approval is refused** with a business message: *"Digital signing is unavailable: CSC DigiSign is not enabled on this environment, and a DPC proposal may not be approved without a legally valid digital signature."* The proposal stays `submitted`, with no signature, no DPC ID, and no partial state. |
| Any | on, unimplemented | `RuntimeException` from the provider. Approval fails, transaction rolls back, nothing is half-written. |

**Production never falls back to the mock.** A promotion order carrying a development signature would be worse than no signature at all — it would look valid while being legally worthless, and would only be discovered when challenged.

The guard is keyed on the feature flag rather than a provider-name string, because the flag is the single switch that decides what is bound; checking anything else could drift out of step with what actually signs. It is enforced at the **service** layer, so any future caller inherits it.

The same rule applies to Pre-DPC approval (M3), which uses the identical guard.

## 6. Future API requirements

To implement `CscDigiSignProvider`, CGPSC must supply:

1. **eSign API endpoint and version** (CSC eSign 2.1/3.0 or their wrapper), per environment.
2. **ASP credentials** — the Application Service Provider ID and signing key, delivered out of band and stored as environment variables.
3. **Signer identification model** — how the AH's identity is asserted (Aadhaar eKYC, DSC token, or an HSM-backed organisational certificate). This affects whether the OTP step-up currently used as record-of-intent remains necessary.
4. **Document format** — whether the API signs raw bytes or requires PDF with a signature field. **This is the one open item that could affect application code:** the signed document is currently plain text, and a PDF requirement would mean rendering the proposal as PDF first. That belongs in the provider or a document-rendering service, not in business logic, but it is not a zero-cost change and should be settled early.
5. **Response contract** — transaction reference, certificate subject/serial/issuer, timestamp.
6. **Timestamping / long-term validation** requirements, if signatures must remain verifiable for years.
7. **Test environment** with test certificates.

## 7. Production considerations

- **Never enable the flag before the provider is implemented and tested against CSC's test environment.** Enabling it early converts every approval into a hard failure.
- **Signature verification.** Whatever CSC returns must be independently verifiable years later — that is the entire point in a service matter. Confirm retention of certificate chains and any timestamping tokens.
- **Storage.** Signed documents are retained permanently. With `FEATURE_S3_STORAGE=true` they go to S3; ensure versioning and object-lock are considered, since these are legal artefacts.
- **Do not log document contents or credentials.**
- **Rollout order:** enable in UAT against CSC's test environment, verify a signature externally, then enable in production. The flag makes this a per-environment decision.
- Enabling in production requires only: environment variables, credentials, and the provider registration already present in `AppServiceProvider`.

## 8. Current test coverage

- `tests/Feature/FeatureFlagTest.php` — the flag defaults off; the mock is bound while off; `CscDigiSignProvider` is bound when on.
- `tests/Feature/Dpc/DpcProposalClosureTest.php` — approval hard-fails with the CSC provider selected but unimplemented, leaving no partial state; **production refuses to sign with the mock**; non-production still signs so UAT stays demonstrable; the signed document contains all five approved sections.
- `tests/Feature/SignatureServiceTest.php` — the mock provider signs and persists metadata and file.
