/** @type {import('tailwindcss').Config} */
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
        sans:    ['"Inter Tight"', 'Inter', 'system-ui', 'sans-serif'],
        serif:   ['"Source Serif 4"', '"Source Serif Pro"', 'Georgia', 'serif'],
        display: ['"Source Serif 4"', 'Georgia', 'serif'],
        mono:    ['"JetBrains Mono"', '"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
      letterSpacing: {
        tightish: '-0.015em',
        snug:     '-0.02em',
      },
      colors: {
        // Forest & sage — primary palette
        forest: {
          50:  '#f3f7f4',
          100: '#e3ede7',
          200: '#c2d9cf',
          300: '#8cbcaa',
          400: '#5fa088',
          500: '#3f8068',
          600: '#2f6552',
          700: '#234d40',
          800: '#1a3a31',
          900: '#112a23',
          950: '#0a1f1a',
        },
        sage: {
          100: '#e6ebe6',
          300: '#b3c0b6',
          500: '#7d9181',
          700: '#4a5d4f',
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
        // Cluster accents
        cluster: {
          1: '#3347b0', // high-functioning — blue
          2: '#c49020', // moderate — amber
          3: '#b94a3a', // low-functioning — terracotta
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
    'cluster-swatch-1',
    'cluster-swatch-2',
    'cluster-swatch-3',
  ],
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
}
