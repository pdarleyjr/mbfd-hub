/** @type {import('tailwindcss').Config} */
export default {
    content: ["./index.html", "./src/**/*.{js,ts,jsx,tsx}"],
    theme: {
        extend: {
            colors: {
                mbfd: {
                    red: '#B91C1C',
                    light: '#DC2626',
                    dark: '#991B1B',
                },
                neutral: {
                    50:  '#FAFAF8',
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
            },
            fontFamily: {
                sans: ['"Source Sans 3"', '"DM Sans"', 'system-ui', 'sans-serif'],
                heading: ['"Plus Jakarta Sans"', '"Outfit"', 'system-ui', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'monospace'],
            },
            fontSize: {
                'fluid-sm': ['clamp(0.8rem, 0.17vw + 0.76rem, 0.89rem)', { lineHeight: '1.5' }],
                'fluid-base': ['clamp(1rem, 0.34vw + 0.91rem, 1.19rem)', { lineHeight: '1.6' }],
                'fluid-lg': ['clamp(1.25rem, 0.61vw + 1.1rem, 1.58rem)', { lineHeight: '1.4' }],
                'fluid-xl': ['clamp(1.56rem, 1vw + 1.31rem, 2.11rem)', { lineHeight: '1.3' }],
                'fluid-2xl': ['clamp(1.95rem, 1.56vw + 1.56rem, 2.81rem)', { lineHeight: '1.2' }],
                'fluid-3xl': ['clamp(2.44rem, 2.38vw + 1.85rem, 3.75rem)', { lineHeight: '1.1' }],
            },
        },
    },
    plugins: [],
}