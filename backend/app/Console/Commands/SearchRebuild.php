<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SearchRebuild extends Command
{
    protected $signature = 'app:search-rebuild';
    protected $description = 'Flushes all searchable models, syncs index settings, and re-imports data into Meilisearch';

    public function handle()
    {
        $models = [
            \App\Models\Photo::class,
            \App\Models\Gallery::class,
            \App\Models\Location::class,
            \App\Models\Customer::class,
            \App\Models\TextSnippet::class,
        ];

        $this->info('Flushing existing indexes...');
        foreach ($models as $model) {
            $this->line("  Flushing {$model}...");
            Artisan::call('scout:flush', ['model' => $model]);
            $this->line('    ' . Artisan::output());
        }

        $this->info('Syncing index settings from config/scout.php...');
        Artisan::call('scout:sync-index-settings');
        $this->line('  ' . Artisan::output());

        $this->info('Importing all models into fresh indexes...');
        foreach ($models as $model) {
            $this->line("  Importing {$model}...");
            Artisan::call('scout:import', ['model' => $model]);
            $this->line('    ' . Artisan::output());
        }

        $this->info('Restarting queue workers to pick up fresh model definitions...');
        Artisan::call('queue:restart');

        $this->newLine();
        $this->info('Search rebuild complete.');
    }
}
