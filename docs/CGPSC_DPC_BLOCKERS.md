# CGPSC DPC — Blockers

**For:** CGPSC, the project sponsor, and whoever owns the production rollout
**Version 1.0 · 2026-08-22**

---

## Read this first

**Nothing on this page blocks a demonstration, a business review or a handover.** The portal runs end to end today with every integration switched off, and there is a seeded dataset that walks the whole process. This document is about the gap between *working* and *live*.

There is **one true blocker** — a procurement, not a piece of software. Everything else is a decision, a credential, or a database grant somebody has to run.

| | Item | Nature | Owner | Blocks |
|---|---|---|---|---|
| **B-1** | **No Government SMS / e-mail gateway** | **Procurement** | CGPSC | **Go-live.** Nobody can complete OTP verification, and OTP is how a signature is taken |
| **B-2** | CSC eSign interface contract not supplied | Information from a supplier | CGPSC + CSC | Real digital signing in production |
| **B-3** | Evidence-table database grants not applied | Deployment action | DBA | Nothing functionally; it is the control that makes evidence tamper-evident at the database |
| **B-4** | Capacity plan and backup scope not set | Infrastructure decision | CGPSC IT | Nothing functionally; retention is permanent by ruling and sized for nothing |
| **B-5** | Three business decisions block publishing a promotion list | Policy | CGPSC | Publishing a result in the affected cases. Detailed in **Pending Business Decisions** |

---

## B-1 — No SMS or e-mail gateway  ·  **the one true blocker**

**What it is.** The portal sends a one-time password at registration and at every legally significant signature. In this build the message is written to the application log; there is no gateway behind it, because none has been procured.

**Why it is the blocker and not merely a gap.** OTP is not a convenience here — it *is* the signature. An Administrative Head approving a proposal, a Member approving a result, a Commission signing a circulation note: each is recorded as a signature because the actor proved possession of a registered channel at that moment. **Without delivery, no officer can register, no officer can sign, and the portal cannot be used at all in production.** Every other item on this page degrades something; this one stops everything.

**What has been built around it.** The gateway sits behind a Provider contract, flagged off (`FEATURE_SMS_GATEWAY`, `FEATURE_EMAIL_GATEWAY`), and switching it on is credentials plus a flag — no code change, no redeployment of business logic. Live state is visible to the CGPSC Administrator at `/admin/integrations`, because a flag nobody can see is a flag nobody can trust.

**Also built because of it:** the **in-app notification channel** is deliberately *outside* the feature flag. It calls nothing external, and gating it would have switched off the only channel that works. Every notification the portal raises is therefore visible from the bell in the header today, with no gateway at all — which is what makes a demonstration and a UAT possible while B-1 is open.

**Update 2026-10-26: SMS integration still pending.** The application log no longer stands in for SMS OTP delivery.
- **An SMS OTP is never sent and never logged while `FEATURE_SMS_GATEWAY=false`.** The log records only that it was not sent.
- **E-mail OTP is delivered synchronously.** A mail failure is reported on screen, and the unreceived code is withdrawn.
- **Department Admin registration and AO/AH accounts** need only the e-mail code. The mobile number carries a recorded waiver, and the screen says so.
- **Self-Inspection signs in by mobile OTP only.** Without SMS, and with the fixed OTP off, its sign-in shows a clear "SMS unavailable" message. With `DPC_OTP_FIXED_ENABLED=true` (local or UAT), it accepts the fixed code. It never shows a false "code sent", and it does not fail with a server error.
- **Unchanged on purpose:**
  - The `identity_factors` driver is not used in place of SMS (judged too weak).
  - The UAT authentication driver is unchanged.
  - The fixed OTP (`DPC_OTP_FIXED_*`, default off) is accepted only when `APP_ENV` is `local`, `testing` or `uat`, never on production, whatever `.env` says. With `DPC_OTP_FIXED_MOBILE=true` it also proves the mobile number in place of a waiver.

Self-Inspection OTP on UAT therefore waits for this gateway.

**Lead time is the concern.** This is the longest item on the list and the one to start first. It is not an engineering task and no amount of engineering shortens it.

**What to ask for.** A government SMS gateway account with a registered sender ID, and an SMTP relay or transactional e-mail account on a `gov.in` domain. Both are ordinary government IT provisioning.

---

## B-2 — The CSC eSign interface contract

**What is missing.** One fact: whether CSC DigiSign / CSC eSign signs **arbitrary bytes** or requires a **PDF**.

**Why it matters.** Documents in this build are rendered as HTML. If the signing service requires a PDF, a renderer has to be added — a contained piece of work behind the `DocumentRenderer` contract that already exists, touching no workflow and no approval path. If it signs bytes, nothing is needed.

**What works today.** The whole signing pipeline: every document is rendered, stored, hashed with SHA-256, signed through the provider interface, and independently verifiable. A mock provider signs in development. **Production refuses to sign rather than pretending to** — there is no code path in which an unsigned document is presented as signed.

**This is recorded as BD-4** in the Pending Business Decisions register, because it needs an answer from CGPSC's supplier rather than a choice by anyone here.

---

## B-3 — The evidence-table database grants

**What it is.** Twelve tables in this system hold evidence: the calculation and its results, the Commission's allotment and schedule, the sitting with its corrections, holds and documents, the review chain, and the circulation note. Every one is immutable in the application — each model refuses to be updated outside a narrow set of lifecycle columns, and refuses to be deleted at all.

