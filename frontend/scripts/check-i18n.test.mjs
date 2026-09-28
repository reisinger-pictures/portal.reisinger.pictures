import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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

describe('check-i18n prose taxonomy (D-7)', () => {
  it('groups a text run split by an expression into one sentence-level finding', () => {
    const filePath = resolve(process.cwd(), 'scripts/fixtures/i18n/sentence-grouping.tsx');
    const violations = findUnlocalizedStrings(readFileSync(filePath, 'utf8'), filePath);

    // Before D-7 this shape produced node-level findings ("Jahre (geb." and
    // ")"). One logical run is one finding.
    expect(violations).toHaveLength(1);
    expect(violations).toMatchObject([
      { category: 'jsx-text', line: 10, text: 'Jahre (geb. )' },
    ]);
    expect(violations[0].classification).toBe('flow');
    // The finding is a merged run, not a single node — that is the whole point.
    expect(violations[0].nodeLevelEquivalent).toBe(false);
  });

  it('groups text on both sides of an expression into one finding', () => {
    // `Reportage-Paket (+` + `{m}` + `% Aufschlag)` is the real shape in
    // ShootigCalculatorModal: a literal, then a variable, then a literal.
    const source = [
      'export const Reportage = ({ m }: { m: number }) => (',
      '  <span>Reportage-Paket (+{m}% Aufschlag)</span>',
      ');',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toHaveLength(1);
    expect(violations[0].text).toBe('Reportage-Paket (+ % Aufschlag)');
    expect(violations[0].nodeLevelEquivalent).toBe(false);
  });

  it('groups a run whose first child is an expression without losing the literal', () => {
    const source = [
      'export const Summary = ({ groups, galleries }: { groups: number; galleries: number }) => (',
      '  <span>{groups} Gruppen, {galleries} Galerien</span>',
      ');',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    // The leading `{groups}` carries no literal; the run's first literal opens
    // the finding and the whole run is judged as one unit.
    expect(violations).toHaveLength(1);
    expect(violations[0].text).toBe('Gruppen, Galerien');
  });

  it('keeps prose and a bare URL as separate findings when a JSX element separates them', () => {
    const filePath = resolve(process.cwd(), 'scripts/fixtures/i18n/trans-adjacent-link.tsx');
    const violations = findUnlocalizedStrings(readFileSync(filePath, 'utf8'), filePath);

    // A JSX element (`<a>`) is a hard grouping boundary: the prose before it,
    // the bare URL inside it and the prose after it stay three logical runs.
    // This pins that grouping does not silently merge across markup and does
    // not lose the prose either side. See AGENTS.todo.md for the finding.
    expect(violations.map(violation => violation.text)).toEqual([
      'Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit, die Sie unter',
      'https://ec.europa.eu/consumers/odr/',
      'finden. Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle…',
    ]);
  });

  describe('does not flag non-prose', () => {
    it('excludes a lone dynamic value with no literal to translate', () => {
      const source = 'export const Count = ({ n }: { n: number }) => <span>{n}</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a unit-only fragment', () => {
      const source = 'export const Rate = () => <span>Bilder/Std.</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a machine value such as an uppercase environment token', () => {
      const source = 'export const Env = () => <span>MAILCHIMP</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a lone word below the prose length threshold', () => {
      const source = 'export const Column = () => <th>Preis</th>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a lone known non-copy word', () => {
      const source = 'export const Qty = () => <span>Stk.</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a purely numeric run', () => {
      const source = 'export const Amount = () => <span>+50€ (0%)</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes punctuation adjacent to an expression', () => {
      // A literal with no letter (`:`, `/`, `·`, `( )`) stays non-prose even
      // when an expression is its sibling. Regression guard: the sentence
      // grouping originally reported these because any literal sibling counted
      // as copy.
      const source = 'export const Meta = ({ a, b }: { a: string; b: string }) => <span>{a}: {b}</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a run whose content words are all stopwords', () => {
      // Two tokens, but both closed-class: not a sentence. Guards that
      // `PROSE_STOPWORDS` is load-bearing rather than decorative.
      const source = 'export const Filler = () => <span>der die das</span>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });
  });

  describe('still reports prose (the positive case)', () => {
    it('reports a German sentence in JSX text', () => {
      const source = 'export const Hint = () => <p>Bitte alle Felder ausfüllen.</p>;';
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toHaveLength(1);
      expect(violations).toMatchObject([
        { category: 'jsx-text', text: 'Bitte alle Felder ausfüllen.' },
      ]);
    });

    it('reports a prose JSX attribute', () => {
      const source = 'export const Close = () => <button title="Löschen" />;';
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toMatchObject([
        { category: 'jsx-attribute', text: 'title="Löschen"' },
      ]);
      expect(violations).toHaveLength(1);
    });

    it('reports a prose toast argument', () => {
      const source = "const report = () => showToast('success', 'Grundhonorar aktualisiert');";
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toMatchObject([
        { category: 'helper-argument', text: 'showToast(…, "Grundhonorar aktualisiert")' },
      ]);
      expect(violations).toHaveLength(1);
    });
  });
});

