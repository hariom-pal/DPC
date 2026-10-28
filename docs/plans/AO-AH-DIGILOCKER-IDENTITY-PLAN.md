# Identity Verification of Authorized Officers and Administrative Heads through DigiLocker

**Status:** Proposal for CGPSC confirmation. Nothing has been built.
**Date:** 27 October 2026
**Portal:** ODPC Portal (CGPSC DPC)

---

## 1. Purpose

Every Authorized Officer (AO) and Administrative Head (AH) acts on promotion proposals that become legal records. Today the Department Admin types the officer's name and contact details, and the portal trusts them. This proposal adds an **independent identity check through DigiLocker** before the officer can use the portal, so that:

- the person who signs in is the person the Department Admin created;
- the name, date of birth and gender on record match the officer's Aadhaar, as held in DigiLocker;
- every correction to an officer's details is recorded: who made it, when, and what changed.

## 2. The flow at a glance

| Step | Who | What happens |
|---|---|---|
| 1 | Department Admin | Creates the AO or AH with **name, date of birth, gender**, designation, role, official e-mail and mobile number. The portal shows a one-time temporary password. |
| 2 | AO / AH | Signs in for the first time with the temporary password. |
| 3 | AO / AH | **Verifies identity through DigiLocker**: signs in to DigiLocker and gives consent. The portal receives name, date of birth and gender. |
| 4 | Portal | **Compares** the DigiLocker name, date of birth and gender with what the Department Admin entered. |
| 5a | If all three match | The officer continues to step 6. Name, date of birth and gender are marked "verified through DigiLocker" and locked; from then on only the Portal Super Admin can correct them, and a correction requires re-verification (§3.4A). |
| 5b | If any one differs | The officer cannot continue. The Department Admin is told what did not match, corrects the details (every edit is recorded), and the officer verifies through DigiLocker again. |
| 6 | AO / AH | Reads the notice and accepts the **undertaking** that the mobile number is the one linked to their Aadhaar, then verifies the **mobile** and the **e-mail** by one-time passwords (OTP). |
| 7 | AO / AH | Sets a new password of their own. The temporary password stops working. |
| 8 | AO / AH | Reaches the dashboard. Only now can they work on proposals allotted to them. |

## 3. Details

### 3.1 Creating the AO / AH (Department Admin)

- **New mandatory fields:** **date of birth** and **gender**. Name, designation, role, official e-mail (`gov.in`) and mobile number are already collected.
- **Name:** must be entered as it appears on the officer's Aadhaar, without salutation. The form says so.
- **The account cannot be used** until the officer completes DigiLocker verification (§3.2).

### 3.2 DigiLocker verification (AO / AH, first sign-in)

- **How the officer verifies:** after the temporary password, the officer is sent to DigiLocker, signs in there and agrees to share their details with the ODPC Portal. Signing in to DigiLocker needs only the officer's own DigiLocker account; the Aadhaar number is never typed into the ODPC Portal.
- **What the portal receives:** name, date of birth, gender, and whether the DigiLocker account is Aadhaar-verified. A DigiLocker account that is **not Aadhaar-verified is not accepted**.
- **What the portal stores:**
  - the fact and time of verification;
  - the name, date of birth and gender DigiLocker returned, kept for the audit trail.
- **What the portal does not store:** the Aadhaar number, the Aadhaar document or the photograph. These are never requested.

### 3.3 Matching rules

| Field | Rule |
|---|---|
| Date of birth | Must be **exactly** the same. |
| Gender | Must be **exactly** the same. |
| Name | Compared after ignoring capital and small letters, extra spaces, dots, and salutations (Shri, Smt., Dr., etc.). Anything else, such as initials versus full name or a spelling difference, is treated as **not matching** (see Q1). |

### 3.4 When the details do not match (Department Admin)

- **The officer is told which field did not match.** DigiLocker's values are shown only to the officer, never to anyone else.
- **The Department Admin is notified.** The officer is listed as "Identity verification pending — details do not match".
- **Before the officer is verified, the Department Admin may edit any detail of the officer:** name, date of birth, gender, designation, e-mail and mobile. Once DigiLocker verification succeeds, name, date of birth and gender can be corrected only by the Portal Super Admin (§3.4A).
- **A reason is mandatory for every edit.** Each edit is recorded with:
  - who made it;
  - the date and time;
  - the field;
  - the old value and the new value;
  - the reason.

  The record is permanent, and is visible in the officer's History and in the CGPSC Audit Trail.
- **After the correction,** the officer verifies through DigiLocker again.
- **If e-mail or mobile changes,** that channel must be verified again by OTP. This is current behaviour.

### 3.4A Correction of DigiLocker-verified identity details

Once an AO/AH has successfully completed DigiLocker identity verification, the verified **name, date of birth and gender are locked** against further modification.

The Department Admin and the officer cannot modify these verified fields. **Only the CGPSC Portal Super Admin** may correct them, under the following conditions:

