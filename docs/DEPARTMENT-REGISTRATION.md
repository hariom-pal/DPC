# Department Registration (business decision, 2026-10-22)

The registration screen follows the CGPSC mock-up of 2026-10-22. It fills a
department's two seats, Administrative Head (Level-2, AH) and Authorized
Officer (Level-1, AO). It is used for a department's first registration and
whenever a seat changes hands later. Officers are not moved by an
administrator's transfer: the new officer registers again.

## Rules

| # | Rule | Where |
|---|---|---|
| 1 | One department dropdown listing every department. District, Place, PIN and contact e-mail come read-only from the Department Master and are never submitted. | `auth/register.blade.php`, `department-registration.js` |
| 2 | A department whose master record lacks any of those four cannot register. | `Department::hasRegistrationDetails()` |
| 3 | The officer's `.gov.in` e-mail is entered first. If the officer is already on the portal, the name comes from the portal record and cannot be edited, and the server keeps that name whatever the form sends. Designation and mobile are always entered fresh. | `register.lookup`, `RegisterDepartmentRequest::officers()` |
| 4 | Each officer takes an Aadhaar OTP (sent to the mobile UIDAI holds) and an e-mail OTP, every time, whether new or existing. | `RegistrationVerificationService` |
| 5 | The Aadhaar number is shown masked (`XXXX XXXX 1234`) and is not stored anywhere: not in the database, and not in the session, which holds only an HMAC of it. | same |
| 6 | Once verified, the e-mail, name, mobile and Aadhaar are locked. The server compares the Register submission against the verified record and refuses any change. Verification stays valid for `DPC_REGISTRATION_VERIFICATION_TTL` minutes (default 15). | `RegistrationVerificationService::matches()` |
| 7 | One Register button. A first registration fills both seats. A registered department may fill one seat or both. | `RegisterDepartmentRequest::after()` |
| 8 | An officer has one account for life. An officer already on the portal keeps their account and password and is posted to the new department; their previous posting is retired. Only a new officer gets an account and credentials. | `RegistrationService` and `DepartmentAssignmentService::assign()` |
| 9 | The same officer, department and seat: refused. | `RegistrationService::conflict()` |
| 10 | The department's AO registering as its AH, or the reverse: allowed after the registrant ticks a confirmation. Their previous seat becomes vacant. | same |
| 11 | A seat that is already held: the holder is released. The account stays and can sign in, but has no department until a new registration posts it. The holder is told by SMS, e-mail and in-app notification. | `RegistrationService::releaseSeat()`, `SendSeatChangeNotification` |
| 12 | A first registration marks the department Registered, which keeps it on the Administrator's posting screen. There is no OTP step after Register because both channels were already verified. | `RegistrationService` |

## Aadhaar: change to decision Q1

Decision Q1 removed Aadhaar from the whole portal: login uses an identifier,
a password and OTP. The 2026-10-22 decision brings Aadhaar back for one purpose
only: proving an officer's identity by UIDAI OTP at department registration.
Login and Self-Inspection are unchanged.

## Aadhaar OTP provider

| Setting | Effect |
|---|---|
| `FEATURE_UIDAI_AADHAAR_OTP=false` (default) | The mock provider is used. No code is sent; `DPC_AADHAAR_OTP_MOCK_CODE` (default `123456`) is accepted. |
| `FEATURE_UIDAI_AADHAAR_OTP=true` | UIDAI OTP API 2.5, then an `<Auth>` request carrying the OTP, both over the CSC authentication API (`App\Integrations\Uidai`). |

For the `uidai` provider, the following come from CSC and UIDAI and go in
`.env` (see `.env.example`):

- **From CSC:**
  - `CSC_AUTH_BASE_URL` and `CSC_AUTH_ENDPOINT`.
  - `CSC_AUTH_CLIENT_ID` and `CSC_AUTH_TOKEN`, issued to DPC.
- **AUA values:** `DPC_UIDAI_AUA_CODE`, `DPC_UIDAI_SUB_AUA_CODE` and `DPC_UIDAI_LICENCE_KEY`, if CSC requires them.
- **From UIDAI:** `DPC_UIDAI_ENCRYPTION_CERTIFICATE`, the path to UIDAI's public encryption certificate for UAT or production. This is not the certificate inside UIDAI's replies; that one is for signing only.

The provider never throws. Every failure reaches the officer as a readable
message.

What is proven and what is not:

- **Proven by tests, using recorded responses and no network:**
  - the request formats;
  - the PID encryption;
  - the CSC request hash recipe;
  - the response handling.
- **Not yet proven:** the plain OTP `<Auth>` request has never been run against
  CSC or UIDAI. It follows CSC's Java reference. Run it once on UAT with real
  credentials before production.
- **If UIDAI answers error 501:** try the certificate expiry (`ci`) as an IST
  date.
