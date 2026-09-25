import { describe, expect, it } from 'vitest';
import {
  findModuleScopeLinguiMacros,
  findModuleScopeLinguiMacrosInTree,
} from './check-i18n.mjs';

describe('check-i18n Lingui scope guard', () => {
  it('flags a direct module-scope macro while allowing function factories', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      'const unsafe = t`unsafe`;',
      'const createSchema = () => ({ label: t`safe factory` });',
      'function renderLabel() { return t`safe function`; }',
    ].join('\n');

    const violations = findModuleScopeLinguiMacros(source, 'fixture.tsx');

    expect(violations).toHaveLength(1);
    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 2, text: 't`unsafe`' },
    ]);
  });

  it('flags a module-scope schema-factory invocation but not its definition', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      'const createSchema = () => z.object({ label: t`safe factory` });',
      'const schema = createSchema();',
      'function renderLabel() { return t`safe function`; }',
    ].join('\n');

    const violations = findModuleScopeLinguiMacros(source, 'fixture.tsx');

    expect(violations).toHaveLength(1);
    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 3, text: 'createSchema()' },
    ]);
  });

  it('flags Lingui macros inside module-level IIFEs', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      'const arrowResult = (() => t`unsafe arrow IIFE`)();',
      'const classicResult = (function () { return t`unsafe classic IIFE`; })();',
      'const createSchema = () => t`safe factory`;',
    ].join('\n');

    const violations = findModuleScopeLinguiMacros(source, 'fixture.tsx');

    expect(violations).toHaveLength(2);
    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 2, text: 't`unsafe arrow IIFE`' },
      { filePath: 'fixture.tsx', line: 3, text: 't`unsafe classic IIFE`' },
    ]);
  });

  it('tracks aliased Lingui macro imports without flagging an uninvoked factory', () => {
    const source = [
      "import { t as translate } from '@lingui/core/macro';",
      'const unsafe = translate`unsafe alias`;',
      'const createSchema = () => translate`safe aliased factory`;',
    ].join('\n');

    const violations = findModuleScopeLinguiMacros(source, 'fixture.tsx');

    expect(violations).toHaveLength(1);
    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 2, text: 'translate`unsafe alias`' },
    ]);
  });

  it('keeps the checked-in frontend source free of module-scope Lingui macros', () => {
    expect(findModuleScopeLinguiMacrosInTree()).toEqual([]);
  });
});
