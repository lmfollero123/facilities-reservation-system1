import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],
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
