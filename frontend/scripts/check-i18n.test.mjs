import { describe, expect, it } from 'vitest';
import {
  findModuleScopeLinguiMacros,
  findModuleScopeLinguiMacrosInTree,
  findUnlocalizedStrings,
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

describe('check-i18n unlocalized string guard', () => {
  it('flags a raw German JSX text node', () => {
    const source = 'export const Welcome = () => <h1>Willkommen</h1>;';

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 1, category: 'jsx-text', text: 'Willkommen' },
    ]);
    expect(violations).toHaveLength(1);
  });

  it('does not flag JSX text wrapped by a Lingui macro component', () => {
    const source = [
      "import { Trans } from '@lingui/react/macro';",
      'export const Welcome = () => <Trans>Willkommen</Trans>;',
    ].join('\n');

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('treats nested markup inside a macro component as wrapped', () => {
    const source = [
      "import { Trans } from '@lingui/react/macro';",
      'export const Welcome = () => <Trans>Willkommen <strong>zurück</strong></Trans>;',
    ].join('\n');

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('flags a German aria-label', () => {
    const source = 'export const Close = () => <button aria-label="Schließen" />;';

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', category: 'jsx-attribute', text: 'aria-label="Schließen"' },
    ]);
    expect(violations).toHaveLength(1);
  });

  it('does not flag data-testid, ARIA tokens, id references or URLs', () => {
    const source = [
      'export const Widget = () => (',
      '  <div',
      '    data-testid="gallery-card"',
      '    aria-hidden="true"',
      '    aria-live="polite"',
      '    aria-labelledby="gallery-heading"',
      '    placeholder="https://example.com"',
      '  />',
      ');',
    ].join('\n');

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('does not flag strings produced by the t macro', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      'export const label = t`Willkommen`;',
      'export const Title = () => <span title={t`Schließen`} />;',
    ].join('\n');

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('ignores punctuation- and whitespace-only JSX text', () => {
    const source = 'export const Separator = () => <span> · — &nbsp; </span>;';

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('flags a raw toast message but not a wrapped one', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      "const report = () => showToast('error', 'Fehler beim Speichern.');",
      'const ok = () => showToast(\'success\', t`Gespeichert`);',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toMatchObject([
      { category: 'helper-argument', line: 2, text: 'showToast(…, "Fehler beim Speichern.")' },
    ]);
    expect(violations).toHaveLength(1);
  });

  it('flags raw confirm-dialog copy', () => {
    const source = [
      'const ask = () => confirm({ title: "Löschen?", message: "Wirklich löschen?" });',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toMatchObject([
      { category: 'helper-argument', text: 'confirm({title: "Löschen?"})' },
      { category: 'helper-argument', text: 'confirm({message: "Wirklich löschen?"})' },
    ]);
    expect(violations).toHaveLength(2);
  });
});
