"""Bounded test subprocesses; kill/reap only groups created by this owner.

Keep the child unreaped until group cleanup (WNOWAIT), so its PID/group cannot
be recycled underneath us. Linux subreaper scope additionally reaps orphaned
own-group grandchildren. This is development infrastructure, never runtime code.
"""
import atexit
import ctypes
import os
import signal
import subprocess
import tempfile
import time
from contextlib import ExitStack


class ProcessGroups:
    def __init__(self):
        self.active = {}
        self.closed = False
        self.spawning = False
        self.pending_signal = None
        self.original_handlers = {}
        self.subreaper = None

    def __enter__(self):
        if not hasattr(os, 'waitid') or not hasattr(os, 'WNOWAIT'):
            raise RuntimeError('test process ownership requires POSIX waitid/WNOWAIT')
        if os.uname().sysname == 'Linux':
            libc = ctypes.CDLL(None, use_errno=True)
            previous = ctypes.c_int()
            if libc.prctl(37, ctypes.byref(previous), 0, 0, 0) != 0:
                raise OSError(ctypes.get_errno(), 'read child-subreaper scope')
            if libc.prctl(36, 1, 0, 0, 0) != 0:
                raise OSError(ctypes.get_errno(), 'establish child-subreaper scope')
            self.subreaper = (libc, previous.value)
        for signum in (signal.SIGTERM, signal.SIGINT):
            self.original_handlers[signum] = signal.signal(signum, self._signal)
        atexit.register(self.close)
        return self

    def _signal(self, signum, _frame):
        # Defer Python's handler during creation until the new PID is recorded;
        # unlike masking around fork, this does not alter the child's signals.
        if self.spawning:
            self.pending_signal = signum
            return
        self.close()
        raise SystemExit(128 + signum)

    def _retire(self, process):
        if process.pid not in self.active:
            return
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        except PermissionError:
            # Darwin reports EPERM for an unreaped group containing only
            # zombies. Accept ONLY a bounded query of this owned group's
            # actual state; a live member or another platform stays fatal.
            if os.uname().sysname != 'Darwin':
                raise
            states = subprocess.run(['ps', '-g', str(process.pid), '-o', 'stat='],
                                    capture_output=True, text=True, timeout=5, check=False)
            if states.returncode not in (0, 1) or any(not item.startswith('Z')
                    for item in states.stdout.split()):
                raise
        process.wait(timeout=5)
        if self.subreaper:
            deadline = time.monotonic() + 5
            while True:
                try:
                    child, _ = os.waitpid(-process.pid, os.WNOHANG)
                except ChildProcessError:
                    break
                if child == 0:
                    if time.monotonic() >= deadline:
                        raise RuntimeError('owned descendant did not retire after SIGKILL')
                    time.sleep(0.005)
        del self.active[process.pid]

    def run(self, command, *, timeout, env=None, cwd=None, capture_output=False,
            text=False, check=False):
        if self.closed or timeout <= 0:
            raise ValueError('open process scope and positive timeout required')
        with ExitStack() as files:
            output = files.enter_context(tempfile.TemporaryFile()) if capture_output else None
            errors = files.enter_context(tempfile.TemporaryFile()) if capture_output else None
            self.spawning = True
            try:
                process = subprocess.Popen(command, env=env, cwd=cwd, stdout=output,
                                           stderr=errors, start_new_session=True)
                self.active[process.pid] = process
            finally:
                self.spawning = False
            if self.pending_signal is not None:
                self._signal(self.pending_signal, None)
            expired = False
            deadline = time.monotonic() + timeout
            try:
                while os.waitid(os.P_PID, process.pid, os.WEXITED | os.WNOHANG | os.WNOWAIT) is None:
                    if time.monotonic() >= deadline:
                        expired = True
                        break
                    time.sleep(0.005)
            finally:
                self._retire(process)
            stdout = stderr = None
            if capture_output:
                output.seek(0); errors.seek(0)
                stdout, stderr = output.read(), errors.read()
                if text:
                    stdout, stderr = stdout.decode(), stderr.decode()
            if expired:
                raise subprocess.TimeoutExpired(command, timeout, output=stdout, stderr=stderr)
            result = subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
            if check:
                result.check_returncode()
            return result

    def close(self):
        if self.closed:
            return
        for process in list(self.active.values()):
            self._retire(process)
        for signum, original in self.original_handlers.items():
            signal.signal(signum, original)
        if self.subreaper:
            libc, previous = self.subreaper
            if libc.prctl(36, previous, 0, 0, 0) != 0:
                raise OSError(ctypes.get_errno(), 'restore child-subreaper scope')
        self.closed = True
        atexit.unregister(self.close)

    def __exit__(self, _kind, _value, _traceback):
        self.close()
