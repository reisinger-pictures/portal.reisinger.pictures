<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTokenEmailIdentityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v057_refuses_case_folded_duplicates_without_changing_tokens(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The collision fixture requires PostgreSQL case-sensitive primary-key semantics.');
        }

        DB::statement('DROP INDEX password_reset_tokens_email_lower_unique');
        DB::table('password_reset_tokens')->insert([
            ['email' => 'First.Token@Example.test', 'token' => 'first-hash', 'created_at' => now()],
            ['email' => 'first.token@example.test', 'token' => 'second-hash', 'created_at' => now()],
        ]);

        try {
            $this->migration()->up();
            $this->fail('V057 accepted reset-token rows that collide after case folding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 case-folded groups across 2 rows', $exception->getMessage());
            $this->assertStringNotContainsString('@example.test', $exception->getMessage());
        }

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'First.Token@Example.test',
            'token' => 'first-hash',
        ]);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'first.token@example.test',
            'token' => 'second-hash',
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/V057__password_reset_token_case_insensitive_identity.php');
    }
}
