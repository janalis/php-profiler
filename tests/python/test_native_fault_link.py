"""Test-only compiler/Cargo transports verify shell plumbing, not ELF freshness."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'scripts/link-native-faults.sh'


class NativeFaultLinkTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='profiler-link-contract-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name).resolve()
        self.tools = self.directory / 'tools'
        self.tools.mkdir()
        self.source = self.directory / 'tests/native/bailout-fault.c'
        self.source.parent.mkdir(parents=True)
        self.source.write_bytes(b'first native shim input\n')
        self.target = self.directory / 'build'
        self.target.mkdir()
        self.cc_log = self.directory / 'cc.jsonl'
        self.cargo_log = self.directory / 'cargo.jsonl'
        self.marker = self.directory / 'linked-marker'
        self.environment = dict(os.environ, PATH=str(self.tools) + os.pathsep + os.environ.get('PATH', ''),
                                CC=str(self.tools / 'fake-cc'), CARGO_TARGET_DIR=str(self.target),
                                CC_LOG=str(self.cc_log), CARGO_LOG=str(self.cargo_log),
                                PROFILER_LINK_TEST_SCRIPT=str(SCRIPT), LINK_MARKER=str(self.marker))
        for name in ('CFLAGS', 'RUSTFLAGS', 'PROFILER_COVERAGE_TOOLCHAIN',
                     'PROFILER_LINKED_BAILOUT_FAULT', 'FAKE_CC_FAIL', 'FAKE_OBJECT_CONSTANT', 'FAKE_CARGO_FAIL'):
            self.environment.pop(name, None)
        self.executable('php-config', "print('-I/test/include -I/test/ext')\n")
        self.executable('cargo', '''import json, os, sys
with open(os.environ['CARGO_LOG'], 'a') as stream:
    stream.write(json.dumps(sys.argv[1:]) + '\\n')
if os.environ.get('FAKE_CARGO_FAIL'):
    raise SystemExit(int(os.environ['FAKE_CARGO_FAIL']))
''')
        self.executable('fake-cc', '''import json, os, sys
from pathlib import Path
args = sys.argv[1:]
with open(os.environ['CC_LOG'], 'a') as stream:
    stream.write(json.dumps(args) + '\\n')
if os.environ.get('FAKE_CC_FAIL'):
    print('intentional compiler transport failure', file=sys.stderr)
    raise SystemExit(73)
index = args.index('-o')
source, target = Path(args[index - 1]), Path(args[index + 1])
contents = (b'constant object bytes' if os.environ.get('FAKE_OBJECT_CONSTANT')
            else b'test transport object\\0' + source.read_bytes())
target.write_bytes(contents)
''')

    def executable(self, name, body):
        path = self.tools / name
        path.write_text('#!' + sys.executable + '\n' + body)
        path.chmod(0o755)

    def invoke(self, environment=None, *, errexit=True):
        return subprocess.run(['bash', '-euc' if errexit else '-uc',
                               'source "$PROFILER_LINK_TEST_SCRIPT"; status=$?; '
                               'if [[ $status != 0 ]]; then exit "$status"; fi; '
                               'printf "%s\\n" "$PROFILER_LINKED_BAILOUT_FAULT" > "$LINK_MARKER"'],
                              cwd=self.directory, env=environment or self.environment,
                              capture_output=True, text=True, timeout=30)

    def cargo_calls(self):
        return [json.loads(line) for line in self.cargo_log.read_text().splitlines()]

    def link_object(self, call):
        paths = [arg.removeprefix('link-arg=') for arg in call
                 if arg.startswith('link-arg=') and arg.endswith('.o')]
        self.assertEqual(len(paths), 1, call)
        path = Path(paths[0])
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        self.assertEqual(path.name, 'bailout-fault-' + digest + '.o')
        self.assertNotEqual(path, self.target / 'bailout-fault.o')
        return path

    def test_repeated_identical_object_uses_identical_immutable_link_path(self):
        for _ in range(2):
            result = self.invoke()
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        calls = self.cargo_calls()
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0], calls[1])
        self.assertEqual(self.link_object(calls[0]), self.link_object(calls[1]))
        commands = [json.loads(line) for line in self.cc_log.read_text().splitlines()]
        self.assertEqual(commands[0], commands[1])
        self.assertEqual(commands[0][-1], str(self.target / 'bailout-fault.o'))
        self.assertEqual(self.marker.read_text(), '1\n')

    def test_changing_only_shim_input_changes_link_argument_and_preserves_old_object(self):
        first = self.invoke()
        self.assertEqual(first.returncode, 0, first.stderr)
        old_path = self.link_object(self.cargo_calls()[0])
        old_bytes = old_path.read_bytes()
        self.source.write_bytes(b'second native shim input\n')
        second = self.invoke()
        self.assertEqual(second.returncode, 0, second.stderr)
        calls = self.cargo_calls()
        new_path = self.link_object(calls[1])
        self.assertNotEqual(old_path, new_path)
        self.assertNotEqual(old_bytes, new_path.read_bytes())
        self.assertEqual(old_path.read_bytes(), old_bytes)
        self.assertNotEqual(calls[0], calls[1])
        metadata = json.loads((self.target / 'native-fault-link.json').read_text())
        self.assertEqual(metadata['source_sha256'], hashlib.sha256(self.source.read_bytes()).hexdigest())
        self.assertEqual(metadata['link_object'], str(new_path))

    def test_same_resulting_object_stays_same_even_when_source_changes(self):
        environment = dict(self.environment, FAKE_OBJECT_CONSTANT='1')
        first = self.invoke(environment)
        self.assertEqual(first.returncode, 0, first.stderr)
        self.source.write_bytes(b'changed source but constant compiler transport result\n')
        second = self.invoke(environment)
        self.assertEqual(second.returncode, 0, second.stderr)
        calls = self.cargo_calls()
        self.assertEqual(self.link_object(calls[0]), self.link_object(calls[1]))

    def test_quoted_flags_remain_one_argument_and_shell_text_is_never_evaluated(self):
        injected = self.directory / 'must-not-exist'
        payload = '-DUNTRUSTED=$(touch ' + str(injected) + ')'
        environment = dict(self.environment, CFLAGS='-DMESSAGE="hello world" "' + payload + '"')
        result = self.invoke(environment)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        args = json.loads(self.cc_log.read_text().splitlines()[0])
        self.assertIn('-DMESSAGE=hello world', args)
        self.assertIn(payload, args)
        self.assertNotIn('-DMESSAGE=hello', args)
        self.assertFalse(injected.exists())
        self.assertEqual(json.loads((self.target / 'native-fault-link.json').read_text())['CFLAGS'],
                         ['-DMESSAGE=hello world', payload])

    def test_failed_php_sdk_provider_cannot_hide_status_behind_printed_include_flags(self):
        self.executable('php-config', "print('-I/test/include -I/test/ext')\nraise SystemExit(71)\n")
        failed = self.invoke(errexit=False)
        self.assertEqual(failed.returncode, 71, failed.stdout + failed.stderr)
        self.assertFalse(self.cc_log.exists())
        self.assertFalse(self.cargo_log.exists())
        self.assertFalse(self.marker.exists())

    def test_failed_cargo_link_propagates_without_errexit_and_never_exports_success(self):
        first = self.invoke()
        self.assertEqual(first.returncode, 0, first.stderr)
        stale = self.target / 'debug/libphp_profiler.so'
        stale.parent.mkdir()
        stale.write_bytes(b'preexisting stale test-transport DSO bytes')
        self.marker.unlink()
        self.source.write_bytes(b'new shim whose Cargo link refuses\n')
        failed = self.invoke(dict(self.environment, FAKE_CARGO_FAIL='79'), errexit=False)
        self.assertEqual(failed.returncode, 79, failed.stdout + failed.stderr)
        self.assertEqual(len(self.cargo_calls()), 2)
        self.assertFalse(self.marker.exists())
        self.assertEqual(stale.read_bytes(), b'preexisting stale test-transport DSO bytes')

    def test_compiler_failure_cannot_reuse_existing_stale_object_or_invoke_cargo(self):
        first = self.invoke()
        self.assertEqual(first.returncode, 0, first.stderr)
        prior_calls = self.cargo_calls()
        prior_object = self.link_object(prior_calls[0])
        prior_bytes = prior_object.read_bytes()
        self.assertTrue((self.target / 'bailout-fault.o').exists())
        self.marker.unlink()
        self.source.write_bytes(b'input that compiler refuses\n')
        failed = self.invoke(dict(self.environment, FAKE_CC_FAIL='1'), errexit=False)
        self.assertEqual(failed.returncode, 73, failed.stdout + failed.stderr)
        self.assertIn('intentional compiler transport failure', failed.stderr)
        self.assertEqual(self.cargo_calls(), prior_calls)
        self.assertEqual(prior_object.read_bytes(), prior_bytes)
        self.assertFalse(self.marker.exists())


if __name__ == '__main__':
    unittest.main()
