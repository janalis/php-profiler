"""Version-only metadata migration cannot renew stale or changed native proofs."""
import copy
import importlib.util
import json
import shutil
import subprocess
import tempfile
from pathlib import Path
import unittest

SPEC = importlib.util.spec_from_file_location('version_transition', Path(__file__).resolve().parents[2] / 'scripts/validate-version-transition.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class VersionTransitionTests(unittest.TestCase):
    def setUp(self):
        self.base = {name: (name + '\n').encode() for name in MODULE.DEPENDENCIES}
        self.base['Cargo.toml'] = b'[workspace.package]\nversion = "0.1.0"\nedition = "2024"\n'
        self.base['Cargo.lock'] = b'''version = 4

[[package]]
name = "php-profiler"
version = "0.1.0"

[[package]]
name = "profiler-core"
version = "0.1.0"

[[package]]
name = "registry-dependency"
version = "1.0.0"
source = "registry+https://github.com/rust-lang/crates.io-index"
'''
        self.ledger = {'schema_version': 2, 'contexts': {'nts': {'flags': ['-O0']}, 'zts': {'flags': ['-DZTS']}},
                       'equivalents': [{'source': 'bridge.c', 'status': 'equivalent', 'replacement': '0', 'location': {'line': 10}}],
                       'dependency_sha256': {name: MODULE.digest(body) for name, body in self.base.items()}}
        self.sync_ledger()
        self.target = dict(self.base)
        self.version = '0.1.0-alpha.1'
        self.target['Cargo.toml'] = self.base['Cargo.toml'].replace(b'"0.1.0"', b'"0.1.0-alpha.1"')
        self.target['Cargo.lock'] = self.base['Cargo.lock'].replace(b'"0.1.0"', b'"0.1.0-alpha.1"')

    def sync_ledger(self):
        self.base[MODULE.LEDGER] = (json.dumps(self.ledger, indent=2) + '\n').encode()

    def derive(self):
        return MODULE.expected_transition(self.base.__getitem__, self.target.__getitem__, self.version)

    def test_only_two_hash_bytes_change_and_all_proof_values_remain_identical(self):
        result, updates = self.derive()
        actual = json.loads(result)
        old_hashes = actual.pop('dependency_sha256')
        expected = copy.deepcopy(self.ledger)
        expected.pop('dependency_sha256')
        self.assertEqual(actual, expected)
        self.assertEqual(set(updates), {'Cargo.toml', 'Cargo.lock'})
        for name in MODULE.DEPENDENCIES:
            self.assertEqual(old_hashes[name], MODULE.digest(self.target[name]))
        restored = result
        for update in updates.values():
            restored = restored.replace(update['new_sha256'].encode(), update['old_sha256'].encode())
        self.assertEqual(restored, self.base[MODULE.LEDGER])

    def test_registry_dependency_version_and_unexpected_entry_are_rejected(self):
        for suffix in (b'\n[[package]]\nname = "unexpected"\nversion = "1"\n', b''):
            with self.subTest(suffix=suffix):
                old = self.target['Cargo.lock']
                self.target['Cargo.lock'] = old + suffix if suffix else old.replace(b'"1.0.0"', b'"2.0.0"')
                with self.assertRaises(ValueError):
                    self.derive()
                self.target['Cargo.lock'] = old

    def test_edition_build_flags_and_any_other_proof_dependency_change_are_rejected(self):
        for name in ('Cargo.toml', 'crates/php-profiler/build.rs', 'tests/native/sampling-unit.c'):
            with self.subTest(name=name):
                old = self.target[name]
                self.target[name] = old.replace(b'2024', b'2021') if name == 'Cargo.toml' else old + b'-DZTS\n'
                with self.assertRaises(ValueError):
                    self.derive()
                self.target[name] = old

    def test_missing_extra_and_stale_dependency_hashes_are_rejected(self):
        for mode in ('missing', 'extra', 'stale'):
            with self.subTest(mode=mode):
                old = copy.deepcopy(self.ledger)
                if mode == 'missing':
                    self.ledger['dependency_sha256'].pop('Cargo.lock')
                elif mode == 'extra':
                    self.ledger['dependency_sha256']['unexpected'] = '0' * 64
                else:
                    self.ledger['dependency_sha256']['crates/php-profiler/build.rs'] = '0' * 64
                self.sync_ledger()
                with self.assertRaises(ValueError):
                    self.derive()
                self.ledger = old
                self.sync_ledger()

    def test_changed_context_status_replacement_or_source_span_is_rejected(self):
        for field in ('context', 'status', 'replacement', 'span'):
            with self.subTest(field=field):
                changed = copy.deepcopy(self.ledger)
                if field == 'context':
                    changed['contexts']['nts']['flags'] = ['-O2']
                elif field == 'span':
                    changed['equivalents'][0]['location']['line'] = 11
                else:
                    changed['equivalents'][0][field] = 'changed'
                self.target[MODULE.LEDGER] = json.dumps(changed).encode()
                with self.assertRaises(ValueError):
                    self.derive()
        self.target[MODULE.LEDGER] = self.base[MODULE.LEDGER]

    def test_unexpected_lock_text_edits_are_rejected_even_if_ast_is_equal(self):
        self.target['Cargo.lock'] += b'\n# harmless-looking unaudited edit\n'
        with self.assertRaises(ValueError):
            self.derive()

    def test_cli_applies_only_verified_hashes_emits_receipt_and_rejects_extra_build_flag(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            fixture = dict(self.base)
            fixture['CHANGELOG.md'] = b'# Changelog\n'
            fixture['.gitignore'] = b'target/\n'
            for name, body in fixture.items():
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            helper = root / 'scripts/validate-version-transition.py'
            shutil.copyfile(SPEC.origin, helper)
            def git(*args):
                return subprocess.check_output(['git', *args], cwd=root, stderr=subprocess.PIPE)
            git('init', '-b', 'main')
            git('config', 'user.name', 'Release Test')
            git('config', 'user.email', 'release@example.invalid')
            git('add', *fixture.keys(), 'scripts/validate-version-transition.py')
            git('commit', '-m', 'feat: audited fixture')
            for name in ('Cargo.toml', 'Cargo.lock'):
                (root / name).write_bytes(self.target[name])
            subprocess.run(['python3', str(helper), 'HEAD', self.version, '--apply'], cwd=root,
                           check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            expected, updates = self.derive()
            self.assertEqual((root / MODULE.LEDGER).read_bytes(), expected)
            receipt = json.loads((root / 'target/version-transition.json').read_text())
            self.assertEqual(receipt['dependency_updates'], updates)
            self.assertEqual(receipt['unchanged_dependency_count'], 23)
            subprocess.run(['python3', str(helper), 'HEAD', self.version], cwd=root,
                           check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            flags = root / '.cargo/config.toml'
            flags.parent.mkdir()
            flags.write_text('[build]\nrustflags = ["-Ctarget-cpu=native"]\n')
            git('add', '.cargo/config.toml')
            result = subprocess.run(['python3', str(helper), 'HEAD', self.version, '--apply'], cwd=root,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(b'Unexpected release transition files', result.stderr)
            self.assertEqual((root / MODULE.LEDGER).read_bytes(), expected)

    def test_same_or_unsupported_version_is_rejected(self):
        for version in ('0.1.0', '1.0.0', '0.1.0-alpha.0'):
            with self.subTest(version=version), self.assertRaises(ValueError):
                MODULE.expected_transition(self.base.__getitem__, self.target.__getitem__, version)


if __name__ == '__main__':
    unittest.main()
