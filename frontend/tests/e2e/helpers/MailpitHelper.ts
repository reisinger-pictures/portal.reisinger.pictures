import { APIRequestContext } from '@playwright/test';
import { MailpitMessage } from '../../../src/api';

export class MailpitHelper {
    private baseUrl = 'http://localhost:8025/api/v1';

    constructor(private request: APIRequestContext) {}

    async deleteAllMessages() {
        await this.request.delete(`${this.baseUrl}/messages`);
    }

    async getMessageForEmail(email: string) {
        for (let i = 0; i < 15; i++) {
            const response = await this.request.get(`${this.baseUrl}/messages?query=${encodeURIComponent(email)}`);
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                const msg = data.messages.find((m: MailpitMessage) => JSON.stringify(m.To).includes(email));
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

    async extractPasswordResetToken(email: string): Promise<string | null> {
        return this.extractLinkForEmail(email, /token=([a-zA-Z0-9]+)/);
    }

    async extractOrgInviteToken(email: string): Promise<string | null> {
        return this.extractLinkForEmail(email, /org-invite\/([a-zA-Z0-9]+)/);
    }
}
