import { spawn, execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

console.log('🤖 Starte automatisierten Stripe Tunnel...');

// Stripe CLI automatisch auf neueste Version aktualisieren
console.log('🔄 Prüfe auf Stripe CLI Updates...');
try {
    execSync('pnpm add -g @stripe/cli', { stdio: 'inherit', shell: true });
} catch (err) {
    console.warn('⚠️  Stripe CLI Update fehlgeschlagen, fahre mit vorhandener Version fort.');
}

const stripe = spawn('stripe listen --forward-to https://portal.test/api/webhooks/stripe', {
    shell: true
});

let secretFound = false;
// Speicherort: storage/app/private wird ohnehin von git ignoriert
const secretFile = path.join('backend', 'storage', 'app', 'private', 'stripe_secret.txt');

const handleOutput = (data) => {
    const output = data.toString();
    process.stdout.write(output);

    if (!secretFound) {
        const match = output.match(/(whsec_[a-zA-Z0-9]+)/);
        if (match) {
            secretFound = true;
            const secret = match[1];
            console.log('\n🤖 Auto-Tunneler: Neues Secret erkannt! Speichere live in Textdatei...');
            
            // Stelle sicher, dass das Verzeichnis existiert
            fs.mkdirSync(path.dirname(secretFile), { recursive: true });
            
            // Schreibe das Secret rein
            fs.writeFileSync(secretFile, secret);
            console.log(`✅ Secret erfolgreich in ${secretFile} abgelegt. Webhooks funktionieren ab sofort!\n`);
        }
    }
};

stripe.stdout.on('data', handleOutput);
stripe.stderr.on('data', handleOutput);

stripe.on('close', (code) => {
    console.log(`Stripe Prozess beendet mit Code ${code}`);
});
