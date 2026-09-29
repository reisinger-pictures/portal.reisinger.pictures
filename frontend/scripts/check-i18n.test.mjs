import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  findModuleScopeLinguiMacros,
  findModuleScopeLinguiMacrosInTree,
  findUnlocalizedStrings,
  findUnlocalizedStringsInTree,
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

  it('flags a German camelCase ariaLabel prop on a custom component', () => {
    // Real shape: `CustomerModal.tsx` passes the visible label of
    // `AutocompleteInput` as `ariaLabel={t` + '`PLZ`' + `}`. React only accepts
    // the camelCase spelling for a prop on a custom element, so the hyphenated
    // `aria-` prefix branch never saw these — and an unwrapped `ariaLabel` is
    // untranslated user-visible copy.
    const source = [
      'export const Location = () => (',
      '  <AutocompleteInput ariaLabel="Stadt" value={value} onChange={onChange} />',
      ');',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    expect(violations).toMatchObject([
      { filePath: 'fixture.tsx', line: 2, category: 'jsx-attribute', text: 'ariaLabel="Stadt"' },
    ]);
    expect(violations).toHaveLength(1);
  });

  it('does not flag a technical, token or id-reference value on a camelCase aria prop', () => {
    // The camelCase branch must share the hyphenated taxonomy, not widen it:
    // a URL is a technical value, `true` is an enumerated ARIA token and
    // `gallery-heading` is an id reference. All three stay silent, exactly like
    // `aria-hidden="true"` and `aria-labelledby="gallery-heading"`.
    const source = [
      'export const Widget = () => (',
      '  <AutocompleteInput',
      '    ariaHidden="true"',
      '    ariaLive="polite"',
      '    ariaLabelledBy="gallery-heading"',
      '    ariaLabel="https://example.com"',
      '  />',
      ');',
    ].join('\n');

    expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
  });

  it('does not flag a camelCase aria prop that the t macro already wraps', () => {
    const source = [
      "import { t } from '@lingui/core/macro';",
      'export const Location = () => <AutocompleteInput ariaLabel={t`Stadt`} />;',
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

  it('keeps prose on both sides of a link while the bare URL is filtered as a technical value', () => {
    const source = [
      'export const Link = () => (',
      '  <p>Die Kommission stellt eine Plattform bereit unter <a href="https://ec.europa.eu/consumers/odr/">https://ec.europa.eu/consumers/odr/</a> finden Sie uns.</p>',
      ');',
    ].join('\n');

    const violations = findUnlocalizedStrings(source, 'fixture.tsx');

    // A JSX element (`<a>`) is a hard grouping boundary, so the prose on either
    // side is its own run. The URL inside the link is a technical value and is
    // excluded wherever it appears — including the sentence-level JSX text path
    // (`Impressum.tsx:43`), which had lost `looksLikeTechnicalValue`.
    expect(violations.map(violation => violation.text)).toEqual([
      'Die Kommission stellt eine Plattform bereit unter',
      'finden Sie uns.',
    ]);
  });

  it('filters the bare URL in the real Impressum shape while keeping the prose runs', () => {
    const filePath = resolve(process.cwd(), 'scripts/fixtures/i18n/trans-adjacent-link.tsx');
    const violations = findUnlocalizedStrings(readFileSync(filePath, 'utf8'), filePath);

    // The fixture mirrors `Impressum.tsx:43`. Before the rule extension the
    // sentence-level path reported the bare URL inside the `<a>` as a third
    // finding; it is now filtered as a technical value, while the prose on
    // either side of the link boundary still reports. (The fixture imports no
    // macro, so its `<Trans>` tags are ordinary elements and their text is
    // exactly the prose the grouping keeps.)
    expect(violations.map(violation => violation.text)).toEqual([
      'Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit, die Sie unter',
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

    it('excludes a short function-word fragment beside an expression', () => {
      // Real shape: ClientOrdersView.tsx:47 `<h2>… vom {date}</h2>`. "vom" is a
      // lone token below the prose length and must not be resurrected merely
      // because `{date}` sits next to it.
      const source = 'export const Meta = ({ a, b }: { a: string; b: string }) => <h2>{a} vom {b}</h2>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a short lone noun fragment beside an expression', () => {
      // Real shape: ManagementOrgsView.tsx:70 `{t.users_count || 0} User`.
      const source = 'export const Count = ({ n }: { n: number }) => <div>{n} User</div>;';
      expect(findUnlocalizedStrings(source, 'fixture.tsx')).toEqual([]);
    });

    it('excludes a unit prefix fragment beside an expression', () => {
      // Real shape: VolumePresetSettingsCard.tsx:325 `Ab {min} → {price}`.
      const source = 'export const Range = ({ min, price }: { min: number; price: string }) => <span>Ab {min} → {price}</span>;';
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

    it('still reports a genuine prose literal sitting beside an expression', () => {
      const source = 'export const Count = ({ n }: { n: number }) => <span>{n} Galerien</span>;';
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toHaveLength(1);
      expect(violations[0].text).toBe('Galerien');
    });

    it('keeps the literal-beside-expression exemption for a prose segment', () => {
      // The merged run ("Galerien der") is not prose on its own — one content
      // word plus a stopword — so this finding exists *only* because the
      // exemption still fires when a literal segment is prose by itself. It
      // guards that narrowing the exemption did not lose this pre-existing
      // finding, which is exactly what D-7's overcount was there to prevent.
      const source = 'export const Count = ({ n }: { n: number }) => <span>Galerien {n} der</span>;';
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toHaveLength(1);
      expect(violations[0].text).toBe('Galerien der');
    });

    it('still reports a label that ends in a colon (not a URL scheme)', () => {
      // Pins the `looksLikeTechnicalValue` fix: its scheme branch used to match
      // any leading `word:`, which silently swallowed real labels such as
      // `Flatrate:`/`Rechnung:`/`Rollover:`. A bare URL is a technical value, a
      // colon-suffixed label is copy.
      const source = 'export const Rows = ({ level }: { level: string }) => <span>Flatrate: {level}</span>;';
      const violations = findUnlocalizedStrings(source, 'fixture.tsx');

      expect(violations).toHaveLength(1);
      expect(violations[0].text).toBe('Flatrate:');
    });
  });
});

describe('check-i18n runner', () => {
  it('keeps the tree fully localized and the runner green (no warn-only path)', () => {
    const script = resolve(process.cwd(), 'scripts/check-i18n.mjs');
    // `NODE_ENV` is scrubbed for the child because vitest sets `NODE_ENV=test`
    // in its workers, and `lingui extract` — which the runner shells out to via
    // `pnpm lingui:extract` — dies there before it writes a single msgid: lingui
    // resolves its worker file by `NODE_ENV`, and under `NODE_ENV=test`
    // `resolveWorkerFile` (`node_modules/@lingui/cli/dist/api/typedPool.js:9-11`)
    // picks `extractWorkerWrapper.jiti.js`, which does not ship (only `.prod.js`
    // exists) — the worker dies on module-not-found. That is reproducible without
    // vitest at all (`NODE_ENV=test pnpm run lingui:extract` → exit 1, module
    // not found; unset → exit 0), so it is a lingui packaging gap, not an i18n
    // finding. The spawn has to measure the i18n contract and not that unrelated
    // toolchain crash.
    const result = spawnSync(process.execPath, [script], {
      encoding: 'utf8',
      env: { ...process.env, NODE_ENV: undefined },
    });

    // D-7: unlocalized-string findings are always fatal — there is no warn-only
    // path. The former `toBeGreaterThan(0)` precondition asserted the backlog
    // and therefore had to be inverted once the tree reached zero; this is its
    // clean-tree counterpart, and the stronger of the two contracts. The empty
    // assertion is the tripwire: one newly introduced unlocalized string turns
    // this red. The exit assertion is what keeps such a finding fatal rather
    // than merely reported.
    expect(findUnlocalizedStringsInTree().length).toBe(0);
    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain('i18n check passed');
  });

  it('survives NODE_ENV=test because the gate cleans the extract environment', () => {
    // The defect was in the gate, not in the tests: the extract child inherited
    // `NODE_ENV=test` (vitest sets it in its workers, and `NODE_ENV=test pnpm
    // build` sets it for `prebuild`), and `lingui extract` then died before
    // writing a single msgid — under `NODE_ENV=test` `resolveWorkerFile`
    // (`node_modules/@lingui/cli/dist/api/typedPool.js:9-11`) resolves
    // `extractWorkerWrapper.jiti.js`, which does not ship (only `.prod.js`
    // exists), so the worker dies on module-not-found. So the runner test above
    // had to scrub the value to get a green measurement — which hid the very
    // failure it was measuring. The contract is now the opposite: the gate must
    // pass **with** the hostile value, so this test hands it in deliberately
    // and asserts the same exit code. Repro without vitest:
    // `NODE_ENV=test pnpm build`.
    const script = resolve(process.cwd(), 'scripts/check-i18n.mjs');
    const result = spawnSync(process.execPath, [script], {
      encoding: 'utf8',
      env: { ...process.env, NODE_ENV: 'test' },
    });

    expect(result.status, result.stderr).toBe(0);
    expect(result.stdout).toContain('i18n check passed');
  });
});

