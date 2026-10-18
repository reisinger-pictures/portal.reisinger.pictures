<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelSensitiveDownloadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create(['brand' => 'rp']);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    /**
     * @return array{ModelProfile, ModelPhoto}
     */
    private function profileWithSensitiveFiles(int $monthsSinceConfirmation): array
    {
        $customer = Customer::factory()->create([
            'brand' => 'rp',
            'is_model' => true,
            'name' => 'Lifecycle Model',
        ]);
        $ageProofPath = 'model-age-proofs/'.$customer->id.'/proof.jpg';
        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'gender' => null,
            'age_proof_required' => true,
            'age_proof_path' => $ageProofPath,
            'age_proof_uploaded_at' => now(),
            'submitted_at' => now(),
            'last_confirmed_at' => now()->subMonths($monthsSinceConfirmation),
        ]);

        $photoPath = 'model-photos/'.$profile->id.'/portrait.png';
        $photoBytes = $this->imageBytes();
        $fileStore = app(ModelFileStore::class);
        $fileStore->putEncrypted($ageProofPath, 'encrypted age-proof fixture');
        $fileStore->putEncrypted($photoPath, $photoBytes);

        $photo = ModelPhoto::create([
            'customer_id' => $profile->customer_id,
            'model_profile_id' => $profile->id,
            'path' => $photoPath,
            'original_name' => 'portrait.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($photoBytes),
            'visibility' => ModelPhoto::VISIBILITY_PUBLIC,
            'is_primary' => true,
            'position' => 0,
        ]);

        return [$profile, $photo];
    }

    private function imageBytes(): string
    {
        $image = imagecreatetruecolor(24, 24);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_sensitive_model_downloads_follow_lifecycle_and_role_policy(): void
    {
        foreach ([
            [0, UserRole::ADMIN, 200],
            [0, UserRole::SUPER_ADMIN, 200],
            [14, UserRole::ADMIN, 403],
            [14, UserRole::SUPER_ADMIN, 200],
            [15, UserRole::ADMIN, 403],
            [15, UserRole::SUPER_ADMIN, 200],
        ] as [$monthsSinceConfirmation, $role, $expectedStatus]) {
            [$profile, $photo] = $this->profileWithSensitiveFiles($monthsSinceConfirmation);
            $user = $this->userWithRole($role);

            $urls = [
                "/api/management/models/{$profile->id}/photos/{$photo->id}",
                "/api/management/models/{$profile->id}/age-proof",
                "/api/management/models/{$profile->id}/contact-sheet?variant=internal",
                "/api/management/models/{$profile->id}/contact-sheet?variant=external",
            ];

            foreach ($urls as $url) {
                $response = $this->actingAs($user, 'api')->get($url);

                $this->assertSame(
                    $expectedStatus,
                    $response->getStatusCode(),
                    sprintf(
                        '%s should return HTTP %d for %s profile %s. Response: %s',
                        $url,
                        $expectedStatus,
                        $role->value,
                        $profile->lifecycleStatus(),
                        $response->getContent(),
                    ),
                );

                if ($expectedStatus === 200
                    && (str_contains($url, '/age-proof') || str_contains($url, '/photos/'))) {
                    $this->assertNotSame('', $response->streamedContent());
                }
            }
        }
    }
}
