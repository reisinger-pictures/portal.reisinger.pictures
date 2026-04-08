import {execSync} from 'child_process';
import fs from 'fs';
import path from 'path';

// Sammelt alle Argumente (z.B. "tests/auth tests/ui" -> ["tests/auth", "tests/ui"])
const args = process.argv.slice(2);

console.log('===================================================');
console.log('🤖 AI-Optimierter Playwright Test Runner gestartet!');
console.log('===================================================');

function runTests(selection) {
    const params = '--reporter=list --workers=8';

    // Wir joinen die Argumente mit Leerzeichen.
    // .replaceAll('\\', '/') korrigiert Windows-Pfade, ohne die Filter zu zerstören.
    const filter = selection ? selection.trim().replaceAll("\\", "/") : '';
    const cmd = `npx playwright test ${filter} ${params}`.replace(/\s+/g, ' ');

    console.log(`\n🚀 Führe aus: ${cmd}\n`);

    try {
        const stdout = execSync(cmd, {
            cwd: '.',
            env: {...process.env, FORCE_COLOR: '0'},
            encoding: 'utf-8',
            stdio: 'pipe'
        });

        console.log('\n✅ Alle ausgewählten Tests bestanden!');
        console.log(stdout);
    } catch (error) {
        console.log('\n❌ Tests fehlgeschlagen. Generiere Report...');

        const rawStdout = error.stdout || '';
        const rawStderr = error.stderr || '';

        let report = "=== PLAYWRIGHT TEST FAILURE REPORT ===\n\n";
        report += "--- STDOUT ---\n" + rawStdout + "\n\n";
        if (rawStderr) report += "--- STDERR ---\n" + rawStderr + "\n\n";

        const contextRegex = /Error Context: (\S+)/g;
        const matches = [...rawStdout.matchAll(contextRegex)];

        if (matches.length > 0) {
            report += "--- INLINED ERROR CONTEXTS ---\n";
            for (const match of matches) {
                const relativePath = match[1];
                const fullPath = path.join('.', relativePath);
                if (fs.existsSync(fullPath)) {
                    try {
                        const content = fs.readFileSync(fullPath, 'utf8');
                        const compressed = content.split('\n').map(line => line.trim()).filter(l => l).join('\n');
                        report += `\n>> ORIGIN: ${relativePath}\n${compressed}\n------------------------------------------\n`;
                    } catch (e) {
                        report += `\n[Fehler beim Lesen von ${relativePath}]: ${e.message}\n`;
                    }
                }
            }
        }

        fs.writeFileSync('ai_test_report.txt', report);
        console.log('✅ "ai_test_report.txt" erstellt.');
        process.exit(1);
    }
}

// Direkter Start ohne Nachfrage
runTests(args.join(' '));