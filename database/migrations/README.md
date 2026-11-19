# Reference data migration

The [migration](2026_11_19_000000_create_reference_data_tables.php) creates four tables on PostgreSQL. Each table has an auto-generated bigint primary key called `id` and a unique `code`. Use codes to identify records across environments, where the numeric IDs may differ.

| Table | Code | Other columns |
| --- | --- | --- |
| `countries` | Uppercase ISO alpha-2, such as `PK` | `name`, `default_locale`, `region`, `flag`, `details`, `currency_codes`, `language_codes`, `timezone_codes` |
| `languages` | Lowercase two- or three-letter identifier, such as `ur` | `name`, `details` |
| `currencies` | Uppercase three-letter ISO code, such as `PKR` | `name`, `minor_unit`, `details` |
| `timezones` | Case-sensitive IANA identifier, such as `Asia/Karachi` | None |

The schema allows null for `default_locale`, `region`, `flag`, and `minor_unit`. When present, `minor_unit` must be an integer from 0 through 4. The tables have no timestamps or soft-delete columns.

The `details` columns use JSONB and default to an empty object (`{}`). The three country code columns also use JSONB and default to empty arrays (`[]`). PostgreSQL checks that these columns contain the specified JSON shapes. Each country array stores codes from its corresponding lookup table. PostgreSQL foreign keys cannot check individual elements in these arrays. The [seeder](../../src/Seeders/README.md) checks each element against the loaded catalog before writing. Your app's tables can still use ordinary foreign keys to reference a country, language, currency, or timezone row.

The migration creates unique indexes for code lookups. It creates no indexes on the JSONB arrays.

## Apply or roll back

Publish the migration to your app's `database/migrations/` directory, then apply it on the intended PostgreSQL connection.

```bash
php artisan vendor:publish --tag=reference-data-migrations
php artisan migrate
```

If any of the tables already exist, review them first. Adapt or omit this create-table migration and write a migration for the changes your schema needs. If you already applied the migration from a copy of the original files, keep the applied migration in the app and avoid publishing a duplicate.

Rolling back this migration drops all four reference tables and their contents. Foreign keys from app tables may block those drops.
