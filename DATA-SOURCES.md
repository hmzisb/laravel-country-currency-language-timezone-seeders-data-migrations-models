# Data sources and coverage

Snapshot dated 2026-11-19. The four JSON catalogs are ready to seed offline. The original input snapshots are in `tools/sources/`. The lock file at `tools/source-lock.json` records the snapshot date, input checksums, and timezone version. [data-quality.json](data-quality.json) records source checksums, catalog counts, decisions about the supplied files, and missing values by code.

| Catalog | Rows | Selection |
| --- | ---: | --- |
| Countries | 249 | All officially assigned ISO 3166-1 alpha-2 countries and territories. The mledoze code set was checked against the IANA `iso3166.tab` table. |
| Languages | 285 | All 184 ISO 639-1 codes from the source snapshot, plus 101 three-letter codes used in country associations. Three are ISO 639-2 language collections. |
| Currencies | 178 | Unique current ISO 4217 codes in the SIX List One publication of 2026-09-17. This includes currencies, funds, metals and special codes that can exist without any country association. |
| Timezones | 419 | PHP `DateTimeZone::listIdentifiers()` from timezone data 2026.3, including `UTC`. |

## Country metadata

- The common English name, region, flag, native names, capitals, top-level domains, area, landlocked status, and borders come from mledoze Countries. `region` is its subregion. `details.geo.neighbors` converts source alpha-3 borders to ISO alpha-2 and includes only codes in the country catalog.
- `details.info.native_names` keeps source language-to-name mappings. The optional singular `name_local` uses a consistent value from the supplied locale file or a single shared native name when available. `details.geo.continent` uses the supplied geography label.
- `details.postal_code` keeps consistent formats and regex strings from the supplied locale file. It also includes a normalization rule and a status. These are legacy patterns, not verified current postal rules. See the postal section below.
- The supplied, consistent BQ flag fills a missing upstream flag. The builder checks that it is the regional-indicator emoji for BQ. This fallback is recorded in the audit.
- `neighbors` means land-border countries. The upstream LK-to-IN entry is removed because the [Sri Lankan Consulate General](https://www.cg-mumbai.gov.lk/en/sri-lanka) confirms that Sri Lanka has no land boundaries. The audit records the original asymmetry, correction, and remaining asymmetries. Country codes outside this catalog are recorded before they are excluded.
- The supplied locale file covers all 249 ISO countries and also includes `XK`, which is outside this ISO-assigned catalog. Six legacy-only country codes were skipped. Thirteen ISO countries absent from the legacy seeder are present in the snapshot.
- `default_locale` is null throughout. Configure your application's fallback independently. `details.secondary_locales` contains supplied tags with the country as the region subtag and a base language in that country's association list. The audit reports excluded tags, including the supplied `pa-AW` and `pa-CW`, whose base tag denotes Punjabi. Other exclusions reflect the limits of the selected language associations or their code granularity.

## Country language options

Country options combine languages declared by mledoze Countries and CLDR 49 territory-language records marked `official`, `official_regional`, or `de_facto_official`. CLDR script variants are reduced to their base language. The source flags have different scopes. The combined reference list covers affiliations recorded by those sources, with legal status and additional spoken languages outside its scope.

The catalog prefers an ISO 639-1 code where available and uses the ISO 639-3 code otherwise. `ber`, `khi`, and `smi` are ISO 639-2 collections used by the country source. Their ISO 639-3 fields are null. Language display names and ISO code mappings are from the selected language sources. Native names use consistent values supplied in the locale file, matched through the ISO 639-1, 639-2, and 639-3 crosswalk. This preserves the supplied `Māori` name for `rar`. The supplied locale file puts Fiji Hindi (`hif`) in its ISO 639-2 field; the builder corrects that mapping only when the code and English name agree with the ISO 639-3 source. This correction is audited; the output ISO 639-2 value remains null. Conflicting identifiers in a supplied language record are excluded from native-name matching and recorded in the audit, rather than mixing individual-language names into a macrolanguage.

New Zealand Sign Language comes from the country source, consistent with section 6 of the New Zealand Sign Language Act 2006. South African Sign Language (`sfs`) is added under the Constitution Eighteenth Amendment Act, 2023. [New Zealand legislation](https://www.legislation.govt.nz/act/public/2006/0018/latest/DLM372789.html) · [South African amendment](https://www.justice.gov.za/constitution/docs/2023-003-Constitution18thAmendment-gg49041-GoN3730-27Jul2023.pdf)

## Country currency options

Country arrays use CLDR 49 territory records with tender status and date windows covering the snapshot date. All selected codes resolve to the SIX current list. The audit shows differences from the legacy currency assignments, including EUR for Bulgaria and USD plus ZWG for Zimbabwe. Curaçao uses XCG. Local instruments without a current ISO 4217 catalog code are outside this dataset.

SIX supplies the ISO display name, three-digit numeric code, and accounting minor-unit exponent. Numeric codes remain strings to preserve leading zeroes. `minor_unit` is null for the SIX entries where the exponent is inapplicable. Currency display symbols use a consistent mledoze value where available, then a consistent supplied value. `local_name` uses a consistent supplied value. A named physical subunit and its ratio are kept together when supplied consistently for that ISO code. This ratio can differ from the accounting exponent. MGA has exponent 2 and five iraimbilanja per ariary. For zero-exponent currencies, the physical subunit fields are null. Payment-provider precision and cash rounding depend on the application and provider.

## Country timezone options

Country rows use local-place identifiers from IANA `zone.tab` where available. A country missing from that table can use the IANA `zone1970.tab` shared-clock table, parsed across all listed countries on each row. The local-place IDs retain country-specific names for selections, including `Europe/Amsterdam` and `America/Curacao`. Some of these IDs are IANA links. `zone1970.tab` groups zones where clocks have agreed since 1970 and may name a city in another country.

Every included identifier was checked against PHP. Bouvet Island (`BV`) and Heard Island and McDonald Islands (`HM`) have no country mapping in the selected IANA files and have empty timezone arrays. There are no stored fixed UTC offsets. For date-specific offsets and transitions, use PHP's timezone rules with the selected identifier. The bundled data includes `America/Coyhaique`. PHP installations with older timezone databases may need an update before they can validate or seed the files.

## Sources and terms

| Input | Snapshot and use | Terms |
| --- | --- | --- |
| [mledoze Countries](https://github.com/mledoze/countries/blob/master/countries.json) | `countries.json` retrieved on the snapshot date. Country metadata, declared languages, and currency display symbols. | [ODbL 1.0](licenses/mledoze-countries-ODbL-1.0.txt). This package's adapted country database and source-derived country data are distributed under the ODbL. |
| [Unicode CLDR currency data](https://github.com/unicode-org/cldr-json/blob/main/cldr-json/cldr-core/supplemental/currencyData.json) and [territory data](https://github.com/unicode-org/cldr-json/blob/main/cldr-json/cldr-core/supplemental/territoryInfo.json) | CLDR 49, Unicode 16.0.0 as declared in the JSON payloads. Current currency periods and certain country language associations. | [Unicode License V3](licenses/Unicode-License-V3.txt). |
| [SIX ISO 4217 List One](https://www.six-group.com/en/products-services/financial-information/market-reference-data/data-standards.html) | Published 2026-09-17. Currency names, alphabetic and numeric codes, and accounting minor units extracted from the [public XML download](https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml). | The raw list is available from the ISO 4217 Maintenance Agency. |
| [wooorm ISO 639-3](https://github.com/wooorm/iso-639-3/blob/main/iso6393.js) and [Library of Congress ISO 639-2 codes](https://www.loc.gov/standards/iso639-2/php/code_list.php) | ISO 639 names and crosswalk, with three ISO 639-2 collections from the country source. | [MIT wrapper license](licenses/wooorm-iso-639-3-MIT.txt) and [ISO/SIL language-data attribution](licenses/iso-language-data-NOTICE.md). |
| [IANA tzdb country tables](https://data.iana.org/time-zones/tzdb/) and [PHP identifier registry](https://www.php.net/manual/en/datetimezone.listidentifiers.php) | IANA `zone.tab` and `zone1970.tab` retrieved on the snapshot date. Local `iso3166.tab` from IANA tzdb 2026f. PHP timezone data 2026.3 enumerates the lookup catalog. | [IANA public-domain source notices](licenses/iana-tzdb-public-domain.txt). |
| Supplied `CountriesTableSeeder.php` and `locales.json` | Legacy country coverage comparison and supplemental locale, native-name, postal and currency display values. | User-supplied inputs. |

## Legacy postal patterns

Postal metadata records a display `format`, a match `regex`, an input `normalization` rule, and the rule's `status`. A `#` in a format means a digit, `@` means a letter, and `|` separates alternatives. Literal letters remain literal. Formats are display hints and may be outdated.

There are 149 countries with some supplied postal metadata. Of these, 147 have a regex; BL and MF have only a format. These records have `status: legacy_unverified`. The other 100 records have `status: unavailable`. A missing rule does not mean that the country has no postal system.

Before matching a pattern, the optional `Hmzisb\ReferenceData\Support\PostalCode` helper uppercases the input and applies its recorded rule:

| Rule | What it does | Where used |
| --- | --- | --- |
| `compact` | Removes whitespace and ASCII hyphens | All supplied patterns except US and TC |
| `trim` | Removes whitespace only at the beginning and end | US, where the regex uses the ZIP+4 hyphen |
| `collapse_spaces` | Trims the ends and changes consecutive whitespace to one space | TC, where the regex includes a space |

The helper accepts the documented examples `01310-100` (BR), `100-0001` (JP), and `SW1A 1AA` (GB). It preserves the separators needed by `12345-6789` (US) and `TKCA 1ZZ` (TC). It returns null when there is no pattern. Matching an old pattern does not establish that a postal code is current or an address is deliverable. Use current postal-service data when validating delivery addresses.

## Rebuilding the same snapshot

Run these commands from the repository root. Rebuilding uses Python 3.9 or later, Node.js, and the local source files. The separate validation step uses PHP.

```sh
python3 tools/build-data.py
php tools/validate.php
python3 tests/test_build.py
```

Generated catalogs use standard JSON, which has no comment syntax. The source snapshots preserve the reviewed inputs as supplied. Use this document, the audit, and the builder to explain catalog choices.

The builder reads `tools/source-lock.json`, checks every source file against its recorded SHA-256 checksum, and only then processes the sources. A missing file or a changed checksum stops the build before it writes output. The timezone identifiers come from the included snapshot, so the PHP installation on the build machine cannot silently change the output.

To compare a rebuild without replacing the checked-in catalogs:

```sh
python3 tools/build-data.py --output /tmp/reference-rebuild/data --audit /tmp/reference-rebuild/data-quality.json
php tools/validate.php /tmp/reference-rebuild/data
```

`--sources` can point to another directory containing the same locked files. The optional `--locales` and `--legacy-countries` paths must also match their locked checksums. The builder verifies each input at its resolved path.

## Preparing a new snapshot

Update the snapshots when you intend to release revised catalogs.

1. Review and replace the relevant source snapshots in `tools/sources/`. The source links above identify their publishers. The checked-in files and hashes identify the exact inputs.
2. If timezone data changes, export a reviewed registry with `php -r 'echo json_encode(DateTimeZone::listIdentifiers(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;'` into `tools/sources/php-timezone-identifiers.json`. Record the value from `php -r 'echo timezone_version_get();'` in the lock. Review the IANA country tables too.
3. Update the lock's explicit `snapshot` date, the timezone version if changed, and the SHA-256 values for changed inputs. Do not replace checksums just to silence a mismatch without reviewing the input changes.
4. Build into a separate output directory. Review changes in country mappings, currencies, languages, timezones, and the audit. Update any explicit correction that no longer applies. Install the reviewed catalogs and audit together and update the documented counts and date.
5. Run the PHP validator and the tests in `TESTING.md` before installing the new files in an app. Check removed or renamed codes against the app's existing references.

The audit lists missing flags, regions, areas, capitals, native names, continents, locale tags, postal fields, and lookup metadata. It also records border exclusions and corrections. A missing value is kept null or empty instead of being guessed. The builder checks country coverage and rejects unresolved currency or timezone associations. Unknown language-source cases are reported for review. The build currently checks the known 249-country ISO set; review that check if the official set changes.
