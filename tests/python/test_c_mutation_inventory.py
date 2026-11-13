"""Native inventory identities retain every ordinary source callsite."""
import importlib.util
from pathlib import Path
import sys
import tempfile
import unittest

SCRIPTS = Path(__file__).resolve().parents[2] / 'scripts'
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location('c_inventory_gate', SCRIPTS / 'check-c-mutation-inventory.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class InventoryIdentityTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve()
        self.source = self.root / 'crates/adapter/src/bridge.c'
        self.source.parent.mkdir(parents=True)
        self.source.write_text('native source')

    def parse(self, text):
        return module.identities(text, self.root, {self.source})

    def test_function_identities_preserve_source_and_exact_span(self):
        identity = f'cxx_replace_scalar_call:{self.source}:64:8:64:44'
        result = self.parse('0000 t ' + identity + '\n0000 T ' + identity)
        self.assertEqual(result, {'cxx_replace_scalar_call:crates/adapter/src/bridge.c:64:8:64:44'})

    def test_data_symbols_and_other_functions_are_not_mutation_clones(self):
        self.assertEqual(self.parse('0000 r cxx_test:constant.1\n0000 t native_function\n'), set())

    def test_malformed_spans_are_rejected(self):
        for span in ('64:0:64:44', '64:8:64', '64:8:64:bad'):
            with self.subTest(span=span), self.assertRaises(ValueError):
                self.parse(f'0000 t cxx_test:{self.source}:{span}')

    def test_unregistered_mutation_sources_are_rejected(self):
        with self.assertRaises(ValueError):
            self.parse(f'0000 t cxx_test:{self.root}/tests/probe.c:1:1:1:2')


if __name__ == '__main__':
    unittest.main()
