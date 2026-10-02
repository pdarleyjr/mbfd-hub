export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.js'],
    corePlugins: { preflight: false },
    theme: {
        extend: {
            colors: { navy: '#173454', aqua: '#087e86', workspace: '#f1f3f5' },
            fontFamily: { sans: ['Figtree', 'Segoe UI', 'sans-serif'] },
        },
    },
};
