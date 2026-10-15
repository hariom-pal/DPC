# Suggestions & Improvements

**e-DPC Portal · Chhattisgarh Public Service Commission**
Raised while implementing the change register of 2026-08-24, and **all fourteen implemented on 2026-08-25** on the instruction to complete everything. **Item 15** was raised by the register of 2026-08-26 and recorded, not built, on that register's own instruction. **Items 16 to 21** were raised while building the Vacancy and Merit engines on 2026-08-27 and are likewise recorded, not built.

**Read the "As built" note under each item.** Four of the fourteen could not be built as proposed, and in three of those cases the reason was that the proposal itself was wrong — writing the code found what reading the code had not. Those notes are the useful part of this document now.

---

## How to read this

The register was explicit: implement what it asks for, invent nothing, and record anything better as a proposal rather than doing it. Every item below is a place where the work turned up something worth changing that the register did not ask for. Each was written as a proposal with what it would cost and what it buys — and each now carries an **As built** note saying what was actually done and why it differs where it does.

Fourteen items. **Four High, six Medium, four Low.** All fourteen are now built.

**Four did not survive contact with the code**, and that is the part worth reading:

- **#1** was worse than described. The proposal said one officer might *see* another's DPC; the code was in fact **transferring the officer out of their own department**. And the recommended per-department unique index would have contradicted a register requirement, so the fix is different.
- **#4** was impossible as recommended. A candidate account has **no mobile and no e-mail** — a Seniority List carries neither — so the proposed OTP reset had no channel to send anything on.
- **#7** could not use the proposed table name: `recruitment_rule_promotion_sources_promotion_from_post_id_foreign` is 65 characters and MySQL stops at 64.
- **#14** was "no change", and remains none.

