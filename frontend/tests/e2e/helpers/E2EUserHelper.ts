import { APIRequestContext } from '@playwright/test';
import { MailpitHelper } from './MailpitHelper';

export class E2EUserHelper {
  private static usersToCleanup: string[] = [];

  static trackForCleanup(identifier: string) {
    if (identifier && !this.usersToCleanup.includes(identifier)) {
      this.usersToCleanup.push(identifier);
    }
  }

    static async createIsolatedUser(
        request: APIRequestContext,
        roleName: 'admin' | 'photographer' | 'client',
        options?: { assignGalleryId?: string, wantsNotifications?: boolean }
    ) {
        const uniqueId = Math.random().toString(36).substring(2, 10);
        const email = `e2e-${roleName}-${uniqueId}@example.com`;
        const password = 'SecurePassword123!';
        const headers = { 'Accept': 'application/json' };

        // 1. Admin Login (Bootstrapping)
        await request.post('/api/auth/login', { data: { email: 'florian@reisinger.pictures', password: 'admin' }, headers });

        // 2. Create User
        const createRes = await request.post('/api/management/users', {
            data: { name: `E2E ${roleName}`, email: email },
            headers
        });
        const createData = await createRes.json();
        const newUserId = createData.user?.id;

        // 3. Assign Role & Gallery
        const rolesRes = await request.get('/api/management/roles', { headers });
        const roles = await rolesRes.json();
        const targetRole = roles.find((r: any) => r.name === roleName);

        await request.put(`/api/management/users/${newUserId}`, {
            data: {
                role_ids: [targetRole.id],
                gallery_ids: options?.assignGalleryId ? [options.assignGalleryId] : [],
                gallery_group_ids: [],
                can_edit_metadata: false
            },
            headers
        });

        // 4. Password Reset via Mailpit (macht den User "aktiv")
        const mailpit = new MailpitHelper(request);
        const token = await mailpit.extractLinkForEmail(email, /token=([a-zA-Z0-9]+)/);
        await request.post('/api/auth/reset-password', { data: { email, token, password }, headers });

        // 5. Opt-In setzen (erfordert Login als dieser User)
        if (options?.assignGalleryId && options?.wantsNotifications) {
            // Logout Admin
            await request.post('/api/auth/logout', { headers });
            // Login als neuer Client
            await request.post('/api/auth/login', { data: { email, password }, headers });
            // Opt-In triggern
            await request.post(`/api/galleries/${options.assignGalleryId}/opt-in`, {
                data: { wants_notifications: true },
                headers
            });
            // Logout Client
            await request.post('/api/auth/logout', { headers });
        } else {
            await request.post('/api/auth/logout', { headers });
        }

        return { email, password, id: newUserId };
    }

  static async cleanupTrackedUsers(request: any) {
    if (this.usersToCleanup.length === 0) return;
    for (const idOrEmail of this.usersToCleanup) {
      try {
        await request.delete(`/api/users/${idOrEmail}`);
      } catch (e) {
        console.error(`❌ Fehler beim Löschen von ${idOrEmail}`, e);
      }
    }
    this.usersToCleanup = [];
  }

  static async cleanupE2EData(request: APIRequestContext) {
    const loginRes = await request.post('/api/auth/login', {
        data: { email: 'florian@reisinger.pictures', password: 'admin' },
        headers: { 'Accept': 'application/json' }
    });
    
    const cookies = loginRes.headers()['set-cookie'];
    if (!cookies) return;
    
    const authHeaders = { 'Accept': 'application/json', 'Cookie': cookies };
    const treeRes = await request.get('/api/management/galleries', { headers: authHeaders });
    
    if (treeRes.ok()) {
        const tree = await treeRes.json();
        
        const deleteGallery = async (id: string) => request.delete(`/api/management/galleries/${id}`, { headers: authHeaders });
        const deleteGroup = async (g: any) => {
            if (g.galleries) for (const gal of g.galleries) await deleteGallery(gal.id);
            if (g.children) for (const c of g.children) await deleteGroup(c);
            await request.delete(`/api/management/gallery-groups/${g.id}`, { headers: authHeaders });
        };

        // Typische Prefixe unserer E2E Tests abfangen
        const e2ePrefixes = ['Tree Group', 'Sub Group', 'Inline Test', 'Dash Test', 'Flow C', 'Create Test', 'To Edit', 'Edited', 'Upload Test', 'Toggle Test', 'E2E Selection', 'OptIn Test', 'Download Test', 'Lightbox Test', 'Metadata Test', 'Selection Lightbox', 'Playwright', 'Comm Test', 'Smart Default Test'];
        const isE2E = (name: string) => e2ePrefixes.some(prefix => name.includes(prefix));

        for (const g of (tree.root_galleries || [])) {
            if (isE2E(g.name)) await deleteGallery(g.id);
        }
        for (const grp of (tree.groups || [])) {
            if (isE2E(grp.name)) await deleteGroup(grp);
        }
    }
  }
}
