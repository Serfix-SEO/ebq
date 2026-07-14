import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/marketing.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        // Never wipe public/build/assets on rebuild — old hashed CSS/JS
        // files must keep resolving at their original URL. Microsoft
        // Clarity session recordings reference the exact hashed filename
        // in effect when the recording was made; if a later `npm run
        // build` deletes that file, the recording plays back with broken
        // CSS (the browser 404s fetching it). manifest.json still only
        // points at the CURRENT build's files, so live pages are
        // unaffected — this only stops old files from being deleted.
        emptyOutDir: false,
    },
});