Two things are deliberately *not* here. Questions of business policy — which member categories are mandatory, what language a signed order is issued in — are decisions for the Commission and live in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` as BD-22 to BD-26. And anything already recorded as technical debt in the Architecture Handbook stays there.

| # | Title | Priority |
|---|---|---|
| 1 | Employee number is unique portal-wide, and two departments can legitimately reuse one | **High** |
| 2 | Existing installations still hold the old committee size bounds | **High** |
| 3 | A DPC raised before this register has no Pre-DPC recorded against it | **High** |
| 4 | No self-service password reset, and candidates are the first users to need one | **High** |
| 5 | Split the dual-language captions inherited from the PPT screens | Medium |
| 6 | The landing page's notice board is a language file, not data | Medium |
| 7 | `recruitment_rule_feeders` still speaks the old vocabulary | Medium |
| 8 | A candidate has two front doors and sees a different subset through each | Medium |
| 9 | Re-verify the recruitment rule at submission, not only at creation | Medium |
| 10 | Give the Commission Members master search and pagination | Medium |
| 11 | Retire the legacy import column aliases on a stated date | Low |
| 12 | An `artisan lang:check` for translators | Low |
| 13 | Balance committee workload across members | Low |
| 14 | The DPC serial is four digits, and was five | Low |

---

## 1. Employee number is unique portal-wide, and two departments can legitimately reuse one

**Description.** `users.employee_number` carries a portal-wide `UNIQUE` index, and `PromotionCandidateProvisioner` keys candidate accounts on it. Departmental employee numbers are issued *by the department*, so two departments can each hold an officer numbered `1024` without either being wrong.

**Reason.** The first department to have a Seniority List uploaded takes the number. The second department's upload then either matches its officer to **a stranger's account** (if the provisioner treats the number as the identity, which it does) or fails. The first outcome is worse than the second: it would show one officer another officer's DPC.

This has not happened, because the demonstration data uses distinct numbers throughout. It is not visible in testing and would surface in production, once, with a real officer's data.

**Impact.** Correctness and confidentiality — the highest-consequence item in this document. Scope is small: one index, one lookup, one migration.

**Recommended solution.** Make the identity `(department_id, employee_number)` rather than `employee_number` alone. Because a department is now an assignment rather than a column, the natural place is a `unique(department_id, employee_number)` on `user_department_assignments`, with the provisioner matching within the proposal's department. Keep the column on `users` for display and login, without the unique index; resolve a login collision by asking for the department, as several government portals do. Alternatively, and more cheaply, prefix the stored value with the department code — but that makes the officer type something they were never issued, so it is the worse of the two.


**As built (2026-08-25) — and the proposal understated the problem.** Reproducing it showed the code did not merely risk showing one officer another's DPC: `ensureCandidateRole()` called `assign()`, which **retires the incumbent posting**, so when Forest uploaded a list carrying a number Higher Education had already used, the officer holding it was *transferred into Forest* and lost their departmental role. A serving Under Secretary, moved by another department's data entry.

The recommended `unique(department_id, employee_number)` was **not** implemented, because it contradicts the register: item 2 and item 17 both say never create duplicate users, and a per-department identity creates a second account for a genuinely transferred officer — which `test_an_officer_transferred_between_departments_keeps_one_account` already pins. The portal cannot tell a transfer from a collision by looking at a number, and neither could the index.

What was done instead assumes neither reading:

- `entriesFor()` is **scoped to the departments the account has been posted to**, past or present. An officer never posted to a department cannot be the officer that department listed, so the disclosure closes whichever way the ambiguity is ruled.
- `ensureCandidateRole()` **never re-posts an existing account**. It grants the candidature only to an account between postings; an account already posted elsewhere is left exactly as it is.
- The mismatch is **reported** — a `conflicts` list beside the existing `skipped` list, shown on the screen and recorded on the timeline — so a person resolves it.

The underlying question is now **BD-27**.

*Files:* `PromotionCandidateProvisioner`, `PromotionCandidateTest` (+3 tests).
**Priority: High.**

---

## 2. Existing installations still hold the old committee size bounds

**Description.** Item 15 fixes the committee at three to six members besides the Chairman. `CommitteeMasterSeeder` now seeds `min_members = 4, max_members = 7` (whole-committee counts), and `config/dpc.php` carries 3 and 6 as the fallback. An installation seeded **before** 2026-08-24 holds a `committee_configurations` row saying 1 and 5, and that row wins — the resolver reads the master first, by design.

**Reason.** The register's numbers are correct on a fresh installation and silently absent on an existing one. A seeder cannot fix it, because seeders do not run on a live database, and a migration should not overwrite a bound an Administrator may have chosen deliberately.

**Impact.** The rule item 15 asks for is not in force where it matters most — on an installation that has been running. It is a data problem, not a code problem, which is exactly the kind that goes unnoticed.

**Recommended solution.** Add a **read-only reconciliation notice** to the Committee Configuration screen: when the stored bounds differ from `config('dpc.committee.*')`, say so, state both, and offer a single button that applies the configured values. That way the change is the Administrator's act and is audited as one, rather than a deployment quietly rewriting a rule. A one-line `artisan` command would also do, and is easier to run at the wrong moment.


**As built (2026-08-25).** As recommended. `CommitteeBoundsReconciler::drift()` compares the stored bounds **as the resolver reads them** — besides the Chairman — so a configuration of 4–7 with a mandatory Chairman is not reported against a configuration-free 3–6; they are the same rule written two ways, and reporting them would send an Administrator to fix something already correct. Only the `dpc_committee` type is checked; another committee's size is somebody else's rule. `apply()` writes through `MasterCrudService`, so it is stamped and audited like any other master edit, and records a timeline entry.

*Files:* `CommitteeBoundsReconciler`, `CommitteeConfigurationController`, its index view, one route, `CommitteeBoundsReconcilerTest` (6 tests).
**Priority: High.**

---

## 3. A DPC raised before this register has no Pre-DPC recorded against it

**Description.** `dpc_proposals.pre_dpc_proposal_id` is nullable, and migration `360007` deliberately backfilled it as `NULL` for every existing proposal.

**Reason.** The backfill could not be honest. Before this register a DPC named its posts directly and a Pre-DPC named a list of posts, so there is often more than one order that *could* have sanctioned a given proposal — and guessing produces a link that looks authoritative and is unfalsifiable. Leaving it null says "not recorded", which is true.

**Impact.** Historical proposals display "—" where a new one shows its sanctioning order, and `DpcRevisionService::open()` writes "Raised from Pre-DPC —". No calculation, approval or document is affected. But the gap is silent: nothing lists which proposals are unlinked.

**Recommended solution.** A read-only reconciliation screen for the Administrator: every proposal with no `pre_dpc_proposal_id`, the candidate orders that match its department, post pair and year, and a button to record one — with the person who recorded it and when. Deliberately manual, deliberately audited. If the Commission would rather not revisit history, the alternative is to state in the User Guide that proposals before this date carry no sanctioning link, which costs nothing and is honest.


**As built (2026-08-25).** As recommended. `PreDpcLinkReconciler` lists proposals with no sanctioning order and, for each, only the orders that could plausibly be it — same department, same post pair, and the same DPC Year where the proposal records one. Anything looser is not offered at all: an Administrator shown a long list of near-misses will pick one.

`link()` refuses three things at the service, not only in the request: a proposal that already has a link (a recorded link is never overwritten), an order from another department, and an order for a different post pair. The timeline entry names **who** identified it, because that is the only evidence there is — the link was decided by a person, not derived.

*Files:* `PreDpcLinkReconciler`, `PreDpcLinkController`, `admin/pre-dpc-links/index`, two routes, sidebar entry, `PreDpcLinkReconcilerTest` (7 tests).
**Priority: High.**

---

## 4. No self-service password reset, and candidates are the first users to need one

**Description.** There is no password reset anywhere in the portal — the User Guide says to contact the nodal officer or the Commission. That was defensible when every account belonged to a departmental officer or a Commission member. Item 17 has now created an account for **every officer on every Seniority List**.

**Reason.** The order of magnitude changed. A department has two or three logins; a single DPC can create forty candidate accounts, and a department runs several a year. Those users log in rarely — perhaps twice — which is exactly the usage pattern that forgets a password. The nodal officer becomes a password desk for the whole establishment.

**Impact.** Operational load, falling on one person per department, and a predictable route to shared or written-down passwords. It is also the most likely reason a candidate never sees the result the portal went to some trouble to publish for them.

**Recommended solution.** An OTP-based reset for the `promotion_candidate` role only, to the mobile number the department recorded on the Seniority List — the channel is already verified, the OTP machinery already exists, and the blast radius of a candidate account is a single read-only screen. Leave the other roles as they are: a reset for an Administrative Head who signs government orders should stay a deliberate, human act.


**As built (2026-08-25) — the recommendation was impossible, and the reason matters.** A Promotion Candidate account has **no mobile number and no e-mail**: a Seniority List carries neither, and `PromotionCandidateProvisioner` creates the account with both channels blank (stamped verified precisely because there is nothing to verify against). So "an OTP to the mobile the department recorded" had no mobile to go to. The proposal assumed a channel that does not exist.

The only thing left to authenticate a self-service reset against would be the three facts printed on the Seniority List — which are already accepted for *reading* one's own data (BD-19), and reading is not taking over an account.

So the reset stays with the department, which is where credential handover already sits: **`reissuePassword()`**, reached from the Seniority List screen, showing the new temporary password once. **The guard is the list, not the role** — the employee number must appear on *this* proposal's Seniority List, so a department can reissue for the officers it has named and for nobody else. Checking the role instead would let any department reset any candidate in the portal.

**This also uncovered a live defect.** `saveSeniorityEntries()` called `provision()` and *discarded the result*, so the temporary passwords were generated, hashed and thrown away — every candidate account was unusable, and no test noticed because none of them tried to log in with a generated password. The result is now flashed and rendered once, which is what the provisioner's own docblock had always claimed happened.

*Files:* `PromotionCandidateProvisioner`, `DpcProposalService`, `DpcProposalController`, `_candidate-accounts` partial, seniority-list view, one route, `PromotionCandidateTest` (+7 tests).
**Priority: High.**

---

## 5. Split the dual-language captions inherited from the PPT screens

**Description.** Fifty-four catalogue values hold both languages in one string — `'नाम / Name'`, `'Pay Scales / वेतनमान'`, `'Register / पंजीकृत करें'`. They came from the approved PPT screens, where a single-language portal had no other way to serve both audiences.

**Reason.** The portal now has a language switch, so a Hindi user reading `नाम / Name` is being shown a language they did not choose, and an English user the same. It also produces the odd result that the *English* catalogue contains Devanagari.

**Impact.** Presentation only, on many screens. No behaviour changes.

**Recommended solution.** Split each value at the separator: the English half into `lang/en`, the Hindi half into `lang/hi`. Mechanical, and safe because the tests compare rendered output. **It changes approved screen designs**, which is why it is a proposal and not a change — under the project's change-control rules that needs sign-off.


**As built (2026-08-25).** Sixty-four values split. The work was almost entirely one-sided: the Hindi catalogue already held a single language, so it was the *English* values carrying `Name / नाम`.

**Three are deliberately left as they were** — `landing.hero.subheading`, `ui.layouts.portal.brand_title` and the Government Web Service badge. Those are institutional identity rendered in both scripts by convention on a government masthead, not UI labels.

**A note on the tooling, because it nearly caused a silent fault.** The first setter matched a leaf key by name at its indent level, which found the first key of that name *anywhere in the file* — so `evaluation_chart.subheading` and `working_officers.subheading` resolved to the same line and one silently overwrote the other. Two values were clobbered before it was caught. The setter was made parent-aware, the two were restored, and **every one of the 64 paths was then verified to hold its intended value**, plus a diff against `HEAD` confirming no other key drifted.

*Files:* 8 catalogue files, `SelfInspectionConfigurationTest` (one assertion had been asserting the Hindi half on an English screen).
**Priority: Medium.**

---

## 6. The landing page's notice board is a language file, not data

**Description.** The four notices on the public landing page live in `lang/{en,hi}/landing.php`, each with a day, a month, a title and a body.

**Reason.** They are content, and content dated August 2026 will be stale by the time anyone reads it. Publishing a notice currently requires a developer and a deployment, which means in practice that notices stop being published.

**Impact.** The most visible page in the portal slowly becomes wrong. No functional risk.

**Recommended solution.** A small `notices` master owned by the Administrator — bilingual title and body, publish-from and publish-until dates, active flag — and the landing page reads the current ones. Roughly a day's work including the admin screen, and it retires a whole category of deployment. The language-file version can stay as the fallback for an installation with no notices.


**As built (2026-08-25).** As recommended. `portal_notices` carries bilingual title and body, publish-from and publish-until dates, display order and a status; `PortalNoticeComposer` reads them for the landing page and **falls back to the four the language file has always carried** when nothing is published, so the section is never an empty box. Master wins outright when it has rows — a mixture of the two would be impossible to reason about.

A notice with no Hindi shows its English on a Hindi screen rather than showing nothing: a notice is often issued in English first, and refusing to publish it until the translation exists means it is not published. `published_until` null means "until further notice", which is how most government notices are actually issued. The composer is guarded exactly as the statistics composer is — a missing table or an unavailable database costs the notice board, never the page.

*Files:* migration `370001`, `PortalNotice`, `PortalNoticeRequest`, `PortalNoticeController`, two views, `PortalNoticeComposer`, routes, sidebar, 12 month keys, `PortalNoticeTest` (10 tests).
**Priority: Medium.**

---

## 7. `recruitment_rule_feeders` still speaks the old vocabulary

**Description.** Item 6 replaced *Feeder Post* with *Promotion From Post* throughout, and the columns were renamed. What remains is the **table** `recruitment_rule_feeders`, the Eloquent relation `feeders()`, and identifiers like `#feeders-table` and `add-feeder` in the recruitment-rule screen.

