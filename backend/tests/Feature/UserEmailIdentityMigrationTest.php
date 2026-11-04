<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class UserEmailIdentityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v053_normalizes_existing_user_emails(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        DB::table('users')->where('id', $user->id)->update(['email' => 'Legacy@Example.COM']);

        $this->migration()->up();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'legacy@example.com',
        ]);
    }

    public function test_v053_refuses_case_folded_duplicates_without_changing_either_address(): void
    {
        $first = User::factory()->create(['email' => 'first-identity@example.com']);
        $second = User::factory()->create(['email' => 'second-identity@example.com']);

        DB::statement('DROP INDEX users_email_lower_unique');
        DB::table('users')->where('id', $first->id)->update(['email' => 'Duplicate@Example.COM']);
        DB::table('users')->where('id', $second->id)->update(['email' => 'duplicate@example.com']);

        try {
            $this->migration()->up();
            $this->fail('V053 accepted emails that collide after case folding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate@example.com', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'id' => $first->id,
            'email' => 'Duplicate@Example.COM',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $second->id,
            'email' => 'duplicate@example.com',
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/V053__normalize_user_email_identity.php');
    }
}
