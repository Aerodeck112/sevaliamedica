import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/panou.css',
                'resources/js/panou.js',
                'resources/css/site.css',
                'resources/js/site.js',
            ],
            refresh: true,
        }),
    ],
    build: {
        // Fără sourcemap în pachetul de producție.
        sourcemap: false,
        assetsInlineLimit: 0,
    },
});
