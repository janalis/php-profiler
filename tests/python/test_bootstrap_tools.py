"""Pinned tool downloads reject ambiguous archives and preserve prior installs."""
import hashlib
import importlib.util
import io
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('bootstrap_tools', ROOT/'scripts/bootstrap-tools.py')
bootstrap = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bootstrap)


def archive(*names, symbolic=False):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as tar:
        for name in names:
            member = tarfile.TarInfo(name)
            if symbolic:
                member.type = tarfile.SYMTYPE
                member.linkname = '/bin/sh'
                tar.addfile(member)
            else:
                member.size = 4
                tar.addfile(member, io.BytesIO(b'tool'))
    return output.getvalue()


class BootstrapTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.destination = Path(self.temp.name)

    def install(self, data, digest=None):
        with patch.object(bootstrap.subprocess, 'check_output', return_value=data):
            bootstrap.install_archive('tool', {'url': 'https://example.invalid/tool', 'sha256': digest or hashlib.sha256(data).hexdigest()}, self.destination)

    def test_install_only_named_member(self):
        self.install(archive('../../tool', 'ignored'))
        self.assertEqual((self.destination/'tool').read_bytes(), b'tool')
        self.assertEqual((self.destination/'tool').stat().st_mode & 0o777, 0o755)

    def test_checksum_mismatch(self):
        with self.assertRaisesRegex(RuntimeError, 'checksum mismatch'):
            self.install(archive('tool'), '0'*64)
        self.assertEqual(list(self.destination.iterdir()), [])

    def test_missing_ambiguous_and_symlink_members(self):
        for data in (archive('other'), archive('tool', 'nested/tool'), archive('tool', symbolic=True)):
            with self.subTest(data=data), self.assertRaisesRegex(RuntimeError, 'one regular'):
                self.install(data)

    def test_replace_failure_preserves_previous_binary_and_cleans_temporary(self):
        binary = self.destination/'tool'
        binary.write_bytes(b'previous')
        with patch.object(bootstrap.os, 'replace', side_effect=PermissionError('fixture')):
            with self.assertRaises(PermissionError):
                self.install(archive('tool'))
        self.assertEqual(binary.read_bytes(), b'previous')
        self.assertEqual(list(self.destination.iterdir()), [binary])

    def test_unsupported_platform(self):
        with self.assertRaisesRegex(RuntimeError, 'Unsupported'):
            bootstrap.cohort({}, 'Windows', 'arm64')

    def test_download_failure_does_not_install(self):
        with patch.object(bootstrap.subprocess, 'check_output', side_effect=OSError('fixture')):
            with self.assertRaises(OSError):
                bootstrap.install_archive('tool', {'url': 'invalid', 'sha256': 'unused'}, self.destination)
        self.assertEqual(list(self.destination.iterdir()), [])

    def test_dependency_install_failure_is_not_hidden(self):
        import subprocess
        with patch.object(bootstrap, 'install_archive'), patch.object(bootstrap.platform, 'system', return_value='Darwin'), patch.object(bootstrap.platform, 'machine', return_value='arm64'), patch.object(bootstrap.subprocess, 'run', side_effect=[None, subprocess.CalledProcessError(1, 'pip')]):
            with self.assertRaises(subprocess.CalledProcessError):
                bootstrap.main()
