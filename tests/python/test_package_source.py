"""PIE source archive safety and reproducibility regression tests."""
from pathlib import Path
import subprocess
import tempfile
import unittest
import shutil
import tarfile
import io
import os

ROOT = Path(__file__).resolve().parents[2]

class PackageSourceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'scripts').mkdir()
        (self.root / 'packaging').mkdir()
        shutil.copy(ROOT / 'scripts/package-source.py', self.root / 'scripts/package-source.py')
        (self.root / 'Cargo.toml').write_text('[workspace.package]\nversion = "0.1.0-alpha.1"\n')
        for filename in ('config.m4', 'Makefile.frag'):
            (self.root / 'packaging' / filename).write_text('fixture\n')
        self.git('init', '-q')
        self.git('add', 'scripts', 'packaging', 'Cargo.toml')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'feat: fixture')

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root)

    def package(self, output='first.tgz'):
        return subprocess.run(['python3', 'scripts/package-source.py', '--output', output], cwd=self.root, capture_output=True)

    def test_reproducible_and_complete(self):
        self.assertEqual(self.package().returncode, 0)
        self.assertEqual(self.package('second.tgz').returncode, 0)
        self.assertEqual((self.root/'first.tgz').read_bytes(), (self.root/'second.tgz').read_bytes())
        with tarfile.open(self.root/'first.tgz') as archive:
            self.assertNotIn('package.xml', archive.getnames())
            self.assertIn('php-profiler-0.1.0-alpha.1/packaging/config.m4', archive.getnames())

    def test_dirty_source_rejected(self):
        (self.root/'Cargo.toml').write_text('changed')
        result = self.package()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'Commit all tracked changes', result.stderr)

    def test_metadata_and_archive_use_same_committed_version(self):
        (self.root/'Cargo.toml').write_text('[workspace.package]\nversion = "0.2.0-alpha.1"\n')
        self.git('add', 'Cargo.toml')
        self.assertNotEqual(self.package().returncode, 0)
        self.assertFalse((self.root/'first.tgz').exists())

    def test_development_source_supported(self):
        (self.root/'Cargo.toml').write_text('[workspace.package]\nversion = "0.1.0"\n')
        self.git('add', 'Cargo.toml')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'chore: stable')
        self.assertEqual(self.package().returncode, 0)

    def test_git_modes_and_vendored_sources_preserved(self):
        vendor = self.root/'vendor/ext-php-rs/src'
        vendor.mkdir(parents=True)
        (vendor/'module.rs').write_text('patched source')
        executable = self.root/'scripts/probe'
        executable.write_text('#!/bin/sh\nexit 0\n')
        executable.chmod(0o755)
        (self.root/'scripts/link').symlink_to('probe')
        self.git('add', 'vendor', 'scripts')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'test: source modes')
        self.assertEqual(self.package().returncode, 0)
        with tarfile.open(self.root/'first.tgz') as archive:
            prefix = 'php-profiler-0.1.0-alpha.1/'
            self.assertEqual(archive.getmember(prefix+'scripts/probe').mode, 0o755)
            self.assertTrue(archive.getmember(prefix+'scripts/link').issym())
            self.assertEqual(archive.extractfile(prefix+'vendor/ext-php-rs/src/module.rs').read(), b'patched source')

    def test_committed_export_rules_exclude_only_marked_files(self):
        (self.root/'.gitattributes').write_text('/private.txt export-ignore\n')
        (self.root/'private.txt').write_text('fixture excluded file')
        (self.root/'Cargo.lock').write_text('fixture lock')
        (self.root/'rust-toolchain.toml').write_text('[toolchain]\nchannel = "stable"\n')
        self.git('add', '.gitattributes', 'private.txt', 'Cargo.lock', 'rust-toolchain.toml')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'test: archive rules')
        self.assertEqual(self.package().returncode, 0)
        with tarfile.open(self.root/'first.tgz') as archive:
            names = archive.getnames()
            self.assertNotIn('php-profiler-0.1.0-alpha.1/private.txt', names)
            self.assertIn('php-profiler-0.1.0-alpha.1/Cargo.lock', names)
            self.assertIn('php-profiler-0.1.0-alpha.1/rust-toolchain.toml', names)

    def test_smoke_rejects_archive_without_composer_root(self):
        archive = self.root/'invalid.tar.gz'
        with tarfile.open(archive, 'w:gz') as output:
            member = tarfile.TarInfo('unexpected/README')
            member.size = 1
            output.addfile(member, io.BytesIO(b'x'))
        phar = self.root/'pie.phar'
        phar.write_text('unused')
        environment = dict(os.environ, PIE_PHAR=str(phar), PHP_CONFIG='/usr/bin/true', PHPIZE='/usr/bin/true', PHP_BINARY='/usr/bin/true')
        result = subprocess.run(['bash', str(ROOT/'scripts/package-install-smoke.sh'), str(archive)], env=environment, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'Expected one PIE source root', result.stderr)
