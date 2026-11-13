"""CI labels must describe actual installed runtimes and matching headers."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/php-environment-check.py'


class PhpEnvironmentTests(unittest.TestCase):
    def check(self, version='8.3.36', zts=False, header='8.3.36', desired_zts='nts', failure=0):
        with tempfile.TemporaryDirectory(prefix='profiler-php-label-') as temporary:
            root = Path(temporary)
            php, config = root / 'php executable', root / 'php-config executable'
            php.write_text('#!/bin/sh\nprintf "%s" ' + shlex.quote(json.dumps({'php_version': version, 'zts': zts, 'sapi': 'cli'})) + '\nexit ' + str(failure) + '\n')
            config.write_text('#!/bin/sh\nprintf "%s\\n" ' + shlex.quote(header) + '\n')
            php.chmod(0o755); config.chmod(0o755)
            return subprocess.run([sys.executable, str(SCRIPT), '--version', '8.3', '--zts', desired_zts],
                                  env=dict(os.environ, PHP=str(php), PHP_CONFIG=str(config)), capture_output=True, text=True)

    def test_actual_labels_and_exact_header_version_pass(self):
        result = self.check()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(json.loads(result.stdout)['zts'])

    def test_wrong_minor_is_not_accepted_as_the_requested_cell(self):
        self.assertNotEqual(self.check(version='8.4.1').returncode, 0)

    def test_missing_thread_safety_cannot_duplicate_the_nts_cell(self):
        self.assertNotEqual(self.check(desired_zts='ts').returncode, 0)
        self.assertEqual(self.check(zts=True, desired_zts='ts').returncode, 0)

    def test_different_patch_headers_are_rejected(self):
        self.assertNotEqual(self.check(header='8.3.35').returncode, 0)

    def test_failed_php_command_is_not_accepted_even_with_valid_stdout(self):
        self.assertNotEqual(self.check(failure=73).returncode, 0)


if __name__ == '__main__':
    unittest.main()
