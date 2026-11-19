#!/usr/bin/env python3
"""Rebuild the Laravel reference catalogs from the included, reviewed snapshots.

The source lock is the authority for the snapshot date and exact input bytes.
All inputs are verified before JavaScript is evaluated or output is written.
Country associations use stable codes; generated IDs belong to the database.
Run this maintenance tool from any directory. It never downloads data.
"""

import argparse
from collections import Counter, defaultdict
from datetime import date
import hashlib
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCE_DIR = ROOT / "tools/sources"
SOURCE_LOCK = ROOT / "tools/source-lock.json"
CLDR_OFFICIAL_STATUSES = {"official", "official_regional", "de_facto_official"}
# ISO 639-2 language collections used by the country source.
ISO6392_COLLECTIONS = {"ber": "Berber", "khi": "Khoisan", "smi": "Sami"}
# South Africa Constitution Eighteenth Amendment Act, 2023.
ADDITIONAL_COUNTRY_LANGUAGES = {"ZA": {"sfs"}}
ATTACHMENT_MISLABELED_ISO6393 = {"hif": "Fiji Hindi"}
NEIGHBOR_REMOVALS = {
    "LK": {
        "IN": {
            "reason": "Sri Lanka has no land boundaries.",
            "source": "https://www.cg-mumbai.gov.lk/en/sri-lanka",
        },
    },
}
POSTAL_NORMALIZATION_OVERRIDES = {"US": "trim", "TC": "collapse_spaces"}
SOURCE_FILES = (
    "countries.json",
    "currencyData.json",
    "territoryInfo.json",
    "iso6393.js",
    "six.xml",
    "iso3166.tab",
    "zone.tab",
    "zone1970.tab",
    "php-timezone-identifiers.json",
    "locales.json",
    "CountriesTableSeeder.php",
)


def read_json(path):
    """Read a UTF-8 JSON file used by the build configuration."""
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def write_json(path, data):
    """Write stable, readable UTF-8 JSON for review and reproducible comparisons."""
    with path.open("w", encoding="utf-8", newline="\n") as stream:
        stream.write(json.dumps(data, ensure_ascii=False, indent=2) + "\n")


def read_locked_sources(paths):
    """Verify every input hash and return the lock plus the verified file bytes.

    Retaining those bytes prevents a later file change from bypassing the check."""
    lock = read_json(SOURCE_LOCK)
    snapshot_date = lock.get("snapshot")
    if not isinstance(snapshot_date, str) or date.fromisoformat(snapshot_date).isoformat() != snapshot_date:
        raise ValueError("The source lock needs an ISO calendar date")
    if not optional_text(lock.get("php_timezone_version")):
        raise ValueError("The source lock needs a PHP timezone data version")
    checksums = lock.get("sha256")
    if not isinstance(checksums, dict) or set(checksums) != set(paths):
        raise ValueError("The source lock must cover every build input")

    inputs = {}
    for name, path in paths.items():
        source = path.read_bytes()
        actual = hashlib.sha256(source).hexdigest()
        if actual != checksums[name]:
            raise ValueError(
                f"Checksum mismatch for {name} ({path}). Expected {checksums[name]}, got {actual}."
            )
        inputs[name] = source
    return lock, inputs


def load_language_source(source):
    """Load the already verified ISO language module through Node.js.

    The source is executed only after the complete input set passes its checksums."""
    script = """
        import {readFileSync} from 'node:fs';
        const source = readFileSync(0);
        const module = await import('data:text/javascript;base64,' + source.toString('base64'));
        process.stdout.write(JSON.stringify(module.iso6393));
    """
    result = subprocess.check_output(
        ["node", "--input-type=module", "-e", script], input=source
    )
    return {row["iso6393"]: row for row in json.loads(result)}


def optional_text(value):
    """Return stripped nonblank text, or None when the source has no usable text."""
    return value.strip() or None if isinstance(value, str) else None


def sorted_unique(values):
    """Return a deterministic list of distinct values."""
    return sorted(set(values))


def stable_attachment_value(records, key):
    """Use a supplied value only when all nonblank source values agree."""
    values = [optional_text(record.get(key)) for record in records]
    nonempty = {value for value in values if value is not None}
    return next(iter(nonempty)) if len(nonempty) == 1 else None


