# Laravel country, currency, language, and timezone seeders

[![Tests](https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models/actions/workflows/tests.yml/badge.svg)](https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models/actions/workflows/tests.yml)

Add country, currency, language, and IANA timezone data to a Laravel app that uses PostgreSQL. The Composer package ships four JSON catalogs, a migration, a repeatable database seeder, and four Eloquent models.

Use these catalogs for country and timezone pickers, onboarding forms, localization settings, or currency display. The package puts country-to-catalog mappings in PostgreSQL JSONB arrays, so you can look up the options for a country from four tables. Seeding uses local files and needs no API or network access.

## Why it was built

Many Laravel projects need the same country lists, currency codes, language names, and timezone choices. This package keeps that reference data in one versioned source, with reusable migrations and Eloquent models, so each project does not have to maintain its own copies or depend on a live API.

It can support:

- Country, language, currency, and timezone dropdowns in registration and account settings.
- SaaS onboarding, organization profiles, marketplaces, directories, and geographic filters.
- Regional defaults and localization tools that need ISO identifiers and native names.
- Currency display and country-specific options, with payment acceptance handled by the application.
- Offline imports, test fixtures, and reference-data APIs built on your own Laravel models.

## Included data

| Catalog | Rows | Coverage |
| --- | ---: | --- |
| Countries | 249 | ISO 3166-1 alpha-2 countries and territories |
| Currencies | 178 | ISO 4217 List One codes, including funds, metals, and special codes |
| Languages | 285 | All 184 ISO 639-1 codes, plus 101 three-letter codes used in country mappings |
| Timezones | 419 | IANA identifiers from PHP timezone data 2026.3, including `UTC` |

The snapshot is dated 2026-11-19. The [source documentation](DATA-SOURCES.md) describes coverage, exclusions, and attribution. The [data quality report](data-quality.json) records missing values and source-specific corrections.

## Requirements

- Laravel 12 with PHP 8.2 or later, or Laravel 13 with PHP 8.3 or later. The package uses PHP 8 with the `pdo_pgsql`, `mbstring`, and `json` extensions.
- PostgreSQL with a UTF-8 database. The migration and seeder use PostgreSQL JSONB.
- PHP timezone data that recognizes the bundled identifiers. If validation rejects an identifier such as `America/Coyhaique`, check the timezone data in the PHP runtime running Artisan.

See [Testing](TESTING.md) for the verified stack and test commands.

## Install

Run these commands from your Laravel app root.

```bash
composer config repositories.reference-data vcs https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models
composer require 'hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models:^1.0'
```

Laravel registers the service provider through package discovery. The migration is opt-in. Publish it to your app, apply it, then seed the catalogs.

```bash
php artisan vendor:publish --tag=reference-data-migrations
php artisan migrate
php artisan db:seed --class='Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder'
```

**If any of the four tables already exist**, review your schema before running `migrate`. Adapt or omit the supplied create-table migration and use your own migration for the necessary schema changes. If you previously installed the files by copying them into your app, retain the migration already recorded in your app's migration history and avoid publishing a duplicate. See the [migration notes](database/migrations/README.md) for the schema and rollback behavior.

To seed the catalogs in your normal application seeding flow, add this line to the app's existing `DatabaseSeeder::run()` method.

```php
$this->call(\Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder::class);
```

## Query the data

```php
use Hmzisb\ReferenceData\Models\Country;
use Hmzisb\ReferenceData\Models\Currency;
use Hmzisb\ReferenceData\Models\Language;
use Hmzisb\ReferenceData\Models\Timezone;

$pakistan = Country::forCode('PK')->firstOrFail();
$rupee = Currency::forCode('PKR')->firstOrFail();
$urdu = Language::forCode('ur')->firstOrFail();
$karachi = Timezone::forCode('Asia/Karachi')->firstOrFail();

$currencies = $pakistan->currencyOptions()->orderBy('name')->get();
$languages = $pakistan->languageOptions()->orderBy('name')->get();
$timezones = $pakistan->timezoneOptions()->orderBy('code')->get();

$capitalNames = $pakistan->details->info->capital_names; // ['Islamabad']
$localeTags = $pakistan->details->secondary_locales; // ['en-PK', 'ur-PK']
$urduName = $urdu->details->name_local; // 'اردو'
$currencySymbol = $rupee->details->symbol; // '₨'

$euroCountries = Country::withCurrencyCode('EUR')->orderBy('name')->get();
$englishCountries = Country::withLanguageCode('en')->get();
$karachiCountries = Country::withTimezoneCode('Asia/Karachi')->get();
```

