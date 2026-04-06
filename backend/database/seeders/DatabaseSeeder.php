<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GalleryGroup;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        
        // Admin-User seeden, um Race-Conditions in parallelen E2E-Tests zu vermeiden
        $adminUser = \App\Models\User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'florian@reisinger.pictures')],
            ['name' => 'Florian Reisinger', 'password' => \Illuminate\Support\Facades\Hash::make(env('ADMIN_PASSWORD', 'admin'))]
        );
        $roles = ['admin', 'photographer', 'client', 'customer_manager', 'power_user'];
        foreach ($roles as $roleName) {
            \App\Models\Role::firstOrCreate(['name' => $roleName]);
        }

        // Admin-User erhält alle verfügbaren Rollen
        $adminUser->roles()->sync(\App\Models\Role::pluck('id')->toArray());

        // 1. Root-Gruppe "Privat" (strikt privat)
        $privatGroup = GalleryGroup::create([
            'name' => 'Privat',
            'slug' => 'privat',
            'is_public' => false
        ]);

        // 2. Root-Gruppe "Presse" (öffentlich)
        $presseGroup = GalleryGroup::create([
            'name' => 'Presse',
            'slug' => 'presse',
            'is_public' => true
        ]);

        // 3. Untergruppen für Presse (Regionen)
        $oberoesterreich = GalleryGroup::create([
            'name' => 'Oberösterreich',
            'slug' => 'oberoesterreich',
            'parent_id' => $presseGroup->id,
            'is_public' => true
        ]);

        $oesterreich = GalleryGroup::create([
            'name' => 'Österreich',
            'slug' => 'oesterreich',
            'parent_id' => $presseGroup->id,
            'is_public' => true
        ]);

        $wien = GalleryGroup::create([
            'name' => 'Wien',
            'slug' => 'wien',
            'parent_id' => $presseGroup->id,
            'is_public' => true
        ]);

        // 4. "Sport" als Meta-Galerien (GalleryGroup) anlegen
        GalleryGroup::create([
            'name' => 'Sport',
            'slug' => 'sport-oberoesterreich',
            'parent_id' => $oberoesterreich->id,
            'is_public' => true
        ]);

        GalleryGroup::create([
            'name' => 'Sport',
            'slug' => 'sport-oesterreich',
            'parent_id' => $oesterreich->id,
            'is_public' => true
        ]);

        GalleryGroup::create([
            'name' => 'Sport',
            'slug' => 'sport-wien',
            'parent_id' => $wien->id,
            'is_public' => true
        ]);

        // Neu: Trigger den Location Import direkt im Seed
        $this->command->info('Starte Smart Assistance Import...');
        \Illuminate\Support\Facades\Artisan::call('app:import-locations', [], $this->command->getOutput());
    }
}
