"""A successful protected merge remains resumable after main dispatch fails."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('release_automation', Path(__file__).resolve().parents[2] / 'scripts/release-automation.py')
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class Transport:
    base = 'a' * 40
    source = 'b' * 40
    merged = 'c' * 40
    branch = 'release/0.1.0-alpha.1-' + base[:12]

    def __init__(self):
        self.main = self.base
        self.branch_exists = True
        self.fail_dispatch = True
        self.merges = 0
        self.dispatches = []
        self.calls = []
        self.tree = 'd' * 40
        self.pr = {'number': 17, 'headRefOid': self.source, 'baseRefName': 'main',
                   'author': {'login': 'github-actions[bot]'}, 'state': 'OPEN', 'mergeCommit': None}
        self.quality = {'name': 'quality', 'conclusion': 'success', 'head_sha': self.source,
                        'head_branch': self.branch, 'head_repository': {'full_name': MODULE.REPOSITORY},
                        'event': 'workflow_dispatch', 'actor': {'login': 'github-actions[bot]'}}

    def output(self, args, **kwargs):
        self.calls.append(tuple(args))
        if args == ['git', 'rev-parse', 'HEAD']:
            return self.main + '\n'
        if args == ['git', 'rev-parse', self.source + '^']:
            return self.base + '\n'
        if args == ['git', 'rev-parse', self.merged + '^']:
            return self.base + '\n'
        if args == ['git', 'rev-parse', self.merged + '^{tree}']:
            return self.tree + '\n'
        if args == ['git', 'rev-parse', self.source + '^{tree}']:
            return 'd' * 40 + '\n'
        if args[:2] == ['gh', 'api']:
            suffix = args[2].removeprefix('repos/' + MODULE.REPOSITORY + '/')
            if suffix == 'actions/runs/42':
                return json.dumps(self.quality)
            if suffix == 'git/ref/heads/main':
                return json.dumps({'object': {'sha': self.main}})
            if suffix == 'git/ref/heads/' + self.branch:
                if not self.branch_exists:
                    raise AssertionError('Deleted branch must not be queried during recovery')
                return json.dumps({'object': {'sha': self.source}})
        if args[:3] == ['gh', 'pr', 'list']:
            assert '--state' in args and args[args.index('--state') + 1] == 'all'
            return json.dumps([self.pr])
        if args[:3] == ['gh', 'pr', 'view']:
            return json.dumps(self.pr)
        raise AssertionError(('Unexpected output command', args))

    def run(self, args, **kwargs):
        self.calls.append(tuple(args))
        if args == ['git', 'fetch', 'origin', 'refs/pull/17/head']:
            return subprocess.CompletedProcess(args, 0)
        if args[:3] == ['gh', 'pr', 'merge']:
            assert self.pr['state'] == 'OPEN'
            assert args[-2:] == ['--match-head-commit', self.source]
            self.merges += 1
            self.main = self.merged
            self.pr.update(state='MERGED', mergeCommit={'oid': self.merged})
            self.branch_exists = False
            return subprocess.CompletedProcess(args, 0)
        if args[:3] == ['gh', 'workflow', 'run']:
            assert args == ['gh', 'workflow', 'run', 'ci.yml', '--repo', MODULE.REPOSITORY,
                            '--ref', 'main', '-f', 'expected_sha=' + self.merged]
            self.dispatches.append(self.merged)
            if self.fail_dispatch:
                raise subprocess.CalledProcessError(1, args)
            return subprocess.CompletedProcess(args, 0)
        raise AssertionError(('Unexpected mutation command', args))


class ReleaseRecoveryTests(unittest.TestCase):
    def invoke(self, transport):
        with patch.object(sys, 'argv', ['release-automation', '42']), \
                patch.object(MODULE.subprocess, 'check_output', side_effect=transport.output), \
                patch.object(MODULE.subprocess, 'run', side_effect=transport.run), \
                patch.object(MODULE, 'validate_candidate') as validate:
            MODULE.main()
            validate.assert_called_once_with(transport.base, transport.source, '0.1.0-alpha.1')

    def merged_transport(self):
        transport = Transport()
        transport.main = transport.merged
        transport.branch_exists = False
        transport.pr.update(state='MERGED', mergeCommit={'oid': transport.merged})
        transport.fail_dispatch = False
        return transport

    def test_merge_then_failed_dispatch_retries_without_deleted_branch_or_duplicate_merge(self):
        transport = Transport()
        with self.assertRaises(subprocess.CalledProcessError):
            self.invoke(transport)
        self.assertEqual(transport.merges, 1)
        self.assertFalse(transport.branch_exists)
        transport.fail_dispatch = False
        self.invoke(transport)
        self.assertEqual(transport.merges, 1)
        self.assertEqual(transport.dispatches, [transport.merged, transport.merged])
        self.assertEqual(len(transport.calls), 20)

    def test_recovery_rejects_foreign_author_wrong_head_and_wrong_base(self):
        for field, value in [('author', {'login': 'someone-else'}), ('headRefOid', 'e' * 40),
                             ('baseRefName', 'other')]:
            with self.subTest(field=field):
                transport = self.merged_transport()
                transport.pr[field] = value
                with self.assertRaisesRegex(ValueError, 'Expected one bot pull request'):
                    self.invoke(transport)
                self.assertEqual(transport.dispatches, [])
                self.assertEqual(transport.merges, 0)

    def test_recovery_rejects_missing_or_unrelated_merge_and_advanced_main(self):
        for merge_commit, main in [(None, 'c' * 40), ({'oid': 'e' * 40}, 'c' * 40),
                                   ({'oid': 'c' * 40}, 'e' * 40)]:
            with self.subTest(merge_commit=merge_commit, main=main):
                transport = self.merged_transport()
                transport.pr['mergeCommit'] = copy.deepcopy(merge_commit)
                transport.main = main
                with self.assertRaisesRegex(ValueError, 'no longer identifies current main'):
                    self.invoke(transport)
                self.assertEqual(transport.dispatches, [])

    def test_recorded_merge_must_have_the_validated_candidate_tree(self):
        transport = self.merged_transport()
        transport.tree = 'e' * 40
        with self.assertRaisesRegex(ValueError, 'differs from the validated squash candidate'):
            self.invoke(transport)
        self.assertEqual(transport.dispatches, [])

    def test_recovery_preserves_quality_actor_and_repository_guards(self):
        for field, value in [('actor', {'login': 'someone-else'}),
                             ('head_repository', {'full_name': 'other/repository'})]:
            with self.subTest(field=field):
                transport = self.merged_transport()
                transport.quality[field] = value
                with self.assertRaises(ValueError):
                    self.invoke(transport)
                self.assertEqual(transport.dispatches, [])
                self.assertEqual(transport.merges, 0)


if __name__ == '__main__':
    unittest.main()
