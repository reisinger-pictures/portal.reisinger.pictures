<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CheckOrgDefaultRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_succeeds_when_every_default_is_null_or_allowlisted(): void
    {
        $client = Role::firstOrCreate(['name' => UserRole::CLIENT->value]);
        Org::factory()->create(['default_role_id' => null]);
        Org::factory()->create(['default_role_id' => $client->id]);

        $this->artisan('ops:check-org-default-roles')
            ->expectsOutputToContain('No organizations have an unsupported default role.')
            ->assertExitCode(0);
    }

    public function test_it_reports_invalid_role_ids_names_and_organization_counts_without_user_pii(): void
    {
        $photographer = Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]);
        $unknown = Role::firstOrCreate(['name' => 'legacy_unknown_role']);
        $firstOrg = Org::factory()->create([
            'name' => 'Private organization name',
            'default_role_id' => $photographer->id,
        ]);
        $secondOrg = Org::factory()->create([
            'default_role_id' => $photographer->id,
        ]);
        Org::factory()->create(['default_role_id' => $unknown->id]);
        User::factory()->create(['email' => 'private.person@example.test']);

        $exitCode = Artisan::call('ops:check-org-default-roles');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString($photographer->id, $output);
        $this->assertStringContainsString(UserRole::PHOTOGRAPHER->value, $output);
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString($firstOrg->id, $output);
        $this->assertStringContainsString($secondOrg->id, $output);
        $this->assertStringContainsString($unknown->id, $output);
        $this->assertStringContainsString('legacy_unknown_role', $output);
        $this->assertStringNotContainsString('Private organization name', $output);
        $this->assertStringNotContainsString('private.person@example.test', $output);
    }
}
