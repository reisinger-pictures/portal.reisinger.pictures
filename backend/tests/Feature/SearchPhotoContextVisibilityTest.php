<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Middleware\BrandContextMiddleware;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchPhotoContextVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_expired_public_photo_context_is_denied_to_guests_and_granted_users_but_visible_to_gallery_managers(): void
    {
        [$gallery, $photo] = $this->photoInGallery([
            'is_public' => true,
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson($this->contextUrl($photo))->assertForbidden()->assertJsonMissingPath('photo');

        $grantedUser = $this->user(UserRole::CLIENT);
        $grantedUser->galleries()->attach($gallery);
        $this->contextAs($grantedUser, $photo)->assertForbidden()->assertJsonMissingPath('breadcrumbs');

        $manager = $this->user(UserRole::PHOTOGRAPHER);
        $manager->galleries()->attach($gallery);
        $this->contextAs($manager, $photo)
            ->assertOk()
            ->assertJsonPath('photo.id', $photo->id)
            ->assertJsonPath('breadcrumbs.0.type', 'gallery');
    }

    public function test_expired_private_photo_context_preserves_grants_and_manager_super_admin_exceptions(): void
    {
        [$gallery, $photo] = $this->photoInGallery([
            'is_public' => false,
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson($this->contextUrl($photo))->assertForbidden()->assertJsonMissingPath('photo');

        $grantedUser = $this->user(UserRole::CLIENT);
        $grantedUser->galleries()->attach($gallery);
        $this->contextAs($grantedUser, $photo)->assertForbidden()->assertJsonMissingPath('photo');

        $manager = $this->user(UserRole::PHOTOGRAPHER);
        $manager->galleries()->attach($gallery);
        $this->contextAs($manager, $photo)->assertOk()->assertJsonPath('photo.id', $photo->id);

        $superAdmin = $this->user(UserRole::SUPER_ADMIN, brand: null);
        $this->contextAs($superAdmin, $photo)->assertOk()->assertJsonPath('photo.id', $photo->id);
    }

    public function test_unexpired_public_and_private_contexts_keep_their_existing_access_rules(): void
    {
        [$publicGallery, $publicPhoto] = $this->photoInGallery(['is_public' => true]);
        $this->getJson($this->contextUrl($publicPhoto))->assertOk();

        [$privateGallery, $privatePhoto] = $this->photoInGallery(['is_public' => false]);
        $this->getJson($this->contextUrl($privatePhoto))->assertForbidden();

        $grantedUser = $this->user(UserRole::CLIENT);
        $grantedUser->galleries()->attach($privateGallery);
        $this->contextAs($grantedUser, $privatePhoto)->assertOk();

        $this->assertNotSame($publicGallery->id, $privateGallery->id);
    }

    public function test_super_admin_does_not_bypass_current_brand_isolation_for_photo_context(): void
    {
        [, $photo] = $this->photoInGallery();
        $superAdmin = $this->user(UserRole::SUPER_ADMIN, brand: null);
        $this->withoutMiddleware(BrandContextMiddleware::class);
        BrandRegistry::set(new BrandConfig('other', 'Other', 'theme', 'Other', null, null));

        $this->contextAs($superAdmin, $photo)->assertNotFound()->assertJsonMissingPath('photo');
    }

    /**
     * @return array{0: Gallery, 1: Photo}
     */
    private function photoInGallery(array $overrides = []): array
    {
        $gallery = Gallery::factory()->create(array_merge([
            'brand' => Brand::B2B->value,
            'type' => 'delivery',
            'is_public' => true,
        ], $overrides));
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        return [$gallery, $photo];
    }

    private function user(UserRole $role, ?string $brand = Brand::B2B->value): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    private function contextAs(User $user, Photo $photo)
    {
        $token = auth('api')->login($user);

        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson($this->contextUrl($photo));
    }

    private function contextUrl(Photo $photo): string
    {
        return '/api/photos/'.$photo->id.'/context';
    }
}
