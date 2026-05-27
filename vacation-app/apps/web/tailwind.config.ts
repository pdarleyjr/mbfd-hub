import type { Config } from 'tailwindcss';
import animate from 'tailwindcss-animate';

const config: Config = {
  darkMode: ['class'],
  content: ['./src/**/*.{ts,tsx}'],
  theme: {
    container: { center: true, padding: '1rem' },
    extend: {
      fontFamily: {
        sans: ['"Source Sans 3"', 'system-ui', 'sans-serif'],
        display: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      colors: {
        brand: {
          50: '#FEF2F2',
          600: '#DC2626',
          700: '#B91C1C',
        },
        admin: {
          700: '#374151',
          850: '#1e293b',
        },
        stone: {
          50: '#FAFAF9',
          100: '#F5F5F4',
          200: '#E7E5E3',
          400: '#A8A29E',
          600: '#78716C',
          800: '#292524',
        },
        status: {
          active: '#B91C1C',
          enroute: '#D97706',
          onscene: '#16A34A',
          clear: '#64748B',
        },
      },
      fontVariantNumeric: {
        tabular: 'tabular-nums',
      },
      transitionTimingFunction: {
        'pro-out': 'cubic-bezier(0.22, 1, 0.36, 1)',
      },
      keyframes: {
        'fade-in-up': {
          '0%': { opacity: '0', transform: 'translateY(4px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
      },
      animation: {
        'fade-in-up': 'fade-in-up 200ms cubic-bezier(0.22, 1, 0.36, 1) both',
      },
    },
  },
  plugins: [animate],
};

export default config;
