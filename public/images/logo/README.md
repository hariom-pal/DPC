# Logos

## Where each one is used

| Logo | Used in |
|---|---|
| `cgpsc` | Masthead left, footer, login and registration cards, and the signed-in header bars |
| `digital-india` | **Masthead right** (the public portal header) |
| `csc` | Footer only — the "Designed & developed with" credit |

## What is installed

| File | Source | Notes |
|---|---|---|
| `cgpsc-logo.png` | Official CGPSC emblem, from `psc.cg.gov.in` | 96 × 96. **Small** — replace with a higher-resolution copy from the Commission when one is available |
| `csc-logo.png` | Official CSC e-Governance Services India Limited mark, from `csc.gov.in` | 141 × 60 |
| `digital-india-logo.svg` | Official Digital India mark, from `digitalindia.gov.in` (`/wp-content/themes/di-child/assets/images/digital-india.svg`) | Vector, viewBox `0 0 142.4 80` (≈16:9). Renders at 42 px high in the masthead |

## Replacing them

**Drop the new file in with the same base name. Nothing else changes.**

`resources/views/partials/logo.blade.php` resolves the extension itself, trying
`.svg`, `.png`, `.webp`, `.jpg`, `.jpeg` in that order. So `cgpsc-logo.svg` from
the Commission will be picked up ahead of the PNG automatically — delete the PNG
or leave it, either works.

Every reference in the application goes through that partial, so there is no
template, layout, route or code change involved in swapping artwork.

## Fallbacks

`cgpsc-logo.fallback.svg`, `csc-logo.fallback.svg` and
`digital-india-logo.fallback.svg` are neutral placeholders. They are used **only**
if no real asset is present, so a fresh checkout renders rather than showing a
broken image. They are deliberately not imitations of the official marks: an
approximated emblem looks authentic and is wrong, which on a government portal is
worse than an obvious placeholder.

All three logos now have their real artwork installed, so no placeholder
renders. The `*.fallback.svg` files are kept because they are what a fresh
checkout falls back to if an asset is ever removed.

## Hero image

`../hero-building.svg` is a drawn illustration, not a photograph — there is no
licensed photography in this project. Replace it with an official photograph if
the Commission supplies one; keep the filename and the 8:5 aspect ratio.
