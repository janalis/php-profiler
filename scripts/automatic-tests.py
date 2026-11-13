"""Automatic request capture integration checks."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import sys
import concurrent.futures
from fpm_support import FpmServer

extension = os.environ['PROFILER_EXTENSION']
with tempfile.TemporaryDirectory() as directory:
    output = Path(directory)
    command = ['php', '-n', '-d', 'extension=' + extension, '-d', 'php_profiler.mode=always',
               '-d', 'php_profiler.output_dir=' + directory, '-r', 'function leaf() {} leaf(); echo "ok";']
    started = subprocess.run(command, capture_output=True, check=True)
    assert started.stdout == b'ok' and not started.stderr, started
    disabled = ['php', '-n', '-d', 'extension=' + extension, '-r',
                'if (ini_set("php_profiler.mode", "always") !== false) exit(1); echo "ok";']
    assert subprocess.check_output(disabled) == b'ok'
    invalid = command.copy()
    invalid[invalid.index('php_profiler.output_dir=' + directory)] = 'php_profiler.output_dir=' + directory + '/missing'
    failed = subprocess.run(invalid, capture_output=True, check=True)
    assert failed.stdout == b'ok'
    assert b'automatic capture failed' in failed.stderr
    files = list(output.glob('*.json'))
    assert len(files) == 1
    report = json.loads(files[0].read_text())
    assert report['termination_reason'] == 'request_complete'
    files[0].unlink()
    fixture = output / 'request.php'
    fixture.write_text('<?php function leaf() {} leaf(); echo "ok";')
    settings = {'php_profiler.mode': 'trigger', 'php_profiler.trigger_secret': 'test-secret',
                'php_profiler.output_dir': directory}
    if "--fpm" in sys.argv:
        output.chmod(0o777)
        with FpmServer(extension, settings) as server:
            for token in [None, '', 'wrong', 'test-secret,other', 'x' * 4097, 'test-secret', None, 'test-secret']:
                params = {} if token is None else {'HTTP_X_PHP_PROFILER_TRIGGER': token}
                response = server.request_raw(str(fixture), params)
                assert response['body'] == b'ok', response
                assert not response['stderr'], response
                headers = response['headers'].decode()
                if token == 'test-secret':
                    line = next(line for line in headers.split('\r\n') if line.lower().startswith('x-php-profiler-id:'))
                    identifier = line.split(':', 1)[1].strip()
                    report = json.loads((output / f'php-profile-{identifier}.json').read_text())
                    assert report['termination_reason'] == 'request_complete'
                else:
                    assert 'x-php-profiler-id:' not in headers.lower()
        assert len(list(output.glob('*.json'))) == 2
        for path in output.glob('*.json'):
            path.unlink()
        for secret in ('', 'test-secret,other'):
            settings['php_profiler.trigger_secret'] = secret
            with FpmServer(extension, settings) as server:
                response = server.request_raw(str(fixture), {'HTTP_X_PHP_PROFILER_TRIGGER': secret})
                assert b'X-Php-Profiler-Id' not in response['headers']
            assert not list(output.glob('*.json'))
        settings['php_profiler.mode'] = 'always'
        with FpmServer(extension, settings, workers=4) as server:
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                responses = list(pool.map(lambda _: server.request_raw(str(fixture)), range(24)))
            ids = set()
            for response in responses:
                assert response['body'] == b'ok' and not response['stderr'], response
                headers = response['headers'].decode()
                assert directory not in headers and 'test-secret' not in headers
                line = next(line for line in headers.split('\r\n') if line.lower().startswith('x-php-profiler-id:'))
                identifier = line.split(':', 1)[1].strip()
                assert identifier not in ids
                ids.add(identifier)
                report = json.loads((output / f'php-profile-{identifier}.json').read_text())
                assert report['functions']['leaf']['calls'] == 1
        assert len(list(output.glob('*.json'))) == 24
        for path in output.glob('*.json'):
            path.unlink()
        with FpmServer(extension, settings) as server:
            for source, body, fatal in [
                ('http_response_code(201); echo "early"; flush(); register_shutdown_function(function () { function shutdown_leaf() {} shutdown_leaf(); });', b'early', False),
                ('http_response_code(503); throw new Exception("test fatal");', None, True),
            ]:
                fixture.write_text('<?php ' + source)
                response = server.request_raw(str(fixture))
                if body is not None:
                    assert response['body'] == body, response
                assert (b'503' if fatal else b'201') in response['headers'], response
                assert b'X-Php-Profiler-Id:' in response['headers']
                reports = list(output.glob('*.json'))
                assert len(reports) == 1
                report = json.loads(reports[0].read_text())
                if not fatal:
                    assert report['functions']['shutdown_leaf']['calls'] == 1
                reports[0].unlink()
            # Occupying the announced filename must never overwrite that file.
            fixture.write_text('<?php foreach (headers_list() as $header) { if (str_starts_with($header, "X-Php-Profiler-Id:")) { $id = trim(substr($header, strlen("X-Php-Profiler-Id:"))); file_put_contents(__DIR__ . "/php-profile-" . $id . ".json", "sentinel"); }} echo "ok";')
            response = server.request_raw(str(fixture))
            assert response['body'] == b'ok'
            reports = list(output.glob('*.json'))
            assert len(reports) == 1 and reports[0].read_text() == 'sentinel'
            assert b'automatic capture failed' in response['stderr'], response
        print('automatic HTTP lifecycle, failure and no-overwrite checks passed')


print('automatic CLI configuration checks passed')

# Each subprocess starts from clean request state. Explicit API controls must not
# resurrect the automatic session or create a second copy at shutdown.
with tempfile.TemporaryDirectory() as directory:
    output = Path(directory)
    base = ['php', '-n', '-d', 'extension=' + extension, '-d', 'display_errors=stderr',
            '-d', 'php_profiler.mode=always', '-d', 'php_profiler.output_dir=' + directory]
    for source, count, reason in [
        ('PhpProfiler\\Profiler::stop();', 1, 'stopped'),
        ('PhpProfiler\\Profiler::disable();', 1, 'disabled'),
        ('PhpProfiler\\Profiler::resetAll();', 0, None),
        ('PhpProfiler\\Profiler::start();', 1, 'request_complete'),
        ('register_shutdown_function(function () { function shutdown_leaf() {} shutdown_leaf(); });', 1, 'request_complete'),
        ('echo "early"; flush();', 1, 'request_complete'),
        ('throw new Exception("fatal test");', 1, 'request_complete'),
    ]:
        result = subprocess.run(base + ['-r', source], capture_output=True)
        assert result.returncode == (255 if source.startswith('throw') else 0), result
        reports = list(output.glob('*.json'))
        assert len(reports) == count, (source, reports)
        for path in reports:
            report = json.loads(path.read_text())
            assert report['termination_reason'] == reason, report
            if source.startswith('register_shutdown'):
                assert report['functions']['shutdown_leaf']['calls'] == 1
            path.unlink()
    os.mkdir(output / 'relative-directory')
    for name, value in [('mode', 'invalid'), ('max_events', '0'), ('max_events', '8193'),
                        ('timeline', 'invalid'), ('sampling', 'invalid'), ('output_dir', 'relative'), ('output_dir', directory + '/missing')]:
        result = subprocess.run(base + ['-d', f'php_profiler.{name}={value}', '-r', 'echo "ok";'], capture_output=True)
        assert result.returncode == 0 and result.stdout == b'ok', result
        assert b'automatic capture failed' in result.stderr, result
        assert not list(output.glob('*.json'))
    relative = subprocess.run(base + ['-d', 'php_profiler.output_dir=relative-directory', '-r', 'echo "ok";'], cwd=directory, capture_output=True, check=True)
    assert relative.stdout == b'ok' and b'automatic capture failed' in relative.stderr
    assert not list((output / 'relative-directory').glob('*.json'))
    result = subprocess.run(base + ['-d', 'open_basedir=/nonexistent-profiler-test', '-r', 'echo "ok";'], capture_output=True, check=True)
    assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr
    assert not list(output.glob('*.json'))
    result = subprocess.run(base + ['-d', 'php_profiler.timeline=1' , '-d', 'php_profiler.max_events=1',
                                  '-r', 'function a() {} a();'], capture_output=True, check=True)
    report = json.loads(next(output.glob('*.json')).read_text())
    assert report['timeline'], report
    for path in output.glob('*.json'):
        path.unlink()
    result = subprocess.run(base + ['-d', 'php_profiler.sampling=1', '-r', 'usleep(20000);'], capture_output=True, check=True)
    sampled = list(output.glob('*.json'))
    if os.environ.get('CI_PROFILER_REQUIRE_SAMPLING') == '1':
        assert sampled, result
    if sampled:
        assert json.loads(sampled[0].read_text())['schema_version'] == 2
    else:
        assert b'automatic capture failed' in result.stderr
    for path in sampled:
        path.unlink()
    # Tightening open_basedir while recording must prevent persistence.
    result = subprocess.run(base + ['-r', 'ini_set("open_basedir", "/nonexistent-profiler-test"); echo "ok";'], capture_output=True, check=True)
    assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr
    assert not list(output.glob('*.json'))
print('automatic lifecycle, configuration, options and persistence failure checks passed')

# Exercise the _SERVER fallback used by HTTP SAPIs without a getenv callback.
import socket
import signal
import time
import urllib.request
with tempfile.TemporaryDirectory(prefix='profiler-http-') as directory:
    output = Path(directory)
    (output / 'index.php').write_text('<?php function http_leaf() {} http_leaf(); echo "ok";')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    arguments = ['php', '-n', '-d', 'extension=' + extension, '-d', 'php_profiler.mode=trigger',
                 '-d', 'php_profiler.trigger_secret=http-secret', '-d', 'php_profiler.output_dir=' + directory,
                 '-S', f'127.0.0.1:{port}', '-t', directory]
    with subprocess.Popen(arguments, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL) as server:
        try:
            address = f'http://127.0.0.1:{port}/'
            for attempt in range(100):
                try:
                    with urllib.request.urlopen(address, timeout=1) as response:
                        assert response.read() == b'ok'
                        assert response.headers.get('X-Php-Profiler-Id') is None
                    break
                except OSError:
                    if server.poll() is not None:
                        raise RuntimeError('PHP HTTP server exited')
                    time.sleep(0.05)
            else:
                raise RuntimeError('PHP HTTP server did not start')
            request = urllib.request.Request(address, headers={'X-Php-Profiler-Trigger': 'http-secret'})
            with urllib.request.urlopen(request, timeout=5) as response:
                assert response.read() == b'ok'
                identifier = response.headers['X-Php-Profiler-Id']
                assert identifier
            path = output / f'php-profile-{identifier}.json'
            for attempt in range(100):
                if path.exists():
                    break
                time.sleep(0.01)
            report = json.loads(path.read_text())
            assert report['functions']['http_leaf']['calls'] == 1
        finally:
            server.send_signal(signal.SIGINT)
            server.wait(timeout=10)
print('automatic built-in HTTP server header trigger passed')

if sys.platform.startswith('linux'):
    # A valid native directory can leave insufficient room for the generated
    # basename. Admission must reject it before FixedPath publication.
    with tempfile.TemporaryDirectory(prefix='profiler-long-path-') as directory:
        current = Path(directory)
        while len(str(current)) + 251 < 4070:
            current = current / ('x' * 250)
            current.mkdir()
        remaining = 4070 - len(str(current)) - 1
        if remaining:
            current = current / ('y' * remaining)
            current.mkdir()
        result = subprocess.run(['php', '-n', '-d', 'extension=' + extension,
            '-d', 'php_profiler.mode=always', '-d', 'php_profiler.output_dir=' + str(current),
            '-r', 'echo "ok";'], capture_output=True, check=True)
        assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr
        assert not list(current.glob('*.json'))
    print('automatic native path admission boundary passed')
