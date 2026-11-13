"""Adversarial test-transport output must not conceal a sanitizer failure.

Execute the actual driver startup branch, stopping at its next subprocess. These
are gate-unit tests, not simulated PHP/Zend or native-sanitizer success evidence.
"""
from contextlib import redirect_stderr, redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('sanitizer_diagnostics', ROOT / 'scripts/sanitizer_diagnostics.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)

HEADERS = (
    '==14==ERROR: LeakSanitizer: detected memory leaks\n',
    'SUMMARY: AddressSanitizer: 20232 byte(s) leaked in 632 allocation(s).\n',
    '==14==ERROR: AddressSanitizer: heap-use-after-free on address 0x1234\n',
    'AddressSanitizer:DEADLYSIGNAL\n',
    'AddressSanitizer CHECK failed: sanitizer_common.cpp:42\n',
    'LeakSanitizer has encountered a fatal error.\n',
    'ASan runtime does not come first in initial library list\n',
)


class StopAfterStartup(Exception):
    pass


def exercise_startup(driver, payload='', *, returncode=255, channel='stderr', sampled_fault='atfork'):
    """Pass real script control flow an adversarial CompletedProcess transport."""
    selected = False
    output, errors = io.StringIO(), io.StringIO()

    def transport(command, **kwargs):
        nonlocal selected
        if selected:
            raise StopAfterStartup()
        program = ' '.join(map(str, command))
        if 'json_encode(profiler_test_sampling_status()' in program:
            return subprocess.CompletedProcess(command, 0, json.dumps({
                'supported': True, 'expected_supported': True, 'expected_zts': sampled_fault == 'tsrm'}), '')
        if 'AFTER_SAMPLER_STARTUP' in program or 'AFTER_MODULE_STARTUP' in program:
            is_selected = ('AFTER_MODULE_STARTUP' in program or
                           kwargs.get('env', {}).get('PROFILER_SAMPLING_FAULT') == sampled_fault)
            selected = is_selected
            diagnostic = 'PHP Warning: Unable to start php_profiler module\n'
            if 'AFTER_MODULE_STARTUP' in program:
                diagnostic += 'INJECTED_HANDLE_API_FAILURE\n'
            stdout = diagnostic + (payload if channel == 'stdout' and is_selected else '')
            stderr = payload if channel == 'stderr' and is_selected else ''
            return subprocess.CompletedProcess(command, returncode, stdout, stderr)
        return subprocess.CompletedProcess(command, 0, '', '')

    class Groups:
        def __enter__(self):
            return self
        def __exit__(self, *_):
            return False
        run = staticmethod(transport)

    group_module = types.ModuleType('process_groups')
    group_module.ProcessGroups = Groups
    with tempfile.TemporaryDirectory(prefix='profiler-sanitizer-gate-') as temporary:
        env = {'PATH': os.environ.get('PATH', ''), 'PROFILER_EXTENSION': '/test-only/extension.so',
               'PROFILER_SAMPLING_EXTENSION': '/test-only/extension.so',
               'PROFILER_LINKED_BAILOUT_FAULT': '1', 'CARGO_TARGET_DIR': temporary}
        cwd = Path.cwd()
        outcome = None
        try:
            with patch.dict(os.environ, env, clear=True), patch.dict(sys.modules, {
                    'process_groups': group_module, 'sanitizer_diagnostics': helper}), \
                    patch.object(sys, 'platform', 'linux'), \
                    patch.object(subprocess, 'run', side_effect=transport), \
                    patch.object(subprocess, 'check_output', return_value=''), \
                    redirect_stdout(output), redirect_stderr(errors):
                try:
                    runpy.run_path(str(driver), run_name='__main__')
                except StopAfterStartup:
                    outcome = 'accepted_startup'
                except (RuntimeError, SystemExit) as error:
                    outcome = error
        finally:
            os.chdir(cwd)
    return outcome, output.getvalue(), errors.getvalue()


class SanitizerDiagnosticsTests(unittest.TestCase):
    def test_actual_error_headers_and_deadlysignal_fail_closed(self):
        for header in HEADERS:
            with self.subTest(header=header), self.assertRaisesRegex(RuntimeError, 'Sanitizer failure'):
                helper.reject_sanitizer_diagnostics('expected PHP fatal\n' + header, 'intentional fatal')

    def test_normal_php_fatal_and_informational_init_are_not_sanitizer_errors(self):
        for output in ('PHP Fatal error: expected fixture allocation failure',
                       'Unable to start php_profiler module',
                       '==14==AddressSanitizer Init done\n'):
            helper.reject_sanitizer_diagnostics(output, 'normal fixture')

    def test_ordinary_php_message_quoting_sanitizer_text_is_not_a_tool_header(self):
        for quoted in ('ERROR: AddressSanitizer: quoted application text',
                       'SUMMARY: AddressSanitizer: quoted application text',
                       'AddressSanitizer:DEADLYSIGNAL'):
            diagnostic = 'PHP Fatal error: expected message containing "' + quoted + '" in fixture.php:1\n'
            helper.reject_sanitizer_diagnostics(diagnostic, 'PHP quoted message')
            for name in ('sampling-controller-tests.py', 'native-bailout-tests.py'):
                with self.subTest(quoted=quoted, driver=name):
                    outcome, _, _ = exercise_startup(ROOT / 'scripts' / name, diagnostic)
                    self.assertEqual(outcome, 'accepted_startup')

    def test_color_always_tool_headers_cannot_bypass_anchored_detection(self):
        for header in HEADERS:
            colored = "\x1b[1m\x1b[31m" + header.rstrip() + "\x1b[0m\n"
            for name in ('sampling-controller-tests.py', 'native-bailout-tests.py'):
                with self.subTest(header=header, driver=name):
                    outcome, _, _ = exercise_startup(ROOT / 'scripts' / name, colored)
                    self.assertIsInstance(outcome, RuntimeError)
                    self.assertIn('Sanitizer failure', str(outcome))
        # Real tools may put color boundaries between the PID and severity.
        helper_output = '==14==\x1b[1m\x1b[31mERROR: LeakSanitizer: detected memory leaks\x1b[0m\n'
        with self.assertRaisesRegex(RuntimeError, 'Sanitizer failure'):
            helper.reject_sanitizer_diagnostics(helper_output, 'color always')

    def test_real_sampling_driver_rejects_stderr_or_stdout_despite_expected_php_fatal(self):
        for header in HEADERS:
            for channel in ('stdout', 'stderr'):
                for fault in ('atfork', 'tsrm'):
                    with self.subTest(header=header, channel=channel, fault=fault):
                        outcome, stdout, _ = exercise_startup(ROOT / 'scripts/sampling-controller-tests.py',
                                                             header, channel=channel, sampled_fault=fault)
                        self.assertIsInstance(outcome, RuntimeError)
                        self.assertIn('Sanitizer failure', str(outcome))
                        self.assertIn(header.rstrip(), str(outcome))
                        self.assertNotIn(fault + ' registration failure PASS', stdout)

    def test_real_sampling_driver_rejects_default_lsan_exit_one_without_sanitizer_env_flags(self):
        outcome, _, _ = exercise_startup(ROOT / 'scripts/sampling-controller-tests.py', HEADERS[0], returncode=1)
        self.assertIsInstance(outcome, RuntimeError)
        self.assertIn('Sanitizer failure', str(outcome))

    def test_real_native_bailout_driver_rejects_sanitizer_even_at_expected_fatal_exit(self):
        for header in HEADERS:
            for channel in ('stdout', 'stderr'):
                with self.subTest(header=header, channel=channel):
                    outcome, stdout, _ = exercise_startup(ROOT / 'scripts/native-bailout-tests.py',
                                                         header, channel=channel)
                    self.assertIsInstance(outcome, RuntimeError)
                    self.assertIn('Sanitizer failure', str(outcome))
                    self.assertIn(header.rstrip(), str(outcome))
                    self.assertNotIn('startup rejects dependency failure', stdout)

    def test_both_real_driver_paths_accept_normal_expected_php_startup_failure(self):
        for name in ('sampling-controller-tests.py', 'native-bailout-tests.py'):
            with self.subTest(driver=name):
                outcome, stdout, _ = exercise_startup(ROOT / 'scripts' / name)
                self.assertEqual(outcome, 'accepted_startup')
                self.assertTrue('registration failure PASS' in stdout or
                                'startup rejects dependency failure' in stdout)


if __name__ == '__main__':
    unittest.main()
