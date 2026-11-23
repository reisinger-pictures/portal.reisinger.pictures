<?php

namespace Tests\Feature\Auth;

use App\Enums\Brand;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\User;
use App\Services\ModelContactSheetLinkService;
use App\Services\OfferTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Phase-0 characterization of the CURRENT JWT behaviour (issue #9).
 *
 * These tests pin what exists TODAY so the dangerous Phase-3 guard flip
 * (jwt -> Sanctum) has an oracle. Production/auth code is deliberately left
 * untouched. Each contract is tied to an owner decision:
 *
 *  - D-73 (guest identity): an anonymous invite redeem resolves to a DB-less
 *    user with a `guest_id`; no `users` row is created.
 *  - D-75 (two httpOnly cookies, no PHP session): login sets `rp_jwt` and
 *    `rp_jwt_refresh`; a cookie-authenticated mutation without a valid CSRF
 *    double-submit token answers 403 (not 419).
 *  - D-76 (refresh shim / windows): 240-minute access window, 7-day refresh
 *    window; `/api/auth/refresh` rotates and blacklists the previous token.
 *  - D-77 (signed links before the HMAC move): the D-48 7-day shared links and
 *    the 14-day offer tokens are signed JWTs verified through the same
 *    secret/path; they are brand-bound, expire, and reject tampering / wrong
 *    brands.
 *
 * Note on the access window: the checked-in local `.env` overrides
 * `JWT_TTL=60`, while D-76 pins the contract at 240 minutes. The cookie test
 * fixes both config keys to the documented contract so the characterization is
 * deterministic and independent of local environment drift.
 */
class LegacyAuthContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * D-75 + D-76: login issues two httpOnly cookies and honours the documented
     * 240-minute access / 7-day refresh windows (mirrors
     * AuthControllerTest::test_auth_issues_separate_http_only_refresh_cookie_with_refresh_ttl).
     */
    public function test_login_issues_two_httponly_cookies_with_documented_windows(): void
    {
        Config::set('jwt.ttl', 240);
        Config::set('jwt.refresh_ttl', 10080); // 7 * 24 * 60 minutes
        // The factory holds its TTL from service-provider boot; force the value
        // after the config override so the issued JWT carries 240 minutes too.
        Auth::guard('api')->factory()->setTTL(240);

        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertCookie('rp_jwt')
            ->assertCookie('rp_jwt_refresh')
            ->assertJsonPath('expires_in', 240 * 60);

        $access = $response->getCookie('rp_jwt', false);
        $refresh = $response->getCookie('rp_jwt_refresh', false);

        $this->assertTrue($access->isHttpOnly());
        $this->assertTrue($refresh->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $refresh->getSameSite()));

        $now = time();
        $this->assertEqualsWithDelta($now + 240 * 60, $access->getExpiresTime(), 5);
        $this->assertEqualsWithDelta($now + 7 * 24 * 60 * 60, $refresh->getExpiresTime(), 5);

        // The access JWT itself carries the 240-minute window in its claims.
        $claims = $this->decodeClaims((string) $access->getValue());
        $this->assertSame(240 * 60, (int) $claims['exp'] - (int) $claims['iat']);

        // Today the refresh cookie is a longer-lived copy of the same JWT, not
        // a distinct credential. Pin that fact before the format changes.
        $this->assertSame($access->getValue(), $refresh->getValue());
    }

    /**
     * D-76: `/api/auth/refresh` reads the refresh cookie, rotates the credential
     * and blacklists the previous one (mirrors
     * AuthControllerTest::test_refresh_rotates_and_blacklists_previous_refresh_cookie).
     */
    public function test_refresh_uses_the_refresh_cookie_and_blacklists_the_previous_token(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $token = Auth::guard('api')->login($user);

        $first = $this->withValidCsrf()
            ->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh');

        $first->assertOk()->assertCookie('rp_jwt')->assertCookie('rp_jwt_refresh');
        $rotated = (string) $first->getCookie('rp_jwt_refresh', false)->getValue();
        $this->assertNotSame($token, $rotated);
        $this->assertTrue(app(JWTAuth::class)->setToken($rotated)->check());

        // Replaying the consumed refresh credential is rejected (blacklist).
        $replay = $this->withValidCsrf()
            ->withUnencryptedCookie('rp_jwt_refresh', $token)
            ->withCredentials()
            ->postJson('/api/auth/refresh');
        $replay->assertStatus(401)
            ->assertJsonPath('error', 'Token konnte nicht aktualisiert werden.');

        // The previous token is also no longer accepted as an access credential.
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        // No refresh cookie at all is rejected as well.
        $this->withValidCsrf()
            ->withCredentials()
            ->postJson('/api/auth/refresh')
            ->assertStatus(401);
    }

    /**
     * D-73: an anonymous invite redeem yields a token whose actor is a DB-less
     * user with a guest_id, and creates no `users` row (mirrors
     * TransientMagicLinkTest::test_no_user_created_for_multiple_anonymous_redeems
     * and test_me_returns_guest_data).
     */
    public function test_anonymous_invite_redeem_yields_a_dbless_guest_actor(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection', 'is_public' => false]);
        GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'guest-contract-1']);
        GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'guest-contract-2']);

        $first = $this->postJson('/api/invites/redeem', [
            'token' => 'guest-contract-1',
            'name' => 'Anna Nom',
            'email' => 'anna@example.com',
            'accept_privacy' => true,
        ]);
        $second = $this->postJson('/api/invites/redeem', [
            'token' => 'guest-contract-2',
            'name' => 'Berta Nom',
            'accept_privacy' => true,
        ]);

        $first->assertOk()->assertCookie('rp_jwt');
        $second->assertOk()->assertCookie('rp_jwt');

        // No users row for either anonymous redeem.
        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'anna@example.com']);

        $token = (string) $first->getCookie('rp_jwt', false)->getValue();
        $claims = $this->decodeClaims($token);
        $guestId = (string) $claims['guest_id'];
        $this->assertNotSame('', $guestId);
        $this->assertSame('guest_'.$guestId, $claims['sub']);
        $this->assertSame('Anna Nom', $claims['guest_name']);

        // The token resolves to the DB-less guest, not to a users row.
        Auth::forgetGuards();
        $me = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me');
        $me->assertOk()
            ->assertJsonPath('id', $guestId)
            ->assertJsonPath('guest_id', $guestId)
            ->assertJsonPath('name', 'Anna Nom');
        $this->assertNull($me->json('email'));
        $this->assertEquals([$gallery->id], $me->json('transient_galleries'));
        $this->assertDatabaseMissing('users', ['id' => $guestId]);
    }

    /**
     * D-77 + D-48: the 7-day shared link is signed, brand-bound, and rejects
     * tampering or an invalid signature (mirrors
     * ModelContactSheetShareTest's tampered / wrong-brand / expired cases).
     */
    public function test_d48_shared_link_is_signed_brand_bound_and_rejects_tampering(): void
    {
        $links = app(ModelContactSheetLinkService::class);
        $token = $links->issue(['profile-1', 'profile-2'], 'rp');

        $payload = $links->verify($token, 'rp');
        $this->assertIsArray($payload);
        $this->assertSame(['profile-1', 'profile-2'], $payload['model_profile_ids']);
        $this->assertSame('rp', $payload['brand']);
        $this->assertSame(ModelContactSheetLinkService::VARIANT, $payload['variant']);
        $this->assertTrue(Str::isUuid($payload['link_id']));

        // Brand binding: the same signed token is unusable for another brand.
        $this->assertNull($links->verify($token, 'srp'));

        // Tampering / an invalid signature is rejected.
        $tampered = substr($token, 0, -1).(str_ends_with($token, 'A') ? 'B' : 'A');
        $this->assertNull($links->verify($tampered, 'rp'));
        $this->assertNull($links->verify('not-a-jwt'));
    }

    /**
     * D-77 + D-48: pinned windows — 7 days for a shared link, 14 days for the
     * default offer token — and expiry enforcement before the HMAC move.
     */
    public function test_d48_shared_link_window_is_seven_days_and_offer_default_is_fourteen(): void
    {
        // issue() calls now() twice (default expiry and iat); freeze the clock so
        // exp - iat cannot drift by one second across a boundary.
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0));

        $links = app(ModelContactSheetLinkService::class);
        $this->assertSame(7, ModelContactSheetLinkService::TTL_DAYS);

        $shared = $this->decodeClaims($links->issue(['profile-1'], 'rp'));
        $this->assertSame(7 * 86400, (int) $shared['exp'] - (int) $shared['iat']);

        // An already expired shared link verifies to null.
        $expiredShared = $links->issue(['profile-1'], 'rp', now()->subDay());
        $this->assertNull($links->verify($expiredShared, 'rp'));

        $offers = app(OfferTokenService::class);
        $this->assertSame(14, OfferTokenService::DEFAULT_VALIDITY_DAYS);
        $offer = $this->decodeClaims($offers->issue(['price' => 100]));
        $this->assertSame(14 * 86400, (int) $offer['exp'] - (int) $offer['iat']);

        $expiredOffer = $offers->issue(['price' => 100], now()->subDay());
        $this->assertNull($offers->verify($expiredOffer));

        Carbon::setTestNow();
    }

    /**
     * D-77 + D-48: offer quote tokens are brand-bound and reject a wrong brand
     * (mirrors the strict payload validation of OfferTokenService::verifyQuote).
     */
    public function test_offer_quote_token_is_brand_bound_and_rejects_a_wrong_brand(): void
    {
        $offers = app(OfferTokenService::class);
        $token = $offers->issueQuote(['photo-1'], 500, null, 'rp');

        $payload = $offers->verifyQuote($token, 'rp');
        $this->assertIsArray($payload);
        $this->assertSame(['photo-1'], $payload['photos']);
        $this->assertSame(500, $payload['price']);
        $this->assertSame('rp', $payload['brand']);

        $this->assertNull($offers->verifyQuote($token, 'srp'));
        $this->assertNull($offers->verifyQuote('not-a-jwt', 'rp'));
    }

    /**
     * D-75: a cookie-authenticated mutation without a valid CSRF double-submit
     * token answers 403 with the exact message; with a valid token it succeeds
     * (mirrors ApiMutationSecurityTest::test_cookie_authenticated_mutations_fail_closed_without_a_csrf_token).
     */
    public function test_cookie_authenticated_mutation_without_csrf_token_is_403(): void
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $jwt = Auth::guard('api')->login($user);
        Auth::forgetGuards();

        $this->withUnencryptedCookie('rp_jwt_refresh', $jwt)
            ->withCredentials()
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->postJson('/api/auth/refresh')
            ->assertStatus(403)
            ->assertJsonPath('error', 'CSRF token missing or invalid.');

        // Positive control: the same cookie with a valid double-submit token.
        $csrf = $this->csrfCredentials();
        Auth::forgetGuards();
        $this->withUnencryptedCookie('rp_jwt', $jwt)
            ->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers'])
            ->withCredentials()
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    /**
     * Decode the payload of a signed JWT without verifying its signature —
     * enough to read the time/identity claims the contracts pin.
     *
     * @return array<string, mixed>
     */
    private function decodeClaims(string $token): array
    {
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'A JWT must have three segments.');

        $payload = $parts[1].str_repeat('=', (4 - strlen($parts[1]) % 4) % 4);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($claims);

        return $claims;
    }

    private function withValidCsrf(): static
    {
        $csrf = $this->csrfCredentials();

        return $this->withUnencryptedCookie('XSRF-TOKEN', $csrf['cookie'])
            ->withHeaders($csrf['headers']);
    }
}
