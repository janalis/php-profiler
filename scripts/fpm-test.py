#!/usr/bin/env python3
"""Exercise concurrent FastCGI requests through one reused worker."""
import concurrent.futures
import os
from fpm_support import FpmServer


def main():
    with FpmServer(os.environ['PROFILER_EXTENSION']) as server:
        def request(unused):
            payload = server.request('tests/fpm/request.php', {'PROFILE_TEST_INPUT': 'request'})
            if payload['id'] != 1 or payload['result'] != 7:
                raise RuntimeError('request state or semantics leaked')
            functions = payload['report']['functions']
            if not isinstance(functions, dict) or functions.get('fpm_profiled_leaf', {}).get('calls') != 1:
                raise RuntimeError('wrong per-request count: ' + str(payload))
            return payload
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            results = list(pool.map(request, range(32)))
        print(f'FPM: {len(results)} isolated requests through one reused worker passed')


if __name__ == '__main__':
    main()
