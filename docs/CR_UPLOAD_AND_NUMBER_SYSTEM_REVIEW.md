# CR Upload aur CR Number System — Review

**Date:** 2026-10-13
**Source document reviewed:** `Understanding of DPC with Coder Instructions.pdf` — 6 pages, created 2026-09-25, Microsoft Word → PDF, internal title `DPC_UNDERSTANDING`.
**Reviewed against:** the code as it stands on branch `feature/m0-setup`.
**Scope:** the two subjects taken together — the **CR mark scale** (document rules 3.4, 3.5) and the **CR capture structure** (rules 3.1, 3.2, 3.3 and the Part 4 CR undertakings). Rules 1.3 and 2.1 are included because they belong to the same rounding family.
**Code changed:** none. This is an analysis only.

---

## Ek nazar me — kya mila

1. Doc ka naya CR scale (क+=9, क=7, ख=5, ग=3, घ=1) **naya rule hai hi nahi**. Wo purane scale ka exact transform hai: `naya = 2 × purana + 1`. Isliye thresholds bhi exactly nikal aate hain — 15→35, 13→31, 11→27, 10→25 — aur **kisi officer ka natija nahi badlega**.
2. Naya scale banaya hi isliye gaya hai taaki **ek saal me 4 CR ka average** (rule 3.1) poora integer bane. Purane scale pe `avg(क+, क) = 3.5` → truncate → 3, yaani aadha grade gaya. Naye pe `avg(9,7) = 8`, kuch nahi gaya. **3.4 aur 3.1 ek hi change hain.**
3. Aur yahi jagah hai jahan engine toot-ta hai: average karte hi saal ki value **mark** ban jaati hai, **grade nahi**. `8` aur `6` kisi grade ka naam nahi. Engine abhi teeno gates pe **grade ka string compare** karta hai.
4. CR ka koi document portal me upload hota hi nahi — NRC, channel document, ungraded CR scan, teeno naye hain. Aur ek saal me ek se zyada CR schema allow hi nahi karta.
5. Doc rule 3.5 ka ek shabd — *"पुराने सेवा अभिलेख के लिए"* — chupke se ye keh raha hai ki **scale CR ke apne saal se tay hoga**, run se nahi. Matlab ek officer ki window me dono system mil sakte hain. `RuleSet` ye express hi nahi kar sakta.
6. Doc ka rule 1.3 **BD-33 ka jawab** hai — aur wo `floor` nahi hai, jo abhi default set hai.

**Nau nayi business decisions** nikalti hain, BD-50 se BD-58. Inme se **chhe published promotion list badal sakti hain**.

---

## 1. Abhi system me CR kaise aata hai

### 1.1 Teen raaste, teeno grade-only

Portal me CR ka **koi document kahin upload nahi hota**.

| Raasta | Kya capture hota hai |
|---|---|
| Evaluation Chart screen — `resources/views/dpc-proposals/evaluation-chart.blade.php:249` | har saal ke liye ek `<select>`, options `dpc.evaluation.cr_grades` se |
| Excel import — `app/Domain/Import/Services/ImportTemplateBuilder.php:102` | har saal ka ek column — `CR 2024`, `CR 2023`, … ek cell = ek grade |
| Record Gap screen — `app/Http/Controllers/Dpc/DpcRecordGapController.php` | CR **gayab** ho to: Reason Master ki ek row, optional remarks, aur ek supporting PDF `sha256` ke saath |

**Validation** — `app/Domain/Dpc/Validation/DpcRowRules.php:264`: `grade` is `nullable` + `Rule::in($grades)`. Ek officer ke liye zyada se zyada `dpc.evaluation.max_cr_years` (6) year blocks.

**Storage** — `dpc_evaluation_cr_grades`: `dpc_evaluation_row_id`, `year`, `grade` (string, 10), `tribal_area`, aur **`unique(dpc_evaluation_row_id, year)`**. Ek saal, ek grade. Bas.

**Sitting pe correction** — `App\Domain\Proceeding\Enums\CorrectableField::CrGrade`: committee proceeding ke dauran kisi saal ka grade theek kar sakti hai.

### 1.2 Abhi ka number system

`config/dpc.php:1381`:

