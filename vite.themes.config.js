import { readdirSync } from 'node:fs';

import { defineConfig } from 'vite';

// Every folder in themes/ is a theme: its theme.css, with the fonts it imports from
// @fontsource bundled next to it, so readers never load anything from elsewhere.
const themes = readdirSync('themes', { withFileTypes: true }).filter((entry) => entry.isDirectory()).map((entry) => entry.name);

// One fixed output path, replaced on every build (see CLAUDE.md, Disk hygiene).
export default defineConfig({
    publicDir: false,
    base: '/assets/build/themes/',
    build: {
        outDir: 'public/assets/build/themes',
        emptyOutDir: true,
        cssCodeSplit: true,
        rollupOptions: {
            input: Object.fromEntries(themes.map((theme) => [theme, `themes/${theme}/theme.css`])),
            output: {
                entryFileNames: '[name].js',
                assetFileNames: (asset) => (asset.names?.[0]?.endsWith('.css') ? '[name][extname]' : 'fonts/[name]-[hash][extname]'),
            },
        },
    },
});
