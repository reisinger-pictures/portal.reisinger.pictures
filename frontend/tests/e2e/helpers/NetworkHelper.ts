import { Page, Response } from '@playwright/test';

export class NetworkHelper {
    constructor(private page: Page) {}

    /**
     * Zentrale Methode um auf API-Antworten zu warten.
     * Verhindert Flakiness bei asynchronen SWR/React-Query Updates.
     */
    async waitForApi(urlIncludes: string, method: 'GET' | 'POST' | 'PUT' | 'DELETE'): Promise<Response> {
        return this.page.waitForResponse(res => 
            res.url().includes(urlIncludes) && res.request().method() === method
        , { timeout: 3000 }).catch(() => null as unknown as Response); // Silent catch to prevent test crash if cached or too fast
    }

    // --- Spezifische Endpunkte für das Reisinger Portal ---
    
    waitForUpload() { return this.waitForApi('/api/management/upload', 'POST'); }
    waitForRating() { return this.waitForApi('/rate', 'POST'); }
    waitForOptIn() { return this.waitForApi('/opt-in', 'POST'); }
    waitForUsersRefetch() { return this.waitForApi('/api/management/users', 'GET'); }
    waitForLogin() { return this.waitForApi('/api/auth/login', 'POST'); }
    waitForMe() { return this.waitForApi('/api/auth/me', 'GET'); }
    waitForGallerySave() { return this.waitForApi('/api/management/galleries', 'POST'); }
    waitForGalleryUpdate() { return this.waitForApi('/api/management/galleries', 'PUT'); }

    waitForManagementMutation() {
        return this.page.waitForResponse(res => 
            res.url().includes('/api/management/') && 
            ['POST', 'PUT', 'DELETE'].includes(res.request().method())
        );
    }
}