| Grade | Mark |
|---|---|
| क+ | 4 |
| क | 3 |
| ख | 2 |
| ग | 1 |
| घ | 0 |

- **Window:** `scoring_years` = 5. `ScoringService::selectScoringWindow()` sabse recent paanch **graded** saal uthata hai — sabse recent paanch *saal* nahi. Bina grade wala saal skip hota hai, zero nahi ginta (`RuleSet::markFor()` `null` deta hai, aur null jaan-boojh kar zero nahi hai).
- **Tribal-area bonus:** ek baar flat `+1`, per-year nahi.
- **Maximum:** 20 + 1 = **21**.
- **Qualifying scores:** `I-I` 15, `II-I` 13, `II-II` 11, `default` 10.
- **Teen gates, teeno grade ka string compare:**
  - `EligibilityService.php:91` — `($officer->crGrades[$year] ?? null) === $rules->disqualifyingGrade` (window me kahin bhi घ)
  - `EligibilityService.php:127` — sabse recent CR `=== $forbidden` (ग, sirf `I-I` ke liye)
  - `SuperSeatService::assess()` — window ka har saal `superSeatGrade` (क+) ke barabar

### 1.3 Jo master-data ke liye pehle se sahi bana hua hai

`App\Domain\Eligibility\RuleSet` ek immutable per-run snapshot hai. `dpc_calculation_runs.configuration_snapshot` (JSON) me har run ka poora rule set store hota hai, aur `RuleSet::fromSnapshot()` use bilkul waisa hi reconstruct karta hai — `toArray()`/`fromSnapshot()` ko golden test inverse prove karta hai. **Engine me kahin bhi `config()` seedha nahi padha jaata.**

Matlab: **purani calculations scale change se already safe hain.**

Jo nahi hai wo sirf *naye* run ka source hai — `RuleSet::fromConfig()` `config('dpc.scoring')` padhta hai, aur wahan `cr_grade_marks` ek **hardcoded array** hai, env se bhi nahi aata. Aaj scale badalna = code change + deployment.

---

## 2. Number system — doc rule 3.4 aur 3.5

### 2.1 Doc kya kehta hai

- **Rule 3.4 — नवीन ऑनलाइन अंकन प्रणाली:** उत्कृष्ट (क+) = 9, बहुत अच्छा (क) = 7, अच्छा (ख) = 5, औसत (ग) = 3, खराब (घ) = 1.
  Coder note: *"Software should be designed for any convertion which will be effective as per Master data"*
- **Rule 3.5 — पुरातन श्रेणी प्रणाली**, *पुराने सेवा अभिलेख* ke liye: `20 >= श्रेणी I-I >= 15`, `15 > श्रेणी I-II >= 13`, `13 > श्रेणी II-II >= 11/10`, शेष `10`.
  Coder note: *"There is no written order so Software should be designed for any interval which will be effective as per Master data"*

> **Ek chhota sa notation point:** doc `श्रेणी I-II` likhta hai jahan config `II-I` rakhta hai (Class II se Class I me promotion). Values milti hain, label ka direction nahi. Confirm karva lena chahiye.

### 2.2 Ye scale purane ka affine transform hai

`naya = 2 × purana + 1`, exactly, paanchon grades pe:

| Grade | Purana | 2×purana+1 | Doc |
|---|---|---|---|
| क+ | 4 | 9 | 9 |
| क | 3 | 7 | 7 |
| ख | 2 | 5 | 5 |
| ग | 1 | 3 | 3 |
| घ | 0 | 1 | 1 |

`N` graded saal pe: `naya_total = 2 × purana_total + N`. `N = 5` pe: `naya_total = 2 × purana_total + 5`, aur maximum 20 se **45** ho jaata hai (`2×20+5`) — transform confirm.

**Isliye thresholds bhi exactly transform hote hain, koi proportional andaaza nahi:**

| Class pair | Purana threshold | Naya threshold = 2T+5 |
|---|---|---|
| I-I | 15 | **35** |
| II-I | 13 | **31** |
| II-II | 11 | **27** |
| default | 10 | **25** |

**Tribal-area bonus ko 2 karna padega**, 1 nahi rehne dena. Slope-2 transform me `+1` ka equivalent `+2` hai. 1 chhod diya to bonus ka asar chup-chaap aadha ho jaayega.

