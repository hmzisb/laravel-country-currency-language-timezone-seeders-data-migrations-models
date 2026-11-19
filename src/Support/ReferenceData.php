<?php

namespace Hmzisb\ReferenceData\Support;

use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

final class ReferenceData
{
    /**
     * Load and validate all four catalogs and their cross-references.
     *
     * @param  string|null  $directory  An explicit directory containing all four JSON files.
     * @return array{languages: list<array<string, mixed>>, currencies: list<array<string, mixed>>, timezones: list<array<string, mixed>>, countries: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException If any catalog or cross-reference is invalid.
     * @throws \RuntimeException If any catalog file is missing or unreadable.
     */
    public function load(?string $directory = null): array
    {
        $directory ??= $this->defaultDirectory();

        $languages = $this->readCatalog(
            $directory,
            'languages',
            ['code', 'name', 'details'],
            '/\A[a-z]{2,3}\z/',
            $this->validateNamedRow(...),
        );

        $currencies = $this->readCatalog(
            $directory,
            'currencies',
            ['code', 'name', 'minor_unit', 'details'],
            '/\A[A-Z]{3}\z/',
            function (array $row, string $location): void {
                $this->validateNamedRow($row, $location);

                $minorUnit = $row['minor_unit'];

                if ($minorUnit !== null && (!is_int($minorUnit) || $minorUnit < 0 || $minorUnit > 4)) {
                    throw new InvalidArgumentException("{$location}.minor_unit must be null or an integer from 0 through 4.");
                }
            },
        );

        $ianaIdentifiers = array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        $timezones = $this->readCatalog(
            $directory,
            'timezones',
            ['code'],
            '/\A[A-Za-z0-9_+\/-]{1,255}\z/',
            function (array $row, string $location) use ($ianaIdentifiers): void {
                if (!isset($ianaIdentifiers[$row['code']])) {
                    throw new InvalidArgumentException("{$location}.code is not an IANA time zone identifier supported by PHP.");
                }
            },
        );

        $catalogCodes = [
            'currency_codes' => array_fill_keys(array_column($currencies, 'code'), true),
            'language_codes' => array_fill_keys(array_column($languages, 'code'), true),
            'timezone_codes' => array_fill_keys(array_column($timezones, 'code'), true),
        ];

        $countries = $this->readCatalog(
            $directory,
            'countries',
            ['code', 'name', 'default_locale', 'region', 'flag', 'details', 'currency_codes', 'language_codes', 'timezone_codes'],
            '/\A[A-Z]{2}\z/',
            function (array $row, string $location) use ($catalogCodes): void {
                $this->validateNamedRow($row, $location);

                $locale = $row['default_locale'];

                if ($locale !== null) {
                    $this->validateText($locale, "{$location}.default_locale");

                    // Check only the locale tag shape expected by the application.
                    if (!preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*\z/', $locale)) {
                        throw new InvalidArgumentException("{$location}.default_locale must be a hyphen-separated locale tag or null.");
                    }
                }

                if ($row['region'] !== null) {
                    $this->validateText($row['region'], "{$location}.region");
                }

                if ($row['flag'] !== null) {
                    $this->validateText($row['flag'], "{$location}.flag", 16);
                }

                foreach ($catalogCodes as $field => $allowedCodes) {
                    $this->validateReferences($row[$field], $allowedCodes, "{$location}.{$field}");
                }
            },
        );

        return compact('languages', 'currencies', 'timezones', 'countries');
    }

    /**
     * Prefer an application override as a complete snapshot so its catalogs stay in sync.
     */
    private function defaultDirectory(): string
    {
        if (function_exists('database_path')) {
            $directory = database_path('data/reference');

            if (is_dir($directory)) {
                return $directory;
            }
        }

        return dirname(__DIR__, 2).'/database/data/reference';
    }

    /**
     * Require an exact field set and a unique, well-formed code in each row.
     *
     * @param  list<string>  $fields
     * @param  callable(array<string, mixed>, string): void  $validate
     * @return list<array<string, mixed>>
     */
    private function readCatalog(
        string $directory,
        string $catalog,
        array $fields,
        string $codePattern,
        callable $validate,
    ): array {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$catalog.'.json';

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Reference data file is missing or unreadable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read reference data file: {$path}");
        }

        try {
            // Keep JSON objects distinct from lists, including when either is empty.
            $decoded = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Invalid JSON in {$path}: {$exception->getMessage()}", 0, $exception);
        }

        if (!is_array($decoded) || $decoded === []) {
            throw new InvalidArgumentException("{$path} must contain a nonempty JSON array.");
        }

        $rows = [];
        $seen = [];

        foreach ($decoded as $index => $value) {
            $location = "{$path}[{$index}]";

            if (!$value instanceof stdClass) {
                throw new InvalidArgumentException("{$location} must be a JSON object.");
            }

            $row = get_object_vars($value);
            $keys = array_keys($row);

            if (array_diff($fields, $keys) || array_diff($keys, $fields)) {
                throw new InvalidArgumentException("{$location} must have exactly these fields: ".implode(', ', $fields));
            }

            $code = $row['code'];

            if (!is_string($code) || preg_match($codePattern, $code) !== 1) {
                throw new InvalidArgumentException("{$location}.code has an invalid format.");
            }

            if (isset($seen[$code])) {
                throw new InvalidArgumentException("{$location}.code duplicates {$code}.");
            }

            $validate($row, $location);
            $seen[$code] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Check the shared name and object-valued details fields.
     *
     * @param  array<string, mixed>  $row
     */
    private function validateNamedRow(array $row, string $location): void
    {
        $this->validateText($row['name'], "{$location}.name");

        if (!$row['details'] instanceof stdClass) {
            throw new InvalidArgumentException("{$location}.details must be a JSON object.");
        }

        $this->validateDetailsValues($row['details'], "{$location}.details");
    }

    /**
     * Enforce nonblank text and the database column limit in UTF-8 characters.
     */
    private function validateText(mixed $value, string $location, int $maxLength = 255): void
    {
        if (!is_string($value)
            || preg_match('/\A[\p{Z}\s]*\z/u', $value) === 1
            || preg_match('/\p{Cc}/u', $value) === 1
            || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException("{$location} must be a nonempty string of at most {$maxLength} characters.");
        }
    }

    /**
     * Check nested metadata for values that PostgreSQL JSONB cannot store.
     */
    private function validateDetailsValues(mixed $value, string $location): void
    {
        if ((is_string($value) && str_contains($value, "\0")) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException("{$location} contains a value that cannot be stored in PostgreSQL JSONB.");
        }

        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $child) {
                if (is_string($key) && str_contains($key, "\0")) {
                    throw new InvalidArgumentException("{$location} contains a key with a null character.");
                }

                $this->validateDetailsValues($child, $location);
            }
        }
    }

    /**
     * Require a JSON list of distinct codes present in the referenced catalog.
     *
     * @param  array<string, true>  $allowedCodes
     */
    private function validateReferences(mixed $codes, array $allowedCodes, string $location): void
    {
        if (!is_array($codes) || !array_is_list($codes)) {
            throw new InvalidArgumentException("{$location} must be a JSON array.");
        }

        $seen = [];

        foreach ($codes as $index => $code) {
            if (!is_string($code) || !isset($allowedCodes[$code])) {
                throw new InvalidArgumentException("{$location}[{$index}] must refer to a code in its lookup catalog.");
            }

            if (isset($seen[$code])) {
                throw new InvalidArgumentException("{$location}[{$index}] duplicates {$code}.");
            }

            $seen[$code] = true;
        }
    }
}
