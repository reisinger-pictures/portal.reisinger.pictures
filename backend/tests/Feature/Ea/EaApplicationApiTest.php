<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaApplication;

class EaApplicationApiTest extends EaBookkeepingTestCase
{
    public function test_applications_require_authentication(): void
    {
        $this->getJson('/api/management/ea/applications')->assertUnauthorized();
        $this->postJson('/api/management/ea/applications', [])->assertUnauthorized();
    }

    public function test_application_status_can_be_changed_and_foreign_id_is_404(): void
    {
        $token = $this->tokenFor();

        $created = $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/applications', [
            'year' => 2026, 'type' => 'Foerderung', 'subject' => 'Antrag 2026',
        ]);

        $created->assertCreated();
        $created->assertJsonPath('application.status', 'open');
        $id = $created->json('application.id');

        $this->withHeaders($this->bearer($token))
            ->patchJson("/api/management/ea/applications/{$id}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('application.status', 'done');

        $this->withHeaders($this->bearer($token))
            ->patchJson("/api/management/ea/applications/{$id}", ['status' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $foreign = EaApplication::create([
            'brand' => self::OTHER_BRAND, 'year' => 2026,
            'type' => 'Foerderung', 'subject' => 'Fremd',
        ]);

        $this->withHeaders($this->bearer($token))
            ->patchJson("/api/management/ea/applications/{$foreign->id}", ['status' => 'done'])
            ->assertNotFound();

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/ea/applications?year=2026&status=done')
            ->assertOk()
            ->assertJsonCount(1, 'applications');
    }

    public function test_application_requires_subject_and_supported_year(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/ea/applications', ['year' => 2026, 'type' => 'Foerderung'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subject');

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/management/ea/applications', [
                'year' => 2025, 'type' => 'Foerderung', 'subject' => 'Antrag',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }
}