Use the catalog codes as stable lookups across installations. Numeric IDs are assigned by each database. Match the spelling and case in the catalog. The country option methods return query builders, so call them as methods and finish with `get()`, `pluck()`, or another query operation. They are not Eloquent relationships and cannot be loaded with `with()` or `load()`. For a list of countries, collect the unique codes and retrieve their matching rows in one query per catalog.

## Data structure

| Table | Columns |
| --- | --- |
| `countries` | `id`, `code`, `name`, `default_locale`, `region`, `flag`, `details`, `currency_codes`, `language_codes`, `timezone_codes` |
| `currencies` | `id`, `code`, `name`, `minor_unit`, `details` |
| `languages` | `id`, `code`, `name`, `details` |
| `timezones` | `id`, `code` |

Each table has a generated bigint primary key and a unique `code`. Country option lists are JSONB arrays of codes and read as PHP arrays. Each `details` column is a JSONB object and reads as a PHP object.

| JSONB metadata | Fields |
| --- | --- |
| Country `details.info` | `name_local`, `native_names`, `capital_names`, `tlds`, `area_sq_km` |
| Country `details.geo` | `continent`, `neighbors` (land borders), `landlocked` |
| Country `details.postal_code` | `format`, `regex`, `normalization`, `status` |
| Country `details.secondary_locales` | Array of selected locale tags associated with the country |
| Currency `details` | `local_name`, `currency_numeric`, `symbol`, and `subunit` with `name` and `units_per_major` |
| Language `details` | `name_local`, `iso_639_1`, `iso_639_2`, `iso_639_3` |

Missing metadata appears as null or an empty value. The three-digit ISO 4217 numeric code is a string, which preserves leading zeroes such as `"050"` for BDT.

### Localization, money, and timezones

- Set your application's supported locales and fallbacks in its own configuration. The supplied `default_locale` values are null. The seeder checks the basic shape of custom locale tags. Full BCP 47 syntax and registry validation are outside that check.
- `minor_unit` describes the ISO 4217 accounting exponent, when applicable. Physical subunits, cash rounding, and payment-provider precision can differ. Configure accepted currencies and rounding in your application.
- Pass the timezone identifier and relevant date to PHP or Carbon to get the offset and daylight-saving rules for that instant.
- Postal formats and regexes are legacy reference metadata. The optional [postal code helper](src/Support/PostalCode.php) can match a supplied pattern. Use current postal-service data for delivery validation.

## Customize or update the catalogs

By default, the seeder uses the bundled catalogs. To edit the data in your app, publish all four files to `database/data/reference/`.

```bash
php artisan vendor:publish --tag=reference-data-data
```

If that directory exists, the seeder uses it in full. Keep all four files together. Published files continue to override the bundled files after a Composer update, so review and merge upstream catalog changes into any app-owned copy. Use `vendor:publish --tag=reference-data-data --force` only when you intend to replace the published files after reviewing and backing up local edits.

Run the same seed command to apply the chosen snapshot. The seeder validates all four files before writing and upserts all rows in one transaction. Matching codes keep their IDs and receive the current metadata and country arrays. Rows whose codes are absent from the new files remain in the database. See the [seeder notes](src/Seeders/README.md) for validation and reseeding details.

## Development and licenses

From a checkout of this repository, run `php tools/validate.php` to validate the bundled files without Laravel or PostgreSQL. See [Testing](TESTING.md) for PostgreSQL integration and offline build tests, and [Data sources](DATA-SOURCES.md#rebuilding-the-same-snapshot) to reproduce the snapshot.

The original package code and documentation use the [MIT license](LICENSE), which permits use, modification, redistribution, and commercial use, including sale, subject to the license notice requirement. Third-party data retains its source terms, including ODbL 1.0 for mledoze-derived country data and Unicode License V3 for CLDR data. Keep the relevant terms and attribution with the data you redistribute. See [Sources and terms](DATA-SOURCES.md#sources-and-terms) and [third-party notices](licenses/).
