export default {
    // Tailwind 4 laeuft ueber @tailwindcss/vite (siehe vite.config.js) und ist
    // kein PostCSS-Plugin mehr; autoprefixer ist ebenfalls entbehrlich, weil
    // v4 die noetigen Vendor-Prefixe selbst erzeugt. Die Datei bleibt als
    // Anlaufstelle bestehen, falls spaeter doch ein PostCSS-Schritt dazukommt.
    plugins: {},
};
