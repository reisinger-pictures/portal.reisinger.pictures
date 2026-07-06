<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExtractOfferValidationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminContext(): array
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin']);
        $user->roles()->attach($role);
        $token = auth('api')->login($user);
        return ['user' => $user, 'token' => $token];
    }

    public function test_non_pdf_upload_gets_422(): void
    {
        $ctx = $this->superAdminContext();

        $fakePdf = UploadedFile::fake()->create('document.pdf', 100, 'text/plain');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/invoices/extract-offer', [
                'pdf' => $fakePdf,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pdf']);
    }

    public function test_valid_pdf_passes_validation(): void
    {
        $ctx = $this->superAdminContext();

        $pdf = UploadedFile::fake()->create('offer.pdf', 500, 'application/pdf');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/invoices/extract-offer', [
                'pdf' => $pdf,
            ]);

        // Validation passes — the endpoint continues to process the (fake) PDF content
        // and will return 404 (no OFFER_JWT marker), NOT 422.
        $response->assertStatus(404);
    }

    public function test_non_super_admin_gets_403(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/management/invoices/extract-offer', [
                'pdf' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(403);
    }

    public function test_large_file_gets_422(): void
    {
        $ctx = $this->superAdminContext();

        $largeFile = UploadedFile::fake()->create('large.pdf', 15000, 'application/pdf');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/invoices/extract-offer', [
                'pdf' => $largeFile,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pdf']);
    }

    public function test_missing_file_gets_422(): void
    {
        $ctx = $this->superAdminContext();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $ctx['token']])
            ->postJson('/api/management/invoices/extract-offer', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['pdf']);
    }
}
