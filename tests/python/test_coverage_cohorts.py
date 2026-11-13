"""Derived source coverage unions retain actual cohort counters and identities."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('coverage_cohorts', Path(__file__).resolve().parents[2] / 'scripts/coverage_cohorts.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
SOURCE = '/coverage-fixture/runtime.rs'


def report(name='function::<u32>', count=1, true=1, false=0):
    return {'type': 'llvm.coverage.json.export', 'data': [{
        'functions': [{'name': name, 'count': count, 'filenames': [SOURCE],
                       'regions': [[1, 1, 1, 9, count, 0, 0, 0]],
                       'branches': [[1, 2, 1, 4, true, false, 0, 0, 4]]}],
        'files': [{'filename': SOURCE, 'segments': [[1, 1, count, True, True, False], [1, 9, 0, False, False, False]],
                   'branches': [[1, 2, 1, 4, true, false, 0, 0, 4]],
                   'summary': {'lines': {'count': 1, 'covered': int(count > 0)},
                               'functions': {'count': 1, 'covered': int(count > 0)},
                               'branches': {'count': 2, 'covered': int(true > 0) + int(false > 0)}}}]}]}


class CoverageCohortTests(unittest.TestCase):
    def test_complementary_cohorts_union_coverage_and_sum_actual_counters(self):
        merged = module.merge_reports([report(true=2, false=0), report(true=0, false=3)], {SOURCE})
        self.assertEqual(merged['data'][0]['totals']['branches'], {'count': 2, 'covered': 2})
        self.assertEqual(merged['instantiations'][0]['count'], 2)
        self.assertEqual(merged['instantiations'][0]['branches'][0][4:6], [2, 3])

    def test_repeated_unit_does_not_inflate_source_denominators(self):
        merged = module.merge_reports([report(count=2), report(count=2)], {SOURCE})
        self.assertEqual(merged['data'][0]['totals']['functions'], {'count': 1, 'covered': 1})
        self.assertEqual(merged['instantiations'][0]['regions'][0][4], 4)
        self.assertEqual(merged['data'][0]['totals']['branches'], {'count': 2, 'covered': 1})

    def test_generic_instances_and_raw_names_are_preserved(self):
        merged = module.merge_reports([report('function::<u32>'), report('function::<u64>')], {SOURCE})
        self.assertEqual(len(merged['instantiations']), 2)
        self.assertEqual(merged['data'][0]['totals']['functions']['count'], 1)
        merged = module.merge_reports([report('_Runit'), report('_Rnative')], {SOURCE}, lambda _: 'function::<u32>')
        self.assertEqual(merged['instantiations'][0]['raw_names'], ['_Rnative', '_Runit'])

    def test_same_instantiation_with_incompatible_boundaries_fails(self):
        changed = report()
        changed['data'][0]['functions'][0]['regions'].append([2, 1, 2, 4, 1, 0, 0, 0])
        with self.assertRaisesRegex(ValueError, 'Incompatible coverage boundaries'):
            module.merge_reports([report(), changed], {SOURCE})

    def test_changed_function_endpoint_cannot_create_a_new_identity(self):
        changed = report()
        changed['data'][0]['functions'][0]['regions'][0][3] = 10
        with self.assertRaisesRegex(ValueError, 'Incompatible coverage boundaries'):
            module.merge_reports([report(), changed], {SOURCE})

    def test_input_summary_is_verified_not_trusted(self):
        changed = report()
        changed['data'][0]['files'][0]['summary']['branches']['covered'] = 2
        with self.assertRaisesRegex(ValueError, 'Cannot reconstruct LLVM branches'):
            module.merge_reports([changed], {SOURCE})

    def test_missing_duplicate_and_unexpected_datasets_fail(self):
        with self.assertRaisesRegex(ValueError, 'absent'):
            module.merge_reports([report()], {SOURCE, '/coverage-fixture/missing.rs'})
        changed = report()
        changed['data'][0]['files'].append(copy.deepcopy(changed['data'][0]['files'][0]))
        with self.assertRaisesRegex(ValueError, 'Duplicate'):
            module.merge_reports([changed], {SOURCE})
        changed['type'] = 'invented'
        with self.assertRaisesRegex(ValueError, 'raw LLVM'):
            module.merge_reports([changed], {SOURCE})

    def test_multiline_function_lines_and_shared_physical_lines_remain_distinct(self):
        changed = report()
        second = copy.deepcopy(changed['data'][0]['functions'][0])
        second['name'] = 'closure'
        second['regions'][0][:4] = [1, 10, 1, 15]
        second['branches'] = []
        changed['data'][0]['functions'].append(second)
        changed['data'][0]['files'][0]['summary']['lines'] = {'count': 2, 'covered': 2}
        changed['data'][0]['files'][0]['summary']['functions'] = {'count': 2, 'covered': 2}
        merged = module.merge_reports([changed], {SOURCE})
        self.assertEqual(merged['data'][0]['totals']['lines'], {'count': 2, 'covered': 2})

    def test_invalid_region_and_branch_layouts_fail(self):
        for key in ('regions', 'branches'):
            changed = report()
            changed['data'][0]['functions'][0][key][0].append(0)
            with self.assertRaisesRegex(ValueError, 'Unsupported LLVM function region'):
                module.merge_reports([changed], {SOURCE})