**Reason.** The register asked for the terminology change and named the columns; it did not ask for a table rename, and the project's migration rule is additive-only. The remaining names are internal — no user sees them — but a maintainer reads two vocabularies for one concept, which is how the next person reintroduces the old one.

**Impact. **Maintainability only. Doing it is a table rename plus its foreign keys, one relation, one model, one Blade partial and one JavaScript file.

**Recommended solution.** Rename to `recruitment_rule_promotion_sources` (or `_from_posts`) in a single reversible migration, following the same create-index-before-dropping pattern migration `360001` uses, and rename the relation with it. Worth doing in the same pass as any other change to that screen; not worth a release of its own.


**As built (2026-08-25) — under a shorter name, because the proposed one does not fit.** `recruitment_rule_promotion_sources_promotion_from_post_id_foreign` is **65 characters** and MySQL caps an identifier at 64. The choice was a shorter table or hand-written constraint names on one table while every other table uses Laravel's derivation — and a schema where one table names its keys differently is a schema whose next migration gets it wrong. So: **`recruitment_rule_sources`**, model `RecruitmentRuleSource`, relation `promotionSources()`.

**The HTTP payload key stays `feeders`.** Suggestion 7 was about the table, model and relation; renaming the posted field churns the view, the JavaScript, the request and every test for no benefit. A first pass renamed it by accident and broke 24 tests, which is how that line got drawn.

**The migration reads its foreign keys from `information_schema` rather than deriving them**, because `promotion_from_post_id` has been renamed once already and its constraint may carry either name — guessing is what made the first attempt fail halfway. Both directions are idempotent and *complete*: a partly-applied run is finished rather than skipped, since a guard that returns early on "already renamed" reports success on a table with no foreign keys. That is not hypothetical — it happened, and left the development database without three constraints until it was repaired.

*Files:* migration `370002`, model rename, 8 code files, 2 views, 1 JS file, 4 test files.
**Priority: Medium.**

---

## 8. A candidate has two front doors and sees a different subset through each

**Description.** An officer on a Seniority List can now reach their data two ways: **Self-Inspection** (no account; name, date of joining and date of birth; shows the data recorded about them) and **My DPC** (an account; shows the DPC, its stage and the published outcome). Neither shows what the other shows.

**Reason.** They were built for different purposes at different times — Self-Inspection to let an officer correct departmental data before the sitting, My DPC to let them follow their own exercise. Both are correct. But an officer who has been given an account has no reason to know that the *other* screen holds the rest of their information, and the two live under different navigation.

**Impact.** Usability, and a support burden: "I can see my DPC but not my data" is a question somebody will ask.

**Recommended solution.** From the candidate portal, link to the same officer's Self-Inspection view — reusing the existing screen rather than rebuilding it, and reusing the identity check rather than trusting the session. Do **not** merge them: Self-Inspection deliberately works without an account, and that property is worth more than the tidiness of one screen.


**As built (2026-08-25).** As recommended: the candidate portal links to Self-Inspection for the particulars it deliberately does not show, reusing the existing screen and its identity check rather than rebuilding either. **Not merged** — Self-Inspection works without an account, and that property is worth more than the tidiness of one screen.

*Files:* `candidate/show`, 3 catalogue keys.
**Priority: Medium.**

---

## 9. Re-verify the recruitment rule at submission, not only at creation

**Description.** `createFromPreDpc()` records the recruitment rule in force when the DPC is raised. A draft may sit for weeks, and an amended Pre-DPC may supersede the order in the meantime.

**Reason.** Recording the rule at creation is right — the DPC must run under the terms that sanctioned it, and a rule that changed underneath it must not silently rewrite a signed proposal. But nothing tells the Authorized Officer that the order they raised the DPC under has since been superseded. They find out when the Administrative Head asks.

**Impact.** Wasted work rather than a wrong result. The recorded rule stays correct in every case.

**Recommended solution.** On the proposal screen, compare the recorded rule against the one now in force and, when they differ, say so — as a notice, not a refusal. Whether a superseded order should *block* submission is a business question, and it belongs with the Commission rather than in code.


**As built (2026-08-25).** As recommended, and as a notice only. `recruitmentRuleSuperseded()` compares the rule recorded on the proposal against the one now in force for the same post; the proposal screen states it. The recorded rule is **not** changed — a DPC runs under the terms that sanctioned it — and a post whose rule has been withdrawn entirely is not reported as superseded, because there is nothing newer to point at and saying so would send the officer looking for a rule that does not exist. Whether a superseded rule should *block* submission remains a business question and is not decided in code.

*Files:* `DpcProposalService`, `DpcProposalController`, `dpc-proposals/show`, 2 catalogue keys.
**Priority: Medium.**

---

## 10. Give the Commission Members master search and pagination

**Description.** `admin/commission-members` lists every member on one page, ordered by name.

**Reason.** Correct today: the Commission has a handful of members. The master also holds retired officers and outside experts, and those accumulate — the register's own design allows a member with no login precisely so that historical members can stay on record.

**Impact.** None now. A slow screen in a few years, and a screen nobody can find anybody on.

**Recommended solution.** The same pagination and search the other masters use, plus a filter on category and on chairman-eligibility — which is how the screen will actually be read once a committee is being composed from it.


**As built (2026-08-25).** As recommended, plus the filters the screen is actually read with: search across name, section and free-text post, a filter on what a member represents, and a "chairman eligible only" narrowing. Paging carries the query string, so paging does not silently drop the filter the reader applied. The UUID is not searchable — nobody reads one off a page and types it.

*Files:* `CommissionMemberController`, its index view, 7 catalogue keys, `CommissionMemberMasterTest` (+3 tests).
**Priority: Medium.**

---

## 11. Retire the legacy import column aliases on a stated date

**Description.** `ImportTemplate::columnMap()` accepts the pre-rename spreadsheet headers (`current_post`, `feeder_post`, `date_of_posting_on_current_post`, `date_of_posting_on_feeder_post`) as aliases for the new ones.

**Reason.** Right for now — a department holding a half-filled spreadsheet from last month should not have its upload refused over a header. But an alias with no end date is permanent, and in two years nobody will know whether it is still load-bearing.

**Impact.** A small amount of code that outlives its reason.

**Recommended solution.** Announce a date — the end of the current DPC year is the natural one — after which the old headers are refused with a message naming the new one. Downloading the template already produces the new headers, so the migration path is a click.


