# 2026-10-13 — CR upload aur CR number system ka gap analysis

**Task:** `Understanding of DPC with Coder Instructions.pdf` (6 pages, 2026-09-25) ko code ke khilaaf padha — do subjects ek saath: CR ka mark scale (rules 3.4, 3.5) aur CR capture structure (rules 3.1, 3.2, 3.3 + Part 4 undertakings).

**Outcome:** poora review ek hi jagah likha gaya —

> **`docs/CR_UPLOAD_AND_NUMBER_SYSTEM_REVIEW.md`**

Yahan dobara nahi likha ja raha taaki do copies drift na karein.

**Headline:** doc ka naya scale (9/7/5/3/1) purane ka exact affine transform hai — `naya = 2 × purana + 1` — isliye thresholds bhi exact nikalte hain (15→35, 13→31, 11→27, 10→25) aur kisi officer ka verdict nahi badalta. Naya scale bana hi isliye hai taaki rule 3.1 ka per-year CR average integer bane. Par average aate hi saal ki value grade nahi, **mark** ban jaati hai (8 aur 6 ka koi grade naam nahi), aur engine ke teeno gates abhi grade ka string compare karte hain — isliye M5 ko mark-based banana padega.

**Nayi business decisions:** BD-50 se BD-58. Inme se chhe published promotion list badal sakti hain. Pehle **BD-51** padhna hai.

**Code changed:** kuch nahi. Analysis only. `docs/ARCHITECT_REVIEW.md` jaan-boojh kar chhua nahi gaya (full-replace policy, 96 KB pichhla review).
