<?php

namespace Tests\Unit\Services;

use App\Services\ActivationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The token claim of `password_reset_tokens` (D-17, AGENTS.md §14): a still
 * valid token is never replaced, so a second issuance cannot invalidate the
 * link an earlier one already handed out.
 *
 * Scope: the service contract only — the returned plaintext is the signal the
 * three callers gate their mail on (`null` → do not mail). No mail fake here
 * on purpose; the three call sites are covered by their own flow tests, and a
 * `Mail::fake()` here would assert the fake, not the claim.
 */
class ActivationTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'model@example.com';

    private ActivationTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen clock: the claim compares `created_at` against
        // `now - lifetime`, so the boundary cases are only reproducible with a
        // fixed "now".
        $this->travelTo('2026-01-01 12:00:00');

        $this->service = new ActivationTokenService;
    }

    private function insertToken(?Carbon $createdAt, string $plaintext = 'foreign-token'): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => self::EMAIL,
            'token' => Hash::make($plaintext),
            'created_at' => $createdAt,
        ]);
    }

    private function storedRow(): object
    {
        return DB::table('password_reset_tokens')->where('email', self::EMAIL)->first();
    }

    public function test_first_issuance_claims_the_slot_and_the_token_verifies(): void
    {
        $token = $this->service->issue(self::EMAIL);

        $this->assertNotNull($token);
        $row = $this->storedRow();
        $this->assertSame(Carbon::now()->toDateTimeString(), $row->created_at);
        $this->assertTrue(Hash::check($token, $row->token));
    }

    public function test_second_issuance_preserves_the_live_token_and_signals_no_second_mail(): void
    {
        $first = $this->service->issue(self::EMAIL);
        $rowAfterFirst = $this->storedRow();

        $second = $this->service->issue(self::EMAIL);

        // `null` is the documented signal for "a still valid token already
        // exists" — the caller must not overwrite it and must not send a
        // second mail.
        $this->assertNull($second);

        $rowAfterSecond = $this->storedRow();
        $this->assertSame(
            $rowAfterFirst->token,
            $rowAfterSecond->token,
            'The live token hash must survive a second issuance.',
        );
        $this->assertSame($rowAfterFirst->created_at, $rowAfterSecond->created_at);
        $this->assertTrue(Hash::check((string) $first, $rowAfterSecond->token));
    }

    public function test_token_is_untouched_one_second_before_the_lifetime_boundary(): void
    {
        $this->insertToken(Carbon::now()->subMinutes(59));

        $this->assertNull($this->service->issue(self::EMAIL));
        $this->assertTrue(Hash::check('foreign-token', $this->storedRow()->token));
    }

    public function test_takeover_happens_exactly_at_the_lifetime_boundary(): void
    {
        // The claim uses `created_at <= now - lifetime`; `resetPassword()`
        // uses a strict `<`. Pinning the claim's side of that documented
        // one-second difference — see the docblock on lifetimeMinutes().
        $this->insertToken(Carbon::now()->subMinutes(60));

        $token = $this->service->issue(self::EMAIL);

        $this->assertNotNull($token);
        $this->assertTrue(Hash::check($token, $this->storedRow()->token));
    }

    public function test_expired_token_is_taken_over(): void
    {
        $this->insertToken(Carbon::now()->subMinutes(61));

        $token = $this->service->issue(self::EMAIL);

        $this->assertNotNull($token);
        $row = $this->storedRow();
        $this->assertTrue(Hash::check($token, $row->token));
        $this->assertFalse(Hash::check('foreign-token', $row->token));
        $this->assertSame(Carbon::now()->toDateTimeString(), $row->created_at);
    }

    public function test_null_created_at_is_taken_over(): void
    {
        // A NULL `created_at` cannot be proven fresh, so it must not make the
        // row unclaimable forever.
        $this->insertToken(null);

        $token = $this->service->issue(self::EMAIL);

        $this->assertNotNull($token);
        $row = $this->storedRow();
        $this->assertTrue(Hash::check($token, $row->token));
        $this->assertNotNull($row->created_at);
    }

    public function test_losing_takeover_returns_null_and_keeps_the_winning_token(): void
    {
        // Post-race state: a concurrent caller inserted a fresh row after this
        // call's `insertOrIgnore` and before its conditional `UPDATE`, so the
        // takeover matches 0 rows. The winner's token is what survives.
        $this->insertToken(Carbon::now(), 'winner-token');

        $this->assertNull($this->service->issue(self::EMAIL));
        $this->assertTrue(Hash::check('winner-token', $this->storedRow()->token));
    }
}
