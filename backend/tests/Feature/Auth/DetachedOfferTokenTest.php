<?php

namespace Tests\Feature\Auth;

use App\Services\OfferTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A detached offer token is a credential for its own public flow, never a
 * session.
 *
 * Offer tokens carry `sub: offer` (see {@see OfferTokenService::SUBJECT}) and
 * are not bound to a user row. Presenting one as a bearer token must therefore
 * resolve to "no authenticated user" — a 401 on an `auth:api` route — and must
 * not send the subject to the UUID-keyed `users.id` column.
 */
class DetachedOfferTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_offer_token_does_not_authenticate_a_request(): void
    {
        $token = app(OfferTokenService::class)->issue(['photos' => [], 'price' => 100]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
