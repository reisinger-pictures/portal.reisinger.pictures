import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

console.log('🚀 Starte Playwright Tests (AI-Optimiert + Context Inline)...');

try {
    const stdout = execSync('npx playwright test --reporter=list --workers=8', {
        cwd: '.',
        env: { ...process.env, FORCE_COLOR: '0' },
        encoding: 'utf-8',
        stdio: 'pipe'
    });
    
    console.log('\n✅ Alle Tests bestanden!');
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

    // --- NEU: Error Context Inlining ---
    // Suche nach Pfaden wie: Error Context: test-results\...\error-context.md
    const contextRegex = /Error Context: (\S+)/g;
    const matches = [...rawStdout.matchAll(contextRegex)];
    
    if (matches.length > 0) {
        report += "--- INLINED ERROR CONTEXTS (Details aus .md Dateien) ---\n";
        
        for (const match of matches) {
            const relativePath = match[1];
            // Da Playwright im 'frontend' Ordner läuft, ist der Pfad relativ dazu
            const fullPath = path.join('.', relativePath);
            
            if (fs.existsSync(fullPath)) {
                try {
                    const content = fs.readFileSync(fullPath, 'utf8');
                    // Komprimierung: Trimmen, leere Zeilen entfernen, Einrückung reduzieren
                    const compressed = content
                        .split('\n')
                        .map(line => line.trim())
                        .filter(line => line.length > 0)
                        .join('\n');
                    
                    report += `\n>> ORIGIN: ${relativePath}\n`;
                    report += compressed + "\n";
                    report += "------------------------------------------\n";
                } catch (readErr) {
                    report += `\n[Fehler beim Lesen von ${relativePath}]: ${readErr.message}\n`;
                }
            } else {
                report += `\n[Datei nicht gefunden]: ${fullPath}\n`;
            }
        }
    }

    fs.writeFileSync('ai_test_report.txt', report);
    
    console.log('✅ Datei "ai_test_report.txt" wurde mit Inlined-Kontext erstellt.');
    console.log('💡 HINWEIS: Du kannst den Report jetzt direkt hochladen.');
    process.exit(1);
}
