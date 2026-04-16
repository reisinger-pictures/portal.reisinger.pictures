import {expect, test} from '@playwright/test';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {UserDetailed} from '../../../src/logic/useUsers';

test.describe('Teardown Integrity Validation', () => {
    test('Flow AK: UserController@destroy properly wipes user and ensures test isolation', async ({request}) => {
        const helper = new E2ESessionHelper(request);
        const testUser = await helper.createIsolatedUser('client');

        // Als Super-Admin anmelden um die DB abzufragen
        const loginRes = await request.post('/api/auth/login', {
            data: {email: 'florian@reisinger.pictures', password: 'admin'},
            headers: {'Accept': 'application/json'}
        });
        const adminToken = loginRes.headers()['set-cookie'];

        // Verifizieren, dass der Test-User VOR dem Teardown existiert
        let usersRes = await request.get('/api/management/users', {
            headers: {'Cookie': adminToken}
        });
        let usersData = await usersRes.json();
        let found = usersData.data.find((u: UserDetailed) => u.email === testUser.email);
        expect(found).toBeTruthy();

        // 🔥 Teardown triggern (Löschung via API)
        await helper.teardown();

        // Verifizieren, dass der Test-User NACH dem Teardown vollständig verschwunden ist
        usersRes = await request.get('/api/management/users', {
            headers: {'Cookie': adminToken}
        });
        usersData = await usersRes.json();
        found = usersData.data.find((u: UserDetailed) => u.email === testUser.email);
        expect(found).toBeUndefined(); // Darf nicht mehr in der DB sein!
    });
});
