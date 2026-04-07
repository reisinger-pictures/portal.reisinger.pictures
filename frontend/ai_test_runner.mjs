import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import readline from 'readline';

const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
});

console.log('===================================================');
console.log('🤖 AI-Optimierter Playwright Test Runner gestartet!');
console.log('===================================================');
console.log('Lass dieses Feld leer und drücke ENTER, um ALLE Tests auszuführen.');
console.log('Oder paste einen spezifischen Befehl von der KI (z.B.: tests/e2e/client/client.spec.ts)');

rl.question('\n👉 Welche Tests sollen ausgeführt werden? ', (answer) => {
    rl.close();

    const params = `--reporter=list --workers=8`;
    let cmd = `npx playwright test ${params}`;
    if (answer.trim() !== '') {
        cmd = `npx playwright test ${answer.trim().replaceAll("\\","/")} ${params}`;
    }

    console.log(`\n🚀 Führe aus: ${cmd}\n`);

    try {
        const stdout = execSync(cmd, {
            cwd: '.',
            env: { ...process.env, FORCE_COLOR: '0' },
            encoding: 'utf-8',
            stdio: 'pipe'
        });
        
        console.log('\n✅ Alle ausgewählten Tests bestanden!');
        console.log(stdout);
    } catch (error) {
        console.log('\n❌ Tests fehlgeschlagen. Generiere Report mit Error-Kontext...');
        
        const rawStdout = error.stdout || '';
        const rawStderr = error.stderr || '';
        
        let report = "=== PLAYWRIGHT TEST FAILURE REPORT ===\n\n";
        report += "--- STDOUT (Live-Schritte & Error Stack Trace) ---\n";
        report += rawStdout + "\n\n";
        
        if (rawStderr) {
            report += "--- STDERR (Kritische Fehler) ---\n";
            report += rawStderr + "\n\n";
        }

        const contextRegex = /Error Context: (\S+)/g;
        const matches = [...rawStdout.matchAll(contextRegex)];
        
        if (matches.length > 0) {
            report += "--- INLINED ERROR CONTEXTS (Details aus .md Dateien) ---\n";
            for (const match of matches) {
                const relativePath = match[1];
                const fullPath = path.join('.', relativePath);
                if (fs.existsSync(fullPath)) {
                    try {
                        const content = fs.readFileSync(fullPath, 'utf8');
                        const compressed = content.split('\n').map(line => line.trim()).filter(line => line.length > 0).join('\n');
                        report += `\n>> ORIGIN: ${relativePath}\n${compressed}\n------------------------------------------\n`;
                    } catch (readErr) {
                        report += `\n[Fehler beim Lesen von ${relativePath}]: ${readErr.message}\n`;
                    }
                }
            }
        }

        fs.writeFileSync('ai_test_report.txt', report);
        console.log('✅ Datei "ai_test_report.txt" wurde mit Inlined-Kontext erstellt.');
        console.log('💡 HINWEIS: Du kannst den Report jetzt direkt hochladen.');
        process.exit(1);
    }
});
