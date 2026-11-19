# Reference data seeder

Apply the [reference data migration](../../database/migrations/README.md), then run this command from your Laravel app root.

```bash
php artisan db:seed --class='Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder'
```

[ReferenceDataSeeder](ReferenceDataSeeder.php) reads the four JSON files in your app's `database/data/reference/` directory if it exists. Otherwise, it reads the files bundled with the installed package. The selected directory must contain all four files.

| File | Rows it supplies |
| --- | --- |
| `languages.json` | Language codes, names, and details |
| `currencies.json` | Currency codes, names, minor units, and details |
| `timezones.json` | IANA timezone identifiers |
| `countries.json` | Country records and their currency, language, and timezone code arrays |

Run `php artisan vendor:publish --tag=reference-data-data` to create an app-owned copy. A published directory remains active after a package upgrade. Review changes and merge them into any customized copy before reseeding. Adding `--force` to the publish command replaces the existing published files with those in your installed package. Review and back up edits before doing so.

The loader validates all four files before writing. It checks required and unexpected fields, duplicate codes, code formats, names, JSON shapes, minor units, and IANA identifiers supported by PHP. Every country option must be a unique code in the corresponding loaded catalog. Locale validation checks a basic hyphenated tag shape. Your app is responsible for supported locales and any BCP 47 registry validation it needs.

After validation and JSON encoding, the seeder upserts all four catalogs in batches within one PostgreSQL transaction. A database failure rolls back the whole run.

## Reseeding

- A new code creates a row. A matching code keeps its ID and replaces the fields supplied by the seeder, including `details` and the country code arrays.
- A row whose code is absent from a new file remains in the database. Review application references before retiring an old code.
- Reseeding replaces `default_locale`, including with null. Configure your app's locale fallback independently or maintain a deliberate value in the JSON files.
- PostgreSQL can consume sequence values when an attempted insert becomes an update, so gaps in IDs are expected.

PostgreSQL checks JSONB container types, and the seeder checks the references inside country arrays. Make catalog changes through the source files and seeder so both checks apply.

## Validate without writing

From this repository's root, run `php tools/validate.php` to check the bundled files without Laravel or PostgreSQL. To check a different set of files, pass the directory containing all four.

```bash
php tools/validate.php /absolute/path/to/reviewed-json-files
```

In Laravel Tinker, run `(new \Hmzisb\ReferenceData\Support\ReferenceData())->load()` to validate the catalogs the seeder would use. Encode values that need exact digits as JSON strings, including currency numeric codes with leading zeroes and decimals that require full precision. See [Data sources and coverage](../../DATA-SOURCES.md) for the snapshot and rebuild instructions.
