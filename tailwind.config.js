import defaultTheme from 'tailwindcss/defaultTheme';
import plugin from 'tailwindcss/plugin';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.jsx',
        './resources/**/*.tsx',
        './resources/**/*.ts',
        './resources/**/*.vue',
        './vendor/pxlrbt/filament-spotlight/resources/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                neutral: {
                    50: '#FAFAF8',
                    100: '#F5F3F0',
                    200: '#E8E5E0',
                    300: '#D4D0CA',
                    400: '#A8A29E',
                    500: '#78716C',
                    600: '#57534E',
                    700: '#44403C',
                    800: '#292524',
                    900: '#1C1917',
                },
                slate: {
                    850: '#1e293b',
                },
                // MBFD-tuned dark palette derived from slate-850 + red-600.
                // Used only when `darkMode: 'class'` is active on a panel.
                mbfd: {
                    bg: '#0f172a',
                    surface: '#1e293b',
                    'surface-2': '#273548',
                    border: '#334155',
                    text: '#e2e8f0',
                    'text-muted': '#94a3b8',
                    accent: '#dc2626',
                    'accent-hover': '#ef4444',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                heading: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                card: '0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px -1px rgb(0 0 0 / 0.08)',
                'card-hover': '0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08)',
                // Subtle inner shadow on cards/tables — the depth signal humans
                // read as "native desktop app" rather than "website".
                'enterprise-inner': 'inset 0 1px 0 rgb(255 255 255 / 0.06), inset 0 -1px 0 rgb(0 0 0 / 0.04)',
            },
            fontFeatureSettings: {
                tnum: '"tnum"',
            },
        },
    },
    plugins: [
        plugin(function ({ addVariant, addUtilities }) {
            // `pwa:` — applies when running as an installed PWA (no browser chrome).
            addVariant('pwa', '@media (display-mode: standalone), (display-mode: window-controls-overlay)');

            // `desktop:` — applies only on wide screens with a fine pointer
            // (i.e. mouse / trackpad — never on phones or basic tablets).
            // This is the safest gate for desktop-only UI changes.
            addVariant('desktop', '@media (min-width: 1280px) and (pointer: fine)');

            // `desktop-pwa:` — both gates simultaneously. Use for the truly
            // OS-integrated experience (window controls overlay tweaks, etc.).
            addVariant('desktop-pwa', '@media (min-width: 1280px) and (pointer: fine) and (display-mode: standalone)');

            // Utility: enable tabular figures (numeric columns line up).
            addUtilities({
                '.tnum': { fontFeatureSettings: '"tnum"' },
                // CSS env vars for Window Controls Overlay (Chromium 102+)
                '.wco-safe-area': {
                    paddingLeft: 'env(titlebar-area-x, 0)',
                    paddingTop: 'env(titlebar-area-y, 0)',
                    width: 'env(titlebar-area-width, 100%)',
                    height: 'env(titlebar-area-height, auto)',
                },
            });
        }),
    ],
};
