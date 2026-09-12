<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserFtpSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_ftp_slug_is_generated_from_the_email_local_part(): void
    {
        $user = User::factory()->create(['email' => 'Max.Mustermann@example.com']);

        $this->assertSame('maxmustermann', $user->ftp_slug);
    }

    public function test_ftp_slug_gets_a_counter_suffix_when_the_local_part_is_taken(): void
    {
        User::factory()->create(['email' => 'race@example.com']);
        $user = User::factory()->create(['email' => 'race@other.com']);

        $this->assertSame('race1', $user->ftp_slug);
    }

    public function test_auto_generated_slug_retries_on_a_unique_violation(): void
    {
        // Boot the model first so the built-in slug generator is registered
        // before the collision listener (listeners run in registration order).
        new User();

        $injected = false;

        // Simulate a concurrent insert landing between the exists() check and
        // the INSERT: the first attempt must lose the race, the retry win it.
        User::creating(function (User $user) use (&$injected) {
            if ($injected) {
                return;
            }
            $injected = true;

            DB::table('users')->insert([
                'id' => (string) Str::uuid(),
                'email' => 'collision@example.com',
                'name' => 'Collision',
                'password' => null,
                'ftp_slug' => $user->ftp_slug,
                'created_at' => now(),
            ]);
        });

        $user = User::create([
            'name' => 'Race',
            'email' => 'race@example.com',
            'password' => 'secret',
        ]);

        $this->assertSame('race1', $user->ftp_slug);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'ftp_slug' => 'race1']);
    }

    public function test_user_provided_slug_collision_is_not_silently_rewritten(): void
    {
        User::factory()->create(['ftp_slug' => 'taken']);

        $user = User::factory()->make(['ftp_slug' => 'taken']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $user->save();
    }
}
