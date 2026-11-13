"""PECL source archive safety and reproducibility regression tests."""
from pathlib import Path
import subprocess
import tempfile
import unittest
import shutil
import tarfile

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
        return subprocess.run(['python3', 'scripts/package-source.py', '--maintainer', 'fixture', '--name', 'Fixture', '--email', 'fixture@example.invalid', '--output', output], cwd=self.root, capture_output=True)

    def test_reproducible_and_complete(self):
        self.assertEqual(self.package().returncode, 0)
        self.assertEqual(self.package('second.tgz').returncode, 0)
        self.assertEqual((self.root/'first.tgz').read_bytes(), (self.root/'second.tgz').read_bytes())
        with tarfile.open(self.root/'first.tgz') as archive:
            self.assertIn('package.xml', archive.getnames())
            self.assertIn('php_profiler-0.1.0alpha1/config.m4', archive.getnames())

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

    def test_stable_source_rejected(self):
        (self.root/'Cargo.toml').write_text('[workspace.package]\nversion = "0.1.0"\n')
        self.git('add', 'Cargo.toml')
        self.git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'chore: stable')
        self.assertIn(b'Only alpha', self.package().stderr)
