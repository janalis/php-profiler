import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'scripts'))
from process_groups import ProcessGroups

FAMILY = '''import os,subprocess,sys,time
child=subprocess.Popen([sys.executable,'-c','import time; time.sleep(60)'])
with open(sys.argv[1],'w') as f: f.write(str(os.getpid())+' '+str(child.pid)); f.flush()
time.sleep(60)
'''


def assert_dead(test, pids):
    for pid in pids:
        deadline = time.monotonic() + 5
        while True:
            try:
                os.kill(pid, 0)
            except ProcessLookupError:
                break
            if time.monotonic() > deadline:
                test.fail('owned process survived cleanup: ' + str(pid))
            time.sleep(0.01)


class OwnedProcessGroupTests(unittest.TestCase):
    def test_capture_and_nonzero_status_are_preserved(self):
        with ProcessGroups() as runner:
            result = runner.run([sys.executable, '-c',
                'import sys; print("out"); print("err",file=sys.stderr); sys.exit(7)'],
                timeout=5, capture_output=True, text=True)
            self.assertEqual((result.returncode, result.stdout, result.stderr), (7, 'out\n', 'err\n'))
            with self.assertRaises(subprocess.CalledProcessError):
                runner.run([sys.executable, '-c', 'raise SystemExit(9)'], timeout=5, check=True)

    def test_timeout_kills_and_reaps_child_and_grandchild(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'pids'
            with ProcessGroups() as runner:
                with self.assertRaises(subprocess.TimeoutExpired):
                    runner.run([sys.executable, '-c', FAMILY, str(path)], timeout=3)
            self.assertTrue(path.is_file(), 'real family was never started')
            assert_dead(self, map(int, path.read_text().split()))

    def test_signal_cleanup_owns_real_descendants(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'pids'
            helper = '''import sys
sys.path.insert(0,sys.argv[1])
from process_groups import ProcessGroups
with ProcessGroups() as runner: runner.run([sys.executable,'-c',sys.argv[2],sys.argv[3]],timeout=60)
'''
            process = subprocess.Popen([sys.executable, '-c', helper,
                str(Path(__file__).resolve().parents[2] / 'scripts'), FAMILY, str(path)])
            try:
                deadline = time.monotonic() + 5
                while not path.is_file():
                    if time.monotonic() > deadline:
                        self.fail('real signal-test family was never started')
                    time.sleep(0.01)
                process.send_signal(signal.SIGTERM)
                self.assertEqual(process.wait(timeout=10), 128 + signal.SIGTERM)
                assert_dead(self, map(int, path.read_text().split()))
            finally:
                if process.poll() is None:
                    process.kill(); process.wait()
