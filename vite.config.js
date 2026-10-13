import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/*
|--------------------------------------------------------------------------
| Asset build
|--------------------------------------------------------------------------
|
| Everything the browser needs is bundled here and served from public/build.
| This is a local point of sale: it is routinely used with no internet at all,
| so nothing may be loaded from a CDN. Tailwind is compiled through PostCSS
| (postcss.config.js + tailwind.config.js); Alpine, Chart.js and axios are
| bundled by Vite from resources/js/app.js.
|
*/
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],

    /*
    | Note: the manifest location is left to laravel-vite-plugin, which writes
    | public/build/manifest.json - where Laravel's @vite() looks for it. Setting
    | build.manifest here overrides that and moves it to build/.vite/, which
    | breaks every page.
    |
    | Filenames are content-hashed, which is what lets the service worker cache
    | JS and CSS indefinitely: a new build is a new URL, so it can never serve a
    | stale one.
    */
});