**Why that is not sufficient.** Those guards live in the ORM's model events, and a query-builder mass update bypasses model events entirely. That is a characteristic of the framework, not a defect, and it cannot be closed from inside the application: any guard added lives in the layer being bypassed. The honest statement of the guarantee is:

> No application code path can edit a completed calculation, and no developer can do so accidentally — but a developer with database access can.

For a promotion record that may be produced in the Central Administrative Tribunal or the High Court, that distinction matters. **Database permissions are what close it.**

**What is now done, and what remains.** This used to be a markdown runbook. It is now a command:

```bash
php artisan security:evidence-grants --user=odpc_app --host='10.%' --database=odpc
#   → a DBA runs the emitted SQL, then:
php artisan security:evidence-grants --verify
```

`--verify` attempts each write the grants should refuse, as whichever account the application actually connects as, and reports what the database did. Every probe is written so that no row can match, so a refusal proves the grant and a success is harmless.

**Why it became a command.** The runbook was written during M5 and named **two** tables. M6 added the allotment and schedule, M7 the proceeding with its corrections, holds and documents, M8 the review chain, M9 the circulation note — **twelve tables, of which the runbook covered two.** Nothing said so, because a document cannot notice a migration. Ten of them would have gone to production unprotected with the deployment checklist ticked. The list now lives in configuration and a test compares it against every model that guards itself, so the next one cannot be forgotten.

**The one mistake to avoid.** Do not revoke `UPDATE` outright. Completing a calculation is a single `UPDATE` of one column, and a document legitimately gets published after it is generated — so the grants are **column-level**, and the command emits them that way. Revoking `UPDATE` wholesale stops every calculation from completing.

**Run `--verify` at every release, not only at first deployment.** A grant can be widened later by an unrelated change.

---

## B-4 — Capacity plan and backup scope

**What it is.** Import files, uploaded evidence, error reports and signed documents are retained **permanently**, by ruling — correct for evidence, and sized for nothing. Nobody has yet said how much storage that needs, on what medium, with what backup and what restore test.

**Why it is on this page.** It blocks nothing today and will block everything eventually. A system whose whole value is that it can explain a promotion made ten years ago needs somebody to have decided, before go-live, where those ten years of documents live and how they are proved to be recoverable.

**What to decide.** Expected DPCs per year, average officers per DPC, retention medium, backup frequency, and — the part usually skipped — a restore test schedule. A backup nobody has restored is not a backup.

---

## B-5 — Business decisions that block publishing

Three of the eight open policy questions prevent a promotion list being *published* in the cases they touch. They are set out in full, with options, recommendations and impact, in **`CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`**; in one line each:

- **BD-2 / BD-3 — reserved-category relaxation.** Whether relaxation is automatic or the Committee's discretion, and how widely it applies. **The most limiting open item**, because a reserved category short of eligible officers is common rather than exceptional. Until answered, such a DPC produces a fully visible but *provisional* result that cannot be published.
- **BD-14 — the effective date of a circulated promotion.** Publication of a circulation note is refused, naming the decision. The note itself is produced, signed and verifiable.
- **BD-15 — whether a CR-shortfall hold may be resolved by Circulation or must go to a Review.** Today those officers cannot be resolved by any route, which is a real gap in service to them.

And one that blocks nothing but should be answered **first**:

- **BD-9 — which waiting list an officer joins when their own category has no vacancy.** Every other open item marks a result provisional or refuses outright. **This one raises no flag**: the system considers the calculation complete and would publish it. A confidently wrong published list is worse than a blocked one.

**Six of the eight are applied by changing a setting**, not by writing software.

---

## What is *not* a blocker, and why it might look like one

| Looks like a blocker | Actually |
|---|---|
| Documents are HTML, not PDF | Behind a contract. Depends on B-2, and swaps in without touching the approval path |
| Integrations are all switched off | Deliberate (AD-13). The whole pipeline runs and demonstrates with nothing connected; each has a defined fallback, so "off" never means "broken" |
| Notifications are not reaching phones | B-1. The in-app bell is complete and does not depend on it |
| A result says "provisional" and will not publish | The system declining to assert a rule CGPSC has not made. That is the design |
| A screen says "Pending Business Decision (BD-n)" | The same, with the question named so it can be answered |
| A circulation cannot proceed because the original allottee has left the Commission | Recorded as TD-28. Only the Member who conducted the original DPC may act, and there is no escape hatch — right for accountability, but it needs a standing rule on absent named actors. **Affects no current case**, and inventing the rule would be inventing service law |
| A department cannot file its first Pre-DPC | Its Department Post master is empty. The screen says so rather than appearing broken. Who populates it is BD-18 — and since 2026-08-24 the master is the CGPSC Administrator's, because Schedule-IV is a published Government Order and not departmental data |
| The archived SRS describes features that do not exist | The decks govern (AD-1). Per-member voting, free-text minutes and a separate promotion-order module appear in neither deck and were not built. BD-17 asks what standing the SRS has |

---

## Recommended order of work

1. **Start B-1 now.** It is the only true blocker, it is pure lead time, and nothing here shortens it.
2. **Ask CGPSC's CSC supplier the B-2 question.** One sentence of answer; it decides whether a PDF renderer is scheduled.
3. **Answer BD-9** (B-5). It blocks nothing, which is exactly why it is dangerous, and it is the only one of the four that can produce a wrong published list rather than a blocked one.
4. **Answer BD-2 / BD-3, BD-14, BD-15.** These unblock publishing. Six of the eight are a setting.
5. **Apply and verify the grants** (B-3) as part of the first production deployment, and re-verify at every release.
6. **Settle the capacity plan** (B-4) before the first real DPC is signed, not after.
