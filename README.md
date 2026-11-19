# 🌍 Countries, currencies, languages & timezones for Laravel

**Ready-to-use data for your Laravel app.**

[![Tests](https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models/actions/workflows/tests.yml/badge.svg)](https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models/actions/workflows/tests.yml)

## 📦 What you get

| Data | Included |
| --- | --- |
| 🌍 **Countries** | 249 countries and territories, with names, codes and flags |
| 💱 **Currencies** | 178 currency-related codes, with names, symbols and details where available |
| 🗣️ **Languages** | 285 language codes, with names and native names where available |
| 🕒 **Timezones** | 419 timezone identifiers, such as `Asia/Karachi` |

**Requires Laravel 12 or 13 and PostgreSQL.**

**Choose a country. Get its currencies, languages and timezones.**

The package also includes:

- 🛠️ **Database setup** to create the tables.
- 🌱 **A seeder** to load the data into them.
- 🧩 **Laravel models** to read and search the data.

Once installed, loading and reading the data works offline. No external API is needed.

## 💡 What can you build?

- **Forms:** country, language, currency and timezone choices.
- **Account settings:** regional preferences and business profiles.
- **Directories and marketplaces:** country filters and regional options.

## 🚀 Get started

Open the sections you need.

<details>
<summary><strong>1. Install and load the data</strong></summary>

### Check your setup

| Laravel | Minimum PHP |
| --- | --- |
| 12 | 8.2 |
| 13 | 8.3 |

You also need:

- A PostgreSQL database using UTF-8.
- PHP extensions: `pdo_pgsql`, `mbstring` and `json`.
- PHP timezone data that recognizes the included identifiers. If `America/Coyhaique` is rejected, update the timezone data in the PHP runtime running Artisan.

### Install the package

Run these commands from your Laravel app folder:

```bash
composer config repositories.reference-data vcs https://github.com/hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models
composer require 'hmzisb/laravel-country-currency-language-timezone-seeders-data-migrations-models:^1.0'
```

### Create the tables and load the data

Laravel discovers the package automatically. You choose when to create its tables.

**Already have these tables?** Adapt or skip the supplied migration if your app has `countries`, `currencies`, `languages` or `timezones`. If you installed the earlier copy-in files, keep the migration already recorded in your app and avoid publishing a duplicate.

```bash
php artisan vendor:publish --tag=reference-data-migrations
php artisan migrate
php artisan db:seed --class='Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder'
```

To include it in your app's usual seeding, add this line to `DatabaseSeeder::run()`:

```php
$this->call(\Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder::class);
```

[Migration guide](database/migrations/README.md) · [Seeder guide](src/Seeders/README.md)

</details>

<details>
<summary><strong>2. Read and search the data</strong></summary>

### Find a country and its options

```php
use Hmzisb\ReferenceData\Models\Country;

$country = Country::forCode('PK')->firstOrFail();

$currencies = $country->currencyOptions()->get();
$languages = $country->languageOptions()->get();
$timezones = $country->timezoneOptions()->get();
```

### Read extra details

```php
$country->flag;                          // '🇵🇰'
$country->details->info->capital_names;  // ['Islamabad']
$country->details->secondary_locales;    // ['en-PK', 'ur-PK']
```

### Look up a currency, language or timezone

```php
use Hmzisb\ReferenceData\Models\Currency;
use Hmzisb\ReferenceData\Models\Language;
use Hmzisb\ReferenceData\Models\Timezone;

$rupee = Currency::forCode('PKR')->firstOrFail();
$urdu = Language::forCode('ur')->firstOrFail();
$karachi = Timezone::forCode('Asia/Karachi')->firstOrFail();

$rupee->details->symbol;    // '₨'
$urdu->details->name_local; // 'اردو'
```

### Filter countries

```php
$euroCountries = Country::withCurrencyCode('EUR')->orderBy('name')->get();
$englishCountries = Country::withLanguageCode('en')->get();
$karachiCountries = Country::withTimezoneCode('Asia/Karachi')->get();
```

Use codes such as `PK`, `PKR` and `ur` to identify records across projects. Numeric IDs can differ between databases. Codes must match the catalog's spelling and case.

The option methods return query builders. Finish them with `get()`, `pluck()` or another query operation. They are not Eloquent relationships, so `with()` and `load()` do not apply. When listing several countries, collect their unique codes and make one lookup query per catalog.

