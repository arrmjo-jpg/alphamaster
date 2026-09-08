import { defineConfig } from '@hey-api/openapi-ts';

/**
 * The contract is the source of API truth (ADR 0011).
 *
 * Input is the committed document rather than a running server, so generation is
 * deterministic and the drift gate compares like with like. Output is committed and
 * never hand-edited: a generated file someone has corrected by hand is a file that
 * silently disagrees with the backend.
 */
export default defineConfig({
    input: '../backend/openapi.json',
    output: {
        path: './src/api/generated',
        format: 'prettier',
        lint: false,
    },
    plugins: ['@hey-api/typescript'],
});
