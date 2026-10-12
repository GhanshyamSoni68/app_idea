import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/main.tsx'], refresh: true }),
        react(),
    ],
    test: { environment: 'jsdom', include: ['resources/js/**/*.test.ts', 'resources/js/**/*.test.tsx'] },
});