1. **A reason is mandatory** for every correction.
2. **Every correction is recorded in the Audit Trail:** the administrator's identity, date and time, field name, previous value, updated value and reason.
3. **Verification status is cancelled:** any change to a verified identity field immediately invalidates the officer's identity verification.
4. **Access is suspended:** the officer's active sessions end, and DPC-related functions stay suspended until re-verification is complete.
5. **Re-verification is required:** the officer completes DigiLocker verification again against the updated details.
6. **Access is restored on success:** the identity fields are locked again and normal access resumes.
7. **History is kept:** previous verification records and the correction history are preserved for audit.

**No administrative correction is a substitute for DigiLocker re-verification.**

### 3.5 Mobile number

- **Notice beside the field:** *"Enter the mobile number linked to your Aadhaar."*
- **Mandatory undertaking (checkbox):** *"I declare that the mobile number given above is linked to my Aadhaar. I am responsible for any incorrect information."* The final wording is for CGPSC (Q4); it is shown in English and Hindi.
- **What the undertaking records:**
  - who accepted it;
  - the date and time;
  - the IP address;
  - the version of the wording.
- **Changing the number:** a new undertaking and a new OTP are required.
- **Limitation:** this is a **self-declaration**. The OTP proves the officer holds the phone; it does not prove the number is the one linked to Aadhaar.
  - DigiLocker's published specification does not return the mobile number. Whether its e-Aadhaar data carries a mobile hash is **not confirmed**, and will be checked once onboarding gives test access.
  - If it does, an automatic check can be added later.

### 3.6 E-mail and mobile OTP

- **Unchanged from today:** the e-mail OTP is sent by e-mail and the mobile OTP by SMS.
- **SMS gateway:** until it is in service, a fixed test OTP works only on local and UAT systems, never in production.

### 3.7 Who is covered

- **AO and AH:** created by the Department Admin. This proposal.
- **Department Admin and Portal Super Admin:** see Q3.

### 3.8 Development and testing

- **Until onboarding is complete:** the portal uses a **simulated DigiLocker** on local and UAT systems, so the whole flow can be tested.
- **Switching to real DigiLocker:** a configuration change only, with no further development.

## 4. Questions for CGPSC

| # | Question | Our suggestion |
|---|---|---|
| Q1 | **How strict is the name match?** Should initials ("S. K. Sharma") count as a match for the full name ("Suresh Kumar Sharma")? | No. A difference is corrected by the Department Admin, so the record holds the Aadhaar name. |
| Q2 | **What if an officer cannot use DigiLocker** (no account, DigiLocker unavailable, Aadhaar not linked)? Should anyone be allowed to approve the officer manually, with a recorded reason? | Allow the **Portal Super Admin** (not the Department Admin) to approve manually, with a mandatory reason and supporting document, recorded in the Audit Trail. |
| Q3 | **Should the Department Admin and the Portal Super Admin also verify through DigiLocker** at their first sign-in? | Yes, for the Department Admin. The Portal Super Admin is created on the server; DigiLocker can be added at first sign-in. |
| Q4 | **Wording of the mobile undertaking** in English and Hindi. | Text in §3.5, for CGPSC to approve or replace. |
| Q5 | **How many failed DigiLocker attempts** before the officer must contact the Department Admin? | 3 attempts in 24 hours. |
| Q6 | **How long should the DigiLocker-returned details be kept?** | As long as the officer's account record, as part of the audit trail. |

## 5. Dependencies

- **DigiLocker Requester onboarding:** with API Setu / DigiLocker, in process. It provides the client ID and secret, the approved return URL, and test (sandbox) access.
- **SMS gateway:** for the mobile OTP in production. This is an existing open item.
- **SMTP (mail server):** for the e-mail OTP in production.

## 6. Risks

| Risk | Mitigation |
|---|---|
| Names written differently in Aadhaar and service records. | Department Admin correction with a recorded reason (§3.4); manual approval route (Q2). |
| DigiLocker unavailable at the time of first sign-in. | The officer can retry; manual approval route (Q2). |
| Mobile number not actually Aadhaar-linked. | Undertaking with recorded consent (§3.5); automatic check later if DigiLocker provides it. |

## 7. Work involved (after CGPSC confirmation)

1. Date of birth and gender on the officer record and on the Department Admin's form.
2. DigiLocker integration behind a provider, with a simulated provider for local and UAT.
3. Identity-verification step in the first sign-in, with the matching rules and attempt limit.
4. Department Admin correction screen (before verification), with a mandatory reason and a field-level change history.
5. Portal Super Admin correction of verified identity fields (§3.4A): mandatory reason, Audit Trail record, verification cancelled, sessions ended, DPC access suspended until DigiLocker re-verification.
6. Mobile notice and undertaking, with the record of acceptance.
7. Manual approval route, if Q2 is accepted.
8. Automated tests for every rule above.