Transform strictly monotone hai, isliye is mapping ke saath **kisi officer ka verdict nahi badlega**. Yahi ise jaisa-ka-taisa adopt karne ka sabse mazboot argument hai: presentation change hai, fairness me kuch kharch nahi, aur purani lists dobara kholne ki zarurat nahi.

### 2.3 Ye scale bana hi kyun — jawab rule 3.1 me hai

Rule 3.1 ek saal me chaar CR tak allow karta hai aur unka **average, decimal part hata kar** maangta hai.

Purane 4/3/2/1/0 scale pe do adjacent grades ka average aadha mark hai — `avg(4,3) = 3.5` — aur truncation us aadhe grade ki merit pheink deta hai.

Naye 9/7/5/3/1 scale pe step 2 hai, to do-CR average hamesha integer hai:

```latex
\mathrm{avg}(2a+1,\; 2b+1) \;=\; (a+b)+1 \;=\; 2\cdot \mathrm{avg}(a,b) + 1
```

- `avg(क+, ख) = avg(9,5) = 7` — purane pe `avg(4,2) = 3`, same grade, par wo ittefaq tha.
- `avg(क+, क) = avg(9,7) = 8` — purane pe `3.5`, truncate hoke `3`. Aadha grade gaya.

> **Dono rule ek hi change hain.** 3.1 ka averaging purane scale pe lagaya to officers systematically neeche round honge; 3.4 ka scale bina 3.1 ke lagaya to kuch mila hi nahi.

### 2.4 Aur yahi jagah hai jahan engine toot-ta hai

Average kiya hua saal ek **mark** deta hai, aur zaruri nahi ki us mark ka koi grade naam ho:

- `8` = क+ ke saath क
- `6` = chaar CR ka average, jaise `9, 7, 5, 5` = 26/4 = 6.5 → truncate → 6

Aaj saal ki value `dpc_evaluation_cr_grades.grade` me **string** ki tarah rakhi jaati hai, aur `RuleSet::markFor($grade)` se padhi jaati hai. Phir teen jagah **grade ka string compare** hota hai:

| # | Jagah | Abhi | Banana padega |
|---|---|---|---|
| 1 | `EligibilityService.php:91` | grade `=== घ` | `mark <= 1` |
| 2 | `EligibilityService.php:127` | last year grade `=== ग` (I-I) | `mark <= 3` |
| 3 | `SuperSeatService::assess()` | har saal `=== क+` | har saal `mark == 9` |

Teesre pe dhyan dene layak baat hai. `config/dpc.php:1447` likhta hai ki Super Seat ko **grade** pe keyed rakha gaya hai, total-of-20 pe nahi, taaki notification se scale badalna ise chup-chaap na tode.

> **Wo tark rescale ke liye sahi hai aur averaging ke liye galat hai.** Grade-keyed test rescale-safe hai, average-safe nahi. Jis officer ke paanch saal 9, 9, 9, 9, 8 hain wo kisi bhi padhne me Super Seat nahi hai — par grade-string test ye **sawal hi nahi poochh sakta**, kyunki `8` ka koi grade naam nahi jisse compare kare.

**Nateeja: rule 3.1 lagane se pehle engine ko grade-based se mark-based banana padega.** Dono subjects me ye sabse bada kaam hai, aur ye **frozen M5 code** me hai.

### 2.5 "Effective as per Master data" — asli maang kya hai

Dono coder notes mapping aur intervals ko **master data** me maangte hain, configuration me nahi. Rule 3.5 ke *पुराने सेवा अभिलेख के लिए* ke saath padhiye, to maang settings screen se zyada hai:

> CR pe kaunsa scale lagega, ye **CR kis saal ka hai** usse tay hoga — calculation kab chali usse nahi.

Yaani ek hi officer ki paanch-saal window **dono system pe phail sakti hai** — kuch saal purane scale pe, kuch naye pe, ek hi score me.

`RuleSet` poore run ke liye **ek flat `crGradeMarks` map** rakhta hai aur ye express hi nahi kar sakta. Scale ko per-CR-year resolve karna `RuleSet`, `ScoringService` aur snapshot format — teeno ka structural change hai.

