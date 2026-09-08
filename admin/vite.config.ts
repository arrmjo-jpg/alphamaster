import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

/**
 * The API and the Admin share one origin, in development as in production.
 *
 * This proxy is not a convenience. The session cookie is `SameSite=Strict`
 * (ADR 0042), so a browser will not attach it to a request the page makes to a
 * different origin — and Vite on :5173 talking to the API on :8080 is a different
 * origin. Without the proxy, authentication fails in development only, and fails
 * as a bare 401 that looks like a broken backend.
 *
 * Production reaches the same arrangement through nginx: `/api` to the backend,
 * everything else to this application's built assets.
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
