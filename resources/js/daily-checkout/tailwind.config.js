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
        },
    },
    plugins: [],
}