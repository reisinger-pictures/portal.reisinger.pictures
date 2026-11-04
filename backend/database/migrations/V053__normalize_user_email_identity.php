<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POSTGRES_EMAIL_INDEX = 'users_email_lower_unique';

    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) AS normalized_email, COUNT(*) AS email_count')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $addresses = $duplicates
                ->pluck('normalized_email')
                ->map(static fn (mixed $email): string => (string) $email)
                ->implode(', ');

            throw new RuntimeException(
                'V053 cannot normalize users.email because case-insensitive duplicates exist: '.$addresses
            );
        }

        DB::table('users')->update(['email' => DB::raw('LOWER(email)')]);

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::POSTGRES_EMAIL_INDEX.' ON users (LOWER(email))');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::POSTGRES_EMAIL_INDEX.' ON users (LOWER(email))');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)
            && ! Schema::hasIndex('users', 'users_email_unique')) {
            Schema::table('users', function ($table): void {
                $table->unique('email', 'users_email_unique');
            });
        }
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }
};
