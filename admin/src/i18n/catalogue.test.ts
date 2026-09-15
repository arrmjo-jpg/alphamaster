import { describe, expect, it } from 'vitest';

import source from '@catalogue/console/en.json';

/**
 * Every key the console reads is in the English catalogue (ADR 0049).
 *
 * The English catalogue is the Interface Translation Catalog: a key in it is offered for
 * translation in every language, and a key missing from it renders as a raw key in all of them
 * and is never offered at all. So a key used in code and absent from the catalogue is a defect
 * this fails on, rather than one an operator finds in French.
 *
 * Dynamic keys are allowed only where the catalogue can answer for them: the static part before
 * the variable must name a branch, and a suffix after it must exist under every child of that
 * branch. A key whose whole prefix is a variable is declared below, with the literal values that
 * reach it.
 */

type Node = { [key: string]: Node | string };

const PLURAL_SUFFIXES = ['_zero', '_one', '_two', '_few', '_many', '_other'];

function flatten(
    node: Node,
    path: string[] = [],
    leaves = new Set<string>(),
    branches = new Set<string>(),
) {
    for (const [key, value] of Object.entries(node)) {
        const here = [...path, key];

        if (typeof value === 'string') {
            leaves.add(here.join('.'));
        } else {
            branches.add(here.join('.'));
            flatten(value, here, leaves, branches);
        }
    }

    return { leaves, branches };
}

const catalogue = source as Node;
const { leaves, branches } = flatten(catalogue);

function hasKey(key: string): boolean {
    return leaves.has(key) || PLURAL_SUFFIXES.some((suffix) => leaves.has(`${key}${suffix}`));
}

function childrenOf(branch: string): Node {
    let node: Node | string = catalogue;

    for (const segment of branch.split('.')) {
        node = typeof node === 'string' ? '' : (node[segment] ?? '');
    }

    return typeof node === 'string' ? {} : node;
}

const applicationSources = import.meta.glob(
    ['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}', '!../api/generated/**', '!../test/**'],
    { query: '?raw', import: 'default', eager: true },
);

/**
 * Keys whose whole prefix is a variable, by file, and where the prefix comes from. Each value
 * must be a branch of the catalogue; the test reads the literals from the file itself, so a new
 * literal that names no branch still fails.
 */
const WHOLLY_DYNAMIC: Record<string, { pattern: RegExp; values: RegExp }> = {
    // FilterSegments renders `${prefix}.${option}`; its callers pass the prefix as a literal.
    '../screens/MediaScreen.tsx': {
        pattern: /t\(`\$\{prefix\}\.\$\{option\}`/,
        values: /prefix="([^"]+)"/g,
    },
};

interface Finding {
    file: string;
    key: string;
}

function problemsIn(file: string, code: string): Finding[] {
    const problems: Finding[] = [];

    for (const match of code.matchAll(/\bt\(\s*(['"])([^'"`]+)\1/g)) {
        const key = match[2] ?? '';

        if (!hasKey(key)) {
            problems.push({ file, key });
        }
    }

    for (const match of code.matchAll(/\bt\(\s*`([^`]*)`/g)) {
        const template = match[1] ?? '';
        const variable = template.indexOf('${');

        if (variable === -1) {
            if (!hasKey(template)) {
                problems.push({ file, key: template });
            }

            continue;
        }

        if (variable === 0) {
            const declared = WHOLLY_DYNAMIC[file];

            if (declared === undefined || !declared.pattern.test(match[0])) {
                problems.push({ file, key: `undeclared dynamic key ${template}` });

                continue;
            }

            for (const value of code.matchAll(declared.values)) {
                if (!branches.has(value[1] ?? '')) {
                    problems.push({ file, key: `${value[1] ?? ''}.*` });
                }
            }

            continue;
        }

        const branch = template.slice(0, variable).replace(/\.$/, '');
        const suffix = /\}\.([\w.]+)$/.exec(template)?.[1];

        if (!branches.has(branch)) {
            problems.push({ file, key: `${branch}.*` });

            continue;
        }

        if (suffix !== undefined) {
            for (const [child, value] of Object.entries(childrenOf(branch))) {
                if (typeof value === 'string' || !hasKey(`${branch}.${child}.${suffix}`)) {
                    problems.push({ file, key: `${branch}.${child}.${suffix}` });
                }
            }
        }
    }

    return problems;
}

describe('the interface translation catalog', () => {
    it('reads the English catalogue and the application', () => {
        expect(leaves.size).toBeGreaterThan(500);
        expect(Object.keys(applicationSources).length).toBeGreaterThan(50);
    });

    it('finds a key that is not in the catalogue when one is planted', () => {
        // The control, before the result is trusted.
        expect(problemsIn('planted.tsx', "t('navigation.no_such_feature')")).toHaveLength(1);
        expect(problemsIn('planted.tsx', 't(`no_such_branch.${kind}`)')).toHaveLength(1);
        expect(problemsIn('planted.tsx', 't(`${whatever}.label`)')).toHaveLength(1);
        expect(problemsIn('planted.tsx', "t('modules.dashboard')")).toHaveLength(0);
    });

    it('holds every key the console reads', () => {
        const problems = Object.entries(applicationSources).flatMap(([file, code]) =>
            problemsIn(file, String(code)),
        );

        expect(problems).toEqual([]);
    });
});
