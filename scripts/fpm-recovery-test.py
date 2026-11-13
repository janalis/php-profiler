#!/usr/bin/env python3
"""Fatal requests must finalize safely and leave the same FPM worker usable."""
import json
import os
from pathlib import Path
import pwd
import re
import tempfile
from fpm_support import FpmServer


def main():
    cases = []
    with tempfile.TemporaryDirectory(prefix='php-profiler-fpm-reports-') as directory:
        # Private writable reports for the same www-data worker used by FpmServer.
        if os.geteuid() == 0:
            worker = pwd.getpwnam('www-data')
            os.chown(directory, worker.pw_uid, worker.pw_gid)
        with FpmServer(os.environ['PROFILER_EXTENSION']) as server:
            def healthy():
                value = server.request('tests/fpm/request.php', {'PROFILE_TEST_INPUT': 'request'})
                if value['id'] != 1 or value['result'] != 7 or value['report']['functions']['fpm_profiled_leaf']['calls'] != 1:
                    raise RuntimeError('healthy request inherited state from a failed request')
                return value['pid']
            pid = healthy()
            for index, mode in enumerate(['exception', 'exit', 'oom-json', 'oom-array'] * 2):
                path = Path(directory) / f'{index}-{mode}.json'
                response = server.request_raw('tests/fpm/fatal.php',
                                              {'PROFILE_FATAL_MODE': mode, 'PROFILE_REPORT_PATH': str(path)})
                text = response['body'].decode(errors='replace')
                match = re.search(r'WORKER_PID=(\d+)', text)
                if not match or int(match.group(1)) != pid or 'UNEXPECTED_CONTINUATION' in text:
                    raise RuntimeError(f'{mode}: fatal request worker identity/continuation changed: {text}')
                diagnostic = text + response['stderr'].decode(errors='replace')
                if mode.startswith('oom-') and ('Allowed memory size' not in diagnostic or 'BEFORE_REPORT' not in text or 'AFTER_REPORT' in text or 'CAUGHT_FATAL' in text):
                    raise RuntimeError(f'{mode}: OOM request did not follow fatal control flow: {text}')
                with path.open() as source:
                    report = json.load(source)
                if report['status'] != 'incomplete' or report['termination_reason'] != 'request_shutdown' or report['functions']['fpm_fatal_leaf']['calls'] != 1:
                    raise RuntimeError(f'{mode}: unfinished session not persisted at request shutdown')
                if healthy() != pid:
                    raise RuntimeError(f'{mode}: FPM replaced the worker instead of recovering its request state')
                cases.append({'mode': mode, 'report_status': report['status'], 'worker_pid': pid})
    print(json.dumps({'result': 'same worker recovered after all fatal requests', 'cases': cases}))


if __name__ == '__main__':
    main()