</details>

<details>
<summary><strong>3. See every available field</strong></summary>

### Database tables

| Table | Fields |
| --- | --- |
| `countries` | `id`, `code`, `name`, `default_locale`, `region`, `flag`, `details`, `currency_codes`, `language_codes`, `timezone_codes` |
| `currencies` | `id`, `code`, `name`, `minor_unit`, `details` |
| `languages` | `id`, `code`, `name`, `details` |
| `timezones` | `id`, `code` |

Each table has a generated bigint ID and a unique code. Country options are stored as PostgreSQL JSONB arrays and read as PHP arrays. The `details` fields are JSONB objects and read as PHP objects.

### Extra details

| Where | Fields |
| --- | --- |
| Country `details.info` | `name_local`, `native_names`, `capital_names`, `tlds`, `area_sq_km` |
| Country `details.geo` | `continent`, `neighbors` (land borders), `landlocked` |
| Country `details.postal_code` | `format`, `regex`, `normalization`, `status` |
| Country `details.secondary_locales` | Selected locale tags associated with the country |
| Currency `details` | `local_name`, `currency_numeric`, `symbol`, and `subunit` with `name` and `units_per_major` |
| Language `details` | `name_local`, `iso_639_1`, `iso_639_2`, `iso_639_3` |

Missing values remain null or empty. Currency numeric codes are strings, so leading zeroes such as `"050"` are preserved.

</details>

<details>
<summary><strong>4. Customize or update the data</strong></summary>

The seeder uses the data included with the package by default.

To keep an editable copy in your app:

```bash
php artisan vendor:publish --tag=reference-data-data
```

This copies all four files to `database/data/reference/`.

- **Keep the four files together.** If this folder exists, the seeder uses it instead of the package's files.
- **Review package updates.** Your published copy continues to take priority after a Composer update. Merge any new data you need.
- **Use `--force` carefully.** Adding it to the publish command replaces your copy. Review and back up local edits first.

Run the seed command again to apply your chosen data.

The seeder checks all four files before writing. It updates them in one transaction, so a failed write rolls back the whole change. Existing codes keep their IDs; metadata and country options are replaced. `default_locale` is replaced too, including with null. Whole rows missing from a new snapshot stay in the database until you retire them deliberately.

[Read the seeder guide](src/Seeders/README.md)

</details>

<details>
<summary><strong>5. Understand the coverage and limits</strong></summary>

**Data snapshot: 19 November 2026.**

| Dataset | Coverage |
| --- | --- |
| Countries | 249 ISO 3166-1 alpha-2 countries and territories |
| Currencies | 178 ISO 4217 List One codes, including funds, metals and special codes |
| Languages | All 184 ISO 639-1 codes, plus 101 three-letter codes used in country mappings |
| Timezones | 419 IANA identifiers from PHP timezone data 2026.3, including `UTC` |

A few distinctions matter when using the data:

- **Languages and locales:** choose your app's supported translations and fallback separately. Supplied `default_locale` values are null. Custom locale tags receive a basic format check, not full BCP 47 validation.
- **Money:** `minor_unit` describes accounting decimal places. Cash rounding and payment-provider rules can differ. Your app decides which currencies it accepts.
- **Timezones:** use the timezone identifier and date with PHP or Carbon to calculate the correct offset.
- **Postal codes:** supplied patterns are unverified legacy reference data. Use current postal-service data to validate delivery addresses. The optional [postal helper](src/Support/PostalCode.php) only checks a supplied pattern.

[Data sources and coverage](DATA-SOURCES.md) · [Missing values and corrections](data-quality.json)

</details>

<details>
<summary><strong>6. Run checks or rebuild the data</strong></summary>

From a checkout of this repository:

```bash
php tools/validate.php
```

This checks the bundled data without Laravel or a database.

[Run the tests](TESTING.md) · [Rebuild from the included sources](DATA-SOURCES.md#rebuilding-the-same-snapshot) · [Read the release review](REVIEW.md)

</details>

## 📄 License

**Code: MIT.** You may use, modify and sell it while keeping the required license notice.

**Data: separate source licenses apply**, including ODbL 1.0 for country data and Unicode License V3 for CLDR data.

Keep the relevant license terms and attribution when redistributing data.

[Code license](LICENSE) · [Data licenses and attribution](DATA-SOURCES.md#sources-and-terms)
