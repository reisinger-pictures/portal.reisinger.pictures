<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgDefaultRoleLegacyGuardTest extends TestCase
{
    use RefreshDatabase;

    private function role(UserRole $role): Role
    {
        return Role::firstOrCreate(['name' => $role->value]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['brand' => 'rp']);
        $admin->roles()->attach($this->role(UserRole::ADMIN));

        return $admin;
    }

    private function org(Role $defaultRole): Org
    {
        return Org::factory()->create([
            'brand' => 'rp',
            'default_role_id' => $defaultRole->id,
            'invoice_frequency' => 'immediate',
        ]);
    }

    /**
     * @param  callable(): void  $callback
     * @return list<string>
     */
    private function collectWriteStatements(callable $callback): array
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $callback();

        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $writes;
    }

    public function test_sync_blocks_an_invalid_photographer_default_without_writes_then_proceeds_after_repair(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);
        $org = $this->org($photographer);
        $existingMember = User::factory()->create(['brand' => 'rp', 'org_id' => $org->id]);
        $existingMember->roles()->attach([$client->id, $photographer->id]);
        $incomingMember = User::factory()->create(['brand' => 'rp']);
        $incomingMember->roles()->attach($client->id);
        $headers = ['Authorization' => 'Bearer '.auth('api')->login($admin)];

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id.'/users', [
                'user_ids' => [$existingMember->id, $incomingMember->id],
            ])
            ->assertOk();

        $this->assertSame($org->id, $incomingMember->fresh()->org_id);
        $this->assertSame(['client'], $incomingMember->fresh()->roles()->orderBy('name')->pluck('name')->all());
        $this->assertSame(
            ['client', 'photographer'],
            $existingMember->fresh()->roles()->orderBy('name')->pluck('name')->all(),
        );

        $writes = $this->collectWriteStatements(function () use ($headers, $org): void {
            $this->withHeaders($headers)
                ->putJson('/api/management/orgs/'.$org->id.'/users', [
                    'user_ids' => [],
                ])
                ->assertStatus(409)
                ->assertJsonPath('error', 'Die gespeicherte Standardrolle ist ungültig. Bitte repariere sie, bevor du Nutzer zuweist.');
        });

        $this->assertSame([], $writes);
        $this->assertSame($org->id, $existingMember->fresh()->org_id);
        $this->assertSame($org->id, $incomingMember->fresh()->org_id);
        $this->assertTrue($existingMember->fresh()->roles->contains('id', $photographer->id));
        $this->assertTrue($existingMember->fresh()->roles->contains('id', $client->id));

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $client->id,
            ])
            ->assertOk();

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id.'/users', [
                'user_ids' => [$incomingMember->id],
            ])
            ->assertOk();

        $this->assertNull($existingMember->fresh()->org_id);
        $this->assertSame($org->id, $incomingMember->fresh()->org_id);
        $this->assertFalse($existingMember->fresh()->roles->contains('id', $client->id));
        $this->assertTrue(
            $existingMember->fresh()->roles->contains('id', $photographer->id),
            'Repairing the default must not revoke an existing photographer role outside the FTP lifecycle.',
        );
    }

    public function test_destroy_blocks_an_invalid_photographer_default_without_writes_then_proceeds_after_repair(): void
    {
        $admin = $this->admin();
        $client = $this->role(UserRole::CLIENT);
        $photographer = $this->role(UserRole::PHOTOGRAPHER);
        $org = $this->org($photographer);
        $member = User::factory()->create(['brand' => 'rp', 'org_id' => $org->id]);
        $member->roles()->attach([$client->id, $photographer->id]);
        $headers = ['Authorization' => 'Bearer '.auth('api')->login($admin)];

        $writes = $this->collectWriteStatements(function () use ($headers, $org): void {
            $this->withHeaders($headers)
                ->deleteJson('/api/management/orgs/'.$org->id)
                ->assertStatus(409)
                ->assertJsonPath('error', 'Die gespeicherte Standardrolle ist ungültig. Bitte repariere sie, bevor du die Organisation löschst.');
        });

        $this->assertSame([], $writes);
        $this->assertModelExists($org);
        $this->assertSame($org->id, $member->fresh()->org_id);
        $this->assertTrue($member->fresh()->roles->contains('id', $photographer->id));
        $this->assertTrue($member->fresh()->roles->contains('id', $client->id));

        $this->withHeaders($headers)
            ->putJson('/api/management/orgs/'.$org->id, [
                'name' => $org->name,
                'invoice_frequency' => 'immediate',
                'default_role_id' => $client->id,
            ])
            ->assertOk();

        $this->withHeaders($headers)
            ->deleteJson('/api/management/orgs/'.$org->id)
            ->assertOk();

        $this->assertModelMissing($org);
        $this->assertNull($member->fresh()->org_id);
        $this->assertFalse($member->fresh()->roles->contains('id', $client->id));
        $this->assertTrue(
            $member->fresh()->roles->contains('id', $photographer->id),
            'Organization deletion must not revoke an existing photographer role outside the FTP lifecycle.',
        );
    }
}
