# Recruitment Rule screen — before / after

Real screenshots of the running application, captured against the seeded demo
database as the CGPSC Admin. Not mock-ups: the page was fetched over HTTP from a
live server and photographed in headless Chromium, and the `after-form-*` shots
were taken after driving the form in the browser, because the grid only exists
once rows have been added and the total only turns red once the shares disagree.

| File | What it shows |
|---|---|
| `before-form.png` | The form as it was. Feeder conditions in a checkbox list; an unnamed `Promotion %` box; `Min Experience (yrs)` as a bare number; no running total; no Action column |
| `before-index.png` | The rule list as it was |
| `after-form-empty.png` | The rebuilt form before any feeder is added — three numbered steps, and the empty grid saying what to do next |
| `after-form-filled.png` | Two feeder cadres, shares totalling **100% ✓**, experience as *8 Years* and *18 Months*, qualification from the master |
| `after-form-unbalanced.png` | Shares at 40 + 30. The total reads **70% ✗** with *"Must total exactly 100% — 30% still unallocated"* |
| `after-form-dropdown.png` | The searchable Feeder Cadre dropdown open and filtered on "e". Note the target cadre (Lecturer) is **absent** — a post cannot feed itself |
| `after-index.png` | The rule list, showing feeder cadre → share → experience → qualification as resolved references |
| `after-qualification-master.png` | Administration → Qualifications, with the *Used by* column |
| `after-form-split-balanced.png` | **DR + PR (2026-08-18).** DR 25 / PR 75 — the pair on its own row, full labels, `%` affixes, and the running total reading **100% ✓** *"Every post is accounted for."* |
| `after-form-split-unbalanced.png` | DR 30 / PR 50 — **80% ✗** *"20% of the posts is unaccounted for."* Saving in this state is refused server-side, message and all |

## Reproducing them

```bash
php artisan serve --port=8744          # a seeded database, see demo:seed
node drive.js                          # asserts the grid behaviour in the browser
node shot-live.js after-form-filled filled
```

`drive.js` and `shot-live.js` are working scripts, not part of the application;
they live in the session scratchpad. Both talk to Chromium over the DevTools
protocol. Two things to know if you re-run them:

- Chromium is a snap here, so it can only read and write inside `$HOME`, and not
  in hidden directories — the staging directory is `~/dpc-shots`.
- The snap profile loads an extension, so the DevTools target must be filtered to
  `type === 'page'` or you attach to the extension's background page instead.
