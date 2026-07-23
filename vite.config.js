import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    build: {
        // KEINE CSS-Minifizierung: der Standard (lightningcss) haengt an
        // einem nativen Plattform-Binary (optionalDependency), das npm auf
        // dem VPS wiederholt nicht installierte -> Produktions-Build brach
        // ab (23.07.2026). Der Verzicht kostet nur ~2 KB gzip (8.4 -> 10.7),
        // dafuer braucht der Build keine nativen Extra-Binaries mehr.
        cssMinify: false,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
