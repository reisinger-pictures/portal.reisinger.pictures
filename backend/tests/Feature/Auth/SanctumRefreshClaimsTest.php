<?php

namespace Tests\Feature\Auth;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\User;
use App\Services\Auth\AuthTokenService;
use App\Services\Auth\TransientClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for issue #9 Phase 3: the Sanctum refresh shim must carry the
 * transient claims of the consumed token into the rotated credential pair.
 * Dropping them logged guests out on the next request and silently stripped
 * a registered actor's invite grants.
 */
class SanctumRefreshClaimsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.migration.driver', 'sanctum');
        config()->set('auth.guards.api.driver', 'sanctum_transient');
        Auth::forgetGuards();
    }

    public function test_rotate_refresh_preserves_guest_claims(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'refresh-claims-'.Str::uuid(),
        ]);

        $guestId = (string) Str::uuid();
        $claims = new TransientClaims(
            guestId: $guestId,
            guestName: 'Refresh Gast',
            transientInviteIds: [(string) $invite->getKey()],
            transientGalleries: [$gallery->id],
        );

        $guest = new User;
        $guest->id = null;
        $guest->guest_id = $guestId;
        $guest->name = 'Refresh Gast';

        $tokens = app(AuthTokenService::class);
        $access = $tokens->issue($guest, $claims);
        $refresh = $tokens->issueRefresh($guest, $claims);

        $pair = $tokens->rotateRefresh($refresh, $access);

        $this->assertNotNull($pair);
        $rotated = $tokens->verify($pair['access']);

        $this->assertNotNull($rotated);
        $this->assertNull($rotated->getKey());
        $this->assertSame($guestId, $rotated->guest_id);
        $this->assertSame('Refresh Gast', $rotated->name);
        $this->assertContains($gallery->id, $rotated->transient_galleries);
        $this->assertContains((string) $invite->getKey(), $rotated->transient_invite_ids);
    }

    public function test_rotate_refresh_is_single_use(): void
    {
        $gallery = Gallery::factory()->create(['is_public' => false]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'refresh-once-'.Str::uuid(),
        ]);

        $guestId = (string) Str::uuid();
        $claims = new TransientClaims(
            guestId: $guestId,
            transientInviteIds: [(string) $invite->getKey()],
            transientGalleries: [$gallery->id],
        );

        $guest = new User;
        $guest->id = null;
        $guest->guest_id = $guestId;

        $tokens = app(AuthTokenService::class);
        $access = $tokens->issue($guest, $claims);
        $refresh = $tokens->issueRefresh($guest, $claims);

        $this->assertNotNull($tokens->rotateRefresh($refresh, $access));
        $this->assertNull($tokens->rotateRefresh($refresh, $access));
    }
}
