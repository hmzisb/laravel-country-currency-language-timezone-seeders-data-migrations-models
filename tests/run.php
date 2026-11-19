<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Hmzisb\ReferenceData\Models\Country;
use Hmzisb\ReferenceData\Models\Currency;
use Hmzisb\ReferenceData\Models\Language;
use Hmzisb\ReferenceData\Models\Timezone;
use Hmzisb\ReferenceData\ReferenceDataServiceProvider;
use Hmzisb\ReferenceData\Seeders\ReferenceDataSeeder;
use Hmzisb\ReferenceData\Support\PostalCode;
use Hmzisb\ReferenceData\Support\ReferenceData;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

const TABLES = ['languages', 'currencies', 'timezones', 'countries'];
const MIGRATION = 'migrations/2026_11_19_000000_create_reference_data_tables.php';

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

/**
 * Compare JSON values by type and array order while allowing object keys in any order.
 */
function sameJsonValue(mixed $expected, mixed $actual): bool
{
    if ($expected instanceof stdClass && $actual instanceof stdClass) {
        $expected = get_object_vars($expected);
        $actual = get_object_vars($actual);
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
    }

    if (!is_array($expected) || !is_array($actual)) {
        return $expected === $actual;
    }

    if (array_keys($expected) !== array_keys($actual)) {
        return false;
    }

    foreach ($expected as $key => $value) {
        if (!sameJsonValue($value, $actual[$key])) {
            return false;
        }
    }

    return true;
}

/**
 * Require the operation to throw the expected exception with a matching message.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $type
 * @return T
 */
function expectFailure(string $type, string $messageFragment, Closure $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $type || !str_contains($error->getMessage(), $messageFragment)) {
            throw new RuntimeException("Expected {$type} containing '{$messageFragment}'.", 0, $error);
        }

        return $error;
    }

    throw new RuntimeException("Expected {$type} containing '{$messageFragment}'.");
}

/**
 * Capture the rows by table and stable code for comparisons across seed runs.
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function snapshot(Connection $connection): array
{
    $tables = [];

    foreach (TABLES as $table) {
        $tables[$table] = $connection->table($table)->orderBy('code')->get()
            ->keyBy('code')->map(static fn (object $row): array => (array) $row)->all();
    }

    return $tables;
}

/**
 * Run a check with a temporary change to a copied seed file.
 *
 * @param  Closure(string): (string|null)  $change  Return null to remove the file.
 * @param  Closure(): void  $test
 */
function fixture(string $path, Closure $change, Closure $test): void
{
    $original = file_get_contents($path);

    if ($original === false) {
        throw new RuntimeException("Could not read fixture {$path}.");
    }

    try {
        $changed = $change($original);

        if ($changed === null) {
            if (!unlink($path)) {
                throw new RuntimeException("Could not remove fixture {$path}.");
            }
        } elseif (file_put_contents($path, $changed) === false) {
            throw new RuntimeException("Could not write fixture {$path}.");
        }

        $test();
    } finally {
        if (file_put_contents($path, $original) === false) {
            throw new RuntimeException("Could not restore fixture {$path}.");
        }
    }
}

/**
 * Adapt an in-place row edit to a JSON fixture transformation.
 *
 * @param  Closure(list<stdClass> &$rows): void  $edit
 * @return Closure(string): string
 */
function editRows(Closure $edit): Closure
{
    return static function (string $json) use ($edit): string {
        $rows = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $edit($rows);

        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    };
}

/**
 * Boot the provider declared in Composer and verify both opt-in publish groups.
 */
function checkPublishTargets(Application $application, string $root): void
{
    $manifest = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $providers = $manifest['extra']['laravel']['providers'] ?? [];
    same([ReferenceDataServiceProvider::class], $providers, 'Composer must advertise the reference data provider.');

    $application->register($providers[0]);
    $application->boot();

    same(
        [$root.'/database/'.MIGRATION => $application->databasePath(MIGRATION)],
        ReferenceDataServiceProvider::pathsToPublish(ReferenceDataServiceProvider::class, 'reference-data-migrations'),
        'The migration publish tag must preserve the migration filename.',
    );
    same(
        [$root.'/database/data/reference' => $application->databasePath('data/reference')],
        ReferenceDataServiceProvider::pathsToPublish(ReferenceDataServiceProvider::class, 'reference-data-data'),
        'The data publish tag must copy the complete catalog directory.',
    );
}

