"""Integration checks for the offline reference catalog builder."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
TOOLS = ROOT / "tools"
CATALOG_DIRECTORY = ROOT / "database/data/reference"
CATALOGS = ("countries", "currencies", "languages", "timezones")


def read_json(path):
    return json.loads(path.read_text(encoding="utf-8"))


def read_catalog(name, directory=CATALOG_DIRECTORY):
    return {row["code"]: row for row in read_json(directory / f"{name}.json")}


def replace_pinned_source(tools, name, content):
    """Rehash a modified fixture so the builder can validate its contents."""
    (tools / "sources" / name).write_bytes(content)
    lock_path = tools / "source-lock.json"
    lock = read_json(lock_path)
    lock["sha256"][name] = hashlib.sha256(content).hexdigest()
    lock_path.write_text(json.dumps(lock), encoding="utf-8")


class BuildTests(unittest.TestCase):
    """Exercise the builder in an isolated directory with the pinned snapshot inputs."""

    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.work = Path(temp.name)
        self.output = self.work / "catalogs"
        self.audit = self.work / "data-quality.json"

    def build(self, *args, tools=TOOLS):
        return subprocess.run(
            [
                sys.executable,
                str(tools / "build-data.py"),
                "--output", str(self.output),
                "--audit", str(self.audit),
                *map(str, args),
            ],
            cwd=self.work,
            capture_output=True,
            text=True,
            timeout=60,
        )

    def copy_tools(self):
        tools = self.work / "tools"
        shutil.copytree(TOOLS, tools)
        return tools

    def seed_outputs(self):
        """Keep the original bytes of an existing snapshot and its audit."""
        self.output.mkdir()
        outputs = [self.output / f"{name}.json" for name in CATALOGS] + [self.audit]
        previous = {path: f"previous {path.name}\n".encode() for path in outputs}
        for path, content in previous.items():
            path.write_bytes(content)
        return previous

    def assert_outputs_unchanged(self, previous):
        self.assertEqual(set(self.output.iterdir()), set(previous) - {self.audit})
        for path, content in previous.items():
            self.assertEqual(path.read_bytes(), content, path.name)

    def test_rebuild_matches_bundled_snapshot_byte_for_byte(self):
        result = self.build()
        self.assertEqual(result.returncode, 0, result.stderr)
        pairs = [
            (self.output / f"{name}.json", CATALOG_DIRECTORY / f"{name}.json")
            for name in CATALOGS
        ]
        pairs.append((self.audit, ROOT / "data-quality.json"))
        for actual, expected in pairs:
            with self.subTest(file=actual.name):
                self.assertTrue(
                    actual.read_bytes() == expected.read_bytes(),
                    f"Rebuilt {actual.name} differs from the bundled copy",
                )

    def test_tampered_source_is_rejected_before_any_output(self):
        sources = self.work / "sources"
        shutil.copytree(TOOLS / "sources", sources)
        for name in ("iso6393.js", "php-timezone-identifiers.json"):
            with self.subTest(source=name):
                source = sources / name
                original = source.read_bytes()
                source.write_bytes(original + b"\n")

                result = self.build("--sources", sources)

                source.write_bytes(original)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(name, result.stderr)
                self.assertFalse(self.output.exists())
                self.assertFalse(self.audit.exists())

    def test_lock_with_invalid_snapshot_date_is_rejected(self):
        tools = self.copy_tools()
        lock_path = tools / "source-lock.json"
        lock = read_json(lock_path)
        lock["snapshot"] = "2026-02-30"
        lock_path.write_text(json.dumps(lock), encoding="utf-8")

        result = self.build(tools=tools)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("day", result.stderr.lower())
        self.assertFalse(self.output.exists())
        self.assertFalse(self.audit.exists())

    def test_missing_source_preserves_previous_outputs(self):
        tools = self.copy_tools()
        source = tools / "sources/CountriesTableSeeder.php"
        source.unlink()
        previous = self.seed_outputs()

        result = self.build(tools=tools)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn(source.name, result.stderr)
        self.assert_outputs_unchanged(previous)

    def test_invalid_pinned_input_preserves_previous_outputs(self):
        tools = self.copy_tools()
        source = tools / "sources/iso3166.tab"
        lines = source.read_bytes().splitlines(keepends=True)
        replace_pinned_source(tools, source.name, b"".join(
            line for line in lines if not line.startswith(b"AD\t")
        ))
        previous = self.seed_outputs()

        result = self.build(tools=tools)

        self.assertNotEqual(result.returncode, 0)
        self.assertIn("ISO 3166", result.stderr)
        self.assert_outputs_unchanged(previous)

    def test_language_names_follow_verified_language_ids(self):
        languages = read_catalog("languages")
        audit = read_json(ROOT / "data-quality.json")
        for code, native_name in {"rar": "Māori", "hif": "फ़िजी बात"}.items():
            with self.subTest(language=code):
                self.assertEqual(languages[code]["details"]["iso_639_3"], code)
                self.assertEqual(languages[code]["details"]["name_local"], native_name)
                self.assertNotIn(code, audit["languages_without_native_name"])
        self.assertEqual(
            audit["attachment_language_code_corrections"]["hif"],
            {
                "name": "Fiji Hindi",
                "attachment_field": "iso_639_2",
                "verified_field": "iso_639_3",
            },
        )
        self.assertEqual(languages["ms"]["details"]["iso_639_3"], "msa")
        self.assertEqual(languages["ms"]["details"]["name_local"], "bahasa Melayu")
        self.assertNotIn("zsm", languages)
        self.assertNotIn("ms", audit["languages_without_native_name"])
        self.assertEqual(
            audit["attachment_language_code_conflicts"]["ms-MY"],
            [{
                "name": "Malaysian",
                "claimed_identifiers": {
                    "iso_639_1": "ms",
                    "iso_639_2": "zsm",
                    "iso_639_3": "msa",
                },
                "resolved_language_ids": ["msa", "zsm"],
                "reason": "Conflicting language identifiers",
            }],
        )

    def test_conflicting_verified_ids_cannot_supply_a_native_name(self):
        # The attachment name must never leak into either of its claimed languages.
        tools = self.copy_tools()
        source = tools / "sources/locales.json"
        locales = read_json(source)
        english = next(row["language"] for row in locales if row["locale"] == "en-US")
        english["iso_639_3"] = "spa"
        english["name_local"] = "Conflicting name"
        replace_pinned_source(tools, source.name, json.dumps(locales).encode("utf-8"))

        result = self.build(tools=tools)

        self.assertEqual(result.returncode, 0, result.stderr)
        languages = read_catalog("languages", self.output)
        self.assertEqual(languages["en"]["details"]["name_local"], "English")
        self.assertEqual(languages["es"]["details"]["name_local"], "Español")
        conflicts = read_json(self.audit)["attachment_language_code_conflicts"]["en-US"]
        self.assertEqual([row["resolved_language_ids"] for row in conflicts], [["eng", "spa"]])

    def test_country_source_gaps_and_corrections_are_reported(self):
        countries = read_catalog("countries")
        audit = read_json(ROOT / "data-quality.json")

        self.assertIn("AQ", audit["countries_without_region"])
        self.assertIn("SJ", audit["countries_without_area"])
        self.assertIn("UM", audit["countries_without_capital"])
        self.assertIsNone(countries["AQ"]["region"])
        self.assertIsNone(countries["SJ"]["details"]["info"]["area_sq_km"])
        self.assertEqual(countries["UM"]["details"]["info"]["capital_names"], [])

        self.assertIn("BQ", audit["countries_without_source_flag"])
        self.assertNotIn("BQ", audit["countries_without_flag"])
        self.assertEqual(countries["BQ"]["flag"], "🇧🇶")
        self.assertEqual(audit["attachment_country_flag_fallbacks"]["BQ"], "🇧🇶")

        self.assertEqual(audit["country_neighbor_source_asymmetries"], {"LK": ["IN"]})
        self.assertEqual(audit["country_neighbor_asymmetries"], {})
        self.assertIn("IN", audit["country_neighbor_removals"]["LK"])
        self.assertEqual(countries["LK"]["details"]["geo"]["neighbors"], [])
        self.assertNotIn("LK", countries["IN"]["details"]["geo"]["neighbors"])


if __name__ == "__main__":
    unittest.main()
