import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/flow.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // Built asset URLs inside CSS (the Inter Variable @font-face
    // sources) must be relative to the stylesheet that references
    // them. Vite's default base emits root-absolute URLs such as
    // /build/assets/inter-*.woff2, which 404 when the app is
    // mounted under a subpath (the /new staging mount), leaving
    // every visitor on a fallback font (audit finding T-1). A
    // relative URL resolves against the CSS file's own location,
    // so the same build works at the root and under any subpath.
    experimental: {
        renderBuiltUrl(filename, { hostType }) {
            if (hostType === 'css') {
                return './' + filename.split('/').pop();
            }
            return filename;
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
