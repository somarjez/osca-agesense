---
name: AgeSense
description: Civic senior-citizen profiling and analytics for OSCA Pagsanjan — a warm paper-and-ink record, not a SaaS dashboard.
colors:
  forest-800: "#1a3a31"
  forest-700: "#234d40"
  forest-600: "#2f6552"
  forest-500: "#3f8068"
  forest-50: "#f3f7f4"
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
    backgroundColor: "{colors.forest-800}"
    textColor: "{colors.paper}"
    rounded: "{rounded.xl}"
    padding: "0.5rem 0.875rem"
  button-primary-hover:
    backgroundColor: "{colors.forest-700}"
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

**Creative North Star: "The Field Ledger"**

AgeSense looks like a well-kept civic record book, not a software product. The body sits on a warm off-white paper (#fbfaf6) with a hairline rule color (#e8e4d6) borrowed from ruled stationery; headings are set in a serif (Source Serif 4) the way a printed report titles its sections; data runs in a tabular-figure mono so columns line up like a ledger. A single forest green carries identity and action. The result reads as institutional and trustworthy: the kind of surface a municipal health office would keep records in, calm enough to live with all day.

The system explicitly rejects the 2026 SaaS defaults: no cream-startup-with-purple-accent, no neon or dark-gradient fintech, no glassmorphism or gradient text, no AI-hype sheen. Risk is graded and calm, never an alarmist wall of red. Decoration never competes with the data; the chrome recedes so scores, charts, and drivers carry the screen.

A full dark theme mirrors the paper world in low light (#131917 ground, #1a201d surfaces, #e4e1d8 ink) for staff working late, remembered per device.

**Key Characteristics:**
- Warm paper ground + ink text + one forest accent (Restrained color strategy).
- Serif for titles, humanist sans for body, mono for all numbers.
- Rounded-2xl cards, rounded-xl controls; soft, low shadows.
- Graded status palette (low/moderate/high/critical) plus a fixed 4-color health-group palette.
- Color is never the only signal: every badge carries a word.

## 2. Colors

A restrained, warm-neutral palette: paper and ink do the work, forest green is the single voice, and a graded status set speaks only for risk and state.

### Primary
- **Forest** (#1a3a31 deep, through #2f6552, #3f8068): the brand and action color. Primary buttons (forest-800), active nav fills, links, focus rings, primary chart series, and the login editorial panel (forest-900). Used for identity, current selection, and primary action, not decoration.

### Secondary
- **Health-Group palette** (Group 1 #2ecc71 green, Group 2 #3498db blue, Group 3 #f39c12 amber, Group 4 #e74c3c red): a fixed, notebook-derived 4-color set used *only* to identify the K=4 health groups (cluster badges, swatches, group chart series). These four hues are reserved for cluster identity and never used as general accents.

### Tertiary
- **Status / risk ramp**: Low #4a8a68 (green), Moderate #c19a3b (amber), High #e0621a (orange), Critical #b94a3a (red), Info #527a9b (blue). Each has a 50/100 tint for backgrounds and a 700 shade for text. Used for risk badges, KPI accents, progress bars, and service-status dots.

### Neutral
- **Paper** (#fbfaf6 default, #f6f4ec recessed, #e8e4d6 rule): page ground, recessed inner panels, and hairline borders/dividers.
- **Ink** (#1a1d1a 900 through #6b7269 500, #8a8f86 400, #a8aca5 300): text and icons. 900/700 for primary text, 500 for secondary, 400 for muted labels.
- **Dark theme neutrals**: #131917 ground, #1a201d surface, #2b3530 rule, #e4e1d8 primary ink.

### Named Rules
**The One Voice Rule.** Forest green is the only brand accent. The health-group palette and the status ramp are *semantic vocabularies*, not decoration: a hue only appears when it means a specific group or a specific risk state.

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

## 5. Components

### Buttons
- **Shape:** gently rounded (rounded-xl, 0.75rem). Inline-flex, icon + label, 0.375rem gap.
- **Primary** (`.btn .btn-primary`): forest-800 fill, paper text; hover forest-700. For the single main action on a screen.
- **Default** (`.btn`): white fill, paper-rule border, ink-700 text; hover lifts border to ink-300 and bg to paper-2.
- **Ghost / Secondary / Danger:** ghost is transparent (toolbar actions); secondary is paper-2 fill; danger is critical-500 fill, used only for destructive confirmation.
- **Hover / Focus / Active:** 150ms transition; `active:scale-[0.97]`; focus shows a forest ring (`focus:ring-2 ring-forest-500/25`). Loading swaps the label for a `.btn-spinner` and disables. Icons inside buttons are normalized to 0.875rem.

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
- **Focus:** border shifts to forest-500 with a soft forest ring (`ring-2 ring-forest-500/20`).
- **Error:** critical-400 border + critical-700 message text below.

### Navigation
- **Side nav** with collapsible rail; **top bar** with page title, search, dark-mode toggle.
- Nav links: 13px medium, ink-700; hover tints forest; **active state is a full forest-800 fill** with paper text (no side-stripe indicator).
- Mobile: the sidebar collapses; tables scroll horizontally inside cards.

### Segmented control (signature)
`.segmented` is a paper-2 pill group used for in-card tab switching (e.g. Model Insights domain tabs). The active button (`.on`) gets a white fill and ink-900 text.

## 6. Do's and Don'ts

### Do:
- **Do** keep forest green as the one brand accent; reserve the four health-group hues for cluster identity and the status ramp for risk/state only (The One Voice Rule).
- **Do** set every number in mono with `tabular-nums`.
- **Do** use rounded-2xl for cards and rounded-xl for controls, with the soft Card-rest shadow.
- **Do** give every interactive element a visible `:focus-visible` forest ring and a `prefers-reduced-motion` fallback.
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
