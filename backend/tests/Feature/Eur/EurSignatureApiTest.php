<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurDocument;

class EurSignatureApiTest extends EurBookkeepingTestCase
{
    public function test_signatures_require_authentication(): void
    {
        $this->getJson('/api/management/eur/signatures')->assertUnauthorized();
        $this->postJson('/api/management/eur/signatures', [])->assertUnauthorized();
    }

    public function test_test_provider_is_flagged_and_repeat_post_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $payload = [
            'year' => 2026,
            'month' => 5,
            'provider' => 'TestSign',
            'status' => 'signed',
        ];

        $first = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/signatures', $payload);

        $first->assertOk();
        $first->assertJsonPath('is_test', true);
        $first->assertJsonPath('signature.provider', 'TestSign');
        $first->assertJsonPath('signature.status', 'signed');

        $second = $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/signatures', $payload);

        $second->assertOk();
        $this->assertSame($first->json('signature.id'), $second->json('signature.id'));
        $this->assertDatabaseCount('eur_signatures', 1);
        $this->assertDatabaseCount('eur_audit_logs', 1);
    }

    public function test_real_provider_is_not_flagged_as_test(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/signatures', [
                'year' => 2026, 'month' => 6, 'provider' => 'eID-Partner',
            ])
            ->assertOk()
            ->assertJsonPath('is_test', false)
            ->assertJsonPath('signature.status', 'pending');
    }

    public function test_foreign_document_and_foreign_year_are_rejected(): void
    {
        $token = $this->tokenFor();

        $foreign = EurDocument::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026,
            'original_name' => 'fremd.pdf',
            'storage_path' => 'eur/srp/fremd.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 5,
            'sha256' => str_repeat('a', 64),
        ]);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/signatures', [
                'year' => 2026, 'month' => 5, 'provider' => 'eID',
                'eur_document_id' => $foreign->id,
            ])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/eur/signatures', [
                'year' => 2025, 'month' => 5, 'provider' => 'eID',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');

        $this->assertDatabaseCount('eur_signatures', 0);
    }
}
