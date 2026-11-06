import { defineConfig } from 'vite';

// One fixed output path, replaced on every build (see CLAUDE.md, Disk hygiene).
export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public/assets/build',
        emptyOutDir: true,
        lib: {
            entry: 'frontend/editor/main.js',
            formats: ['es'],
            fileName: () => 'editor.js',
            cssFileName: 'editor',
        },
    },
});
