<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Support\FtpSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-M21: `ftp_slug` is the FTP/SFTP account name (7.2 in
 * features/infrastructure/19-ftp-upload-pipeline.md), so the endpoint a
 * photographer uses to change it has to hold the account-name format.
 *
 * The use case these tests describe: a photographer changes the login their
 * camera uses, gets an error for a value the account system cannot carry, and
 * gets the login stored for a value it can.
 */
class FtpSlugValidationTest extends TestCase
{
    use RefreshDatabase;

    private function photographer(string $email = 'max@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedValues(): array
    {
        return [
            'dash' => ['j-doe'],
            'underscore' => ['j_doe'],
            'trailing digit' => ['jdoe1'],
            'plain' => ['jdoe'],
            'minimum length' => ['abc'],
            'maximum length' => [str_repeat('a', FtpSlug::MAX_LENGTH)],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_photographer_login_is_stored_for_a_compliant_value(string $value): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => $value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => $value,
        ]);
    }

    public function test_cosmetic_input_is_normalized_before_it_is_stored(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'Max NeÚ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'max-neu',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedValues(): array
    {
        return [
            'dot' => ['a.b'],
            'at sign' => ['a@b'],
            'slash' => ['a/b'],
            'path traversal' => ['../etc'],
            'leading underscore' => ['_jdoe'],
            'leading dash' => ['-jdoe'],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', FtpSlug::MAX_LENGTH + 1)],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_photographer_gets_an_error_for_a_value_the_account_system_cannot_carry(string $value): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => $value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug')
            ->assertJsonFragment(['ftp_slug' => [FtpSlug::message()]]);

        // The rejected value must not be persisted, and the previous login must
        // still be the account name.
        $this->assertDatabaseMissing('users', ['id' => $photographer->id, 'ftp_slug' => $value]);
        $this->assertNotSame($value, $photographer->fresh()->ftp_slug);
    }

    public function test_uniqueness_still_guards_the_login(): void
    {
        $other = $this->photographer('florian@example.com');
        $other->forceFill(['ftp_slug' => 'florian'])->save();

        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'florian',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug');

        $this->assertDatabaseMissing('users', ['id' => $photographer->id, 'ftp_slug' => 'florian']);
    }

    public function test_keeping_your_own_login_is_allowed(): void
    {
        $photographer = $this->photographer();
        $photographer->forceFill(['ftp_slug' => 'max'])->save();
        $token = auth('api')->login($photographer->fresh());

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'max',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $photographer->id, 'ftp_slug' => 'max']);
    }

    /**
     * The documented decision: existing, non-conforming values are not migrated.
     * A photographer whose legacy login does not satisfy the rule gets the error
     * until they pick a compliant one — the stored value is never rewritten
     * behind their back, because the slug is a foreign key into the inbox
     * directory.
     */
    public function test_legacy_non_conforming_login_is_reported_not_silently_renamed(): void
    {
        $photographer = $this->photographer();
        $photographer->forceFill(['ftp_slug' => 'j.doe'])->save();
        $token = auth('api')->login($photographer->fresh());

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j.doe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug');

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'j.doe',
        ]);
    }

    public function test_profile_update_without_a_slug_stays_valid(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', ['name' => 'Max Mustermann'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['id' => $photographer->id, 'name' => 'Max Mustermann']);
    }
}
