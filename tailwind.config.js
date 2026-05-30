/** @type {import('tailwindcss').Config} */

// Professional accent-blue ramp — the single interactive / action colour.
// A brighter, more saturated cousin of the institutional `navy` chrome so the
// whole system reads as one cohesive blue family (navy = chrome / where you are,
// accent = action / what you do). Replaces the retired forest-green accent.
const accent = {
  50:  '#eef4fc',
  100: '#d9e6f9',
  200: '#b7d0f1',
  300: '#8ab1e6',
  400: '#5689d6',
  500: '#3a6fc4',
  600: '#2657aa',
  700: '#1d4488',
  800: '#173663',
  900: '#112742',
  950: '#0b1a2e',
};

export default {
  darkMode: 'class',
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./app/Livewire/**/*.php",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans:    ['"Plus Jakarta Sans"', '"Inter Tight"', 'system-ui', 'sans-serif'],
        serif:   ['"Source Serif 4"', '"Source Serif Pro"', 'Georgia', 'serif'],
        display: ['"Source Serif 4"', 'Georgia', 'serif'],
        mono:    ['"JetBrains Mono"', '"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
      letterSpacing: {
        tightish: '-0.015em',
        snug:     '-0.02em',
      },
      colors: {
        // Accent blue — the interactive / action colour (canonical name).
        accent,
        // `forest` is now a legacy alias of the accent-blue ramp: the ~228
        // existing forest-* usages across the views recolour to blue in one
        // move, with no per-file edits. New code should use accent-*.
        forest: accent,
        sage: {
          100: '#e6ebe6',
          300: '#b3c0b6',
          500: '#7d9181',
          700: '#4a5d4f',
        },
        // Navy — institutional chrome (RP band, masthead, active nav, document framing).
        // Slightly warm/slate so it sits on warm paper without clashing. Forest stays
        // the action/health accent; navy is "the institution / where you are".
        navy: {
          50:  '#eef1f6',
          100: '#dde3ee',
          200: '#bcc8dd',
          300: '#8e9fc1',
          400: '#5a6f9c',
          500: '#3a4f7a',
          600: '#2a3c61',
          700: '#1f2d4a',
          800: '#16213a',
          900: '#0f1729',
        },
        // Paper neutrals — warm off-white, like a journal page
        paper: {
          DEFAULT: '#fbfaf6',
          2:       '#f6f4ec',
          rule:    '#e8e4d6',
        },
        // Ink — high-contrast text on paper
        ink: {
          100: '#ebeae3',
          200: '#d4d6d0',
          300: '#a8aca5',
          400: '#8a8f86',
          500: '#6b7269',
          700: '#383d36',
          900: '#1a1d1a',
        },
        // Status colors — paper-friendly muted
        critical: {
          50:  '#fbeeea',
          100: '#f5dfd9',
          500: '#b94a3a',
          700: '#8a2a1f',
        },
        high: {
          50:  '#fff0e8',
          100: '#ffe0cc',
          500: '#e0621a',
          700: '#b84a10',
        },
        moderate: {
          50:  '#faf3dc',
          100: '#f3e6c2',
          500: '#c19a3b',
          700: '#8a6b1f',
        },
        low: {
          50:  '#e7f0ea',
          100: '#d6e6dc',
          500: '#4a8a68',
          700: '#2a6244',
        },
        info: {
          100: '#d6e1ec',
          500: '#527a9b',
          700: '#2c5273',
        },
        // Cluster accents (K=4 notebook palette)
        cluster: {
          1: '#2ecc71', // high-functioning — green
          2: '#3498db', // stable/moderate — blue
          3: '#f39c12', // env/financial vulnerable — amber
          4: '#e74c3c', // low-functioning/priority — red
        },
      },
      boxShadow: {
        sm: '0 1px 0 rgba(20, 30, 25, 0.04), 0 1px 2px rgba(20, 30, 25, 0.04)',
        md: '0 1px 0 rgba(20, 30, 25, 0.05), 0 4px 12px -2px rgba(20, 30, 25, 0.06)',
      },
    },
  },
  safelist: [
    'badge-cluster-1',
    'badge-cluster-2',
    'badge-cluster-3',
    'badge-cluster-4',
    'cluster-swatch-1',
    'cluster-swatch-2',
    'cluster-swatch-3',
    'cluster-swatch-4',
    'status-dot-ok',
    'status-dot-warn',
    'status-dot-err',
    'badge-low',
    'badge-moderate',
    'badge-high',
    'badge-critical',
    'badge-info',
    'badge-neutral',
    'kpi-rule',
    { pattern: /^(bg|text|border)-(low|moderate|high|critical|info|forest|accent)-(50|100|500|700)$/ },
    { pattern: /^(bg|text|border)-navy-(50|100|200|300|400|500|600|700|800|900)$/ },
  ],
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}