**As built (2026-08-25) — the mechanism, not the date.** `ImportTemplate::legacyAliases()` returns the four old headers until `dpc.imports.legacy_headers_until` has passed, and nothing after. The cutoff is **unset by default**, which means *no cutoff has been decided*: announcing a date is the Commission's call, and inventing one would be exactly the business rule this project does not invent. A cutoff of "the 31st" makes the 31st the last day the old headers work, not the first day they do not.

*Files:* `ImportTemplate`, `config/dpc.php`, `ImportTemplateTest` (+3 tests).
**Priority: Low.**

---

## 12. An `artisan lang:check` for translators

**Description.** `BilingualCoverageTest` holds item 7's guarantees: key parity, no key resolving to an array, every view compiling, and no view rendering text that did not come from the catalogue.

**Reason.** It runs in the suite, which means a translator has to run the test suite — and read PHPUnit output — to find out whether their file is complete. That is the wrong interface for the person most likely to need the answer.

**Impact.** Convenience, and the odds that translations are checked at all.

**Recommended solution.** A command wrapping the same checks with human output: missing keys per group, keys present in Hindi and not English, and untranslated rendered strings. No new logic — the test is the logic — just a second front door.


**As built (2026-08-25).** `php artisan lang:check` reports missing keys, extra keys and keys identical in both locales — the last being where an untranslated string hides. No new logic: the same questions `BilingualCoverageTest` asks, with output a translator can read.

**It earned its place immediately**, finding three dead keys left behind by earlier rewrites (`cgpsc.allotments.history.badge`, `cgpsc.schedules.history.badge`, `dpc.form.heading`) whose call sites had been replaced. They are gone.

*Files:* `CheckLanguageCatalogueCommand`.
**Priority: Low.**

---

## 13. Balance committee workload across members

**Description.** When the required categories are covered before the minimum is reached, `topUp()` fills the remaining seats with the members who represent the most categories.

**Reason.** That preference is about robustness — a committee that still satisfies its requirements if one member is unavailable. It is not about fairness, and it will consistently propose the same few members, because the members who represent several categories are the same people every time.

**Impact.** Advisory only; the officer may edit the proposal, and nothing is enforced. But an advisory the officer accepts unchanged is in practice the decision.

**Recommended solution.** Break ties by how many committees the member already sits on this DPC Year — the data is in `dpc_committee_members` and needs no new table. Keep coverage as the first criterion; it is a requirement, and workload is a preference.


**As built (2026-08-25).** As recommended. The top-up now sorts by category coverage first — a requirement — and then by how many committees the member already sits on **in this DPC Year**, counted from `dpc_committee_members`, which already records it. No new table and no counter to keep in step. Across all time the count would only ever favour whoever was registered most recently, hence the year.

*Files:* `CommitteeSelectionService`, `CommitteeSelectionTest` (+1 test).
**Priority: Low.**

---

## 14. The DPC serial is four digits, and was five

**Description.** `dpc.dpc_id.serial_width` went from 5 to 4 so the number matches the register's example, `PDPC-2026-HED-0001`.

**Reason.** Recorded so nobody rediscovers it as a bug. Numbers issued before the change carry five digits and are unchanged — an issued identifier is never rewritten — so a department may hold both `DPC-2026-SE-00001` and `DPC-2026-SE-0002`. Both are valid; they were issued under different settings.

**Impact.** Cosmetic, and only where a department's numbering straddles the change. The width is an environment variable if a different one is preferred.

**Recommended solution.** No change. State it in the User Guide's numbering paragraph so that a department noticing the difference finds an answer rather than raising a ticket.


**As built (2026-08-25): no change, as recommended.** The width remains four, issued numbers are never rewritten, and the difference is stated in the documentation so a department that notices it finds an answer rather than raising a ticket.
**Priority: Low.**

---

*Prepared 2026-08-24. **All fourteen implemented 2026-08-25.** Gate at completion: 1,124 tests passing, PHPStan clean, Pint clean, no security advisories, MySQL schema built from zero, `demo:seed` rebuilding from an empty database.*

*One new business question came out of the work: **BD-27**, whether the same employee number in two departments is one transferred officer or two officers. It is recorded in `CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md`, the code assumes neither reading, and nothing is blocked on the answer.*

---

# Raised by the change register of 2026-08-26

**Not implemented.** The register of 2026-08-26 repeats the standing instruction —
*"If you identify a better approach, document it in Suggestions & Improvements
only"* — so everything below is a proposal awaiting a decision. Nothing in this
section has been built.

---

## 15. Committee member search should also accept an Employee ID

**Priority: Medium. Requested by the Commission on 2026-08-26 and deliberately left
unbuilt.**

**Description.** Item 6 of the register gives the committee screen two ways to find
a person: search an existing user by **e-mail address** or by **mobile number**, and
the same two fields drive the "this user already exists" check behind *Create New
Member*. `CommitteeMemberDirectory::matching()` and `existing()` implement exactly
those two, plus a name prefix that is offered as a convenience and is deliberately
never the basis of the duplicate warning.

**Reason.** In a government department those two handles are the *least* stable
things about a person:

- An officer's e-mail follows their posting. Transfer between departments and the
  address changes; some officers hold a departmental address that is reassigned to
  their successor, which is worse than it changing — the old address now belongs to
  somebody else, and a search on it finds the wrong person.
- Mobile numbers change with carriers and handsets, and are frequently recorded once
  at registration and never revised.
- The **Employee ID / Employee Number** is issued once and is generally stable for
  the whole of an officer's service. It is also the handle every other establishment
  record already uses, so it is the number a nodal officer has in front of them.

The portal already holds it: `users.employee_number`, added on 2026-08-24, unique
portal-wide, and already accepted as a **login identifier** by `LoginRequest`
alongside e-mail and mobile. So the portal will authenticate somebody by employee
number but will not let an Authorized Officer find them by it — which is an
inconsistency a user will notice before a developer does.

**Impact.** Usability, and — less obviously — **duplicate prevention**, which is the
part that matters. Item 6 exists so that one person never becomes two accounts. An
officer whose recorded e-mail has gone stale is invisible to today's check, so the
officer composing the committee types their details afresh and the portal creates a
second account for somebody it already holds. Adding the employee number closes the
commonest route to exactly the duplicate the register is guarding against.

**Recommended solution.** Extend the same two methods, and nothing else:

- `matching()` gains an exact match on `employee_number`, beside the existing exact
  matches on e-mail and mobile. Exact rather than partial, for the reason already
  written there: a `LIKE` on a number returns strangers who share four digits and
  invites the officer to seat the wrong person.
- `existing()` gains the same, so the *"This user already exists → Use Existing
  User"* path fires on an employee number too. This is the half that prevents the
  duplicate; the search box alone would only make the officer's life easier.
- The committee form gains an **Employee ID** input beside e-mail and mobile, and
  `dpc-committee.js` includes it in the debounced duplicate check.
- `SaveDpcCommitteeRequest` compares posted employee numbers for duplicates within
  one submission, as it already does for e-mail and mobile.

Roughly half a day, including tests. No migration: the column exists and is already
unique.

**Two things to settle before building it.**

1. **Normalisation.** `EMP-001`, `emp001` and `EMP 001` are one employee number to a
   person and three strings to a database. Mobile numbers already get
   `normaliseMobile()` for precisely this reason, and an employee number needs the
   same treatment or the check will report "no such user" and create the duplicate
   anyway — the failure this proposal exists to prevent, reintroduced by the fix.
