<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\UserRole;

/** Issue #11: the `accounting` role grants EAR access; `super_admin` has it implicitly. */
class EurAccountingRoleTest extends EurBookkeepingTestCase
{
    public function test_accounting_role_can_use_the_bookkeeping_api(): void
    {
        $token = $this->tokenFor(UserRole::ACCOUNTING->value);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference')
            ->assertOk();
    }

    public function test_accounting_role_cannot_use_other_management_areas(): void
    {
        $token = $this->tokenFor(UserRole::ACCOUNTING->value);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/roles')
            ->assertForbidden();
    }

    public function test_accounting_role_cannot_change_ear_settings(): void
    {
        // Settings updates stay super_admin-only; accounting is read/write on
        // bookkeeping data, not on the configuration.
        $token = $this->tokenFor(UserRole::ACCOUNTING->value);

        $this->withHeaders($this->bearer($token))
            ->putJson('/api/management/eur/settings', [])
            ->assertForbidden();
    }

    public function test_super_admin_has_accounting_capability_implicitly(): void
    {
        $token = $this->tokenFor(UserRole::SUPER_ADMIN->value);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/reference')
            ->assertOk();
    }
}
