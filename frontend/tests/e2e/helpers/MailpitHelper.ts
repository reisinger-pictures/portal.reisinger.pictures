import { APIRequestContext, expect } from '@playwright/test';

export class MailpitHelper {
    private baseUrl = 'http://localhost:8025/api/v1';

    constructor(private request: APIRequestContext) {}

    async deleteAllMessages() {
        await this.request.delete(`${this.baseUrl}/messages`);
    }

    async getLatestMessage() {
        // Retry-Logik: Bis zu 5 Versuche mit 1s Pause
        for (let i = 0; i < 5; i++) {
            const response = await this.request.get(`${this.baseUrl}/messages`);
            const data = await response.json();
            if (data.messages && data.messages.length > 0) {
                const messageId = data.messages[0].ID;
                const detailResponse = await this.request.get(`${this.baseUrl}/message/${messageId}`);
                return await detailResponse.json();
            }
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
        return null;
    }

    async extractLinkFromLatestMessage(regexPattern: RegExp): Promise<string | null> {
        const msg = await this.getLatestMessage();
        if (!msg || !msg.HTML) return null;
        const match = msg.HTML.match(regexPattern);
        return match ? match[1] : null;
    }
}