2. **BD-27 is not settled, and this touches it.** The open question is whether the
   same employee number in two departments is one transferred officer or two
   officers. Searching *portal-wide* on employee number assumes the first;
   `PromotionCandidateProvisioner` currently assumes neither and reports the clash.
   A committee search that quietly assumed one reading would decide BD-27 by
   implementation, which is the thing this project does not do. **The recommendation
   is therefore to build the search scoped exactly as BD-27 is answered, and to wait
   for that answer** — or, if the Commission would rather not wait, to search
   portal-wide and *show the department* beside each result so the officer resolves
   the ambiguity rather than the portal.

*Files it would touch:* `CommitteeMemberDirectory`, `SaveDpcCommitteeRequest`,
`dpc-proposals/committee.blade.php`, `public/js/forms/dpc-committee.js`,
`DpcCommitteeTest`, plus two catalogue keys.

---

## Raised by the change register of 2026-08-27 — the Vacancy and Merit engines

Six proposals. **None was implemented**, because the register's second instruction was
explicit: *"If you identify a better architecture, workflow, database design or
validation, DO NOT implement it automatically."* Each is written as a proposal with what
it would cost and what it buys.

Where an item is really a *policy* question rather than an engineering one, it is in
`CGPSC_DPC_PENDING_BUSINESS_DECISIONS.md` instead — the qualification comparison is
BD-39, the tribal-area formula is BD-36, and so on. What follows is engineering.

| # | Title | Priority |
|---|---|---|
| 16 | Give the Evaluation Chart a structured qualification, mapped to the master | **High** |
| 17 | Derive Working Strength from the Working Officer List rather than trusting a count | Medium |
| 18 | Let a department answer a missing record from the Evaluation Chart itself | Medium |
| 19 | Show the vacancy breakdown on the DPC detail page, not only on the form | Medium |
| 20 | Reconcile the M6 hold with item 13's rejection | Low |
| 21 | Record the authority behind a retirement or deputation vacancy | Low |

---

## 16. Give the Evaluation Chart a structured qualification, mapped to the master

**Description.** `dpc_evaluation_rows.qualification` is free text an operator types
(`M.A., B.Ed.`). `recruitment_rule_sources` links to the Qualification Master, which
holds *levels* (`Post-Graduate`, `Graduate`). Item 12 asks the two to be compared, and
they are not comparable.

**Reason.** This is the engineering half of **BD-39**. The policy question is what the
comparison should mean; the engineering answer is that no comparison is possible until
both sides are structured. Substring matching — which is what the code would naturally
reach for — refuses `M.A., B.Ed.` against a requirement of `Post-Graduate`, and a
promotion refused on a false ground is the worst error this system can make.

**Impact.** Item 12's qualification limb cannot be enforced until this exists. Today the
finding is reported on the run's Pending Decisions and applied to nobody, which is safe
but is not what the register asked for.

**Recommended solution.** Three pieces, in this order:

1. Add `qualification_id` to `dpc_evaluation_rows` and to `dpc_seniority_entries`,
   nullable, referencing `qualifications`. Keep the free-text column — it is what the
   department wrote, and an audit needs it.
2. Give the Qualification Master a **level** and a **rank**, so `M.A.` can be recorded as
   a `Post-Graduate`-level qualification and a rule requiring `Graduate` is satisfied by
   anything ranked at or above it. This is the piece that needs CGPSC: the mapping of
   degrees to levels is theirs.
3. Set `dpc.merit.qualification_gate` to `reject`, and the gate starts enforcing.

Charts already recorded keep a null `qualification_id` and continue to produce an
advisory rather than a refusal, so nothing published becomes retroactively wrong.

*Files it would touch:* two migrations, `Qualification`, `DpcEvaluationRow`,
`OfficerRecord`, `CandidateGateService`, the seniority and chart forms, the import
template and parser, `CandidateGateServiceTest`, `MeritGateTest`.
**Roughly three days, most of it the master data.**
**Priority: High** — it is the one thing standing between item 12 and full enforcement.

---

## 17. Derive Working Strength from the Working Officer List rather than trusting a count

**Description.** Item 2 was implemented as asked: Working Strength is the number of rows
in the uploaded Working Officer List, and the officer cannot type it. But the *list* is
still trusted as complete — nothing reconciles it against the department's establishment
return or against last year's list.

**Reason.** Base Vacancy is `Sanctioned − Working`, so a list that is short by one
officer creates a vacancy that does not exist, and the DPC promotes into it. The current
validation catches `Working > Sanctioned`, which is the *safe* direction of the error;
the dangerous direction — a list quietly missing rows — passes.

**Impact.** A promotion made against a phantom vacancy. Low likelihood, high
consequence, and invisible when it happens.

**Recommended solution.** A soft reconciliation rather than a hard rule, because the
portal cannot know the truth: compare the arriving list against the previous DPC's list
for the same post and **show the difference** — officers who were there last year and are
not there now, with their retirement or deputation dates where recorded. The officer
confirms or corrects. No blocking, because a genuine reduction in strength is ordinary.

*Files it would touch:* `VacancyEngine` (a `reconcile()` alongside `calculate()`), the
working-officers view, one new test class.
**Roughly a day and a half.**
**Priority: Medium** — the failure is rare but silent, and silence is the problem.

---

## 18. Let a department answer a missing record from the Evaluation Chart itself

**Description.** Missing CRs and Loyalty Certificates are recorded on a screen of their
own, reached per officer from the chart. That was the right call — a file upload per year
per officer on the chart's single all-officers form would lose everybody's data on one
bad file — but it means an officer filling in a fifteen-row chart makes fifteen round
trips to answer fifteen gaps.

**Reason.** Ergonomics, and therefore data quality: a workflow that costs fifteen page
loads is a workflow where the fifteenth gap gets a reason nobody chose carefully.

**Impact.** No correctness consequence. It affects how likely the reasons are to be
accurate, which matters because a reason is what holds a promotion.

**Recommended solution.** Keep the per-officer screen as the place the PDF is uploaded,
and add a **bulk reason pass** on the chart: for every year the chart shows as missing, a
reason dropdown inline, saved with the chart. The document then remains outstanding and
the candidate stays *un*-justified until it arrives — which is correct under item 19, and
the screen should say so plainly ("reason recorded, document awaited").

That last point is the reason this is a proposal and not a change. It introduces a third
state — *reason without document* — which item 19 does not describe. Better to ask than
to invent it.

*Files it would touch:* `SaveEvaluationChartRequest`, `EvaluationCandidateService`,
`RecordGapService`, the chart view, `DpcRecordGapTest`.
**Roughly two days.**
**Priority: Medium.**

---

## 19. Show the vacancy breakdown on the DPC detail page, not only on the form

**Description.** The nine figures item 8 asks for — Sanctioned, Working, Base,
Retirement, Deputation, Total, Promotion %, Promotion, Direct Recruitment — are shown on
the Working Officers form, where they are computed. The DPC detail page shows the total
only.

**Reason.** The form is where the officer *enters* data; the detail page is where
everybody else *reads* it. A reviewing officer, a committee member and the Commission all
arrive at the detail page, and the breakdown is exactly what they would ask for. It is
already stored — `dpc_vacancy_calculations` holds every figure with its inputs — so
nothing needs computing.

**Impact.** Presentation only. Nothing is wrong today; something useful is hidden.

