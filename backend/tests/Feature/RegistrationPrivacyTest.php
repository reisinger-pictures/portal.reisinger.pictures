<?php

namespace Tests\Feature;

use App\Enums\AutoJoinPolicy;
use App\Enums\Brand;
use App\Enums\UserRole;
use App\Mail\ActivateAccountMail;
use App\Models\Org;
use App\Models\OrgInvite;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Regression for finding AUTH-2 (issue #24): `POST /api/auth/register` must not
 * be an account-existence oracle.
 *
 * An address that already has an account and an address without one answer with
 * the same 200 body. The existing account is not touched by the attempt — no
 * write, no org/invite handling, no activation token, no mail — because a
 * stranger who signs up with somebody else's address must not be able to
 * consume that person's pending org invite, move their membership or reset
 * their activation state.
 */
class RegistrationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        BrandRegistry::set(Brand::B2B);
        Mail::fake();
    }

    public function test_new_address_creates_the_account_and_queues_the_activation_mail(): void
    {
        $email = 'new-address@example.test';

        $response = $this->register('New Person', $email);

        $response->assertOk();
        $this->assertSame([
            'success' => true,
            'message' => 'Registrierung erfolgreich. Bitte prüfe deine E-Mails.',
        ], $response->json());

        $this->assertDatabaseHas('users', ['email' => $email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $email]);

        // The activation mail is queued, not sent inline: ActivateAccountMail
        // extends AbstractBrandAwareMailable, which implements ShouldQueue.
        Mail::assertQueued(
            ActivateAccountMail::class,
            fn (ActivateAccountMail $mail): bool => $mail->hasTo($email)
        );
    }

    public function test_existing_address_returns_the_identical_response_without_writing_or_mailing(): void
    {
        $existing = User::factory()->create(['brand' => Brand::B2B, 'email' => 'existing@example.test']);

        $newAddress = $this->register('New Person', 'brand-new@example.test');
        $newAddress->assertOk();
        $newAddressPayload = $newAddress->json();

        // Only the freshly created account has queued a mail at this point.
        Mail::assertQueued(ActivateAccountMail::class, 1);

        $existingAttempt = $this->register('Someone Else', $existing->email);
        $existingAttempt->assertOk();

        // The two decoded payloads are compared with each other, never against
        // two hand-written literals: only the equality is the contract.
        $this->assertSame(
            $newAddressPayload,
            $existingAttempt->json(),
            'An existing address must receive the byte-identical success payload of a new one.'
        );

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($existing->password, $existing->fresh()->password, 'The existing password must stay untouched.');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $existing->email]);

        Mail::assertQueued(ActivateAccountMail::class, 1);
        Mail::assertNotQueued(
            ActivateAccountMail::class,
            fn (ActivateAccountMail $mail): bool => $mail->hasTo($existing->email)
        );
    }

    public function test_existing_member_attempt_does_not_consume_the_org_invite_or_change_membership(): void
    {
        $orgAdminRole = Role::firstOrCreate(['name' => UserRole::ORG_ADMIN->value]);

        $homeOrg = Org::factory()->create([
            'domain' => 'home-org.example.test',
            'brand' => Brand::B2B->value,
        ]);
        $inviteOrg = Org::factory()->create([
            'domain' => 'invite-org.example.test',
            'brand' => Brand::B2B->value,
            'auto_join_policy' => AutoJoinPolicy::REQUIRES_INVITE,
        ]);

        $email = 'member@invite-org.example.test';
        $member = User::factory()->create([
            'brand' => Brand::B2B,
            'email' => $email,
            'org_id' => $homeOrg->id,
        ]);
        $member->roles()->attach($orgAdminRole);

        $invite = OrgInvite::create([
            'email' => $email,
            'org_id' => $inviteOrg->id,
            'token' => 'pending-org-invite-token',
            'expires_at' => now()->addDays(7),
        ]);

        $this->register('Member', $email)->assertOk();

        // The invite of the real owner must survive: consuming it would be a
        // cross-account mutation triggered by a stranger.
        $this->assertDatabaseHas('org_invites', [
            'id' => $invite->id,
            'email' => $email,
            'org_id' => $inviteOrg->id,
        ]);

        $fresh = $member->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($homeOrg->id, $fresh->org_id);
        $this->assertSame([UserRole::ORG_ADMIN->value], $fresh->roles()->pluck('name')->all());

        Mail::assertNotQueued(
            ActivateAccountMail::class,
            fn (ActivateAccountMail $mail): bool => $mail->hasTo($email)
        );
    }

    public function test_existing_address_of_another_brand_gets_the_same_generic_response(): void
    {
        // `srp` is a known legacy brand id that is not the current host brand
        // (`rp`, set in setUp), so a brand-aware implementation would have to
        // decide something here. The response must not disclose brand
        // membership either.
        $foreign = User::factory()->create(['brand' => 'srp', 'email' => 'foreign-brand@example.test']);

        $newAddress = $this->register('New Person', 'unrelated-new@example.test');
        $newAddress->assertOk();
        $newAddressPayload = $newAddress->json();

        $foreignAttempt = $this->register('Someone Else', $foreign->email);
        $foreignAttempt->assertOk();

        $this->assertSame($newAddressPayload, $foreignAttempt->json());

        $this->assertDatabaseCount('users', 2);
        $this->assertSame('srp', $foreign->fresh()->brand, 'The foreign account must keep its brand.');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $foreign->email]);
        Mail::assertNotQueued(
            ActivateAccountMail::class,
            fn (ActivateAccountMail $mail): bool => $mail->hasTo($foreign->email)
        );
    }

    public function test_lost_insert_race_returns_the_same_generic_success(): void
    {
        $email = 'race@example.test';

        $newAddress = $this->register('New Person', 'race-control@example.test');
        $newAddress->assertOk();
        $newAddressPayload = $newAddress->json();

        // Simulate the loser of two concurrent registrations: both requests pass
        // the existence check, then the competing request inserts the row just
        // before this one's INSERT reaches the unique index on `users.email`.
        // The raw insert deliberately bypasses the model so it cannot recurse
        // into the listener below.
        $competitorInserted = false;
        User::creating(function () use (&$competitorInserted, $email): void {
            if ($competitorInserted) {
                return;
            }

            $competitorInserted = true;

            DB::table('users')->insert([
                'id' => (string) Str::uuid(),
                'email' => $email,
                'name' => 'Concurrent registrant',
                'password' => null,
                'created_at' => now(),
            ]);
        });

        $response = $this->register('Race Loser', $email);

        $this->assertTrue($competitorInserted, 'The simulated competitor must have inserted the address.');
        $response->assertOk();
        $this->assertSame(
            $newAddressPayload,
            $response->json(),
            'A lost insert race must answer like a successful registration, not with a 500.'
        );

        Mail::assertNotQueued(
            ActivateAccountMail::class,
            fn (ActivateAccountMail $mail): bool => $mail->hasTo($email)
        );

        // The competitor's row lived in this transaction as well and is rolled
        // back with it; the assertion documents that the losing request wrote
        // nothing of its own.
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    /**
     * Call the registration endpoint without tripping `throttle:5,1`.
     *
     * The middleware keys the budget on `sha1(route domain | client ip)`
     * (`ThrottleRequests::resolveRequestSignature()`); the route has no domain
     * and the test client is `127.0.0.1`. The limiter is cleared per call so a
     * test that registers more than once cannot become flaky — the configured
     * budget stays untouched.
     */
    private function register(string $name, string $email): TestResponse
    {
        RateLimiter::clear(sha1('|127.0.0.1'));

        return $this->postJson('/api/auth/register', [
            'name' => $name,
            'email' => $email,
        ]);
    }
}
