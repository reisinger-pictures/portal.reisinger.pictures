import { APIRequestContext, expect } from '@playwright/test';

export class MailpitHelper {
    private baseUrl = 'http://localhost:8025/api/v1';

    constructor(private request: APIRequestContext) {}

    async deleteAllMessages() {
        await this.request.delete(`${this.baseUrl}/messages`);
    }

    async getMessageForEmail(email: string) {
        // Retry-Logik: Bis zu 15 Versuche mit 1s Pause (falls SMTP etwas länger braucht)
        for (let i = 0; i < 15; i++) {
            const response = await this.request.get(`${this.baseUrl}/messages`);
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                // Suche explizit nach der Nachricht an DIESE E-Mail-Adresse, um Parallel-Test-Konflikte zu vermeiden
                const msg = data.messages.find((m: any) => JSON.stringify(m.To).includes(email));
                if (msg) {
                    const detailResponse = await this.request.get(`${this.baseUrl}/message/${msg.ID}`);
                    return await detailResponse.json();
                }
            }
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
        return null;
    }

    async extractLinkForEmail(email: string, regexPattern: RegExp): Promise<string | null> {
        const msg = await this.getMessageForEmail(email);
        if (!msg || !msg.HTML) return null;
        const match = msg.HTML.match(regexPattern);
        return match ? match[1] : null;
    }
}
