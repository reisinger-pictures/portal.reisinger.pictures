import js from '@eslint/js';
import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import pluginLingui from 'eslint-plugin-lingui';

export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'lingui.config.ts', 'playwright.config.ts', 'tests/e2e', 'src/**/__tests__', 'src/**/*.test.ts', 'src/**/*.test.tsx'] },
  pluginLingui.configs['flat/recommended'],
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ['src/**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': [
        'error',
        { allowConstantExport: true },
      ],
      'lingui/no-expression-in-message': 'error',
      '@typescript-eslint/consistent-type-definitions': ['error', 'interface'],
      'lingui/t-call-in-function': 'off',
    },
  },
  {
    files: ['tests/e2e/**/*.ts'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'Property[key.name="force"][value.value=true]',
          message: 'Strict QA Enforcement: Do not use { force: true } in Playwright E2E tests. Fix the UI instead.'
        },
        {
          selector: 'CallExpression[callee.property.name="setViewportSize"]',
          message: 'Strict QA Enforcement: Do not use page.setViewportSize(). Use Playwright projects/devices in playwright.config.ts instead.'
        }
      ]
    },
  }
);
