# Release review

The Composer package contains the PostgreSQL migration, seeder, Eloquent models, JSON catalogs, source snapshots, and reproducible data builder. Laravel's discovery mechanism registers the service provider. The app opts in to both migration and data publishing.

## Package and data changes

- The package uses PSR-4 autoloading under `Hmzisb\ReferenceData`. App-owned data can be published and edited locally. Once published, that directory takes precedence over the installed catalogs.
- The offline builder checks all input hashes against `tools/source-lock.json` before writing output. The lock also pins the snapshot date and the PHP timezone identifier registry.
- The audit at `data-quality.json` records source differences, missing metadata, and corrections. It records the BQ flag fallback, the removal of Sri Lanka's incorrect land border, and conflicts in supplemental language identifiers.
- Native names for Cook Islands Māori, Fiji Hindi, and Malay are preserved through verified identifier mappings. Conflicting supplemental identifiers are excluded from native-name matching.
- Postal rules carry an explicit status and normalization strategy. The legacy pattern helper preserves separators for US and TC formats.

## Verification scope

The catalog counts are 249 countries and territories, 285 language codes, 178 currency and related codes, and 419 timezone identifiers.

Local verification passed with Composer-installed Laravel 13.43.0, PHP 8.5.11, and PostgreSQL 18.6. Eight offline builder tests also passed. The current GitHub Actions run records the version-matrix results.

The [test suite](TESTING.md) covers migration and rollback, repeated seeding, code uniqueness, JSONB constraints, exact metadata values, transaction rollback, and the data publishing paths. The offline builder tests compare generated files byte for byte and check that invalid inputs leave existing outputs intact.

## Integration limits

- The Composer package supports Laravel 12 and 13. See [Testing](TESTING.md) for the verification commands.
- The seeder validates references inside arrays. PostgreSQL checks the JSONB container types. App changes outside the seeder need to preserve those references.
- Repeat seeding replaces `default_locale`, including with null. Codes absent from a later snapshot stay in the database until explicitly retired.
- Preserve exact large integers and decimals in custom metadata by storing them as JSON strings.
- Locale validation checks a basic hyphenated tag shape. Applications that need full BCP 47 validation should apply it where they accept locale input.
- Postal metadata is historical, with coverage and sourcing documented in [Data sources](DATA-SOURCES.md#legacy-postal-patterns).
- The original code and documentation use MIT. The datasets retain the terms and attribution in [Data sources](DATA-SOURCES.md#sources-and-terms).
