import { spawn, execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import os from 'os';

console.log('🤖 Starte automatisierten Stripe Tunnel...');

// Stripe CLI automatisch auf neueste Version aktualisieren
console.log('🔄 Prüfe auf Stripe CLI Updates...');
try {
    execSync('pnpm add -g @stripe/cli', { stdio: 'inherit' });
} catch (err) {
    console.warn('⚠️  Stripe CLI Update fehlgeschlagen, fahre mit vorhandener Version fort.');
}

function findStripe() {
    if (process.platform === 'win32') {
        // Windows: pnpm/npm installieren .CMD-Shims
        const localAppData = process.env.LOCALAPPDATA || path.join(os.homedir(), 'AppData', 'Local');
        const appData = process.env.APPDATA || path.join(os.homedir(), 'AppData', 'Roaming');
        const candidates = [
            path.join(localAppData, 'pnpm', 'stripe.CMD'),
            path.join(appData, 'npm', 'stripe.CMD'),
            path.join(localAppData, 'pnpm', 'stripe'),
            path.join(appData, 'npm', 'stripe'),
        ];
        for (const c of candidates) {
            try {
                fs.accessSync(c);
                return c;
            } catch { /* nicht gefunden */ }
        }
        // PATH durchsuchen nach .CMD / .cmd / ohne Extension
        const paths = (process.env.PATH || '').split(path.delimiter);
        for (const dir of paths) {
            for (const exe of ['stripe.CMD', 'stripe.cmd', 'stripe']) {
                const full = path.join(dir, exe);
                try {
                    fs.accessSync(full);
                    return full;
                } catch { /* nicht gefunden */ }
            }
        }
        return 'stripe';
    }

    // macOS / Linux
    const home = os.homedir();
    const candidates = [
        path.join(home, '.npm-global', 'bin', 'stripe'),
        '/usr/local/bin/stripe',
        '/opt/homebrew/bin/stripe',
        path.join(home, '.local', 'bin', 'stripe'),
    ];
    for (const c of candidates) {
        try {
            fs.accessSync(c, fs.constants.X_OK);
            return c;
        } catch { /* nicht gefunden */ }
    }
    // PATH durchsuchen
    const paths = (process.env.PATH || '').split(path.delimiter);
    for (const dir of paths) {
        const full = path.join(dir, 'stripe');
        try {
            fs.accessSync(full, fs.constants.X_OK);
            return full;
        } catch { /* nicht gefunden */ }
    }
    return 'stripe';
}

const stripePath = findStripe();
console.log(`🔧 Verwende Stripe CLI: ${stripePath}`);
const stripe = process.platform === 'win32'
    ? spawn('cmd.exe', ['/c', stripePath, 'listen', '--forward-to', 'https://portal.test/api/webhooks/stripe'])
    : spawn(stripePath, ['listen', '--forward-to', 'https://portal.test/api/webhooks/stripe']);

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
