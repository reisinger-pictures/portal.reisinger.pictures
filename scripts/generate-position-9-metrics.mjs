import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPOSITORY_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const CORRECTION_TABLE_HEADER = /^\s*\|\s*Stelle\s*\|\s*alt\s*\|\s*neu\s*\|\s*Grund\s*\|\s*$/;
const NUMBERED_LOCATOR = /\bZ\.\s*(\d+(?:-\d+)?(?:\/\d+(?:-\d+)?)*)/g;
const PATH_CANDIDATE = /(?:~\/|\.{0,2}\/)?[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)*(?:\/)?(?::\d+(?:-\d+)?)?/g;

function findSection(lines, headingPattern) {
    const start = lines.findIndex((line) => headingPattern.test(line));
    if (start === -1) {
        throw new Error('Required heading was not found.');
    }

    let end = lines.length;
    for (let index = start + 1; index < lines.length; index += 1) {
        if (/^##\s/.test(lines[index])) {
            end = index;
            break;
        }
    }

    return {start, end, lines: lines.slice(start, end)};
}

function measureSkillsTable(source) {
    const lines = source.split(/\r?\n/);
    const section = findSection(lines, /^## Skill-Stand\s*$/);
    const rows = [];

    for (let offset = 0; offset < section.lines.length; offset += 1) {
        const match = section.lines[offset].match(/^\|\s*`([^`]+)`\s*\|\s*(ja|nein|offen)\s*\|/);
        if (match) {
            rows.push({skill: match[1], verdict: match[2], line: section.start + offset + 1});
        }
    }

    if (rows.length === 0) {
        throw new Error('The Skill-Stand table has no parseable body rows.');
    }

    const verdicts = {ja: 0, nein: 0, offen: 0};
    for (const row of rows) {
        verdicts[row.verdict] += 1;
    }

    return {
        rows: rows.length,
        verdicts,
        firstLine: rows[0].line,
        lastLine: rows[rows.length - 1].line,
    };
}

function isConcretePath(token) {
    if (!token || /[*<>…]/.test(token) || token.startsWith('~/')) {
        return false;
    }

    const normalized = token.replace(/:\d+(?:-\d+)?$/, '');
    if (/\.opencode(?:\/|$)|opencode\.jsonc|metadata\.opencode|\.config(?:\/|$)/i.test(normalized)) {
        return false;
    }
    if (/^[0-9a-f]{7,40}(?:\.\.[0-9a-f]{7,40})?$/i.test(normalized)
        || /^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/.test(normalized)) {
        return false;
    }

    if (normalized.endsWith('/')) {
        return true;
    }

    const basename = normalized.slice(normalized.lastIndexOf('/') + 1);
    const hasFileExtension = /\.[A-Za-z0-9_-]+$/.test(basename);
    const isDotPath = normalized.startsWith('.');

    return hasFileExtension || (isDotPath && normalized.includes('/'));
}

function countPathReferences(codeSpan) {
    const trimmed = codeSpan.trim();
    // Shell snippets, code excerpts and package identifiers are not location
    // anchors, even when they contain a path-like token.
    if (/^(?:git|grep|sed|awk|import)\b/.test(trimmed) || trimmed.startsWith('@')) {
        return [];
    }

    const references = [];
    for (const match of codeSpan.matchAll(PATH_CANDIDATE)) {
        const token = match[0];
        const before = codeSpan[match.index - 1] ?? '';
        const after = codeSpan[match.index + token.length] ?? '';
        // A token cut out of a glob is still a glob, not a concrete path.
        if (before === '*' || after === '*' || !isConcretePath(token)) {
            continue;
        }
        references.push(token);
    }

    return references;
}

function measureAnchorReferences(source) {
    const lines = source.split(/\r?\n/);
    const linesWithReferences = new Set();
    let pathReferences = 0;
    let numberedLocatorGroups = 0;
    let inCorrectionTable = false;

    for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index];
        if (CORRECTION_TABLE_HEADER.test(line)) {
            inCorrectionTable = true;
            continue;
        }
        if (inCorrectionTable) {
            if (line.trimStart().startsWith('|')) {
                continue;
            }
            inCorrectionTable = false;
        }

        let hasReference = false;
        for (const match of line.matchAll(NUMBERED_LOCATOR)) {
            numberedLocatorGroups += match[1].split('/').length;
            hasReference = true;
        }

        for (const codeSpan of line.matchAll(/`([^`\n]+)`/g)) {
            const references = countPathReferences(codeSpan[1]);
            pathReferences += references.length;
            if (references.length > 0) {
                hasReference = true;
            }
        }

        if (hasReference) {
            linesWithReferences.add(index + 1);
        }
    }

    return {
        pathReferences,
        numberedLocatorGroups,
        occurrences: pathReferences + numberedLocatorGroups,
        lines: linesWithReferences.size,
    };
}

