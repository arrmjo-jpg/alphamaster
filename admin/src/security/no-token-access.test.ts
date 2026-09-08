import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * The session credential is unreachable from JavaScript, and this proves it stays
 * that way.
 *
 * The access token travels in an HttpOnly cookie (ADR 0042). That is worth nothing
 * if some later screen "helpfully" reads a token out of storage and sets its own
 * Authorization header — the credential would then be readable by any script on the
 * page, which is the exact property the cookie exists to remove. Review does not
 * catch this reliably. A scan does.
 */

const SOURCE_ROOT = join(import.meta.dirname, '..');

const IGNORED_DIRECTORIES = new Set(['generated', 'node_modules']);

/** The file that argues about tokens in prose is this one. */
const IGNORED_FILES = new Set(['no-token-access.test.ts']);

const FORBIDDEN: { pattern: RegExp; why: string }[] = [
    {
        pattern: /document\s*\.\s*cookie/,
        why: 'reads or writes cookies directly; the session cookie is HttpOnly and must stay unreachable',
    },
    {
        pattern:
            /(?:localStorage|sessionStorage)[\s\S]{0,40}?\b(?:token|jwt|bearer|auth_token|access_token)\b/i,
        why: 'puts a credential in web storage, where any script on the page can read it',
    },
    {
        pattern: /['"`]Authorization['"`]\s*:/,
        why: 'sets an Authorization header; the cookie is the transport and the client sets no credential header',
    },
    {
        pattern: /Bearer\s+\$\{/,
        why: 'builds a bearer header from a value the page holds',
    },
];

/**
 * Comments are prose, and prose talks about the very thing being banned — this file
 * and the API client both explain why the token is unreachable. Stripping them keeps
 * the scan pointed at code. Whole-line comments only, so a URL in a string is never
 * mistaken for the start of one.
 */
function code(source: string): string {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .filter((line) => !/^\s*(?:\/\/|\*)/.test(line))
        .join('\n');
}

function sourceFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((entry) => {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            return IGNORED_DIRECTORIES.has(entry) ? [] : sourceFiles(path);
        }

        if (!/\.tsx?$/.test(entry) || IGNORED_FILES.has(entry)) {
            return [];
        }

        return [path];
    });
}

describe('the Admin holds no credential', () => {
    const files = sourceFiles(SOURCE_ROOT);

    it('scans a source tree that is actually there', () => {
        // Without this the suite would pass loudest when the scan is broken.
        expect(files.length).toBeGreaterThan(10);
    });

    it.each(FORBIDDEN)('no source file $why', ({ pattern }) => {
        const offenders = files.filter((file) => pattern.test(code(readFileSync(file, 'utf8'))));

        expect(offenders).toEqual([]);
    });
});