def attachment_language_crosswalk(language_source):
    """Index ISO identifiers by their declared code system to avoid false matches."""
    return {
        "iso_639_1": {
            row["iso6391"]: code for code, row in language_source.items() if row.get("iso6391")
        },
        "iso_639_2": {
            **{code: code for code in ISO6392_COLLECTIONS},
            **{
                row[field]: code
                for code, row in language_source.items()
                for field in ("iso6392B", "iso6392T")
                if row.get(field)
            },
        },
        "iso_639_3": {code: code for code in language_source},
    }


def resolve_attachment_language(language, language_source, crosswalk):
    """Resolve one supplied language identity, or report conflicting identifiers.

    A wrong-field ISO code cannot silently map a name to another language.
    The sole explicit repair requires both the known code and canonical name."""
    claimed_codes = {
        field: code for field in crosswalk if (code := optional_text(language.get(field))) is not None
    }
    verified_codes = {
        crosswalk[field][code] for field, code in claimed_codes.items() if code in crosswalk[field]
    }
    # An ISO 639-3 code in the wrong field can contradict a verified identity.
    # It cannot supply a match by itself unless a verified correction applies.
    misfiled_codes = {
        code
        for field, code in claimed_codes.items()
        if code not in crosswalk[field] and code in crosswalk["iso_639_3"]
    }
    iso2 = claimed_codes.get("iso_639_2")
    corrected_iso3 = None
    if iso2 in ATTACHMENT_MISLABELED_ISO6393 and iso2 not in crosswalk["iso_639_2"]:
        expected_name = ATTACHMENT_MISLABELED_ISO6393[iso2]
        if language.get("name") == expected_name == language_source.get(iso2, {}).get("name"):
            verified_codes.add(iso2)
            corrected_iso3 = iso2

    resolved_ids = verified_codes | misfiled_codes
    if len(resolved_ids) > 1:
        return None, None, {
            "name": language.get("name"),
            "claimed_identifiers": claimed_codes,
            "resolved_language_ids": sorted(resolved_ids),
            "reason": "Conflicting language identifiers",
        }
    return next(iter(verified_codes), None), corrected_iso3, None


def currency_subunit(records, minor_unit):
    """Keep a physical subunit name and ratio together when the sources agree.

    This ratio is separate from the accounting minor-unit exponent."""
    if minor_unit == 0:
        return None, None
    supplied = set()
    for row in records:
        name = optional_text(row.get("currency_subunit_name"))
        ratio = row.get("currency_subunit_value")
        if name is not None and type(ratio) is int and ratio > 1:
            supplied.add((name, ratio))
    return next(iter(supplied)) if len(supplied) == 1 else (None, None)


def parse_zone_table(source):
    """Map every country on an IANA table row to its timezone identifier."""
    result = defaultdict(set)
    for line in source.decode("utf-8").splitlines():
        if line and not line.startswith("#"):
            codes, _, identifier, *_ = line.split("\t")
            for code in codes.split(","):
                result[code].add(identifier)
    return result


def parse_six(source):
    """Read current ISO 4217 entries and reject conflicting records for a code."""
    root = ET.fromstring(source)
    result = {}
    for entry in root.findall(".//CcyNtry"):
        code = entry.findtext("Ccy")
        if not code:
            continue
        name = entry.findtext("CcyNm")
        numeric = entry.findtext("CcyNbr")
        unit = entry.findtext("CcyMnrUnts")
        row = (name, numeric, int(unit) if unit.isdigit() else None)
        if code in result and result[code] != row:
            raise ValueError(f"SIX contains conflicting currency rows for {code}")
        result[code] = row
    return root.attrib["Pblshd"], result


def active_currency_codes(records, snapshot_date):
    """Select legal-tender code periods covering the locked snapshot date."""
    for record in records:
        for code, dates in record.items():
            if (
                dates.get("_tender", "true") == "true"
                and dates.get("_from", "0000-00-00") <= snapshot_date
                and dates.get("_to", "9999-99-99") >= snapshot_date
            ):
                yield code


