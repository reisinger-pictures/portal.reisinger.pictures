<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Mail\Transports\GmailRestTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

class GmailRestTransportTest extends TestCase
{
    public function test_send_uses_multipart_related_with_rfc822_part(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token'], 200),
            'gmail.googleapis.com/*' => Http::response(['id' => 'msg-123'], 200),
        ]);

        $transport = new GmailRestTransport('client-id', 'client-secret', 'refresh-token');
        $this->assertInstanceOf(TransportInterface::class, $transport);

        $email = (new Email())
            ->from('florian@reisinger.pictures')
            ->to('test@example.com')
            ->subject('SMTP Test')
            ->text('Hello world');

        $transport->send($email);

        // Verify the Gmail send request used multipart/related (not raw JSON).
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'gmail.googleapis.com')) {
                return false;
            }

            $contentType = $request->header('Content-Type')[0] ?? '';
            $body = (string) $request->body();

            return str_contains($contentType, 'multipart/related')
                && str_contains($body, 'Content-Type: message/rfc822')
                && str_contains($body, '"raw"');
        });
    }

    public function test_send_throws_on_gmail_api_error(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token'], 200),
            'gmail.googleapis.com/*' => Http::response(
                ['error' => ['code' => 400, 'message' => "Media type 'application/json' is not supported."]],
                400
            ),
        ]);

        $transport = new GmailRestTransport('client-id', 'client-secret', 'refresh-token');

        $email = (new Email())
            ->from('florian@reisinger.pictures')
            ->to('test@example.com')
            ->subject('SMTP Test')
            ->text('Hello world');

        $this->expectException(\Exception::class);
        $transport->send($email);
    }
}
