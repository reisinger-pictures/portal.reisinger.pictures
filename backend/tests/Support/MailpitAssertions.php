<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

trait MailpitAssertions
{
    private const DEFAULT_MAILPIT_API_URL = 'http://127.0.0.1:8025/api/v1';

    private function mailpitApiUrl(): string
    {
        return rtrim((string) (env('MAILPIT_API_URL') ?: self::DEFAULT_MAILPIT_API_URL), '/');
    }

    protected function getMailpitMessages(): array
    {
        return Http::get($this->mailpitApiUrl().'/messages')->json('messages', []);
    }

    protected function getMailpitMessageByEmail(string $email): ?array
    {
        // Recipient-scoped search, not the global message list. GET /messages
        // applies a default limit (50) and returns only the most recent
        // messages, so in a full parallel run — or against a long-lived local
        // Mailpit that has accumulated hundreds of messages — the message under
        // test falls outside the window and the lookup reports it as missing.
        // Measured locally with 569 messages stored: /messages returned 50,
        // /search?query=to:<recipient> returned the 7 that actually matched.
        // CI did not catch this because its Mailpit starts empty every run.
        foreach ($this->getMailpitMessagesByRecipient($email) as $message) {
            return Http::get($this->mailpitApiUrl()."/message/{$message['ID']}")->json();
        }

        return null;
    }

    protected function getMailpitMessagesByRecipient(string $email): array
    {
        $response = Http::get($this->mailpitApiUrl().'/search', ['query' => "to:{$email}"]);

        return $response->json('messages', []);
    }

    protected function assertMailpitSentTo(string $email, int $expectedCount = 1): void
    {
        $messages = $this->getMailpitMessages();
        $matched = array_filter($messages, fn ($m) => collect($m['To'] ?? [])->pluck('Address')->contains($email));

        $this->assertGreaterThanOrEqual(
            $expectedCount,
            count($matched),
            "Expected at least {$expectedCount} mail(s) to {$email} in Mailpit, found ".count($matched)
        );
    }

    protected function assertMailpitAttachmentExists(
        string $email,
        ?string $expectedFilename = null,
        ?string $expectedMimeType = null,
    ): array {
        $message = $this->getMailpitMessageByEmail($email);
        $this->assertNotNull($message, "No mail found for {$email} in Mailpit");

        $attachments = $message['Attachments'] ?? [];
        $this->assertNotEmpty($attachments, "No attachments found in mail to {$email}");

        if ($expectedFilename !== null) {
            $filenames = array_column($attachments, 'FileName');
            $this->assertContains(
                $expectedFilename,
                $filenames,
                "Attachment '{$expectedFilename}' not found in mail to {$email}. Available: ".implode(', ', $filenames)
            );
        }

        if ($expectedMimeType !== null) {
            $matching = array_filter($attachments, fn ($a) => ($a['ContentType'] ?? '') === $expectedMimeType);
            $this->assertNotEmpty(
                $matching,
                "No attachment with MIME type '{$expectedMimeType}' found in mail to {$email}"
            );
        }

        return $attachments;
    }
}
