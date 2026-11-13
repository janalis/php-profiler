"""Repository controls preserve evidence and reject invalid squash subjects."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class RepositoryToolTests(unittest.TestCase):
    def test_clean_preserves_evidence_and_tools(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'scripts').mkdir()
            shutil.copy2(ROOT / 'scripts/clean-builds.py', root / 'scripts/clean-builds.py')
            for name in ('debug', 'release', 'package-build', 'package-smoke', 'tools', 'historical-evidence'):
                directory = root / 'target' / name
                directory.mkdir(parents=True)
                (directory / 'receipt').write_text(name)
            subprocess.run(['python3', str(root / 'scripts/clean-builds.py')], check=True)
            self.assertEqual(sorted(path.name for path in (root / 'target').iterdir()), ['historical-evidence', 'tools'])

    def test_conventional_titles(self):
        for title, accepted in [('fix(api): isolate requests', True), ('feat!: new API', True), ('chore(release): prepare 0.1.0-alpha.1', True), ('unstructured title', False), ('fix: okay\nfeat: injected', False)]:
            with self.subTest(title=title):
                result = subprocess.run(['python3', str(ROOT / 'scripts/check-pr-title.py')], env={**os.environ, 'PR_TITLE': title}, capture_output=True)
                self.assertEqual(result.returncode == 0, accepted)
