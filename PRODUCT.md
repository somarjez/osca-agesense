# Product

## Register

product

## Users

OSCA (Office of Senior Citizens Affairs) staff for the municipality of Pagsanjan, Laguna, working in a local-government office on desktop machines. Three roles with distinct jobs:

- **Administrators** run the full workflow: manage senior profiles, run ML batch/single assessments, archive and export, manage user accounts.
- **Encoders** capture and maintain senior profiles and Quality-of-Life surveys, and run assessments.
- **Viewers** read dashboards, reports, and profiles but change nothing.

Their job is to **identify and prioritize the senior citizens who most need care or intervention**, then act on that (home visits, livelihood/health referrals, case management). The system is also presented in an academic defense setting, so claims must be defensible and reproducible.

## Product Purpose

AgeSense profiles senior citizens against the **WHO Healthy Ageing framework** (three domains: Intrinsic Capacity, Environment, Functional Ability), then runs a machine-learning pipeline that produces a composite risk score, a 3-level risk classification (LOW / MODERATE / HIGH) with an urgency flag, a K=4 health-group assignment, per-senior explainability (XAI risk drivers), and decision-support recommendations.

Success looks like: a staff member opens the dashboard, immediately sees who is high-risk and urgent, understands *why* (the drivers), and can act, with results that stay consistent across machines and reproduce the validated notebook foundation.

It is **decision support, not clinical diagnosis**. Every risk surface carries that framing.

## Brand Personality

Trustworthy, grounded, civic. The voice of a careful public-health record, not a startup. Calm and plain-spoken; it states what the data shows and hedges what it infers. Emotional goals: **confidence** (staff trust the numbers), **clarity** (no jargon walls), and **care** (the seniors are people, not rows).

Copy is specific and literal: "Run batch analysis", "Take snapshot", "raises risk / lowers risk". No marketing register, no exclamation, no alarm.

## Anti-references

- **SaaS-cream startup dashboards** (the generic Inter-on-near-white-with-a-purple-accent look).
- **Neon / dark-gradient fintech** and crypto-style dashboards.
- **Glassmorphism**, decorative blur, and gradient text.
- **AI-hype marketing** aesthetics and buzzword copy ("supercharge", "AI-powered insights").
- **Alarmist clinical dashboards** where everything is red and urgent; risk here is graded and calm.

## Design Principles

- **Decision support, not diagnosis.** Risk surfaces hedge: graded levels, a disclaimer in the risk card, "indicates possible risk" phrasing. Never assert a clinical verdict.
- **Earned familiarity.** Staff are not power users. Use standard, boring, correct affordances (top bar + side nav, tables, modals for quick edits). The tool should disappear into the task.
- **Legible over decorative.** Body and data must clear 4.5:1 contrast. Color is never the only signal; badges always carry text. Light gray "for elegance" is banned.
- **Consistency over surprise.** One button vocabulary, one card vocabulary, one icon style across every screen. The same thing looks the same everywhere.
- **The data is the hero.** Charts, scores, and drivers carry the screen. Chrome (nav, headers) recedes; accent color marks state and action, not decoration.

## Accessibility & Inclusion

- Target WCAG AA: body text ≥4.5:1, large/bold ≥3:1, including in dark mode.
- Visible keyboard focus rings on all interactive elements (`:focus-visible`).
- `prefers-reduced-motion` honored globally; transitions collapse to instant.
- Color is never the sole carrier of meaning: risk and group badges always include a text label.
- Full light **and** dark theme, toggled per-device and remembered.
- Tables scroll horizontally on small screens rather than clipping; modals trap focus, close on ESC, and lock body scroll.
