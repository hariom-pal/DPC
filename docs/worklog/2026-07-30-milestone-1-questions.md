# Milestone 1 — Architect Questions

The following decisions require your approval. Each is currently implemented with a safe default (or deferred) so development can continue, but they should be confirmed before the affected areas are relied upon in production.

---

## Q1 — Production SMS / Email gateway
**Title:** Which SMS and transactional-email providers should deliver OTPs and credentials?

**Background:** OTP codes and account credentials are sent over SMS + email. M1 uses a `log` SMS driver and Laravel's `log`/`array` mailer — nothing is actually delivered yet. The `SmsService` is written so a real gateway is a drop-in.

**Why this decision is required:** No OTP/credential can reach real users until a gateway is integrated; government projects usually mandate a specific empanelled provider and a DLT-registered sender ID.

**Available options:**
1. Government SMS gateway (e.g. state/NIC SMS gateway) + government SMTP relay.
2. Commercial provider (MSG91 / Gupshup / Textlocal) + SES/SMTP.
3. Defer until a later milestone; keep the log driver for now.

**Pros:**
1. Compliance, trust, often free for gov use.
2. Fast integration, good deliverability/APIs.
3. No blocker to feature development now.

**Cons:**
1. Onboarding/paperwork, DLT template approvals.
2. Cost; vendor onboarding.
3. Flows remain untested end-to-end against a real gateway.

**Recommendation:** Option 1 (government gateway) for production, integrated in a dedicated milestone; keep the log driver until credentials are provided.

**Future impact:** Determines the `SmsService` driver and a mail transport config; affects OTP delivery latency and audit of delivery receipts.

**Default action if you approve the recommendation:** Keep the log driver now; add a government-gateway driver when credentials/DLT templates are supplied, with no calling-code changes.

---

## Q2 — Identity model reconciliation (single .gov email + two mobiles)
**Title:** Confirm mobile-primary login with a nullable, unique email.

**Background:** The registration screen (PPT S6) exposes **two distinct mobiles** (AO, AH) but a **single department .gov email**. The Q1 auth decision was "email or mobile + password." A single email cannot uniquely identify two accounts.

**Why this decision is required:** It determines the unique login identifier and how each officer is verified.

**Available options:**
1. **Mobile is the primary unique login; email is nullable/unique.** The AO (registrant) holds the department email and verifies email + mobile; the AH logs in by mobile and verifies mobile only. *(implemented)*
2. Require a distinct official email per officer (adds fields not shown in the PPT).
3. Email-only login (contradicts the two-mobile design of the PPT).

**Pros:**
1. Faithful to the PPT (two mobiles, one dept email); every user has a unique mobile; no invented fields.
2. Uniform email+mobile verification for both officers.
3. Simplest username model.

**Cons:**
1. AH has no email on file (mobile-only channel).
2. Extra registration fields beyond the PPT.
3. Doesn't match the two-mobile reality.

**Recommendation:** Option 1 (implemented).

**Future impact:** Affects notifications to the AH (SMS-only), password reset channels, and how CGPSC roles (admin/chairman/member) — which do have unique emails — authenticate.

**Default action if you approve the recommendation:** Keep the implemented mobile-primary model; AH notifications go by SMS.

---

## Q3 — E-signature mechanism (replacing Aadhaar signing)
**Title:** Confirm OTP step-up + immutable SignEvent as the "digital signing" of record.

**Background:** The PPT signs actions via "Aadhaar No. + Aadhaar OTP." Per Q1 (approved) Aadhaar is removed. M1 builds the foundation: a fresh OTP to the actor's verified channel, then an immutable `SignEvent` (actor, channel, identifier, IP, user-agent, timestamp) recorded against the signed record.

**Why this decision is required:** Promotion orders are contested in CAT/High Court; the "record of intent" must be legally defensible.

**Available options:**
1. OTP step-up + immutable SignEvent + audit entry. *(foundation implemented)*
2. Password re-confirmation at signing (proves session, not intent).
3. Full PKI / DSC digital signatures (USB token / eSign/Aadhaar-eSign service).

**Pros:**
1. Strong intent evidence, no extra hardware, cheap.
2. Simple.
3. Strongest legal standing (IT Act digital signature).

**Cons:**
1. Not a cryptographic signature.
2. Weak evidence of intent.
3. Costly, hardware/onboarding, UX friction.

**Recommendation:** Option 1 now; keep the design open so PKI/eSign can be added later if legal review requires it.

**Future impact:** Wired to proposal submission, proceedings sign-off and order generation in later milestones.

**Default action if you approve the recommendation:** Use OTP step-up + SignEvent for all signing actions.

---

## Q4 — First-login password policy
**Title:** Force a password change on first login for system-issued credentials?

**Background:** Registration issues system-generated temporary passwords via SMS/email. There is currently no forced rotation on first login.

**Why this decision is required:** Security best practice for issued credentials; affects the login flow.

**Available options:**
1. Force password change immediately after first successful verification/login.
2. Optional change; allow keeping the issued password.

**Pros:**
1. Removes the issued secret from circulation; stronger posture.
2. Simpler UX.

**Cons:**
1. One extra step at first login.
2. Long-lived issued secret in SMS/email history.

**Recommendation:** Option 1 (force change on first login), implemented alongside the password-reset flow.

**Future impact:** Adds a "must_change_password" gate and a change-password screen.

**Default action if you approve the recommendation:** Add forced first-login password change in the next milestone that touches auth (with password reset).