function measureTodoBoard(source) {
    const lines = source.split(/\r?\n/);
    const skillsSection = findSection(lines, /^##\s+.*SKILLS-STAND\b/);
    const positions = [];

    for (let offset = 0; offset < skillsSection.lines.length; offset += 1) {
        const match = skillsSection.lines[offset].match(/^\s*-\s+\[([ x])\]\s+\*\*Position\s+(\d+)\b/);
        if (match) {
            positions.push({
                number: Number(match[2]),
                status: match[1] === ' ' ? 'open' : 'closed',
                line: skillsSection.start + offset + 1,
            });
        }
    }

    // Owner decision 2026-10-24 (formerly Position 10): a UI review's findings
    // are a report in the skill's nine-column form, never a table on this board.
    // The two legacy four-column headers are counted so the decision stays
    // measurable instead of resting on the absence of a heading.
    const legacyFindingTables = lines.filter(
        (line) => /^\|\s*(Schwere \| Stelle \| Befund \| Vorschlag|Befund \| Schwere \| Von mir geprueft)\s*\|$/.test(line),
    ).length;

    return {
        openPositions: positions.filter((position) => position.status === 'open'),
        closedPositions: positions.filter((position) => position.status === 'closed'),
        legacyFindingTables,
    };
}

function countLfTerminatedLines(source) {
    return (source.match(/\n/g) ?? []).length;
}

export function collectMetricsFromSources({skillsMarker, todoBoard, ciWorkflow}) {
    const skills = measureSkillsTable(skillsMarker);
    const anchors = measureAnchorReferences(skillsMarker);
    const todo = measureTodoBoard(todoBoard);

    return {
        skills,
        anchors,
        workflowLines: countLfTerminatedLines(ciWorkflow),
        todo,
    };
}

function linesLabel(lines) {
    return lines.length === 0 ? 'not found' : lines.map((line) => `AGENTS.todo.md:${line}`).join(', ');
}

export function renderMetrics(metrics) {
    const positionDetails = metrics.todo.openPositions
        .map(({number, line}) => `Position ${number} (AGENTS.todo.md:${line})`)
        .join(', ') || 'none';

    return [
        '<!-- Generated by scripts/generate-position-9-metrics.mjs; do not edit by hand. -->',
        '<!--',
        'Definitions: skills rows are body rows under `## Skill-Stand` with a verdict of `ja`, `nein` or `offen`.',
        'Anchor occurrences count concrete backtick-delimited file/directory paths plus each numeric group after `Z.` (slash-separated groups count separately); glob/placeholder paths, machine config paths, command/source snippets and the historical correction table are excluded. Anchor lines are source lines with at least one such occurrence.',
        'Workflow lines use LF-terminated-line (`wc -l`) semantics. TODO positions are open checklist entries in the `SKILLS-STAND` section only. Board finding tables are counted in the two legacy four-column forms; the owner decision of 2026-10-24 keeps a UI review\'s findings in its report, so the count stays at 0.',
        '-->',
        '',
        '# Position 9 metrics',
        '',
        'Generated from the current repository files. The values below are derived; do not copy them into prose.',
        '',
        '| Metric | Current value | Source / definition |',
        '|---|---:|---|',
        `| Skills-marker table rows | ${metrics.skills.rows} | AGENTS.skills.md:${metrics.skills.firstLine}–${metrics.skills.lastLine} |`,
        '| Skills-marker verdict `ja` rows | ' + metrics.skills.verdicts.ja + ' | `ja` cell values in those rows |',
        '| Skills-marker verdict `nein` rows | ' + metrics.skills.verdicts.nein + ' | `nein` cell values in those rows |',
        '| Skills-marker verdict `offen` rows | ' + metrics.skills.verdicts.offen + ' | `offen` cell values in those rows |',
        `| Anchor references (generator definition) | ${metrics.anchors.occurrences} | ${metrics.anchors.pathReferences} path references + ${metrics.anchors.numberedLocatorGroups} numbered locator groups |`,
        `| Lines containing anchor locators | ${metrics.anchors.lines} | AGENTS.skills.md; definition in this report header |`,
        '| CI workflow LF-terminated lines | ' + metrics.workflowLines + ' | .github/workflows/ci.yml (`wc -l` semantics) |',
        `| Open SKILLS-STAND positions | ${metrics.todo.openPositions.length} | ${positionDetails} |`,
        `| Closed SKILLS-STAND positions | ${metrics.todo.closedPositions.length} | ${metrics.todo.closedPositions.map(({number, line}) => `Position ${number} (AGENTS.todo.md:${line})`).join(', ') || 'none'} |`,
        `| Board finding tables in the legacy four-column form | ${metrics.todo.legacyFindingTables} | owner decision 2026-10-24: a UI review's findings live in its report, not on the board |`,
        '',
    ].join('\n');
}

async function generate() {
    const args = process.argv.slice(2);
    let outputPath = null;
    if (args.length === 2 && args[0] === '--output') {
        outputPath = args[1];
    } else if (args.length !== 0) {
        throw new Error('Usage: node scripts/generate-position-9-metrics.mjs [--output <path>]');
    }

    const [skillsMarker, todoBoard, ciWorkflow] = await Promise.all([
        readFile(path.join(REPOSITORY_ROOT, 'AGENTS.skills.md'), 'utf8'),
        readFile(path.join(REPOSITORY_ROOT, 'AGENTS.todo.md'), 'utf8'),
        readFile(path.join(REPOSITORY_ROOT, '.github/workflows/ci.yml'), 'utf8'),
    ]);
    const report = renderMetrics(collectMetricsFromSources({skillsMarker, todoBoard, ciWorkflow}));

    if (outputPath) {
        await writeFile(outputPath, report, 'utf8');
    } else {
        process.stdout.write(report);
    }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        await generate();
    } catch {
        process.stderr.write('Could not generate the Position 9 metrics report.\n');
        process.exitCode = 1;
    }
}
