import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * Lint enforces what review keeps missing, and nothing else.
 *
 * The design-system rules below are narrow on purpose. They catch the two things
 * that actually erode a token system — a raw colour and a Tailwind arbitrary colour
 * value — rather than banning every literal number, which would flag legitimate
 * one-off geometry and train everyone to disable the rule.
 */
export default tseslint.config(
    { ignores: ['dist', 'node_modules', 'src/api/generated'] },

    js.configs.recommended,
    ...tseslint.configs.recommendedTypeChecked,

    {
        files: ['**/*.{ts,tsx}'],
        languageOptions: {
            ecmaVersion: 2023,
            globals: globals.browser,
            parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
        },
        plugins: {
            'react-hooks': reactHooks,
            'react-refresh': reactRefresh,
        },
        rules: {
            ...reactHooks.configs.recommended.rules,
            // A provider and its hook belong in one file: the context they share is
            // private to the pair. Fast refresh loses that module's state on edit,
            // which costs a theme toggle in development and nothing in production.
            'react-refresh/only-export-components': [
                'warn',
                {
                    allowConstantExport: true,
                    allowExportNames: [
                        'useTheme',
                        'useDensity',
                        'useDirection',
                        'useAuth',
                        'useCurrentUser',
                    ],
                },
            ],

            '@typescript-eslint/consistent-type-imports': [
                'error',
                { fixStyle: 'inline-type-imports' },
            ],
            '@typescript-eslint/no-unused-vars': [
                'error',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
            // An empty catch is how the storage guards stay silent on purpose.
            'no-empty': ['error', { allowEmptyCatch: true }],

            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        'Literal[value=/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/]',
                    message:
                        'Colour belongs in src/styles/tokens.css. Use a semantic token, not a hex value.',
                },
                {
                    selector: 'Literal[value=/-\\[#[0-9a-fA-F]{3,8}\\]/]',
                    message:
                        'Tailwind arbitrary colour values bypass the token layer. Use a semantic token.',
                },
                {
                    selector: "MemberExpression[object.name='document'][property.name='cookie']",
                    message:
                        'The session cookie is HttpOnly by design (ADR 0042). The Admin never reads or writes it.',
                },
            ],
        },
    },

    {
        // Config files run in Node and are outside the type-aware program.
        files: ['*.config.{js,ts}'],
        ...tseslint.configs.disableTypeChecked,
        languageOptions: { globals: globals.node },
    },

    {
        files: ['src/**/*.test.{ts,tsx}', 'src/test/**/*.{ts,tsx}'],
        rules: {
            '@typescript-eslint/no-unsafe-assignment': 'off',
            '@typescript-eslint/no-unsafe-member-access': 'off',
        },
    },
);
