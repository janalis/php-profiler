"""C mutation evidence must cover the entire runtime registry without survivors."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/check-c-mutations.py'

class CMutationGateTests(unittest.TestCase):
    def run_gate(self, status='Killed', missing=False, empty=False, extra=False):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'crates/adapter/src/bridge.c'
            source.parent.mkdir(parents=True)
            source.write_text('int native(void) { return 1; }')
            report = root / 'reports'; report.mkdir()
            files = {} if missing else {str(source): {'mutants': [] if empty else [{'status': status}]}}
            if extra:
                files[str(root / 'tests/fault.c')] = {'mutants': [{'status': 'Killed'}]}
            (report / 'elements.json').write_text(json.dumps({'files': files}))
            return subprocess.run([sys.executable, str(SCRIPT), str(report), '--workspace-root', str(root)], capture_output=True, text=True)

    def test_all_registered_source_mutants_must_be_killed(self):
        result = self.run_gate()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_survivor_is_not_treated_as_equivalent(self):
        self.assertNotEqual(self.run_gate(status='Survived').returncode, 0)

    def test_missing_empty_or_extra_source_is_rejected(self):
        for options in ({'missing': True}, {'empty': True}, {'extra': True}):
            with self.subTest(options=options):
                self.assertNotEqual(self.run_gate(**options).returncode, 0)


class CEquivalenceLedgerTests(unittest.TestCase):
    def run_ledger(self, change=None, context=None):
        import copy
        import hashlib
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'crates/adapter/src/bridge.c'
            source.parent.mkdir(parents=True)
            source.write_text('audited compare exchange loop\n')
            proof = root / 'docs/equivalence.md'
            proof.parent.mkdir(); proof.write_text('reviewed proof')
            entry = {'source': 'crates/adapter/src/bridge.c',
                     'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
                     'mutatorName': 'cxx_init_const',
                     'location': {'start': {'line': 1, 'column': 1}, 'end': {'line': 1, 'column': 6}},
                     'replacement': '42', 'proof': 'docs/equivalence.md'}
            mutant = {key: copy.deepcopy(entry[key]) for key in ('mutatorName', 'location', 'replacement')}
            mutant.update(id='equivalent-initial-guess', status='Survived')
            mutants = [{'id': 'ordinary', 'status': 'Killed'}, mutant]
            ledger = {'schema_version': 1, 'equivalents': [entry]}
            if change:
                change(entry, mutants, ledger)
            report = root / 'reports'; report.mkdir()
            if context is not None:
                (report / 'provenance').mkdir()
                (report / 'provenance/equivalence-context.json').write_text(json.dumps(context))
            (report / 'elements.json').write_text(json.dumps({'files': {str(source): {'mutants': mutants}}}))
            ledger_path = root / 'ledger.json'; ledger_path.write_text(json.dumps(ledger))
            result = subprocess.run([sys.executable, str(SCRIPT), str(report), '--workspace-root', str(root),
                                     '--equivalence-ledger', str(ledger_path)], capture_output=True, text=True)
            classification = report / 'provenance/classification.json'
            return result, json.loads(classification.read_text()) if classification.exists() else None

    def audited_context(self):
        return {'compiler_sha256': 'compiler', 'compiler_version': 'version',
                'mull_version': 'mull', 'plugin_sha256': 'audited', 'compiler_flags': ['-O0'],
                'native_compile_commands': {'bridge-unit.c': ['-O0'], 'sampling-unit.c': ['-O0']},
                'native_fixture_symbols': ['__wrap_clock_gettime'],
                'system': 'Linux', 'machine': 'x86_64', 'php_zts': False,
                'php_version_id': 80336, 'php_int_size': 8, 'rustc_version': 'stable',
                'rustflags': '', 'cargo_encoded_rustflags': '', 'libc_sha256': 'libc'}

    def context_audit(self, contexts=None, dependencies=None):
        def change(entry, mutants, ledger):
            ledger['schema_version'] = 2
            values = contexts if contexts is not None else [self.audited_context()]
            ledger['contexts'] = {str(index): value for index, value in enumerate(values)}
            entry['contexts'] = list(ledger['contexts'])
            ledger['dependency_sha256'] = dependencies if dependencies is not None else {
                entry['source']: entry['source_sha256'],
                'docs/equivalence.md': __import__('hashlib').sha256(b'reviewed proof').hexdigest()}
        return change

    def test_scoped_equivalence_requires_exact_typed_context(self):
        valid = {**self.audited_context(), 'extra': 'allowed'}
        result, evidence = self.run_ledger(self.context_audit(), context=valid)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(evidence['audited_equivalent_count'], 1)
        for context in (None, {}, {**valid, 'php_zts': 0},
                        {**valid, 'php_zts': True}, {**valid, 'plugin_sha256': 'different'}):
            with self.subTest(context=context):
                result, _ = self.run_ledger(self.context_audit(), context=context)
                self.assertNotEqual(result.returncode, 0)

    def test_nested_configuration_types_are_exact(self):
        audit = self.context_audit(contexts=[{**self.audited_context(), 'flags': {'enabled': False}}])
        result, _ = self.run_ledger(audit, context={**self.audited_context(), 'flags': {'enabled': 0}})
        self.assertNotEqual(result.returncode, 0)
        result, _ = self.run_ledger(audit, context={**self.audited_context(), 'flags': {'enabled': False}})
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_scoped_equivalence_rejects_missing_or_changed_dependencies(self):
        for dependencies in ({}, {'missing': '0'*64}, {'../outside': '0'*64},
                             {'crates/adapter/src/bridge.c': '0'*64}):
            result, _ = self.run_ledger(self.context_audit(dependencies=dependencies),
                                       context=self.audited_context())
            self.assertNotEqual(result.returncode, 0)

    def test_scoped_equivalence_requires_the_proof_bytes_to_be_hashed(self):
        def change(entry, mutants, ledger):
            self.context_audit()(entry, mutants, ledger)
            del ledger['dependency_sha256']['docs/equivalence.md']
        result, _ = self.run_ledger(change, context=self.audited_context())
        self.assertNotEqual(result.returncode, 0)

    def test_scoped_equivalence_requires_nonempty_context_maps(self):
        for contexts in ([], [{}], [{'php_zts': False}], ['invalid'], None):
            change = self.context_audit(contexts=contexts)
            if contexts is None:
                def change(entry, mutants, ledger):
                    ledger['schema_version'] = 2
            result, _ = self.run_ledger(change, context={})
            self.assertNotEqual(result.returncode, 0)

    def test_unknown_and_duplicate_context_references_fail_closed(self):
        for names in (['missing'], ['0', '0'], []):
            def change(entry, mutants, ledger):
                self.context_audit()(entry, mutants, ledger)
                entry['contexts'] = names
            result, _ = self.run_ledger(change, context=self.audited_context())
            self.assertNotEqual(result.returncode, 0)

    def test_exact_audit_preserves_raw_survived_and_separate_denominator(self):
        result, evidence = self.run_ledger()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(evidence['raw_outcomes'], {'Killed': 1, 'Survived': 1})
        self.assertEqual(evidence['raw_killed'], 1)
        self.assertEqual(evidence['raw_generated'], 2)
        self.assertEqual(evidence['audited_equivalent_count'], 1)
        self.assertEqual(evidence['non_equivalent_generated'], 1)

    def test_changed_source_hash_requires_a_new_audit(self):
        result, _ = self.run_ledger(lambda entry, *_: entry.update(source_sha256='0' * 64))
        self.assertNotEqual(result.returncode, 0)

    def test_changed_span_replacement_or_mutator_is_not_equivalent(self):
        for change in (lambda entry, *_: entry['location']['start'].update(column=2),
                       lambda entry, *_: entry.update(replacement='0'),
                       lambda entry, *_: entry.update(mutatorName='another_mutator')):
            result, _ = self.run_ledger(change)
            self.assertNotEqual(result.returncode, 0)

    def test_extra_survivor_or_timeout_cannot_use_the_ledger(self):
        for status in ('Survived', 'Timeout'):
            def add_extra(entry, mutants, ledger):
                mutants.append({'id': 'unclassified', 'status': status,
                                'mutatorName': 'different', 'location': entry['location'], 'replacement': '42'})
            result, _ = self.run_ledger(add_extra)
            self.assertNotEqual(result.returncode, 0)

    def test_duplicate_audits_are_rejected(self):
        result, _ = self.run_ledger(lambda entry, mutants, ledger: ledger['equivalents'].append(entry))
        self.assertNotEqual(result.returncode, 0)

    def test_duplicate_raw_equivalent_matches_are_rejected(self):
        import copy
        result, _ = self.run_ledger(lambda entry, mutants, ledger: mutants.append(copy.deepcopy(mutants[-1])))
        self.assertNotEqual(result.returncode, 0)

    def test_zero_non_equivalent_candidates_is_not_a_score(self):
        result, _ = self.run_ledger(lambda entry, mutants, ledger: mutants.pop(0))
        self.assertNotEqual(result.returncode, 0)

if __name__ == '__main__':
    unittest.main()
