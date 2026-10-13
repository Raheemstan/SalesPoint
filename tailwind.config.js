import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/*
|--------------------------------------------------------------------------
| Tailwind v3
|--------------------------------------------------------------------------
|
| This project briefly had both v3 (this file + postcss.config.js) and the v4
| Vite plugin installed. v3 is the version that is kept: the whole app is
| written against a v3 config (`@tailwindcss/forms`, `darkMode: 'class'`,
| `theme.extend`), so converting to v4 would mean rewriting every view.
| `@tailwindcss/vite` has been removed from package.json.
|
*/

/** @type {import('tailwindcss').Config} */
export default {
    /*
    | The app toggles a `dark` class on <html> (see the themeSwitcher Alpine
    | component), so dark: must follow that class. Tailwind's default is the
    | prefers-color-scheme media query, which ignored the class entirely and
    | made the "Light"/"Dark" setting do nothing on an OS set the other way.
    */
    darkMode: 'class',

    /*
    | Every place a class name can appear. Tailwind only emits classes it can
    | find as literal text, so anything not covered here silently disappears
    | from the build - which is exactly what happened when the CDN build (which
    | generates classes at runtime) was removed.
    */
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/views/**/*.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Figtree if the till happens to have it installed, otherwise the
                // system stack. Nothing is fetched from a font CDN: this app has to
                // start with no internet.
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            // `primary` is an alias for blue, which is what the existing pages use.
            // The DEFAULT shade is what makes bare `bg-primary` / `text-primary`
            // work (purchases/create.blade.php already uses `bg-primary`).
            colors: {
                primary: { ...colors.blue, DEFAULT: colors.blue[600] },
            },

            spacing: {
                // Minimum comfortable tap target on a touchscreen till.
                touch: '2.75rem',
            },

            zIndex: {
                drawer: '40',
                modal: '50',
                toast: '60',
            },
        },
    },

    plugins: [forms],
};
