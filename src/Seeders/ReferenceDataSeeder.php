<?php

namespace Hmzisb\ReferenceData\Seeders;

use Hmzisb\ReferenceData\Support\ReferenceData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferenceDataSeeder extends Seeder
{
    private const BATCH_SIZE = 500;

    /**
     * Fields to update when a catalog code already exists.
     */
    private const TABLES = [
        'languages' => ['name', 'details'],
        'currencies' => ['name', 'minor_unit', 'details'],
        'timezones' => ['code'],
        'countries' => ['name', 'default_locale', 'region', 'flag', 'details', 'currency_codes', 'language_codes', 'timezone_codes'],
    ];

    private const JSON_COLUMNS = ['details', 'currency_codes', 'language_codes', 'timezone_codes'];

    /**
     * Validate and atomically upsert the resolved reference snapshot by code.
     *
     * @throws \RuntimeException If the connection is unsupported or a catalog cannot be read.
     * @throws \InvalidArgumentException If any catalog or cross-reference is invalid.
     */
    public function run(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The reference data seeder requires PostgreSQL.');
        }

        // Resolve and serialize the complete snapshot before entering the write transaction.
        $catalogs = (new ReferenceData())->load();
        $prepared = [];

        foreach (self::TABLES as $table => $updates) {
            $columns = array_fill_keys(['code', ...$updates], true);
            $jsonColumns = array_intersect(self::JSON_COLUMNS, $updates);

            foreach ($catalogs[$table] as $row) {
                $row = array_intersect_key($row, $columns);

                foreach ($jsonColumns as $column) {
                    $row[$column] = json_encode($row[$column], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                }

                $prepared[$table][] = $row;
            }
        }

        $connection->transaction(function () use ($connection, $prepared): void {
            foreach (self::TABLES as $table => $updates) {
                foreach (array_chunk($prepared[$table], self::BATCH_SIZE) as $rows) {
                    $connection->table($table)->upsert($rows, ['code'], $updates);
                }
            }
        }, 3);
    }
}
