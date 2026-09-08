import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

/**
 * The API and the Admin share one origin, in development as in production.
 *
 * This proxy is not a convenience, and the reason is worth stating precisely because
 * the obvious one is wrong. `SameSite=Strict` is not what makes it necessary: a
 * cookie's site is a registrable domain and does not include the port, so on
 * localhost the session would cross from :5173 to :8080 quite happily. What stops it
 * is CORS. The API answers with `Access-Control-Allow-Origin: *`, and a wildcard is
 * refused outright for a request whose credentials mode is `include` — the browser
 * says so in as many words. Measured: through the proxy `/auth/me` answers 200, and
 * the same call addressed to `http://localhost:8080` never completes.
 *
 * Production reaches the same arrangement through nginx: `/api` to the backend,
 * everything else to this application's built assets. There the two really are
 * different hosts, and SameSite does the work as well.
 */
const API_ORIGIN = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8080';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: API_ORIGIN,
                changeOrigin: false,
            },
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./src/test/setup.ts'],
        css: false,
        include: ['src/**/*.test.{ts,tsx}'],
    },
});
