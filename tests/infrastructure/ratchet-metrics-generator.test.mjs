import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { collectMetricsFromSources, renderMetrics } from '../../scripts/generate-position-9-metrics.mjs';

const REPOSITORY_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const GENERATOR = path.join(REPOSITORY_ROOT, 'scripts/generate-position-9-metrics.mjs');

const skillsMarkerFixture = [
    '# Marker',
    '## Skill-Stand',
    '| Skill | angewendet? |',
    '|---|---|',
    '| `one` | ja |',
    '| `two` | nein |',
    '| `three` | offen |',
    '## Next',
    '`AGENTS.md` §1 (Z. 1/2-3)',
    '`src/file.ts` Z. 4',
    '`docs/` and `~/.config/opencode.jsonc`',
    '`src/*.md` and `git grep -n text -- AGENTS.md`',
    '`e559a39..33cbd00` and `1.64.1` are history/version values, not locations',
    '| Stelle | alt | neu | Grund |',
    '| `old.md` | Z. 7 | Z. 8 | historical |',
    'After table: `features/current.md`',
].join('\n');

const todoBoardFixture = [
    '# TODO',
    '## 📌 SKILLS-STAND (fixture)',
    '- [ ] **Position 4 — open**',
    '- [x] **Position 5 — closed**',
    '- [ ] a non-position task',
    '## UI-Review 2026-09-26 — historical',
    '| Schwere | Stelle | Befund | Vorschlag |',
    '| medium | sample | finding | **Erledigt 2026-09-26: done** |',
    '## Dialog-UI-Review 2026-09-27 — historical',
    '| Befund | Schwere | Von mir geprueft |',
    '## Another section',
].join('\n');

test('calculates skills verdict rows and source line bounds', () => {
    const metrics = collectMetricsFromSources({
        skillsMarker: skillsMarkerFixture,
        todoBoard: todoBoardFixture,
        ciWorkflow: 'one\ntwo\n',
    });

    assert.deepEqual(metrics.skills, {
        rows: 3,
        verdicts: {ja: 1, nein: 1, offen: 1},
        firstLine: 5,
        lastLine: 7,
    });
});

test('counts defined anchor forms and excludes patterns, machine config, commands, history and old correction rows', () => {
    const metrics = collectMetricsFromSources({
        skillsMarker: skillsMarkerFixture,
        todoBoard: todoBoardFixture,
        ciWorkflow: 'one\ntwo\n',
    });

    assert.deepEqual(metrics.anchors, {
        pathReferences: 4,
        numberedLocatorGroups: 3,
        occurrences: 7,
        lines: 4,
    });
});

test('counts only open named positions in the skills section and locates cited historical rows', () => {
    const metrics = collectMetricsFromSources({
        skillsMarker: skillsMarkerFixture,
        todoBoard: todoBoardFixture,
        ciWorkflow: 'one\ntwo\n',
    });

    assert.deepEqual(metrics.todo, {
        openPositions: [{number: 4, status: 'open', line: 3}],
        closedPositions: [{number: 5, status: 'closed', line: 4}],
        uiReviewHeaderLines: [7],
        dialogReviewHeaderLines: [10],
        completionExampleLines: [8],
    });
    assert.equal(metrics.workflowLines, 2);
});

test('renders stable Markdown without timestamps or checkout-specific paths', () => {
    const input = {
        skillsMarker: skillsMarkerFixture,
        todoBoard: todoBoardFixture,
        ciWorkflow: 'one\ntwo\n',
    };
    const first = renderMetrics(collectMetricsFromSources(input));
    const second = renderMetrics(collectMetricsFromSources(input));

    assert.equal(first, second);
    assert.match(first, /Position 4 \(AGENTS\.todo\.md:3\)/);
    assert.doesNotMatch(first, /\b20\d\d-\d\d-\d\dT|\/Users\/|\/tmp\//);
});

test('CLI output is deterministic and does not include its temporary output path', async (t) => {
    const temporaryDirectory = await mkdtemp(path.join(os.tmpdir(), 'ratchet-metrics-test-'));
    t.after(async () => rm(temporaryDirectory, {recursive: true, force: true}));
    const firstPath = path.join(temporaryDirectory, 'first.md');
    const secondPath = path.join(temporaryDirectory, 'second.md');

    execFileSync(process.execPath, [GENERATOR, '--output', firstPath], {cwd: REPOSITORY_ROOT});
    execFileSync(process.execPath, [GENERATOR, '--output', secondPath], {cwd: REPOSITORY_ROOT});

    const [first, second] = await Promise.all([
        readFile(firstPath, 'utf8'),
        readFile(secondPath, 'utf8'),
    ]);
    assert.equal(first, second);
    assert.doesNotMatch(first, new RegExp(temporaryDirectory.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});