/**
 * Verify catalog selection and prepare temporary copies for the database tests.
 */
function prepareCatalogFixtures(string $root, string $directory): void
{
    $loader = new ReferenceData();
    $bundle = $root.'/database/data/reference';
    $expected = $loader->load($bundle);
    same(true, sameJsonValue($expected, $loader->load()), 'A fresh Laravel app must load the complete bundled catalog.');

    if (!mkdir($directory, 0700)) {
        throw new RuntimeException("Could not create test fixtures at {$directory}.");
    }

    foreach (TABLES as $table) {
        if (!copy("{$bundle}/{$table}.json", "{$directory}/{$table}.json")) {
            throw new RuntimeException("Could not copy {$table} fixture.");
        }
    }

    fixture("{$directory}/timezones.json", static fn (): null => null, static function () use ($loader, $directory, $bundle, $expected): void {
        $missing = "{$directory}/timezones.json";
        expectFailure(RuntimeException::class, "missing or unreadable: {$missing}", $loader->load(...));
        expectFailure(RuntimeException::class, "missing or unreadable: {$missing}", static fn () => $loader->load($directory));
        same(true, sameJsonValue($expected, $loader->load($bundle)), 'An explicit catalog directory must take precedence over the published directory.');
    });
}

/**
 * Invalid catalog inputs must fail before any database writes are attempted.
 */
function checkValidation(Connection $connection, ReferenceDataSeeder $seeder, string $directory, array $baseline): void
{
    $reject = static function (string $file, Closure $change, string $type, string $message) use ($connection, $seeder, $directory, $baseline): void {
        fixture("{$directory}/{$file}.json", $change, static function () use ($connection, $seeder, $baseline, $type, $message): void {
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            try {
                expectFailure($type, $message, $seeder->run(...));
                $writes = array_filter($connection->getQueryLog(), static fn (array $entry): bool =>
                    preg_match('/^\s*(insert|update|delete|truncate)\b/i', $entry['query']) === 1);
                same([], $writes, 'Catalog validation attempted a write.');
            } finally {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }

            same($baseline, snapshot($connection), 'Invalid fixtures changed stored reference data.');
        });
    };

    $reject('timezones', static fn (): null => null, RuntimeException::class, 'missing or unreadable');
    $reject('countries', static fn (): string => '[{', InvalidArgumentException::class, 'Invalid JSON');
    $reject('languages', static fn (): string => '[]', InvalidArgumentException::class, 'nonempty JSON array');
    $reject('languages', static fn (): string => '[[]]', InvalidArgumentException::class, '[0] must be a JSON object');
    $reject('languages', editRows(static function (array &$rows): void {
        unset($rows[0]->details);
    }), InvalidArgumentException::class, 'must have exactly these fields');
    $reject('languages', editRows(static function (array &$rows): void {
        $rows[0]->unexpected = null;
    }), InvalidArgumentException::class, 'must have exactly these fields');
    $reject('languages', editRows(static function (array &$rows): void {
        $rows[] = $rows[0];
    }), InvalidArgumentException::class, '.code duplicates');
    $reject('currencies', editRows(static function (array &$rows): void {
        $rows[0]->code = 'usd';
    }), InvalidArgumentException::class, '.code has an invalid format');
    $reject('timezones', editRows(static function (array &$rows): void {
        $rows[0]->code = 'Etc/Not_A_Timezone';
    }), InvalidArgumentException::class, 'not an IANA time zone identifier supported by PHP');
    $reject('currencies', editRows(static function (array &$rows): void {
        $rows[0]->details = [];
    }), InvalidArgumentException::class, '.details must be a JSON object');
    $reject('currencies', editRows(static function (array &$rows): void {
        $rows[0]->details->nested = [(object) ['value' => "with\0nul"]];
    }), InvalidArgumentException::class, '.details contains a value that cannot be stored in PostgreSQL JSONB');
    $reject('currencies', editRows(static function (array &$rows): void {
        $rows[0]->details->nested = [(object) ["with\0nul" => 'value']];
    }), InvalidArgumentException::class, '.details contains a key with a null character');

    foreach (["\u{00A0}", "Name\u{0001}", str_repeat('é', 256)] as $name) {
        $reject('languages', editRows(static function (array &$rows) use ($name): void {
            $rows[0]->name = $name;
        }), InvalidArgumentException::class, '.name must be a nonempty string of at most 255 characters');
    }

    foreach (['ja_JP', 'ja--JP', 'a-JP', 'ja-abcdefghi'] as $locale) {
        $reject('countries', editRows(static function (array &$rows) use ($locale): void {
            $rows[0]->default_locale = $locale;
        }), InvalidArgumentException::class, '.default_locale must be a hyphen-separated locale tag or null');
    }

    foreach ([-1, 5, 1.5, '2'] as $minorUnit) {
        $reject('currencies', editRows(static function (array &$rows) use ($minorUnit): void {
            $rows[0]->minor_unit = $minorUnit;
        }), InvalidArgumentException::class, '.minor_unit must be null or an integer from 0 through 4');
    }

    foreach (['currency_codes' => 'JPY', 'language_codes' => 'ja', 'timezone_codes' => 'Asia/Tokyo'] as $field => $code) {
        foreach ([
            [(object) ['unexpected' => $code], "{$field} must be a JSON array"],
            [[1], "{$field}[0] must refer to a code"],
            [[[$code]], "{$field}[0] must refer to a code"],
            [[(object) ['code' => $code]], "{$field}[0] must refer to a code"],
            [['not-a-catalog-code'], "{$field}[0] must refer to a code"],
            [[$code, $code], "{$field}[1] duplicates"],
        ] as [$value, $message]) {
            $reject('countries', editRows(static function (array &$rows) use ($field, $value): void {
                $rows[0]->{$field} = $value;
            }), InvalidArgumentException::class, $message);
        }
    }
}

