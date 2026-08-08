<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

trait MailpitAssertions
{
    private const MAILPIT_API = 'http://127.0.0.1:8025/api/v1';

    protected function getMailpitMessages(): array
    {
        return Http::get(self::MAILPIT_API . '/messages')->json('messages', []);
    }

    protected function getMailpitMessageByEmail(string $email): ?array
    {
        $messages = $this->getMailpitMessages();

        foreach ($messages as $msg) {
            foreach ($msg['To'] ?? [] as $recipient) {
                if (($recipient['Address'] ?? '') === $email) {
                    return Http::get(self::MAILPIT_API . "/message/{$msg['ID']}")->json();
                }
            }
        }

        return null;
    }

    protected function getMailpitMessagesByRecipient(string $email): array
    {
        $response = Http::get(self::MAILPIT_API . '/search', ['query' => "to:{$email}"]);
        return $response->json('messages', []);
    }

    protected function assertMailpitSentTo(string $email, int $expectedCount = 1): void
    {
        $messages = $this->getMailpitMessages();
        $matched = array_filter($messages, fn ($m) => collect($m['To'] ?? [])->pluck('Address')->contains($email));

        $this->assertGreaterThanOrEqual(
            $expectedCount,
            count($matched),
            "Expected at least {$expectedCount} mail(s) to {$email} in Mailpit, found " . count($matched)
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
                "Attachment '{$expectedFilename}' not found in mail to {$email}. Available: " . implode(', ', $filenames)
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