def neighbor_asymmetries(neighbors):
    """Report land-border claims that the named neighbor does not reciprocate."""
    asymmetric = {}
    for code, borders in neighbors.items():
        unreciprocated = [neighbor for neighbor in borders if code not in neighbors[neighbor]]
        if unreciprocated:
            asymmetric[code] = unreciprocated
    return asymmetric


def build(inputs, lock, output, audit_path):
    """Create catalogs and their audit from verified inputs.

    Resolve source conflicts before output, preserve unknown values as null,
    and validate all country-to-catalog mappings before replacing any files."""
    snapshot_date = lock["snapshot"]
    country_data = json.loads(inputs["countries.json"])
    cldr = json.loads(inputs["currencyData.json"])["supplemental"]
    territory_info = json.loads(inputs["territoryInfo.json"])["supplemental"]
    language_source = load_language_source(inputs["iso6393.js"])
    language_crosswalk = attachment_language_crosswalk(language_source)
    six_date, six = parse_six(inputs["six.xml"])
    shared_zones = parse_zone_table(inputs["zone1970.tab"])
    country_specific_zones = parse_zone_table(inputs["zone.tab"])
    timezone_codes = json.loads(inputs["php-timezone-identifiers.json"])
    if len(set(timezone_codes)) != len(timezone_codes) or "UTC" not in timezone_codes:
        raise ValueError("Pinned PHP identifiers must be unique and include UTC")
    timezone_codes = set(timezone_codes)

    attachments_by_country = defaultdict(list)
    attachments_by_currency = defaultdict(list)
    attachments_by_language = defaultdict(list)
    attachment_locales = defaultdict(set)
    attachment_language_conflicts = defaultdict(list)
    attachment_language_corrections = set()
    for entry in json.loads(inputs["locales.json"]):
        country = entry["country"]
        country_code = country["code"]
        attachments_by_country[country_code].append(country)
        attachments_by_currency[country["currency_code"]].append(country)
        attachment_locales[country_code].add(entry["locale"])
        for language in [entry["language"], *country["languages"]]:
            language_id, corrected_iso3, conflict = resolve_attachment_language(
                language, language_source, language_crosswalk
            )
            if conflict:
                locale_conflicts = attachment_language_conflicts[entry["locale"]]
                if conflict not in locale_conflicts:
                    locale_conflicts.append(conflict)
            if language_id:
                attachments_by_language[language_id].append(language)
            if corrected_iso3:
                attachment_language_corrections.add(corrected_iso3)

    official_countries = {
        row["cca2"]: row for row in country_data if row["status"] == "officially-assigned"
    }
    if len(official_countries) != 249:
        raise ValueError(f"Expected 249 ISO countries, got {len(official_countries)}")
    alpha3_to_alpha2 = {
        country["cca3"]: code for code, country in official_countries.items()
    }
    iso_codes = {
        line.split("\t")[0]
        for line in inputs["iso3166.tab"].decode("utf-8").splitlines()
        if line and not line.startswith("#")
    }
    if iso_codes != official_countries.keys():
        raise ValueError("Country source and IANA ISO 3166 code lists disagree")

    source_neighbors = {
        code: sorted_unique(
            alpha3_to_alpha2[neighbor] for neighbor in source["borders"] if neighbor in alpha3_to_alpha2
        )
        for code, source in sorted(official_countries.items())
    }
    applied_neighbor_removals = defaultdict(dict)
    for code, removals in NEIGHBOR_REMOVALS.items():
        for neighbor, correction in removals.items():
            if neighbor in source_neighbors[code]:
                applied_neighbor_removals[code][neighbor] = correction
    country_neighbors = {
        code: [neighbor for neighbor in borders if neighbor not in applied_neighbor_removals.get(code, {})]
        for code, borders in source_neighbors.items()
    }

    symbols = defaultdict(set)
    for source in country_data:
        for code, currency in source["currencies"].items():
            symbol = optional_text(currency.get("symbol"))
            if symbol:
                symbols[code].add(symbol)

    catalog_source_ids = language_crosswalk["iso_639_1"]
    language_ids = set(catalog_source_ids.values())
    country_language_ids = {}
    missing_language_source = {}
    unrecognized_cldr_language_codes = defaultdict(set)
    for code, country in official_countries.items():
        country_language_ids[code] = set(country["languages"]) | ADDITIONAL_COUNTRY_LANGUAGES.get(code, set())
        for language_id, name in country["languages"].items():
            if language_id not in language_source and language_id not in ISO6392_COLLECTIONS:
                missing_language_source[language_id] = name
        population = territory_info["territoryInfo"].get(code, {}).get("languagePopulation", {})
        for tag, details in population.items():
            if details.get("_officialStatus") not in CLDR_OFFICIAL_STATUSES:
                continue
            base = tag.split("_", 1)[0]
            iso3 = catalog_source_ids.get(base, base)
            if iso3 in language_source or iso3 in ISO6392_COLLECTIONS:
                country_language_ids[code].add(iso3)
            else:
                unrecognized_cldr_language_codes[code].add(base)
        language_ids.update(country_language_ids[code])

    language_code = {
        code: language_source.get(code, {}).get("iso6391", code) for code in language_ids
    }
    languages = []
    for iso3 in sorted(language_ids):
        row = language_source.get(iso3, {})
        code = language_code[iso3]
        native_name = stable_attachment_value(attachments_by_language[iso3], "name_local")
        languages.append({
            "code": code,
            "name": row.get("name") or ISO6392_COLLECTIONS.get(iso3) or missing_language_source[iso3],
            "details": {
                "name_local": native_name,
                "iso_639_1": row.get("iso6391"),
                "iso_639_2": row.get("iso6392T") or (iso3 if iso3 in ISO6392_COLLECTIONS else None),
                "iso_639_3": iso3 if row else None,
            },
        })

    currencies = []
    for code, (name, numeric, minor_unit) in sorted(six.items()):
        records = attachments_by_currency[code]
        source_symbols = symbols[code]
        symbol = next(iter(source_symbols)) if len(source_symbols) == 1 else None
        symbol = symbol or stable_attachment_value(records, "currency_symbol")
        subunit_name, subunit_ratio = currency_subunit(records, minor_unit)
        currencies.append({
            "code": code,
            "name": name,
            "minor_unit": minor_unit,
            "details": {
                "local_name": stable_attachment_value(records, "currency_local"),
                "currency_numeric": numeric,
                "symbol": symbol,
                "subunit": {
                    "name": subunit_name,
                    "units_per_major": subunit_ratio,
                },
            },
        })

    countries = []
    discarded_locales = defaultdict(list)
    missing_currency_source = defaultdict(list)
    missing_zone_runtime = defaultdict(list)
    attachment_currency_differences = {}
    countries_without_source_flag = []
    attachment_country_flag_fallbacks = {}
    for code, source in sorted(official_countries.items()):
        records = attachments_by_country[code]
        languages_for_country = sorted_unique(
            language_code[language] for language in country_language_ids[code]
        )
        secondary_locales = []
        for tag in sorted(attachment_locales[code]):
            if not isinstance(tag, str):
                continue
            parts = tag.split("-")
            if (
                re.fullmatch(r"[a-z]{2,3}(?:-[A-Za-z0-9]{1,8})*-[A-Z]{2}", tag)
                and parts[0] in languages_for_country
                and parts[-1] == code
            ):
                secondary_locales.append(tag)
            else:
                discarded_locales[code].append(tag)

        active_codes = sorted_unique(
            active_currency_codes(cldr["currencyData"]["region"].get(code, []), snapshot_date)
        )
        for currency in active_codes:
            if currency not in six:
                missing_currency_source[code].append(currency)
        attached_codes = sorted_unique(
            record["currency_code"] for record in records if record["currency_code"]
        )
        if attached_codes != active_codes:
            attachment_currency_differences[code] = {
                "attachment": attached_codes,
                "active_cldr": active_codes,
            }

        requested_zones = country_specific_zones.get(code) or shared_zones.get(code, set())
        for zone in sorted(requested_zones - timezone_codes):
            missing_zone_runtime[code].append(zone)

        native_names = {
            language_code.get(iso3, iso3): name["common"]
            for iso3, name in source["name"]["native"].items()
            if optional_text(name.get("common"))
        }
        name_local = stable_attachment_value(records, "name_local")
        if name_local is None and len(set(native_names.values())) == 1:
            name_local = next(iter(native_names.values()))
        flag = optional_text(source.get("flag"))
        if flag is None:
            countries_without_source_flag.append(code)
            expected_flag = "".join(chr(0x1F1E6 + ord(letter) - ord("A")) for letter in code)
            if stable_attachment_value(records, "flag") == expected_flag:
                attachment_country_flag_fallbacks[code] = flag = expected_flag
        postal_format = stable_attachment_value(records, "postal_code_format")
        postal_regex = stable_attachment_value(records, "postal_code_regex")
        postal_normalization = (
            POSTAL_NORMALIZATION_OVERRIDES.get(code, "compact") if postal_regex else None
        )
        countries.append({
            "code": code,
            "name": source["name"]["common"],
            "default_locale": None,
            "region": optional_text(source.get("subregion")),
            "flag": flag,
            "details": {
                "info": {
                    "name_local": name_local,
                    "native_names": native_names,
                    "capital_names": source["capital"],
                    "tlds": source["tld"],
                    "area_sq_km": source["area"] if source["area"] > 0 else None,
                },
                "geo": {
                    "continent": stable_attachment_value(records, "continent"),
                    "neighbors": country_neighbors[code],
                    "landlocked": source["landlocked"],
                },
                "postal_code": {
                    "format": postal_format,
                    "regex": postal_regex,
                    "normalization": postal_normalization,
                    "status": "legacy_unverified" if postal_format or postal_regex else "unavailable",
                },
                "secondary_locales": secondary_locales,
            },
            "currency_codes": [currency for currency in active_codes if currency in six],
            "language_codes": languages_for_country,
            "timezone_codes": sorted(requested_zones & timezone_codes),
        })

    catalogs = {
        "countries": countries,
        "currencies": currencies,
        "languages": sorted(languages, key=lambda row: row["code"]),
        "timezones": [{"code": code} for code in sorted(timezone_codes)],
    }
    if missing_currency_source:
        raise ValueError(f"Active CLDR currency codes missing from SIX: {dict(missing_currency_source)}")
    if missing_zone_runtime:
        raise ValueError(f"IANA country timezones missing from the pinned PHP identifiers: {dict(missing_zone_runtime)}")

    for name, rows in catalogs.items():
        row_codes = {row["code"] for row in rows}
        if len(row_codes) != len(rows):
            raise ValueError(f"Duplicate codes in {name}")
        if name != "countries":
            field = name.removesuffix("s") + "_codes"
            if name == "currencies":
                field = "currency_codes"
            for row in countries:
                if set(row[field]) - row_codes:
                    raise ValueError(f"Broken {field} in {row['code']}")

    legacy = inputs["CountriesTableSeeder.php"].decode("utf-8")
    legacy_codes = set(re.findall(r"'code'\s*=>\s*'([A-Z]{2})'", legacy))
    audit = {
        "snapshot": snapshot_date,
        "counts": {name: len(rows) for name, rows in catalogs.items()},
        "cldr_version": cldr["version"],
        "six_publication": six_date,
        "php_timezone_version": lock["php_timezone_version"],
        "iso3166_alpha2_crosscheck_count": len(iso_codes),
        "sha256": lock["sha256"],
        "legacy_only_country_codes": sorted(legacy_codes - official_countries.keys()),
        "official_country_codes_missing_from_legacy": sorted(official_countries.keys() - legacy_codes),
        "attachment_only_country_codes": sorted(attachments_by_country.keys() - official_countries.keys()),
        "official_country_codes_missing_from_attachment": sorted(official_countries.keys() - attachments_by_country.keys()),
        "countries_without_currency": [row["code"] for row in countries if not row["currency_codes"]],
        "countries_without_language": [row["code"] for row in countries if not row["language_codes"]],
        "countries_without_timezone": [row["code"] for row in countries if not row["timezone_codes"]],
        "countries_without_source_flag": countries_without_source_flag,
        "attachment_country_flag_fallbacks": attachment_country_flag_fallbacks,
        "countries_without_flag": [row["code"] for row in countries if row["flag"] is None],
        "countries_without_region": [row["code"] for row in countries if row["region"] is None],
        "countries_without_area": [
            row["code"] for row in countries if row["details"]["info"]["area_sq_km"] is None
        ],
        "countries_without_capital": [
            row["code"] for row in countries if not row["details"]["info"]["capital_names"]
        ],
        "country_neighbor_codes_outside_catalog": {
            code: excluded
            for code, source in sorted(official_countries.items())
            if (excluded := sorted(set(source["borders"]) - alpha3_to_alpha2.keys()))
        },
        "country_neighbor_source_asymmetries": neighbor_asymmetries(source_neighbors),
        "country_neighbor_removals": applied_neighbor_removals,
        "country_neighbor_asymmetries": neighbor_asymmetries(country_neighbors),
        "postal_code_status_counts": dict(sorted(Counter(
            row["details"]["postal_code"]["status"] for row in countries
        ).items())),
        "countries_without_attached_postal_format": [
            row["code"] for row in countries if not row["details"]["postal_code"]["format"]
        ],
        "countries_without_attached_postal_regex": [
            row["code"] for row in countries if not row["details"]["postal_code"]["regex"]
        ],
        "countries_with_postal_format_without_regex": [
            row["code"] for row in countries
            if row["details"]["postal_code"]["format"] and not row["details"]["postal_code"]["regex"]
        ],
        "countries_without_native_name": [
            row["code"] for row in countries if row["details"]["info"]["name_local"] is None
        ],
        "countries_without_native_names": [
            row["code"] for row in countries if not row["details"]["info"]["native_names"]
        ],
        "countries_without_continent": [
            row["code"] for row in countries if row["details"]["geo"]["continent"] is None
        ],
        "countries_without_secondary_locale": [
            row["code"] for row in countries if not row["details"]["secondary_locales"]
        ],
        "languages_without_native_name": [
            row["code"] for row in languages if row["details"]["name_local"] is None
        ],
        "attachment_language_code_conflicts": dict(sorted(attachment_language_conflicts.items())),
        "attachment_language_code_corrections": {
            code: {
                "name": ATTACHMENT_MISLABELED_ISO6393[code],
                "attachment_field": "iso_639_2",
                "verified_field": "iso_639_3",
            }
            for code in sorted(attachment_language_corrections)
        },
        "currencies_without_minor_unit": [
            row["code"] for row in currencies if row["minor_unit"] is None
        ],
        "currencies_without_local_name": [
            row["code"] for row in currencies if row["details"]["local_name"] is None
        ],
        "currencies_without_symbol": [
            row["code"] for row in currencies if row["details"]["symbol"] is None
        ],
        "currencies_without_subunit": [
            row["code"] for row in currencies if row["details"]["subunit"]["name"] is None
        ],
        "attachment_currency_differences": attachment_currency_differences,
        "active_cldr_currency_codes_missing_from_six": missing_currency_source,
        "iana_country_timezones_missing_from_php": missing_zone_runtime,
        "country_language_codes_missing_from_iso6393": missing_language_source,
        "iso6392_language_collections": ISO6392_COLLECTIONS,
        "cldr_language_codes_outside_supported_iso_catalogs": {
            code: sorted(ids) for code, ids in unrecognized_cldr_language_codes.items()
        },
        "discarded_attachment_locale_tags": discarded_locales,
    }
    output.mkdir(parents=True, exist_ok=True)
    audit_path.parent.mkdir(parents=True, exist_ok=True)
    for name, rows in catalogs.items():
        write_json(output / f"{name}.json", rows)
    write_json(audit_path, audit)
    print(json.dumps(audit["counts"]))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sources", type=Path, default=DEFAULT_SOURCE_DIR)
    parser.add_argument("--locales", type=Path)
    parser.add_argument("--legacy-countries", type=Path)
    parser.add_argument("--output", type=Path, default=ROOT / "database/data/reference")
    parser.add_argument("--audit", type=Path, default=ROOT / "data-quality.json")
    args = parser.parse_args()
    source_paths = {name: args.sources / name for name in SOURCE_FILES}
    if args.locales:
        source_paths["locales.json"] = args.locales
    if args.legacy_countries:
        source_paths["CountriesTableSeeder.php"] = args.legacy_countries
    try:
        lock, inputs = read_locked_sources(source_paths)
        build(inputs, lock, args.output, args.audit)
    except (OSError, ValueError) as error:
        parser.exit(1, f"{error}\n")
