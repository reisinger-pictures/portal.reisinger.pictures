<?php

namespace App\Console\Commands;

use App\Models\Org;
use App\Services\OrgDefaultRolePolicy;
use Illuminate\Console\Command;

class CheckOrgDefaultRoles extends Command
{
    protected $signature = 'ops:check-org-default-roles';

    protected $description = 'Report organizations with unsupported persisted default roles.';

    public function handle(OrgDefaultRolePolicy $policy): int
    {
        $groups = [];

        Org::query()
            ->whereNotNull('default_role_id')
            ->with('defaultRole')
            ->get(['id', 'default_role_id'])
            ->each(function (Org $org) use ($policy, &$groups): void {
                if ($policy->hasSupportedPersistedDefault($org)) {
                    return;
                }

                $info = $policy->persistedDefaultInfo($org);
                if ($info === null) {
                    return;
                }

                $key = $info['id'].'|'.($info['name'] ?? '');
                $groups[$key] ??= [
                    'role_id' => $info['id'],
                    'role_name' => $info['name'] ?? '(missing role row)',
                    'organization_ids' => [],
                ];
                $groups[$key]['organization_ids'][] = (string) $org->getKey();
            });

        if ($groups === []) {
            $this->info('No organizations have an unsupported default role.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                $group['role_id'],
                $group['role_name'],
                (string) count($group['organization_ids']),
                implode(', ', $group['organization_ids']),
            ];
        }

        $this->error('Unsupported organization default roles found:');
        $this->table(['Role ID', 'Role name', 'Organization count', 'Organization IDs'], $rows);

        return self::FAILURE;
    }
}
