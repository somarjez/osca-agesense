---
name: AgeSense
description: Civic senior-citizen profiling and analytics for OSCA Pagsanjan — a warm paper-and-ink record framed in institutional navy, not a SaaS dashboard.
colors:
  navy-900: "#0f1729"
  navy-800: "#16213a"
  navy-700: "#1f2d4a"
  navy-500: "#3a4f7a"
  navy-300: "#8e9fc1"
  navy-50: "#eef1f6"
  accent-700: "#1d4488"
  accent-600: "#2657aa"
  accent-500: "#3a6fc4"
  accent-400: "#5689d6"
  accent-50: "#eef4fc"
  paper: "#fbfaf6"
  paper-2: "#f6f4ec"
  paper-rule: "#e8e4d6"
  ink-900: "#1a1d1a"
  ink-700: "#383d36"
  ink-500: "#6b7269"
  ink-400: "#8a8f86"
  low-500: "#4a8a68"
  moderate-500: "#c19a3b"
  high-500: "#e0621a"
  critical-500: "#b94a3a"
  info-500: "#527a9b"
  cluster-1: "#2ecc71"
  cluster-2: "#3498db"
  cluster-3: "#f39c12"
  cluster-4: "#e74c3c"
  dark-bg: "#131917"
  dark-surface: "#1a201d"
  dark-rule: "#2b3530"
  dark-ink: "#e4e1d8"
typography:
  display:
    fontFamily: "Source Serif 4, Georgia, serif"
    fontSize: "32px"
    fontWeight: 600
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Source Serif 4, Georgia, serif"
    fontSize: "15px"
    fontWeight: 600
    letterSpacing: "-0.015em"
  body:
    fontFamily: "Plus Jakarta Sans, Inter Tight, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "Plus Jakarta Sans, system-ui, sans-serif"
    fontSize: "10.5px"
    fontWeight: 600
    letterSpacing: "0.13em"
  mono:
    fontFamily: "JetBrains Mono, IBM Plex Mono, ui-monospace, monospace"
    fontSize: "11px"
    fontWeight: 500
rounded:
  lg: "0.5rem"
  xl: "0.75rem"
  2xl: "1rem"
  full: "9999px"
spacing:
  card: "1.25rem"
  section: "1.25rem"
components:
  button-primary:
    backgroundColor: "{colors.accent-600}"
    textColor: "{colors.paper}"
    rounded: "{rounded.xl}"
    padding: "0.5rem 0.875rem"
  button-primary-hover:
    backgroundColor: "{colors.accent-700}"
  button-default:
    backgroundColor: "#ffffff"
    textColor: "{colors.ink-700}"
    rounded: "{rounded.xl}"
    padding: "0.5rem 0.875rem"
  card:
    backgroundColor: "#ffffff"
    textColor: "{colors.ink-900}"
    rounded: "{rounded.2xl}"
    padding: "{spacing.card}"
  badge-low:
    backgroundColor: "{colors.low-500}"
    textColor: "{colors.low-700}"
    rounded: "{rounded.full}"
  input:
    backgroundColor: "#ffffff"
    textColor: "{colors.ink-900}"
    rounded: "{rounded.xl}"
    padding: "0.625rem 0.75rem"
---

# Design System: AgeSense

## 1. Overview

**Creative North Star: "The Field Ledger, formalized"**