/**
 * Check model casts, mappings, and the IDs inserted before seeding.
 *
 * @param  list<int>  $knownIds  IDs for the language, currency, time zone, and country.
 */
function checkModels(array $knownIds): void
{
    $country = Country::forCode('JP')->firstOrFail();
    $currency = Currency::forCode('JPY')->firstOrFail();
    $language = Language::forCode('ja')->firstOrFail();
    $timezone = Timezone::forCode('Asia/Tokyo')->firstOrFail();

    same($knownIds, [$language->id, $currency->id, $timezone->id, $country->id], 'Seed changed established numeric IDs.');
    same(0, $currency->minor_unit, 'JPY minor_unit must cast to an integer.');
    same('050', Currency::forCode('BDT')->firstOrFail()->details->currency_numeric, 'BDT must retain its leading zero in details.currency_numeric.');
    foreach ([$country, $currency, $language] as $model) {
        same(true, $model->details instanceof stdClass, 'Details must cast to an object.');
    }

    foreach ([
        ['currency_codes', 'JPY', 'withCurrencyCode', 'currencyOptions'],
        ['language_codes', 'ja', 'withLanguageCode', 'languageOptions'],
        ['timezone_codes', 'Asia/Tokyo', 'withTimezoneCode', 'timezoneOptions'],
    ] as [$attribute, $code, $scope, $options]) {
        $codes = $country->{$attribute};
        same(true, is_array($codes) && array_is_list($codes), "{$attribute} must cast to a list.");
        same(true, in_array($code, $codes, true), "Japan must map to {$code}.");
        same(true, Country::forCode('JP')->{$scope}($code)->exists(), "{$scope} did not find Japan.");
        same(false, Country::forCode('JP')->{$scope}('not-a-catalog-code')->exists(), "{$scope} returned a false match.");

        $expected = $codes;
        sort($expected, SORT_STRING);
        $actual = $country->{$options}()->pluck('code')->all();
        sort($actual, SORT_STRING);
        same($expected, $actual, "{$options} did not resolve the stored codes.");
    }
}

