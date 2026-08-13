<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2ETestUserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [];
        foreach (UserRole::cases() as $case) {
            $roles[$case->value] = Role::firstOrCreate(['name' => $case->value])->id;
        }

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Florian Reisinger',
                'password' => Hash::make('admin'),
                'brand' => null,
            ]
        );

        $adminUser->roles()->sync(array_values($roles));

        $this->command->info('E2E admin user created: admin@example.com / admin');
    }
}