AgeSense looks like a well-kept civic record book, not a software product. The body sits on a warm off-white paper (#fbfaf6) with a hairline rule color (#e8e4d6) borrowed from ruled stationery; headings are set in a serif (Source Serif 4) the way a printed report titles its sections; data runs in a tabular-figure mono so columns line up like a ledger. The result reads as institutional and trustworthy: the kind of surface a municipal health office would keep records in, calm enough to live with all day.

Institutional authority is carried by a **single cohesive blue family**: an official deep **navy** owns the chrome (the Republic-of-the-Philippines band atop every screen, the serif masthead, the active navigation, document framing) while a brighter, decisively-blue **accent** (#2657aa / #3a6fc4) is the single **action accent** (primary buttons, links, focus rings, framework chip). The rule reads cleanly: *navy is the institution and where you are; accent blue is what you do.* Green is no longer a brand colour — it survives only as a *semantic* signal (low-risk and Group 1 = "healthy"), so nothing on the chrome clashes with the navy. A Republic of the Philippines band, an AgeSense application mark, a serif "Office of Senior Citizens Affairs" masthead, and an official document footer (control number, system-generated note, signatory line) give the system the ambiance of a sanctioned government record.

The system explicitly rejects the 2026 SaaS defaults: no cream-startup-with-purple-accent, no neon or dark-gradient fintech, no glassmorphism or gradient text, no AI-hype sheen. Risk is graded and calm, never an alarmist wall of red. Decoration never competes with the data; the chrome recedes so scores, charts, and drivers carry the screen.

A full dark theme mirrors the paper world in low light (#131917 ground, #1a201d surfaces, #e4e1d8 ink) for staff working late, remembered per device.

**Key Characteristics:**
- Warm paper ground + ink text, framed in institutional navy chrome, with one accent blue as the action colour (Restrained color strategy, single blue family).
- Serif for titles, humanist sans for body, mono for all numbers.
- Rounded-2xl cards, rounded-xl controls; soft, low shadows.
- Graded status palette (low/moderate/high/critical) plus a fixed 4-color health-group palette.
- Color is never the only signal: every badge carries a word.

## 2. Colors

A restrained, warm-neutral palette in a single cool **blue family**: paper and ink do the work, navy + accent-blue are the one voice, and a graded status set speaks only for risk and state.

### Primary — chrome (navy) + action (accent blue)
- **Navy** (#0f1729 / #16213a / #1f2d4a, slightly warm-slate so it sits on warm paper): the institutional **chrome** colour. The Republic-of-the-Philippines band, the serif masthead, the application-mark tile, the active navigation fill, the login editorial panel, and document framing (page under-rule, section banner). Navy means *the institution / where you are* — never an action.
- **Accent blue** (#2657aa primary, #3a6fc4 mid, #5689d6 light — a brighter, cleaner cousin of navy that reads unmistakably blue, never green): the single **action accent**. Primary buttons (`accent-600`), links, focus rings, the framework chip, the login "Sign in" eyebrow, and primary chart series. Accent blue means *what you do* — never chrome. (Implementation note: the legacy `forest-*` Tailwind classes are aliased to this same accent ramp, so existing markup renders blue; new code uses `accent-*`.)

### Secondary
- **Health-Group palette** (Group 1 #2ecc71 green, Group 2 #3498db blue, Group 3 #f39c12 amber, Group 4 #e74c3c red): a fixed, notebook-derived 4-color set used *only* to identify the K=4 health groups (cluster badges, swatches, group chart series). These four hues are reserved for cluster identity and never used as general accents.

### Tertiary
- **Status / risk ramp**: Low #4a8a68 (green), Moderate #c19a3b (amber), High #e0621a (orange), Critical #b94a3a (red), Info #527a9b (blue). Each has a 50/100 tint for backgrounds and a 700 shade for text. Used for risk badges, KPI accents, progress bars, and service-status dots.

### Neutral
- **Paper** (#fbfaf6 default, #f6f4ec recessed, #e8e4d6 rule): page ground, recessed inner panels, and hairline borders/dividers.
- **Ink** (#1a1d1a 900 through #6b7269 500, #8a8f86 400, #a8aca5 300): text and icons. 900/700 for primary text, 500 for secondary, 400 for muted labels.
- **Dark theme neutrals**: #131917 ground, #1a201d surface, #2b3530 rule, #e4e1d8 primary ink.

### Named Rules
**The One-Family Rule.** The brand is a single blue family with two roles: **navy** is institutional chrome (band, masthead, mark, active nav, document framing), **accent blue** is action (buttons, links, focus, framework chip). Never swap them — a navy button or an accent-blue active-nav fill is wrong. Green is not a brand colour; it appears *only* as the semantic low-risk / Group-1 "healthy" signal. The health-group palette and the status ramp remain *semantic vocabularies*, not decoration: a hue only appears when it means a specific group or a specific risk state.

**The Calm Risk Rule.** High and Critical use muted orange/oxblood (#e0621a / #b94a3a), never pure saturated red, and never as a full-card fill. Risk is graded and legible, not alarming.

## 3. Typography

**Display Font:** Source Serif 4 (with Georgia, serif fallback)
**Body Font:** Plus Jakarta Sans (with Inter Tight, system-ui fallback)
**Label/Mono Font:** JetBrains Mono (with IBM Plex Mono, ui-monospace)

**Character:** A serif/sans contrast pairing: the serif gives titles a printed-report authority while the humanist sans keeps dense UI and forms quiet and legible. All numbers render in tabular-figure mono so ledgers, scores, and percentages align.

### Hierarchy
- **Display** (Source Serif 4, 600, ~32px, line-height 1.1, -0.02em): page titles and welcome headings (e.g. login "Welcome back.").
- **Headline / KPI value** (Source Serif 4, 600, 36px, tabular-nums): the single big number in KPI cards.
- **Title** (Source Serif 4, 600, 15px, -0.015em): card titles and section headings.
- **Body** (Plus Jakarta Sans, 400, 13px, line-height 1.5): UI text, table cells, form values. Prose blocks cap at 65–75ch.
- **Label / eyebrow** (Plus Jakarta Sans, 600, 10.5px, +0.13em, uppercase): field labels, KPI labels, small section eyebrows.
- **Mono** (JetBrains Mono, 500, ~11px, tabular-nums): all numeric data, scores, percentages, IDs.

### Named Rules
**The Tabular Number Rule.** Every number that sits in a column, score, or percentage uses mono with `tabular-nums`. Numbers never reflow or jitter as values change.

**The Serif-for-Titles Rule.** The serif is reserved for titles, headings, and hero numbers. Never set body copy, buttons, labels, or data in the serif.

## 4. Elevation

Mostly flat with soft, low shadows. Surfaces are paper-on-paper, separated by the 1px rule color and very light shadows, not heavy drop shadows. Depth is tonal first (paper vs paper-2 vs white card), shadow second.

### Shadow Vocabulary
- **Card rest** (`box-shadow: 0 1px 0 rgba(20,30,25,0.04), 0 1px 2px rgba(20,30,25,0.04)`): the default near-flat card lift.
- **Card hover / lift** (`0 1px 0 rgba(20,30,25,0.05), 0 4px 12px -2px rgba(20,30,25,0.06)`): interactive cards on hover, paired with a 0.5px translate-up.
- **Modal** (`shadow-2xl`): the only place a strong shadow is allowed, to separate a dialog from its backdrop.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. A lift appears only as a response to state (card hover, modal). If a card has a heavy shadow at rest, it is wrong.

## 4b. Motion

Motion is restrained and functional: it conveys state and guides the eye, never decorates. Every transition eases out (`cubic-bezier(0.22, 1, 0.36, 1)`); no bounce or elastic. The global `prefers-reduced-motion` rule collapses all of it to instant.

- **Entrance** (`.rise-in`, optional `.rise-1..4` stagger): a one-time 0.5s fade + 10px rise for top-level dashboard blocks. Content is present and server-rendered by default; the animation only adds the rise, so nothing ships blank if it doesn't run.
- **Hover lift** (`.card-lift`, and `.kpi` directly): interactive cards and KPIs translate up 2px with a soft shadow on hover (0.18s). Static prose cards do not lift.
- **Dropdown (accordion) cards:** the dashboard's Urgent Pending Actions and Recent Senior Records collapse vertically via a `grid-template-rows` `1fr ↔ 0fr` transition (no plugin needed); the chevron rotates 180°, and each card's open state persists in `localStorage` (`oscaUrgentOpen` / `oscaRecentOpen`). Lists scroll inside a capped `max-h` when long.
- **Data viz:** doughnuts carry a center total (custom `centerText` Chart.js plugin) and `hoverOffset` segments, with a 0.75s `easeOutQuart` rotate/scale entrance; legends pair count + percentage. The Risk Distribution doughnut is **click-to-filter** (slice click dispatches `dashboard-filter-risk` to the Livewire component). Bar charts use a vertical accent gradient with value labels above each bar; the Barangay panel is a proportional **data-bar list** (accent total bar + high-risk overlay), not a table. Dashboard layout: analytics `lg:col-span-2`, priorities dropdowns in the 1/3 side column.
- **Micro-interactions:** primary-button arrow slides on hover (`group-hover:translate-x-1`); buttons `active:scale-[0.97]`; chevrons rotate on toggle. All 150–320ms.

## 5. Components

### Buttons
- **Shape:** gently rounded (rounded-xl, 0.75rem). Inline-flex, icon + label, 0.375rem gap.
- **Primary** (`.btn .btn-primary`): accent-600 fill, paper text; hover accent-700. For the single main action on a screen.
- **Default** (`.btn`): white fill, paper-rule border, ink-700 text; hover lifts border to ink-300 and bg to paper-2.
- **Ghost / Secondary / Danger:** ghost is transparent (toolbar actions); secondary is paper-2 fill; danger is critical-500 fill, used only for destructive confirmation.
- **Hover / Focus / Active:** 150ms transition; `active:scale-[0.97]`; focus shows an accent-blue ring (`focus:ring-2 ring-forest-500/25`, where `forest-*` is aliased to the accent ramp). Loading swaps the label for a `.btn-spinner` and disables. Icons inside buttons are normalized to 0.875rem.

### Chips / Badges
- **Risk badge** (`.badge .badge-{low|moderate|high|critical}`): pill (rounded-full), tinted background + matching 700-shade text + hairline border, always with a text label and a small dot.
- **Cluster badge** (`.badge-cluster-{1..4}`): pill in the fixed health-group hue, labelled "Group N".
- Badges never rely on color alone; the word is always present.

### Cards / Containers
- **Corner Style:** rounded-2xl (1rem).
- **Background:** white on paper (light); #1a201d surface on #131917 (dark).
- **Shadow Strategy:** Card-rest by default; Card-hover only on interactive cards (see Elevation).
- **Border:** 1px paper-rule (#e8e4d6 light / #2b3530 dark).
- **Internal Padding:** 1.25rem (`.card-body`); header is a `.card-head` strip with a serif `.card-title`.
- Nested cards are forbidden; recessed regions use paper-2, not a second card.

### Inputs / Fields
- **Style:** white fill, 1px paper-rule border, rounded-xl, 13px text.
- **Focus:** border shifts to accent-500 with a soft accent-blue ring (`ring-2 ring-forest-500/20`, aliased to the accent ramp).
- **Error:** critical-400 border + critical-700 message text below.

### Navigation
- **Side nav** with collapsible rail; **top bar** with page title, search, dark-mode toggle. A full-width **Republic of the Philippines band** sits above the whole shell.
- Nav links: 13px medium, ink-700; hover tints navy (navy-50 / navy-700 text); **active state is a full navy-800 fill** with paper text (no side-stripe indicator). Active nav is chrome, hence navy — not the action accent.
- Mobile: the sidebar collapses; tables scroll horizontally inside cards.

### Government chrome (signature)
- **RP band** (`<x-gov-band>`, `.gov-band`): a 28px navy-900 strip across the top of the app shell and login — "Republic of the Philippines · Province of Laguna · Municipality of Pagsanjan", with the office name trailing. Small all-caps tracked label in paper/navy-200.
- **Application mark** (`<x-app-logo>`): a navy rounded-tile monogram — a serif "A" in paper crossed by an accent-blue "ledger rule", pairing the chrome and action blues. Replaces any generic seal; scales by `size` prop.
- **Serif masthead** (`.masthead-name` / `.masthead-office`): the navy "AgeSense / Office of Senior Citizens Affairs" lockup in the sidebar brand block and login.
- **Document footer** (`<x-doc-footer>`, `.doc-footer`): official footer on senior records and reports — control number (mono), system-generated provenance line, and a ruled signatory line. Subtle on screen, formal on paper.
- **Page under-rule** (`.page-underrule`): a short 2px navy "ledger rule" under page-header titles that firms up document framing.

### Segmented control (signature)
`.segmented` is a paper-2 pill group used for in-card tab switching (e.g. Model Insights domain tabs). The active button (`.on`) gets a white fill and ink-900 text.

## 6. Do's and Don'ts

### Do:
- **Do** keep the single-blue-family split: navy for chrome (band, masthead, mark, active nav, document framing), accent blue for action (buttons, links, focus, framework chip). Green is semantic-only (low-risk / Group 1). Reserve the four health-group hues for cluster identity and the status ramp for risk/state only (The One-Family Rule).
- **Do** set every number in mono with `tabular-nums`.
- **Do** use rounded-2xl for cards and rounded-xl for controls, with the soft Card-rest shadow.
- **Do** give every interactive element a visible `:focus-visible` accent-blue ring and a `prefers-reduced-motion` fallback.
- **Do** label every risk and group badge with a word; color is a reinforcement, never the only signal.
- **Do** keep High/Critical muted (#e0621a / #b94a3a) and never fill a whole card with them (The Calm Risk Rule).

### Don't:
- **Don't** ship the SaaS-cream-with-purple-accent look, neon/dark-gradient fintech, glassmorphism, or gradient text (`background-clip: text`). These are the project's named anti-references.
- **Don't** use a colored `border-left`/`border-right` stripe as an accent on cards or alerts; use full hairline borders or background tints.
- **Don't** set body copy, buttons, labels, or data in the serif; the serif is titles and hero numbers only.
- **Don't** nest a card inside a card; use a paper-2 recessed panel instead.
- **Don't** rely on muted light-gray body text on tinted paper; body must clear 4.5:1.
- **Don't** add orchestrated page-load animation; motion conveys state (hover, loading, reveal), not choreography.
- **Don't** phrase risk as a clinical verdict; it is decision support ("indicates possible risk", graded levels, with the disclaimer in the risk card).
