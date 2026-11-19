# Testing

Run these checks from a checkout of the package repository. Install its development dependencies with Composer. Laravel 12 requires PHP 8.2 or later. Laravel 13 requires PHP 8.3 or later.

```bash
composer install
composer validate --strict
composer validate-data
```

The standalone validator checks the four bundled JSON files without booting Laravel or connecting to PostgreSQL. You can also run `php tools/validate.php /path/to/catalogs` against a directory containing all four files.

## PostgreSQL integration tests

Use a disposable UTF-8 PostgreSQL database. The user needs permission to connect and create a schema. If your local connection accepts your system user, the following creates the test database and runs the suite.

```bash
createdb -h 127.0.0.1 -U "$(id -un)" reference_data_test
REF_TEST_DATABASE=reference_data_test REF_TEST_USER="$(id -un)" composer test
```

| Environment variable | Value |
| --- | --- |
| `REF_TEST_DATABASE` | Required name of the disposable database |
| `REF_TEST_USER` | Required PostgreSQL user |
| `REF_TEST_HOST` | Hostname or Unix socket directory. Defaults to `127.0.0.1` |
| `REF_TEST_PORT` | PostgreSQL port. Defaults to `5432` |
| `REF_TEST_PASSWORD` | Password, if required by the database |
| `REF_TEST_AUTOLOAD` | Optional absolute path to a compatible Laravel installation's Composer autoloader |

The runner uses a unique schema and drops it on exit. It uses temporary copies of the JSON catalogs for tests that modify the data. The suite checks the following.

- Publishing paths, bundled data fallback, and failure when a selected catalog directory is missing a required file.
- Migration apply, rollback, and reapply. Full catalog seeding and repeat seeding with stable IDs.
- Eloquent model queries, JSONB shape constraints, and preservation of JSON types and exact strings such as `"050"` for BDT.
- Rejection of invalid snapshots before database writes. Rollback across all catalogs when a write fails.
- Replacement of country options and `default_locale`. Postal pattern normalization and matching.

The integration harness exercises the package and PostgreSQL directly. Test your own migrations and service providers in your app as part of integration.

## Offline builder tests

The builder tests require Python 3.9 or later and Node.js. They use the pinned files in `tools/sources/` and run without network access.

```bash
composer build-tests
```

The eight tests verify byte-for-byte reproduction of the four JSON catalogs and audit. They also check that bad checksums, missing sources, invalid dates, and invalid inputs are rejected before replacing any output. Other cases cover language-source conflicts, native names, and country flag and border corrections.

See [Data sources](DATA-SOURCES.md#rebuilding-the-same-snapshot) to rebuild or review the generated files.

## Verified locally

The Composer package passed the PostgreSQL integration suite with Laravel 13.43.0, PHP 8.5.11, and PostgreSQL 18.6. It also passed with the earlier Laravel 13.40.0 dependency set. This includes provider publishing and catalog selection as well as the database checks above.

The GitHub Actions workflow runs separate PostgreSQL jobs for Laravel 12 / PHP 8.2 and Laravel 13 / PHP 8.3, plus an offline builder job. See the repository's Actions page for results against each committed revision. Composer security checks remain enabled.