**Recommended solution.** A read-only panel on `dpc-proposals/show`, sourced from
`DpcVacancyCalculation::scopeCurrent()`, showing the nine figures, the period they were
computed for, the rounding rule applied, and the officers who contributed the retirement
and deputation vacancies by name. Superseded calculations listed beneath, collapsed —
the audit trail is the point of that table and nothing currently displays it.

*Files it would touch:* `DpcProposalController::show`, `dpc-proposals/show.blade.php`,
one section partial, catalogue keys, one test.
**Roughly half a day.**
**Priority: Medium** — it is the cheapest item here and the most often asked for.

---

## 20. Reconcile the M6 hold with item 13's rejection

**Description.** A missing Property Return now produces two independent outcomes: an M6
**hold** in the proceedings subsystem, and a **`Rejected`** status from the merit engine.
Before this register the engine had no opinion about property returns, which is what
architect ruling AQ-4 — *holds are independent of merit* — was about.

**Reason.** One fact, two flags. Neither is wrong, but a Commission member looking at an
officer marked twice for one reason is entitled to know whether that is deliberate.

**Impact.** None on any outcome. It is a clarity problem, and it is recorded as **BD-38**
because whether the hold is now redundant is the Commission's call, not ours.

**Recommended solution.** Wait for BD-38. If the answer is "the rejection supersedes it",
`HoldReason::PropertyMissing` retires and detection stops raising it. If the answer is
"both", the result screen should show them as one finding with two consequences rather
than two findings.

*Files it would touch:* `HoldService`, `HoldReason`, the proceedings and result views.
**Half a day either way, once ruled.**
**Priority: Low.**

---

## 21. Record the authority behind a retirement or deputation vacancy

**Description.** Items 3 and 4 add a vacancy for a retirement or a deputation inside the
vacancy period. The calculation records *that* it did and *which officer* caused it — the
`contributors` column names them with dates — but not **under what rule**.

**Reason.** BD-42 and BD-43 ask which Government Rule authorises these additions. When
they are answered, the answer belongs on the calculation, not only in a document: a
vacancy figure that cannot cite its authority is a figure that has to be defended from
memory.

**Impact.** Auditability. It affects nothing computed.

**Recommended solution.** A `rule_citation` column on `dpc_vacancy_calculations`,
populated from configuration once BD-42 and BD-43 are answered, and printed beside the
breakdown wherever the nine figures appear. Additive, and the table is append-only, so
existing rows keep a null and read as "authority not recorded" — which is the truth.

*Files it would touch:* one migration, `DpcVacancyCalculation`,
`VacancyCalculationRecorder`, the breakdown view.
**Two hours, after the ruling.**
**Priority: Low.**

---

---

# Raised during Business UAT (from 2026-08-28)

**Numbering continues the existing sequence.** Items 1–21 above were raised during construction; item 22 onward are raised while the business verifies the portal module by module. **None of these is implemented.** Each waits for explicit business approval.

Every UAT-phase item additionally records the **Screen / Page URL** and the **Evidence** (the file, class or method that produces the behaviour, and the screenshot filename where one was taken), so that any item can be traced back and implemented later without re-investigation.

---

## 22. District Master ships the pre-2022 district list — five districts are missing

| Field | Value |
|---|---|
| **Suggestion ID** | 22 |
| **Date** | 2026-08-28 |
| **Module** | 1 — Masters |
| **Screen** | District Master |
| **Screen / Page URL** | `/admin/districts` |
| **Category** | Better Database Design *(reference-data completeness)* |
| **Evidence** | `app/Integrations/Mock/SeedDirectoryProvider::districts()` lines 19–50; `database/seeders/DistrictSeeder.php`; verified in `dpc_dev.districts` — **28 rows** |
| **Requires Business Approval** | Yes — the Commission must confirm the authoritative district list |
| **Priority** | Medium |
| **Implementation Complexity** | Low — five rows added to one seeder array, or five records typed on the screen |
| **Status** | Open |

**Current Situation.** The District Master is seeded from `SeedDirectoryProvider` and holds **28 districts**, all `Active`. That is the Chhattisgarh district set as it stood between February 2020 and September 2022. The five districts constituted in September 2022 — **Manendragarh-Chirmiri-Bharatpur, Mohla-Manpur-Ambagarh Chowki, Sarangarh-Bilaigarh, Sakti, and Khairagarh-Chhuikhadan-Gandai** — are absent. Separately, every seeded row carries `lgd_code = NULL`; only `state_lgd_code = 22` is populated.

**Suggestion.** Confirm the authoritative list with CGPSC, then either extend the seeder array or have the Administrator add the missing districts on the screen. Populate `lgd_code` from the LG Directory at the same time.

**Reason.** A department headquartered in one of the five newer districts cannot complete registration, because the district dropdown on `/register` is filtered to `status = active` and the row does not exist. The administrator can add it by hand, but only if somebody notices.

**Benefits.** Registration works for every department in the state on day one. A populated `lgd_code` makes the eventual LGD API integration (`FEATURE_LGD_SYNC`) a reconciliation rather than a re-keying exercise.

**Impact.** Registration for departments in five districts. No effect on any calculation, workflow or published document.

---

## 23. A master can be deleted while it is still in use, and the record that used it goes quietly blank

| Field | Value |
|---|---|
| **Suggestion ID** | 23 |
| **Date** | 2026-08-28 |
| **Module** | 1 — Masters |
| **Screen** | District Master *(and every other master using the shared delete path)* |
| **Screen / Page URL** | `/admin/districts` — the Delete button on each row |
| **Category** | Better Validation · Better Database Design |
| **Evidence** | `app/Domain/Master/Services/MasterCrudService::delete()` — no in-use check; `app/Domain/Master/Concerns/IsMaster` (`SoftDeletes`); FK `departments.district_id → districts` is **`ON DELETE SET NULL`**, verified in `information_schema` |
| **Requires Business Approval** | Yes — the business must say whether an in-use master may be removed at all |
| **Priority** | High |
| **Implementation Complexity** | Low–Medium — one guard in the shared CRUD service, applied to every master |
| **Status** | Open |

**Current Situation.** `MasterCrudService::delete()` performs a **soft delete** and makes **no check of whether the record is referenced**. The database's `SET NULL` rule never fires, because no row is actually deleted. The consequence is that a department keeps a `district_id` pointing at a soft-deleted district, and because `SoftDeletes` applies a global scope, `$department->district` then resolves to **null** — the department's district silently becomes blank on every screen that shows it. The same shared delete path is used by Designation, Pay Scale, Reservation Category, Vacancy Type, DPC Type, Committee Type, Review Reason, Record Reason and Qualification.

**Suggestion.** Refuse the delete when the record is referenced, and say what is referencing it — *"This district cannot be deleted: 3 departments are located in it. Set it to Inactive instead."* Where the business does want removal, `Inactive` is already the correct mechanism and needs no new concept.

**Reason.** The master lifecycle already distinguishes `Active` from `Inactive`, and `Inactive` means *"not choosable on new work, but undisturbed on old work"* — which is exactly what a business user means when they say "remove this district". Delete is a different act and, as implemented, silently damages existing records rather than refusing.

**Benefits.** A referenced master cannot be lost by a single click. The distinction between "retire" and "delete" becomes visible to the administrator. The audit question *"why does this department have no district?"* stops being unanswerable.

**Impact.** Every master screen in Module 1, plus Department, Department Post Mapping and Recruitment Rules. Highest consequence on Reservation Category, which is referenced by four officer-level tables including `dpc_calculation_results` inputs.