function checkPostalCodes(): void
{
    foreach ([
        'BR' => ['01310-100', '01310100'],
        'JP' => ['100-0001', '1000001'],
        'GB' => ['sw1a 1aa', 'SW1A1AA'],
        'US' => [" \t12345-6789\n", '12345-6789'],
        'TC' => [" \ttkca   1zz\n", 'TKCA 1ZZ'],
    ] as $code => [$input, $normalized]) {
        $rules = Country::forCode($code)->firstOrFail()->details->postal_code;
        same('legacy_unverified', $rules->status, "Wrong postal rule status for {$code}.");
        same($normalized, PostalCode::normalize($input, $rules->normalization), "Wrong postal normalization for {$code}.");
        same(true, PostalCode::matchesLegacyPattern($input, $rules), "The postal pattern did not match the {$code} example.");
        same(false, PostalCode::matchesLegacyPattern('!', $rules), "The postal pattern matched an invalid {$code} example.");
    }

    $unavailable = Country::forCode('AE')->firstOrFail()->details->postal_code;
    same('unavailable', $unavailable->status, 'Expected an unavailable postal rule for AE.');
    same(null, PostalCode::matchesLegacyPattern('12345', $unavailable), 'A missing postal pattern must return null.');
    expectFailure(InvalidArgumentException::class, 'Unknown postal normalization strategy', static fn () => PostalCode::normalize('12345', 'unknown'));
    expectFailure(InvalidArgumentException::class, 'The legacy postal pattern is invalid', static fn () => PostalCode::matchesLegacyPattern('12345', (object) [
        'regex' => '[',
        'normalization' => 'compact',
        'status' => 'legacy_unverified',
    ]));
}

function checkConstraints(Connection $connection, array $baseline): void
{
    foreach ([
        'languages' => ['details' => '[]'],
        'currencies' => ['details' => '[]'],
        'countries' => ['details' => '[]', 'currency_codes' => '{}', 'language_codes' => '{}', 'timezone_codes' => '{}'],
    ] as $table => $invalidJson) {
        $code = array_key_first($baseline[$table]);

        foreach ($invalidJson as $column => $value) {
            $error = expectFailure(QueryException::class, '23514', static function () use ($connection, $table, $code, $column, $value): void {
                $connection->table($table)->where('code', $code)->update([$column => $value]);
            });
            same('23514', $error->errorInfo[0], "{$table}.{$column} did not enforce its JSON shape.");
        }
    }

    foreach (TABLES as $table) {
        $row = reset($baseline[$table]);
        unset($row['id']);
        $error = expectFailure(QueryException::class, '23505', static fn () => $connection->table($table)->insert($row));
        same('23505', $error->errorInfo[0], "{$table}.code was not unique.");
    }

    $error = expectFailure(QueryException::class, '23514', static function () use ($connection): void {
        $connection->table('currencies')->where('code', 'JPY')->update(['minor_unit' => 5]);
    });
    same('23514', $error->errorInfo[0], 'Currency minor units must be null or an integer from 0 through 4.');
}

