"""Build recipes and dependencies are validation inputs, caches never are."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('validation_inputs', Path(__file__).resolve().parents[2] / 'scripts/validation_inputs.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class ValidationInputTests(unittest.TestCase):
    def test_recipes_and_dependencies_are_fingerprinted_without_build_caches(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            expected = {'Makefile', 'mago.toml', '.clang-format', 'composer.json', 'package-lock.json', '.githooks/pre-commit', 'packaging/config.m4', 'packaging/Makefile.frag', 'php/Profiler.php', '.dockerignore', 'requirements-dev.txt', 'Dockerfile',
                        'tests/docker/Dockerfile', 'tests/docker/ClangCoverage.Dockerfile',
                        'crates/new/src/runtime.rs', '.github/workflows/ci.yaml',
                        'tests/native/c-equivalents.json', 'schema/new-version.json'}
            ignored = {'tests/support/example/target/build.rs',
                       'scripts/__pycache__/cached.py', 'tests/mutants.out/copy.rs'}
            for name in expected | ignored:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(name)
            before = module.snapshot(root)
            self.assertEqual(set(before), expected)
            (root / 'tests/docker/Dockerfile').write_text('new compiler')
            self.assertNotEqual(module.snapshot(root)['tests/docker/Dockerfile'], before['tests/docker/Dockerfile'])

if __name__ == '__main__':
    unittest.main()
