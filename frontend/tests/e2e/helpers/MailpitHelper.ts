import { APIRequestContext } from '@playwright/test';
import { MailpitMessage } from '../../../src/api';

export class MailpitHelper {
    private baseUrl = process.env.MAILPIT_API_URL || 'http://localhost:8025/api/v1';

    constructor(private request: APIRequestContext) {}

    async deleteMessagesFor(email: string) {
        await this.request.delete(`${this.baseUrl}/search?query=${encodeURIComponent(`to:"${email}"`)}`);
    }

    async getMessageForEmail(email: string) {
        for (let i = 0; i < 20; i++) {
            // Mailpit paginiert die Message-Liste (Default-Limit ~50). Unter
            // paralleler Playwright-Last füllt sich die Mailbox schnell — die
            // Ziel-Mail darf nicht aus der ersten Seite rutschen, sonst wird sie
            // nie gefunden. Daher explizites hohes Limit + präziser Empfänger-Filter.
            const response = await this.request.get(`${this.baseUrl}/messages?query=${encodeURIComponent(`to:"${email}"`)}&limit=1000`);
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                const msg = data.messages.find((m: MailpitMessage) =>
                    m.To?.some((recipient: {Address?: string}) => recipient.Address === email)
                );
                if (msg) {
                    const detailResponse = await this.request.get(`${this.baseUrl}/message/${msg.ID}`);
                    return await detailResponse.json();
                }
            }
            await new Promise(resolve => setTimeout(resolve, 500));
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