function checkRollback(Connection $connection, ReferenceDataSeeder $seeder, string $directory, array $baseline): void
{
    // Reject Japan during the final catalog write after earlier catalogs have been upserted.
    $constraint = 'reject_japan_during_seed';
    $connection->statement("ALTER TABLE countries ADD CONSTRAINT {$constraint} CHECK (code <> 'JP') NOT VALID");

    try {
        fixture("{$directory}/languages.json", editRows(static function (array &$rows): void {
            $rows[0]->name = 'Rollback probe';
        }), static function () use ($connection, $constraint, $seeder): void {
            $connection->flushQueryLog();
            $connection->enableQueryLog();

            try {
                expectFailure(QueryException::class, $constraint, $seeder->run(...));
                $queries = array_column($connection->getQueryLog(), 'query');
                same(true, str_contains(implode("\n", $queries), 'insert into "languages"'), 'The probe never reached the earlier language upsert.');
            } finally {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        });
    } finally {
        $connection->statement("ALTER TABLE countries DROP CONSTRAINT {$constraint}");
    }

    same($baseline, snapshot($connection), 'A failed country write left earlier catalog writes committed.');
}

function checkReplacement(Connection $connection, ReferenceDataSeeder $seeder, array $baseline): void
{
    same(null, $baseline['countries']['JP']['default_locale'], 'Expected the bundled Japan default locale to be null.');
    $stale = ['default_locale' => 'en-US'];

    foreach (['currency_codes' => 'currencies', 'language_codes' => 'languages', 'timezone_codes' => 'timezones'] as $field => $table) {
        $codes = json_decode($baseline['countries']['JP'][$field], true, 512, JSON_THROW_ON_ERROR);
        $otherCodes = array_values(array_diff(array_keys($baseline[$table]), $codes));
        same(true, $otherCodes !== [], "Expected an unused {$table} code.");
        $codes[] = $otherCodes[0];
        $stale[$field] = json_encode($codes, JSON_THROW_ON_ERROR);
    }

    $connection->table('countries')->where('code', 'JP')->update($stale);
    $seeder->run();
    same($baseline, snapshot($connection), 'Reseeding did not restore the source country mappings and default locale.');
}

function checkCustomizedCountry(Connection $connection, ReferenceDataSeeder $seeder, string $directory, array $baseline): void
{
    $details = (object) [
        'numeric_strings' => (object) [
            'padded' => '00042',
            'large' => '9007199254740993',
            'decimal' => '1.00',
            'exponent' => '1e3',
        ],
        'mixed' => ['0', 0, 1.0, false, null, new stdClass(), []],
    ];

    fixture("{$directory}/countries.json", editRows(static function (array &$rows) use ($details): void {
        foreach ($rows as $row) {
            if ($row->code === 'JP') {
                $row->default_locale = 'ja-JP';
                $row->details = $details;

                return;
            }
        }

        throw new RuntimeException('Missing Japan in the country fixture.');
    }), static function () use ($connection, $seeder, $details): void {
        $seeder->run();
        $country = Country::forCode('JP')->firstOrFail();
        same(true, sameJsonValue($details, $country->details), 'Custom nested details must preserve numeric strings, scalar types, and JSON shapes.');
        same('ja-JP', $country->default_locale, 'Expected the customized source default locale.');

        $connection->table('countries')->where('code', 'JP')->update(['default_locale' => 'en-US']);
        $seeder->run();
        same('ja-JP', $country->refresh()->default_locale, 'Reseeding did not overwrite the manual default locale with the source value.');
    });

    $seeder->run();
    same($baseline, snapshot($connection), 'Reseeding the bundled catalog did not restore the original country values.');
}

/**
 * Exercise schema changes and repeated seeding against the isolated test schema.
 */
function runSuite(Connection $connection, string $root, string $directory): void
{
    $migration = require $root.'/database/'.MIGRATION;
    $seeder = new ReferenceDataSeeder();
    $schema = $connection->getSchemaBuilder();
    $connection->transaction($migration->up(...));

    foreach (TABLES as $table) {
        same(true, $schema->hasTable($table), "Migration did not create {$table}.");
    }

    $ids = [100001, 100002, 100003, 100004];
    $connection->table('languages')->insert(['id' => $ids[0], 'code' => 'ja', 'name' => 'Old Japanese']);
    $connection->table('currencies')->insert(['id' => $ids[1], 'code' => 'JPY', 'name' => 'Old Yen']);
    $connection->table('timezones')->insert(['id' => $ids[2], 'code' => 'Asia/Tokyo']);
    $connection->table('countries')->insert(['id' => $ids[3], 'code' => 'JP', 'name' => 'Old Japan']);
    $initial = Country::forCode('JP')->firstOrFail();
    same(true, $initial->details instanceof stdClass && (array) $initial->details === [], 'Default details must be an empty JSON object.');
    same([[], [], []], [$initial->currency_codes, $initial->language_codes, $initial->timezone_codes], 'Default code lists must be empty arrays.');

    $seeder->run();
    $baseline = snapshot($connection);
    $catalogs = (new ReferenceData())->load($directory);

    foreach (TABLES as $table) {
        same(count($catalogs[$table]), count($baseline[$table]), "Seed count mismatch for {$table}.");

        foreach ($catalogs[$table] as $source) {
            $stored = $baseline[$table][$source['code']] ?? null;
            same(true, $stored !== null, "Missing {$table} code {$source['code']}.");

            foreach ($source as $field => $value) {
                $actual = $value instanceof stdClass || is_array($value)
                    ? json_decode($stored[$field], false, 512, JSON_THROW_ON_ERROR)
                    : $stored[$field];

                same(true, sameJsonValue($value, $actual), "Wrong {$field} for {$table}.{$source['code']}.");
            }
        }
    }

    $seeder->run();
    same($baseline, snapshot($connection), 'Repeated seeding changed stored rows or IDs.');
    checkModels($ids);
    checkPostalCodes();
    echo "PASS migration up, full catalog seed, repeat seed, model helpers, and postal patterns\n";

    checkValidation($connection, $seeder, $directory, $baseline);
    checkConstraints($connection, $baseline);
    echo "PASS fixture validation, uniqueness, and JSONB shape constraints\n";

    checkRollback($connection, $seeder, $directory, $baseline);
    checkReplacement($connection, $seeder, $baseline);
    checkCustomizedCountry($connection, $seeder, $directory, $baseline);
    echo "PASS transactional rollback, country mapping and locale replacement, and typed JSONB round trip\n";

    $connection->transaction($migration->down(...));

    foreach (TABLES as $table) {
        same(false, $schema->hasTable($table), "Migration did not drop {$table}.");
    }

    $connection->transaction($migration->up(...));
    same(array_fill_keys(TABLES, []), snapshot($connection), 'Migration did not recreate empty reference tables.');
    echo "PASS migration down and reapply\n";
}

/**
 * Run the suite with a minimal Laravel application, temporary catalogs, and a unique PostgreSQL schema.
 */
function main(): void
{
    $database = getenv('REF_TEST_DATABASE');
    $username = getenv('REF_TEST_USER');

    if ($database === false || $database === '' || $username === false || $username === '') {
        throw new RuntimeException('Set REF_TEST_DATABASE and REF_TEST_USER to a disposable PostgreSQL database and user.');
    }

    $root = dirname(__DIR__);
    $autoloadOverride = getenv('REF_TEST_AUTOLOAD');
    $autoload = $autoloadOverride ?: $root.'/vendor/autoload.php';

    if (!is_file($autoload)) {
        throw new RuntimeException('Install test dependencies with composer install from the package root.');
    }

    require $autoload;

    if ($autoloadOverride) {
        // An external Composer installation supplies Laravel. Resolve the package classes from this checkout.
        $loader = new ClassLoader();
        $loader->addPsr4('Hmzisb\\ReferenceData\\', $root.'/src');
        $loader->register(true);
    }

    $suffix = bin2hex(random_bytes(8));
    $temporary = sys_get_temp_dir()."/reference-data-test-{$suffix}";
    $directory = "{$temporary}/database/data/reference";

    if (!mkdir(dirname($directory), 0700, true)) {
        throw new RuntimeException("Could not create test fixtures at {$directory}.");
    }

    try {
        $application = new Application($temporary);
        checkPublishTargets($application, $root);
        prepareCatalogFixtures($root, $directory);
        echo "PASS provider publishing, bundled catalog fallback, and published and explicit catalog selection\n";

        $capsule = new Manager($application);
        $schema = "reference_data_test_{$suffix}";
        $capsule->addConnection([
            'driver' => 'pgsql',
            'host' => getenv('REF_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('REF_TEST_PORT') ?: '5432',
            'database' => $database,
            'username' => $username,
            'password' => getenv('REF_TEST_PASSWORD') ?: '',
            'charset' => 'utf8',
            'search_path' => $schema,
        ]);
        $application->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication($application);
        $capsule->bootEloquent();
        $connection = $capsule->getConnection();
        same($database, $connection->selectOne('SELECT current_database() AS name')->name, 'Connected to an unexpected database.');
        $connection->statement("CREATE SCHEMA \"{$schema}\"");

        try {
            same($schema, $connection->selectOne('SELECT current_schema() AS name')->name, 'Test schema isolation check failed.');
            echo 'Laravel '.Application::VERSION.' / PostgreSQL '.$connection->selectOne('SHOW server_version')->server_version."\n";
            runSuite($connection, $root, $directory);
        } finally {
            $connection->statement("DROP SCHEMA \"{$schema}\" CASCADE");
        }
    } finally {
        foreach (TABLES as $table) {
            $path = "{$directory}/{$table}.json";

            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }

        rmdir(dirname($directory));
        rmdir(dirname($directory, 2));
        rmdir($temporary);
    }
}

try {
    main();
} catch (Throwable $error) {
    fwrite(STDERR, $error.PHP_EOL);
    exit(1);
}