---

## 24. A new master record defaults to Draft, and Draft is invisible downstream with nothing saying so

| Field | Value |
|---|---|
| **Suggestion ID** | 24 |
| **Date** | 2026-08-28 |
| **Module** | 1 — Masters |
| **Screen** | District Master *(pattern repeats across the master forms)* |
| **Screen / Page URL** | `/admin/districts/create` |
| **Category** | Better UI · Better Workflow |
| **Evidence** | `resources/views/admin/districts/form.blade.php` — `@selected(old('status', $district->status?->value ?? 'draft') === $status->value)`; migration `2026_07_30_090001_create_districts_table.php` — `status` default `'draft'`; consumers filter to Active: `RegistrationController:36`, `DepartmentController::districts()` line 114 |
| **Requires Business Approval** | Yes — whether a new master should be born Active or Draft is a business choice |
| **Priority** | Medium |
| **Implementation Complexity** | Low — a default value, or a line of help text under the dropdown |
| **Status** | Open |

**Current Situation.** The Status dropdown on a **new** master defaults to **Draft**. Every downstream consumer filters on `status = active`. An administrator who fills the form and presses Save without touching the dropdown creates a record that **appears in the master list, looks saved, and cannot be selected anywhere else in the portal.** Nothing on either screen explains this. The seeders, by contrast, write `Active` — so seeded data behaves differently from hand-entered data.

**Suggestion.** Either default a newly created master to `Active`, or keep `Draft` and add one line of help text under the dropdown — *"Draft records are saved but cannot be selected on other screens. Set to Active to make this district available."* — and show a count of non-Active records at the top of the list screen.

**Reason.** A four-state lifecycle (`Draft`, `Active`, `Inactive`, `Archived`) is a reasonable design, but three of the four states are indistinguishable to the administrator at the moment of saving. The failure is silent and appears later, in another module, to a different user.

**Benefits.** Removes the commonest "I entered it and it isn't there" support call. Makes seeded and hand-entered data behave alike.

**Impact.** Every master screen. Most visible on District (blocks registration), Qualification (blocks recruitment rules) and Record Reason (blocks a department justifying a missing CR).

---

## 25. The repository defines no queue runner, and OTP plus credential delivery depend on one

| Field | Value |
|---|---|
| **Suggestion ID** | 25 |
| **Date** | 2026-08-31 |
| **Module** | Cross-cutting — deployment and run-book *(raised during End-to-End UAT Step 2E)* |
| **Screen** | Not screen-specific. First bites at `/register` → `/verify` |
| **Screen / Page URL** | — |
| **Category** | Better Deployment · Better Documentation |
| **Evidence** | Fifteen sources inspected 2026-08-31. `composer.json` — **`laravel/horizon` absent**; `scripts.dev` is `php artisan serve` alone. `config/horizon.php` — **does not exist**. No Supervisor configuration, no `Dockerfile`, no `docker-compose*`, no `Procfile`, no `.github/`, no deployment script anywhere in the repository. `routes/console.php` — no scheduler entries. `README.md` line 34 names *"Redis (cache, queue, sessions)"* and the *Local setup* block stops at `php artisan serve`. `docs/CGPSC_DPC_DEVELOPER_HANDBOOK.md` — **zero occurrences of "queue"**. `docs/archive/**` — only `deployment/EVIDENCE-TABLE-PERMISSIONS.md`; the archived design README names the production platform as *"AWS EC2 + S3 · Nginx · PHP-FPM · Ubuntu LTS. No Docker/Kubernetes"*, which implies Supervisor-managed workers, **but no Supervisor unit was ever committed**. `.env.example` ships **`QUEUE_CONNECTION=redis`** |
| **Requires Business Approval** | No — this is engineering and documentation |
| **Priority** | **High** |
| **Implementation Complexity** | Low — a README section, a Developer Handbook section, and one committed Supervisor unit file |
| **Status** | Open |

**Current Situation.** Redis is the **designed** queue transport: `.env.example` ships `QUEUE_CONNECTION=redis`, so this is not local drift. Yet nothing in the repository starts a consumer, and no document says one is required. The consequence is not cosmetic — `SendOtpNotification` and `SendCredentialNotification` are both `ShouldQueue`, and `OtpService::issue()` persists only `code_hash`. With no worker, a newly registered department's OTP is generated, never delivered, and **cannot be recovered**, because the plaintext code exists nowhere. The screen simply waits for a code that will never arrive. This is recorded as the defect `UAT-OBS-006`; the present entry is the improvement proposal that would prevent it recurring.

It has been invisible until now because the demonstration seeder pre-verifies both channels and disables the first-login password change, so **seeded accounts work and a genuinely new account does not**.

**Suggestion.** Three parts, in order of value:

1. **Document the prerequisite.** Add a *Running the queue* section to `README.md` beside *Local setup*, and to the Developer Handbook, stating the command and — more usefully — **what breaks without it**: OTP verification, credential delivery, workflow notifications and the in-app notification feed.
2. **Commit a Supervisor unit** for the EC2 target the archived design note names, so production has a managed, restart-on-failure worker rather than an operator's memory. This is what the user directed on 2026-08-31: *"Production deployment must use a managed queue worker (for example Supervisor) and this must be documented in the deployment guide."*
3. **Consider defaulting `local` to `QUEUE_CONNECTION=sync`.** A developer or tester who has not started a worker would then still receive OTPs. This is a judgement call, not a clear improvement: `sync` runs listeners inside the request, and both `SendWorkflowNotification` and `RecordInAppNotification` set `$afterCommit = true` specifically because a synchronous queue once let a failing notification roll back a completed workflow transition. `afterCommit` handles that correctly, so the risk is managed — but the divergence between local and production behaviour is real and should be a conscious choice.

**Reason.** `php artisan queue:work` is being used for this UAT because it is the only mechanism consistent with the configuration present — **not** because the project sanctions it. That distinction must not be lost: an undocumented command that happens to work is how a production installation comes to run no worker at all.

**Benefits.** Registration and OTP login work on a fresh checkout. The notification pipeline, already built and already behind `FEATURE_NOTIFICATIONS`, actually delivers when the flag is turned on. Production gains a worker that survives a reboot.

**Impact.** Deployment and developer onboarding. No application code need change for parts 1 and 2.

---

## Raised by the Demo Readiness verification of 2026-09-01

Three proposals. **None was implemented**, because the work was carried out under Demo
Freeze — verification only, with a closed list of permitted runtime repairs. Each is
recorded here rather than built.

Item 28 is the one that matters. It is not a hypothetical improvement: the failure it
describes had already happened in the demonstration database and would have been shown
to the customer.

| # | Title | Priority |
|---|---|---|
| 28 | A missing stored document downloads as a blank page, silently | **High** |
| 26 | The demo cheat sheet lists external committee members as login accounts | Medium |
| 27 | Test fixtures inherit the wall clock, so tests can be time-bombed to a future date | Medium |

---

## 26. The demo cheat sheet lists external committee members as login accounts

| Field | Value |
|---|---|
| **Suggestion ID** | 26 |
| **Requires Business Approval** | No — this is seeder and presentation |
| **Priority** | Medium |
| **Implementation Complexity** | Low |
| **Status** | Open |

**Current Situation.** `DemoJourneySeeder::committee()` seeds a DPC committee member with
`'user' => null` — the Deputy Secretary, General Administration Department. That member is
*external*: they sit on the committee but hold no portal account. A `User` row is
nevertheless created to carry their name, designation, mobile and e-mail, because the
committee membership record needs somewhere to hang.

