import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
    // outDir is nested inside the project's real public/ web root, so Vite's
    // default publicDir-copy step would try to copy public/ into itself.
    publicDir: false,
    build: {
        outDir: 'public/js/dist',
        emptyOutDir: false,
        rollupOptions: {
            input: 'resources/react/booking-calendar/main.jsx',
            output: {
                entryFileNames: 'booking-calendar.js',
                assetFileNames: 'booking-calendar.[ext]',
            },
        },
    },
});
