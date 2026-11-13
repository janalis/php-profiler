import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[2] / 'scripts/analyze-c-coverage.py'
spec = importlib.util.spec_from_file_location('coverage_analysis', path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

NOTES = """FUNCTION ident=1, lineno_checksum=0xab, cfg_checksum=0xcd, `f' src/bridge.c:1:1-3:1
block 6: 7:0004(fall) 8:0001(tree)
block 6:`src/bridge.c':2
"""
TREE = """;; Function f (f, funcdef_no=1)
<bb 6> :
persistent_30 = 0;
[/usr/include/php/Zend/zend_string.h:176:15] if (persistent_30 != 0)
 goto <bb 7>; [INV]
else
 goto <bb 8>; [INV]
"""


def data(complete=False):
    return {'gcc_version': '12.2.0', 'files': [{'file': 'src/bridge.c',
        'functions': [{'name': 'f', 'execution_count': 1}],
        'lines': [{'line_number': 2, 'function_name': 'f', 'count': 1,
                   'branches': [{'count': int(complete)}, {'count': 1}]}]}]}


class CoverageAnalysisTests(unittest.TestCase):
    def analyze(self, tree=TREE, rebuilt=NOTES):
        return module.analyze(data(), 'src/bridge.c', module.notes(NOTES),
                              module.notes(rebuilt), module.tree_functions(tree))

    def test_constant_header_proof_does_not_change_raw_gate(self):
        result = self.analyze()
        self.assertFalse(result['raw_gate_pass'])
        self.assertEqual(result['raw_counts']['branches'], {'covered': 1, 'count': 2})
        self.assertEqual(result['explained_constant_header_outcomes'], 1)
        self.assertEqual(result['unresolved_outcomes'], 0)
        self.assertIsNone(result['adjusted_coverage_percentage'])

    def test_first_party_constant_is_never_classified_as_external(self):
        result = self.analyze(tree=TREE.replace('/usr/include/php/Zend/zend_string.h', 'src/bridge.c'))
        self.assertEqual(result['unresolved_outcomes'], 1)

    def test_stale_cfg_and_nonconstant_ssa_fail_closed(self):
        self.assertEqual(self.analyze(rebuilt=NOTES.replace('0xcd', '0xef'))['unresolved_outcomes'], 1)
        self.assertEqual(self.analyze(tree=TREE.replace('persistent_30 = 0;', 'persistent_30 = input;'))['unresolved_outcomes'], 1)

    def test_ssa_alias_chain_is_resolved_but_cycle_is_not(self):
        alias = TREE.replace('persistent_30 = 0;', 'initial_1 = 0;\npersistent_30 = initial_1;')
        self.assertEqual(self.analyze(tree=alias)['explained_constant_header_outcomes'], 1)
        cycle = TREE.replace('persistent_30 = 0;', 'initial_1 = persistent_30;\npersistent_30 = initial_1;')
        self.assertEqual(self.analyze(tree=cycle)['unresolved_outcomes'], 1)

    def test_missing_evidence_does_not_explain_gap(self):
        result = module.analyze(data(), 'src/bridge.c')
        self.assertFalse(result['raw_gate_pass'])
        self.assertEqual(result['unresolved_outcomes'], 1)

    def test_complete_raw_data_passes_without_adjustment(self):
        result = module.analyze(data(True), 'src/bridge.c')
        self.assertTrue(result['raw_gate_pass'])
        self.assertEqual(result['uncovered_outcomes'], [])

    def test_ambiguous_source_block_and_missing_source_fail_closed(self):
        extra = NOTES + "block 9: 10:0004(fall) 11:0001(tree)\nblock 9:`src/bridge.c':2\n"
        result = module.analyze(data(), 'src/bridge.c', module.notes(extra), module.notes(extra), module.tree_functions(TREE))
        self.assertEqual(result['unresolved_outcomes'], 1)
        with self.assertRaises(ValueError):
            module.analyze({'files': []}, 'src/bridge.c')


if __name__ == '__main__':
    unittest.main()