**Ye number-system wale hisse ki sabse gehri baat hai, aur doc me kahin likhi nahi hai** — rule 3.5 ke shabdon se nikalti hai. Kuch banane se pehle Commission se seedha poochhna chahiye (BD-50), kyunki dono padhne se ek hi officer ka total alag aata hai.

---

## 3. CR capture structure — rules 3.1, 3.2, 3.3 aur Part 4

### 3.1 Doc jo flow maangta hai

Har officer, har saal:

1. **"Officer/Employee Putup CR [Yes/No]"**
   - No → pichhle ek saal ki CR le lo (carry forward)
2. **"Is there any Grading in CR [Yes/No]"**
   - No → pichhle ek saal ki CR lo **aur** bina grading wali CR upload karao
3. Yes → **"no of CR in that Year, which may max 4"**. Har segment ke liye:
   - **Reporter** ki designation + unka remark
   - **Reviewer** ki designation + unka remark
   - **Accepting Officer** ki designation + unka remark
   - **Final remark = Accepting Officer → warna Reviewer → warna Reporter**
   - Teeno me se **koi ek bhi missing ho to NRC upload compulsory**
4. Saal me ek se zyada CR → **average, decimal hata kar, integer part badle bina**
5. **Undertaking** ki CR channel ke hisaab se signed hai, **aur** channel document upload
6. **Har CR upload pe undertaking** ki us saal ki duplicate CR nahi hai

**Rule 3.2** — CR unavailable ho to reason exactly teen me se ek, har ek ka apna mandatory document:

| Reason | Mandatory document |
|---|---|
| NRC issued | NRC |
| Employee was on long vacation | Vacation Approval Document |
| Employee was suspended | Suspension **aur** Reinstatement order |

### 3.2 Jo bana hua hai uske khilaaf

| Doc ki maang | Code me status |
|---|---|
| Ek saal me 4 CR | **Namumkin** — `unique(dpc_evaluation_row_id, year)` |
| Reporter / Reviewer / Accepting Officer, designation + remark | **Nahi hai** — teeno shabd `app/`, `resources/`, `database/` me kahin nahi |
| Final remark fallback chain | Nahi hai |
| Average + truncation | Nahi hai |
| NRC | **Nahi hai** — concept hi nahi |
| Pichhli CR carry forward | **Nahi hai, aur maujooda behaviour alag hai** — dekhiye 3.3 |
| Ungraded CR upload | Nahi hai — CR ka koi document upload hai hi nahi |
| Channel undertaking + channel document | Nahi hai |
| No-duplicate-CR undertaking | Nahi hai |
| Teen fix reasons, har ek ka apna document | **Aadha** — `record_reasons` configurable master hai, par saat alag naamon se seed hua hai, aur supporting document ek generic PDF hai, reason se bandha nahi |
| CR har 3 maheene; 90 din se upar service pe mandatory; retirement ke 1 maheene baad tak likhi ja sakti hai | Nahi hai — modelled hi nahi |

### 3.3 Carry-forward wo nahi hai jo engine aaj karta hai

Rule 3.1 kehta hai missing CR ki jagah pehle se uplabdh CR **carry forward** hogi.
`ScoringService::selectScoringWindow()` iske bajaay sabse recent paanch **available** graded saal uthata hai — aur ek alag saal ke liye aur peeche chala jaata hai.

Ye numerically alag hain. Officer ke grades: `2024: क+(9)`, `2023: —`, `2022: ख(5)`, `2021: ख(5)`, `2020: ख(5)`, `2019: ख(5)`:

| | Window | Total |
|---|---|---|
| **Carry forward** (doc) — 2023 ko 2024 ka क+ milta hai | 2024..2020 → 9, 9, 5, 5, 5 | **33** |
| **Aaj ka engine** — 2023 skip, 2019 tak pahunchta hai | 9, 5, 5, 5, 5 | **29** |

35 ke threshold pe chaar marks ka farq. Ye academic baat nahi hai.

Carry-forward ek aur sawal kholta hai jiska jawab doc me nahi hai: **CR kitni baar carry forward ho sakti hai?** Unbounded chhoda to ek CR wale officer ki wahi CR paanchon saal bhar degi aur poore marks mil jaayenge. Cap chahiye, aur specify nahi hai (BD-54).

### 3.4 Saal ki value entered se derived ban jaati hai

