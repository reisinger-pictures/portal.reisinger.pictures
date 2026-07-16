<?php

namespace App\Mail\Transports;

use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GmailRestTransport extends AbstractTransport
{
    protected $clientId;
    protected $clientSecret;
    protected $refreshToken;

    public function __construct($clientId, $clientSecret, $refreshToken)
    {
        parent::__construct();
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        
        // Symfony Email in einen RFC 2822 String umwandeln
        $rawMessage = $email->toString();
        
        // Gmail REST API erwartet Base64Url Encoding (ohne padding)
        $base64Url = str_replace(['+', '/'], ['-', '_'], rtrim(base64_encode($rawMessage), '='));

        try {
            // 1. Frischen Access Token via Refresh Token holen
            $tokenResponse = Http::post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if (!$tokenResponse->successful()) {
                throw new Exception('Google OAuth2 Token Refresh failed: ' . $tokenResponse->body());
            }

            $accessToken = $tokenResponse->json('access_token');

            // 2. E-Mail via REST API senden.
            // Der Gmail upload-Endpoint erwartet MULTIPART/RELATED (Content-Type
            // message/rfc822 für den Raw-Teil) — ein reiner JSON-Body mit 'raw'
            // wird mit "Media type 'application/json' is not supported" (400)
            // abgelehnt. Wir bauen den Multipart-Body manuell auf.
            $boundary = 'boundary_' . bin2hex(random_bytes(16));
            $rawRfc822 = $email->toString();

            $multipartBody = implode("\r\n", [
                '--' . $boundary,
                'Content-Type: application/json; charset=UTF-8',
                '',
                json_encode(['raw' => $base64Url]),
                '--' . $boundary,
                'Content-Type: message/rfc822',
                '',
                $rawRfc822,
                '--' . $boundary . '--',
                '',
            ]);

            $sendResponse = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'multipart/related; boundary="' . $boundary . '"',
                ])
                ->withBody($multipartBody, 'multipart/related; boundary="' . $boundary . '"')
                ->post('https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=multipart');

            if (!$sendResponse->successful()) {
                throw new Exception('Gmail REST API rejected the message: ' . $sendResponse->body());
            }

        } catch (Exception $e) {
            Log::error('GmailRestTransport Error: ' . $e->getMessage());
            $this->triggerMakeWebhook($e->getMessage());
            throw $e;
        }
    }

    /**
     * Fallback Logik aus dem form2email Projekt
     */
    private function triggerMakeWebhook($errorMsg)
    {
        $webhookUrl = env('MAKE_WEBHOOK_URL');
        $makeApiKey = env('MAKE_API_KEY');

        if ($webhookUrl && $makeApiKey) {
            Http::withHeaders(['x-make-apikey' => $makeApiKey])
                ->post($webhookUrl, [
                    'error' => 'Laravel Portal: Failed to send Email via Gmail REST API',
                    'details' => $errorMsg,
                    'info' => 'Mail system down. See Laravel logs for details.' // SECURITY FIX: Keine Raw-E-Mail Dumps mehr an externe Webhooks.
                ]);
        }
    }

    public function __toString(): string
    {
        return 'gmail_rest';
    }
}
