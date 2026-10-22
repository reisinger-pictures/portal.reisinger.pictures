<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Org;
use App\Models\Role;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;

class OrgDefaultRolePolicy
{
    /** @var list<string> */
    public const ALLOWED_ROLE_NAMES = [
        UserRole::CLIENT->value,
        UserRole::POWER_USER->value,
        UserRole::ORG_ADMIN->value,
    ];

    /**
     * The request rule for an optional organization default role.
     *
     * @return array<int, mixed>
     */
    public function defaultRoleIdValidationRules(): array
    {
        return [
            'sometimes',
            'nullable',
            Rule::exists('roles', 'id')->where(
                fn (Builder $query) => $query->whereIn('name', self::ALLOWED_ROLE_NAMES),
            ),
        ];
    }

    /**
     * Resolve the role to give a newly assigned organization member.
     *
     * Unsupported legacy values fail closed to CLIENT and are logged without
     * including any user identity or other personal data.
     */
    public function roleForNewAssignment(Org $org): Role
    {
        if ($org->default_role_id === null) {
            return $this->clientRole();
        }

        $role = $this->persistedRole($org);
        if ($role !== null && $this->isAllowedRoleName($role->name)) {
            return $role;
        }

        Log::warning('Unsupported organization default role; using CLIENT for a new assignment.', [
            'org_id' => (string) $org->getKey(),
            'default_role_id' => (string) $org->default_role_id,
            'default_role_name' => $role?->name,
        ]);

        return $this->clientRole();
    }

    /**
     * A null default is supported (and means CLIENT). A non-null persisted
     * default is supported only when its role row names an allowed role.
     */
    public function hasSupportedPersistedDefault(Org $org): bool
    {
        if ($org->default_role_id === null) {
            return true;
        }

        $role = $this->persistedRole($org);

        return $role !== null && $this->isAllowedRoleName($role->name);
    }

    /**
     * Return the persisted role data used by the repair UI.
     *
     * @return array{id: string, name: string|null, allowed: bool}|null
     */
    public function persistedDefaultInfo(Org $org): ?array
    {
        if ($org->default_role_id === null) {
            return null;
        }

        $role = $this->persistedRole($org);

        return [
            'id' => (string) $org->default_role_id,
            'name' => $role?->name,
            'allowed' => $role !== null && $this->isAllowedRoleName($role->name),
        ];
    }

    public function isAllowedRoleName(?string $name): bool
    {
        return $name !== null && in_array($name, self::ALLOWED_ROLE_NAMES, true);
    }

    private function clientRole(): Role
    {
        $clientRole = Role::query()->where('name', UserRole::CLIENT->value)->first();

        if ($clientRole === null) {
            throw new RuntimeException('Critical: Default CLIENT role missing in database.');
        }

        return $clientRole;
    }

    private function persistedRole(Org $org): ?Role
    {
        if ($org->relationLoaded('defaultRole')) {
            $role = $org->getRelation('defaultRole');

            return $role instanceof Role ? $role : null;
        }

        return Role::query()->whereKey($org->default_role_id)->first();
    }
}
