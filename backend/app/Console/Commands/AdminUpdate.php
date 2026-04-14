<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUpdate extends Command
{
    protected $signature = 'admin:update';
    protected $description = 'Updates or creates the admin user based on .env credentials';

    public function handle()
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            $this->error('ADMIN_EMAIL or ADMIN_PASSWORD not set in .env');
            return 1;
        }

        $admin = User::firstOrNew(['email' => $email]);
        $admin->name = 'Admin';
        if (!$admin->exists || empty($admin->password)) {
            $admin->password = Hash::make($password);
        }
        $admin->save();

        // Weise grundlegende Rollen zu
        $roles = Role::whereIn('name', ['admin', 'photographer', 'client'])->pluck('id');
        $admin->roles()->syncWithoutDetaching($roles);

        $this->info('Admin user updated successfully.');
        return 0;
    }
}
