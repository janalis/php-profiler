#!/usr/bin/env python3
"""Exact Rust allocation counts across repeated FPM Zend-OOM and worker reuse."""
import json
import os
from pathlib import Path
import pwd
import re
import tempfile
from exception_fixture_support import exception_fixture
from fpm_support import FpmServer


def main():
    fixture = exception_fixture()
    evidence = []
    with tempfile.TemporaryDirectory(prefix='php-profiler-fpm-ownership-') as directory:
        if os.geteuid() == 0:
            worker = pwd.getpwnam('www-data')
            os.chown(directory, worker.pw_uid, worker.pw_gid)
        with FpmServer(fixture) as server:
            def healthy():
                value = server.request('tests/fpm/vendor-healthy.php')
                if value['result'] != 7:
                    raise RuntimeError('healthy request semantics changed')
                return value
            initial = healthy()
            pid = initial['pid']
            stable = None
            for index, kind in enumerate([6, 7] * 4):
                path = Path(directory) / f'{index}-{kind}.json'
                try:
                    response = server.request_raw('tests/fpm/vendor-oom.php',
                                                  {'PROFILE_OWNERSHIP_PATH': str(path), 'PROFILE_OWNERSHIP_KIND': str(kind)})
                except BaseException as failure:
                    failure.add_note(f'Ownership request index={index}, kind={kind}, expected PID={pid}')
                    raise
                text = (response['body'] + response['stderr']).decode(errors='replace')
                match = re.search(r'WORKER_PID=(\d+)', text)
                if (not match or int(match.group(1)) != pid or 'BEFORE_EXCEPTION' not in text or
                        'Allowed memory size' not in text or 'AFTER_EXCEPTION' in text or 'CAUGHT_FATAL' in text):
                    raise RuntimeError(f'kind {kind}: expected native OOM/worker control flow absent: {text}')
                counts = json.loads(path.read_text())
                if counts['pid'] != pid or counts['after'] != counts['before']:
                    raise RuntimeError('Rust-owned exception allocation leaked: ' + str(counts))
                next_request = healthy()
                if next_request['pid'] != pid or next_request['live_bytes'] != counts['after']:
                    raise RuntimeError('worker restart or allocation accounting changed after fatal request')
                if stable is not None and (counts['before'] != stable or counts['after'] != stable):
                    raise RuntimeError('steady-state Rust allocation count grew across requests: ' + str(counts))
                stable = counts['after']
                evidence.append(counts)
    print(json.dumps({'result': 'same FPM worker reclaimed direct/fallback exception owners',
                      'scope': 'test-only extension global Rust allocator; interpreter/native allocators not counted',
                      'initial_live_bytes': initial['live_bytes'], 'stable_live_bytes': stable, 'requests': evidence}))


if __name__ == '__main__':
    main()
