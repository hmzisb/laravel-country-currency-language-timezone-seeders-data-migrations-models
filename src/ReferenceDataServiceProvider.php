<?php

namespace Hmzisb\ReferenceData;

use Illuminate\Support\ServiceProvider;

class ReferenceDataServiceProvider extends ServiceProvider
{
    /**
     * Register publishable files. Applications opt in to the schema migration
     * because they may already own tables with these names.
     */
    public function boot(): void
    {
        $database = dirname(__DIR__).'/database';
        $migration = '2026_11_19_000000_create_reference_data_tables.php';

        $this->publishes([
            $database.'/migrations/'.$migration => $this->app->databasePath('migrations/'.$migration),
        ], 'reference-data-migrations');

        $this->publishes([
            $database.'/data/reference' => $this->app->databasePath('data/reference'),
        ], 'reference-data-data');
    }
}
