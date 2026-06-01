---
name: agensense-impeccable
description: Use when running /impeccable on any AgeSense OSCA UI surface — provides the project-specific design register, stack constraints, audience profile, and color tokens so impeccable commands skip context-gathering and work correctly on first try.
---

# Impeccable: AgeSense OSCA Context

## Critical setup note

The impeccable scripts (`context.mjs`, `palette.mjs`, `detect.mjs`) are **not installed** in this project. Skip impeccable's Step 1 ("run context.mjs") — it will error. Use this skill as the context substitute.

## Register

**Product UI / app** — use `reference/product.md` when impeccable prompts for a register reference. This is an internal government analytics tool, not a marketing surface.

## Stack

| Layer | Detail |
|---|---|
| Templates | Laravel 11 Blade (`.blade.php`) |
| Styling | Tailwind CSS 3.4 JIT + `resources/css/app.css` component layer |
| Interactivity | Livewire 3 (most pages), plain JS only on `auth/login.blade.php` |
| Fonts (Google) | Plus Jakarta Sans (body), Source Serif 4 (serif/display), JetBrains Mono (mono) |

**No Alpine on the login page** — `x-data`, `x-show`, templates will render blank. Button loading state is handled with plain JS there.

## Audience

Non-technical LGU/government staff at the Office of Senior Citizens Affairs (OSCA), Pagsanjan, Laguna. They:
- Are not data scientists; avoid all ML/statistics terminology in visible UI
- Expect government-grade formality and trustworthiness
- Work in a Filipino local government context

## Design language

- **Tone:** editorial/institutional — authoritative, dignified, purpose-driven; not corporate SaaS
- **Left panel chrome:** `bg-navy-900` with layered glows; institutional; dark
- **Right panel / app content:** `bg-paper` (warm off-white `#fbfaf6`); light
- **Action color:** accent (≡ forest) — a mid-range blue ramp, used for buttons, links, focus rings

## Color token map

| Token | Role | Key shades |
|---|---|---|
| `navy` | Institutional chrome — nav, left panel, masthead | 700–900 for chrome; 200–300 for muted text on navy |
| `accent` / `forest` | Action — buttons, links, focus | 500 primary; 600 hover; 50/200 tinted backgrounds |
| `paper` | Page background | DEFAULT `#fbfaf6`; `paper-2` `#f6f4ec`; `paper-rule` `#e8e4d6` |
| `ink` | Text on paper | 900 headings; 700 body; 500 muted; 300 placeholders |
| `cluster.1–4` | Care group indicators | green/blue/amber/red |
| `low/moderate/high/critical` | Risk badges | each has 50/100/500/700 steps |

`forest` is an exact alias of `accent` — they're interchangeable; existing code uses `forest`, new code may use `accent`.

## Care groups — never show ML labels in UI

The K-means clusters map to plain-language care groups. **Never expose `K=4`, `cluster`, or `kmeans` in user-visible text.**

| Cluster | Color | Plain label |
|---|---|---|
| 1 | `#2ecc71` green | Thriving |
| 2 | `#3498db` blue | Stable |
| 3 | `#f39c12` amber | At-Risk |
| 4 | `#e74c3c` red | Priority Care |

## Key component classes (defined in `resources/css/app.css`)

`.card`, `.card-head`, `.card-title`, `.card-body` — standard card shell  
`.kpi`, `.kpi-label`, `.kpi-value`, `.kpi-rule` — stat cards  
`.badge`, `.badge-cluster-{1-4}`, `.badge-low/moderate/high/critical` — status badges  
`.btn`, `.btn-primary`, `.btn-ghost`, `.btn-secondary`, `.btn-danger` — buttons  
`.form-input`, `.form-select` — form fields  
`.eyebrow` — 10.5 px uppercase label (use sparingly; impeccable absolute ban applies)  
`.nav-link`, `.nav-link-active` — sidebar navigation  
`.gov-band`, `.gov-band-title` — republic-of-the-Philippines top strip  

## Impeccable absolute bans (already enforced in this codebase)

Side-stripe borders, gradient text, glassmorphism, hero-metric template, identical card grids, eyebrow on every section, numbered section markers as scaffolding. See impeccable's general guidance for full list.

## Common impeccable commands for this project

```
/impeccable audit resources/views/auth/login.blade.php
/impeccable polish resources/views/dashboard.blade.php
/impeccable critique resources/views/seniors/index.blade.php
/impeccable layout resources/views/reports/
```