Naye flow me `dpc_evaluation_cr_grades.grade` wo nahi rahega jo Authorized Officer ne type kiya — wo ek **computed result** ban jaayega: ya to segments ka average, ya kisi doosre saal se inherit ki hui value. Do baatein isse nikalti hain:

- **`CorrectableField::CrGrade`** committee ko sitting pe saal ka grade theek karne deta hai. Agar grade derived hai, to correction ka matlab ya to derivation override karna hai (aur ye record karna hai ki override hua), ya segment theek karke dobara derive karna hai. **Derived value ko chup-chaap overwrite karna wahi audit trail mitaa dega jiske liye derivation banayi ja rahi hai.**
- **Excel import** me har saal ka ek `CR <year>` column hai. Chaar segments, har segment me nau field — ye ek cell me nahi aa sakta. Ya to import simple case ka raasta bana rahe (ek grade = ek segment, koi reviewer chain nahi), ya template multi-sheet workbook banega. Ruling chahiye (BD-55).

### 3.5 Proposed shape — additive only

Standing rule ke mutabik ki migrations additive hain:

**`dpc_evaluation_cr_grades` ko *year-level* record hi rehne do** — ek row per (row, year). Unique index chhoona hi nahi padega. Isme add karo:

- `put_up` (nullable boolean) — step 1
- `has_grading` (nullable boolean) — step 2
- `source` — `entered` | `carried_forward`
- `carried_from_year` (nullable smallint)
- `mark` (nullable smallint) — **derived value jo engine padhega**, grade-string lookup ki jagah
- `no_duplicate_undertaking_at`, `channel_undertaking_at` (nullable timestamps)
- `grade` rahega waisa hi, aur single-CR case me entered grade hi rakhega — isse maujooda chart aur self-inspection view kuch nahi tootega

**Naya `dpc_evaluation_cr_entries` — segments:**

- `dpc_evaluation_cr_grade_id`, `seq` (1..4), `unique(cr_grade_id, seq)`
- `reporter_designation`, `reporter_grade`
- `reviewer_designation`, `reviewer_grade`
- `accepting_designation`, `accepting_grade`
- `final_grade` — Accepting → Reviewer → Reporter fallback se derive hoke **store** hoga, recompute nahi — kyunki fallback *kyun* laga, wo khud evidence hai

**Naya `dpc_evaluation_cr_documents`** — `kind` (`cr_scan` | `nrc` | `channel`), wahi `disk` / `path` / `original_name` / `size_bytes` / `sha256` discipline jo `dpc_evaluation_record_gaps` pehle se use karta hai. Ek table `kind` column ke saath, usi wajah se jo record-gaps migration apni single table ke liye deti hai: teen tables jo sirf ek shabd me alag hain, wo teen jagah hain jahan agla field add karna hai, aur ek jagah chhoot jaayegi.

**`record_reasons` extend karo** taaki reason ye declare kar sake ki *kaunsa* document chahiye, har reason generic PDF na le.

### 3.6 Governance

Dono hisse frozen zameen pe girte hain:

- Chart, uska request aur schema — **M4 (frozen, sirf fixes)**
- `ScoringService`, `EligibilityService`, `SuperSeatService`, `RuleSet` — **M5 (frozen; har business rule pe golden test; calculations kabhi overwrite nahi)**

Change-control rule ke mutabik isko **naye milestone ke roop me explicit Architect approval** chahiye, approved phase ke andar refactor ke roop me nahi. Aur BD-50 se BD-55 ke jawab aane se pehle shuru nahi hona chahiye, kyunki inme se chaar stored values badalte hain.

---

## 4. Rounding family — ek hi doc me teen alag rule

Ye number system ka hi hissa hai.

| Doc rule | Subject | Rounding |
|---|---|---|
| 1.3 | post allocation (सिग्निफ़िकेंट रूल) | **round half up**; aur jahan DR aur promotion dono `.5` pe aayen, DR ka `.5` hata kar promotion ko ek badha do |
| 2.1 | PH 3% reservation | **floor**, saaf likha hai ki `.9` pe bhi |
| 3.1 | CR average | **truncate** |

Aaj `config/dpc.php:742` `vacancy.promotion_rounding` ko **`floor`** rakhta hai, comment ke saath ki ye **BD-33** pending hai.

