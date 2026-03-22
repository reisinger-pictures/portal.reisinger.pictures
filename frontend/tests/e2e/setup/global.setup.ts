import { execSync } from 'child_process';

async function globalSetup() {
    console.log('\n🔄 Setze Test-Datenbank zurück (migrate:fresh --seed)...');
    try {
        execSync('php artisan migrate:fresh --seed', { cwd: '../backend', stdio: 'inherit' });
        console.log('✅ Datenbank bereit für E2E Tests.');
    } catch (error) {
        console.error('❌ Fehler beim Zurücksetzen der Datenbank:', error);
        throw error;
    }
}

export default globalSetup;
