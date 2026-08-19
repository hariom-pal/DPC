# Pre-DPC screen — master-sourced fields

Real screenshots of the running application, driven in headless Chromium against
the seeded demo database as the **Authorized Officer**. Not mock-ups.

| File | What it shows |
|---|---|
| `after-form-master-sourced.png` | **2026-08-18.** Post Name, Feeder Cadre and Minimum Qualification are searchable dropdowns sourced from the masters; Class and Pay Scale filled in from the chosen cadre (Lecturer → Class-II, Level-11); every feeder column carries a label and a one-line note; DR + PR reads **100% ✓** on load |

## What changed

| Field | Before | After |
|---|---|---|
| **Post Name** | free-text `<input>` | `<select>` of the department's **Cadre master**, searchable |
| **Feeder Post** | free-text `<input>` with a placeholder | **Feeder Cadre** `<select>` of the same master, searchable |
| **Minimum Qualification** | free-text `<input>` | `<select>` of the **Qualification master**, searchable |
| **Feeder grid** | four bare inputs, **no header row at all** | five labelled columns — Feeder Cadre · Promotion Share (%) · Minimum Experience · Minimum Qualification · Action — each with a note |
| **Class / Pay Scale** | typed independently of the post | filled in from the chosen cadre, still editable |

The labels use the **same words as the Recruitment Rule master screen** for the
same four fields. That screen is what these entries are projected into on
approval, and a department should not have to learn two vocabularies for one
concept.

## Reproducing them

```bash
php artisan serve --port=8123          # a seeded database, see demo:seed
node predpc.js                         # 13 browser assertions on the grid
node predpc-save.js                    # fills the form and saves it end to end
```

Both scripts live in the session scratchpad and talk to Chromium over the
DevTools protocol. Two things to know if you re-run them:

- Chromium is a snap here, so it can only read and write inside `$HOME`, and the
  DevTools target list must be filtered to `type === 'page'` or you attach to the
  extension's background page instead.
- **`document.querySelector('form')` is the wrong form on any authenticated
  screen** — it matches the navbar's logout form, and submitting it logs you out
  and lands on `/`. Use `document.getElementById('posts').closest('form')`.