> **Rule 1.3 BD-33 ka jawab hai, aur wo `floor` nahi hai.** Wo `round` hai, plus ek tie-break jo `round` akela implement nahi karta: DR aur promotion ke beech `.5 / .5` ka batwara promotion ke haq me jaata hai.

`VacancyEngine.php:202-204` `ceil`, `round` aur `floor` support karta hai — **teeno me se koi tie-break implement nahi karta**, kyunki wo rounding mode hai hi nahi, cross-channel rule hai.

PH wala floor rule (2.1) ka code me koi counterpart nahi — `config/dpc.php` me PH/PwD reservation handling hai hi nahi.

---

## 5. Jo business decisions isse nikalti hain

Numbering BD-49 ke aage se, jo abhi sabse ooncha used hai.

| # | Sawal | Published list badlegi? |
|---|---|---|
| **BD-50** | **CR scale per CR year resolve hoga ya per calculation run?** Rule 3.5 ka *"पुराने सेवा अभिलेख के लिए"* keh raha hai ki CR apne saal ka scale leke chalti hai, yaani ek officer ki window dono system pe phail sakti hai. Doosra padhna ye ki ek run har saal pe ek hi scale lagaye. Dono se ek hi officer ka total alag aata hai. | **Haan** |
| **BD-51** | **9/7/5/3/1 scale pe qualifying thresholds kya hain?** Doc scale deta hai, thresholds nahi, aur khud kehta hai ki 15/13/11/10 purane system ke hain. Sifarish: `2T+5` → 35 / 31 / 27 / 25 — exact hai aur kisi officer ka verdict nahi badalta. Bina ruling ke scale badla to engine **sabko pass** kar dega. | **Haan** |
| **BD-52** | **Tribal-area bonus 2 hoga?** Slope-2 transform me `+1` ko `+2` banana padega warna asar aadha reh jaayega. | **Haan** |
| **BD-53** | **Naye scale pe घ = 1 abhi bhi outright disqualify karta hai, aur I-I ke last year me ग abhi bhi forbidden hai?** Average aane ke baad dono gates threshold banne padenge (`mark <= 1`, `mark <= 3`), grade equality nahi. Aur: jis **averaged** saal me ek घ shaamil hai wo disqualify karta hai, ya sirf wo saal jiska final mark 1 ho? | **Haan** |
| **BD-54** | **CR lagatar kitne saal carry forward ho sakti hai?** Unbounded carry-forward ek CR se poori window bhar deta hai. | **Haan** |
| **BD-55** | **Excel import chaar-segment reviewer chain support karega, ya sirf single-CR case?** | Nahi |
| **BD-56** | **Rule 3.1 ka apna khula point:** saal me teen CR likhi aur ek gayab — doc khud kehta hai koi sthapit niyam nahi, samiti tay karti hai. Average ka denominator kya hoga, aur samiti ka faisla logged discretion ki tarah record hoga? | Nahi, par marks badlenge |
| **BD-57** | **Rule 3.3:** Class-I → Class-I merit-cum-seniority me CR missing — doc kehta hai kuch mamlon me seedha circulation, kuch me pichhli CR maanya, koi spasht niyam nahi. **BD-15** aur **BD-49** se overlap karta hai. | Nahi |
| **BD-58** | **BD-33 dobara khula.** Rule 1.3 use `round` + DR/promotion `.5` tie-break ke roop me jawab deta hai, jo kisi maujooda rounding mode me nahi hai. Abhi default `floor` hai. | **Haan** |

**Pehle BD-51 padhiye.** Wo akela aisa item hai jo scale badalne par **har officer ko pass** kara sakta hai, aur uska jawab pehle se nikla hua hai (`2T+5`), sirf approve hona hai.

---

## 6. Jo chhua nahi gaya

Koi code, configuration, migration ya test modify nahi hua. `docs/ARCHITECT_REVIEW.md` jaan-boojh kar chhoda gaya — usme pichhle phase ka 96 KB review hai aur wo full-replace policy pe chalta hai, isliye use replace karna Architect ka faisla hai, is analysis ka side-effect nahi.

**Source PDF:** `~/Downloads/Understanding of DPC with Coder Instructions.pdf`