That row is then indistinguishable from a real account in the credentials listing printed
by `demo:seed`. The cheat sheet shows:

```
| —  | ds.gad@cg.gov.in / 9000009001 | Shri Sanjay Kumar Agrawal |
```

under the heading **"Login (email or mobile)"**. The account has no role, no department,
`email_verified_at` and `mobile_verified_at` both null, and `must_change_password` true. A
presenter who tries it is sent into OTP verification and, past that, reaches nothing — the
route sweep of 2026-09-01 confirmed this identity receives 302 on 122 of 128 routes, the
same surface as a guest. Two such rows exist.

**Suggestion.** Either of two, in order of preference:

1. **Do not create a `User` for an external committee member.** Carry the name,
   designation and contact details on `dpc_committee_members` itself, which already has
   columns for them. The `User` row buys nothing — nobody signs in as it.
2. **If the row must exist**, mark it (a nullable `is_external` flag, or simply the absence
   of any role) and have `SeedDemoDataCommand::cheatSheet()` list such rows under a separate
   heading — *"Committee members without portal accounts"* — rather than under "Login".

**Reason.** A credentials sheet is read under pressure, minutes before a demonstration. An
entry that looks like a login and is not will be tried, and the three minutes lost happen
in front of the customer. The `—` in the role column is the only signal, and it is easy to
read as "role not yet assigned" rather than "this is not an account".

**Benefits.** The cheat sheet becomes trustworthy: everything listed as a login is one.
Removes a whole class of presenter confusion.

**Impact.** `DemoJourneySeeder`, `DemoAccountSeeder`, `SeedDemoDataCommand::cheatSheet()`.
Demonstration data only — no production code path.

---

## 27. Test fixtures inherit the wall clock, so tests can be time-bombed to a future date

| Field | Value |
|---|---|
| **Suggestion ID** | 27 |
| **Requires Business Approval** | No — this is test infrastructure |
| **Priority** | Medium |
| **Implementation Complexity** | Low to Medium |
| **Status** | Open — one instance fixed 2026-09-01, the pattern remains |

**Current Situation.** `UserFactory::assignedTo()` posts an officer with effect from
**today**, taken from the wall clock. Tests that then travel to a hardcoded date and
perform a transfer are silently asserting a relationship between two dates — one fixed in
the source, one supplied by the calendar.

On 2026-09-01 that assumption expired. `UserDepartmentAssignmentTest` had been green for
weeks and failed twice on the morning of demo eve, because the real date reached the
hardcoded `2026-09-01` and the fixture's posting therefore began on the very day of the
transfer. Two correct business rules then fired — the `->max($incumbent->effective_from)`
clamp in `DepartmentAssignmentService::retireIncumbent()`, which stops a posting ending
before it began, and the validation refusing a transfer dated on or before the current
posting's start.

**Nothing was wrong with the application.** The tests were asserting against a moving
calendar. The immediate fix was to freeze the clock in that file's `setUp()` before any
fixture is built.

**Suggestion.** Three parts, in order of value:

1. **Freeze the clock by default.** Call `travelTo()` with a fixed date in the base
   `Tests\TestCase::setUp()`. This is the same argument the base class already makes for
   `Storage::fake('local')` and the Chairman fixture — *a precondition that has to be
   remembered per test is one that will be forgotten*. A test that genuinely needs the real
   clock can travel back.
2. **Give `assignedTo()` an explicit `?Carbon $from` parameter**, so a fixture's posting
   date is stated rather than inherited.
3. **Sweep for the remaining instances.** Hardcoded future dates elsewhere in the suite
   will fail on the day the calendar reaches them, and each will present as an unrelated
   regression at the worst possible moment.

**Reason.** A test that passes today and fails on an arbitrary future date is worse than a
test that fails consistently, because it fails without anybody changing anything — and the
first assumption on demo eve is always "what did I just break?". Diagnosing this one
consumed a meaningful part of the verification window.

**Benefits.** The suite becomes reproducible on any date. Removes a class of failure that
is expensive precisely because it arrives unannounced.

**Impact.** `tests/TestCase.php`, `database/factories/UserFactory.php`, and any test file
carrying a hardcoded date. No application code.

---

## 28. A missing stored document downloads as a blank page, silently

| Field | Value |
|---|---|
| **Suggestion ID** | 28 |
| **Requires Business Approval** | No — this is defensive engineering |
| **Priority** | **High** |
| **Implementation Complexity** | Low |
| **Status** | Open — the data was repaired 2026-09-01, the code path was not |

**Current Situation.** `CirculationDocumentService::contents()` and its proceedings
counterpart end in:

```php
return (string) Storage::disk((string) $document->disk)->get((string) $document->path);
```

`Storage::get()` returns `null` when the file is absent. The cast turns that into `''`, and
the controller returns it with **HTTP 200** and a `Content-Disposition` header. The user
receives a blank page. There is no exception, no log entry, no failed status code, and
nothing in any monitoring that would show it.

`$document->isAvailable()` does not help: it tests the row's `status` column, which still
reads `generated`. The row's opinion of itself is not evidence that the file exists.

**This is not hypothetical.** On 2026-09-01 all **8** document rows in the demonstration
database pointed at files that were no longer on disk. Four downloads returned 0 bytes and
two returned only the 2,443-byte *"SUPERSEDED"* banner the application prepends, with no
document beneath it. The affected artefacts were the **DPC Proceedings** and the
**Promotion List** — the legal output of the entire workflow, and exactly what a presenter
opens last. It was found only because the verification measured response *bytes* rather
than status codes; every status-based check in the project passed it.

**Suggestion.** Two lines of defence, both cheap, both using machinery that already exists:

1. **Assert the file exists before serving it.** In `contents()`, `abort(404)` — or better,
   throw the existing `WorkflowException` with a message naming the document — when
   `Storage::missing($path)`. The controllers already catch `WorkflowException` and render
   it properly, so the user would see *"That document could not be read"* instead of a
   blank page.
2. **Verify the hash on download, not only on demand.** Every document row already stores a
   `content_hash`, and `CirculationDocumentService::verify()` already knows how to check it
   — but the download path calls neither. A document whose bytes no longer match the hash
   taken when it was signed is precisely the thing the hash exists to detect, and serving it
   silently defeats the control. Given the volume, a hash check on every download is
   affordable; if it is not, check existence always and hash on the verify route only.

Optionally, a scheduled integrity command reporting rows whose files are missing would turn
a silent loss into a monitored one.

**Reason.** The hash column, the `verify()` method and the supersession banner all show a
system that takes document integrity seriously. The download path is the one place that
does not — and it is the only path a user actually travels. A control that is bypassed on
the common path is not a control.

There is a second, sharper reason. The failure mode is **silence**. A 500 would have been
caught by the route sweep, by the log, and by any monitoring. A 200 with an empty body
passes all three. The system is currently unable to tell anybody that its most important
output has gone missing.

**Benefits.** A missing or corrupted document announces itself instead of hiding. The
`content_hash` starts earning its place. The failure becomes diagnosable in seconds rather
than requiring byte-level measurement to notice at all.

**Impact.** `CirculationDocumentService::contents()`,
`ProceedingDocumentService` equivalent, and their two controllers. No schema change — the
`content_hash` column already exists. No business rule change: a document that is present
and intact behaves exactly as it does today.
