# e-DPC Portal — Documentation

**Chhattisgarh Public Service Commission · Online Departmental Promotion Committee**

Engineering complete, 2026-08-19. Four documents, one for each audience.

| Document | For | Read it to |
|---|---|---|
| **[SYSTEM_USER_MANUAL.md](SYSTEM_USER_MANUAL.md)** | Officers and Commission users — no technical knowledge needed | Operate the portal. Every screen, every button, every workflow, with a demonstration walkthrough and troubleshooting |
| **[BUSINESS_DECISION_REGISTER.md](BUSINESS_DECISION_REGISTER.md)** | CGPSC / business | See the **eight** policy questions the system deliberately does not decide, each with options, a recommendation and its impact |
| **[ARCHITECT_HANDBOOK.md](ARCHITECT_HANDBOOK.md)** | Architect / technical lead | Understand the architecture, the twenty-five decisions behind it, the freezes, the quality gate, and what remains before production |
| **[DEVELOPER_HANDOVER.md](DEVELOPER_HANDOVER.md)** | A developer joining with no context | Get it running and change it safely — structure, conventions, database, testing, PPT and SRS mapping |

## The source of requirements

`ODPC_NEW_FINAL.pptx` (52 slides, cited as `S1`–`S52`) and `CONVENTIONAL DPC PROCESS.pptx` (7 slides, `C1`–`C7`) are **the authoritative statement of what this system must do**. They are kept here, not archived, because code comments throughout the application cite slide numbers and there is nowhere else to check them.

## What was archived, and why nothing was deleted

`archive/2026-08-19-pre-final/` holds the working record of construction: the architect review, the architecture-decision register, the milestone summary, the project timeline and progress reports, the technical-debt register, the pending-business-decisions register, the event catalogue, the UAT and demo scripts, the per-milestone design documents, the deployment runbooks, the integration notes and the worklog.

**Their substance is carried into the four documents above.** They are archived rather than deleted because they are the audit trail of how the system came to be the way it is — including the reasoning behind decisions that would otherwise look arbitrary, and the record of defects found and how. Permanent deletion is a separate decision for after final approval.

Two things in there are worth knowing about specifically:

- **`deployment/EVIDENCE-TABLE-PERMISSIONS.md`** is an operational runbook that must be applied at production rollout. It is summarised in the Architect Handbook §13, but apply the runbook itself.
- **`archive/DPC- SRS.docx`** is an older specification that describes features appearing in neither presentation deck. **The decks govern.** Do not implement from the SRS without a ruling — see Developer Handover §14.
