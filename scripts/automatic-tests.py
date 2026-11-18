"""Automatic request capture integration checks."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import sys
import concurrent.futures
import http.client
import shutil
import signal
import socket
import time
from fpm_support import FpmServer
from sanitizer_diagnostics import reject_sanitizer_diagnostics

php_environment = os.environ.copy()
if php_environment.get('PROFILER_ASAN_RUNTIME'):
    preload = 'DYLD_INSERT_LIBRARIES' if sys.platform == 'darwin' else 'LD_PRELOAD'
    php_environment[preload] = php_environment['PROFILER_ASAN_RUNTIME']


def run_php(*args, **kwargs):
    kwargs.setdefault('env', php_environment)
    try:
        result = subprocess.run(*args, **kwargs)
    except subprocess.CalledProcessError as failure:
        diagnostics = (failure.stdout or b'') + (failure.stderr or b'')
        raise RuntimeError('PHP subprocess failed: ' + diagnostics.decode(errors='replace')) from failure
    diagnostics = (result.stdout or b'') + (result.stderr or b'')
    reject_sanitizer_diagnostics(diagnostics.decode(errors='replace'), 'automatic PHP subprocess')
    return result


INHERITED = b'HTTP_X_PHP_PROFILER_TRIGGER is set in the process environment'


def free_port():
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        return probe.getsockname()[1]


def trigger_settings(directory, secret):
    """PHP options without any host INI; run with PHP_INI_SCAN_DIR set to ''."""
    if b'\0' in secret:
        # Only a quoted INI string carries a NUL byte; -c replaces php.ini as -n would skip it.
        ini = directory / 'secret.ini'
        ini.write_bytes(b'php_profiler.trigger_secret="' + secret + b'"\n')
        source = ['-c', str(ini)]
    else:
        source = ['-n', '-d', b'php_profiler.trigger_secret=' + secret]
    # OPcache stays enabled for the built-in server even with -n; a cached script may inline leaf().
    return [*source, '-d', 'opcache.enable=0', '-d', 'extension=' + extension, '-d', 'php_profiler.mode=trigger',
            '-d', 'php_profiler.output_dir=' + str(directory)]


def wait_until_ready(process, ready, what):
    for attempt in range(200):
        if ready():
            return
        assert process.poll() is None, f'{what} exited during startup'
        time.sleep(0.05)
    raise RuntimeError(f'{what} startup timed out')


def accepts(port):
    try:
        socket.create_connection(('127.0.0.1', port), timeout=1).close()
        return True
    except OSError:
        return False


def built_in_server_checks(output):
    """The built-in server exposes headers only through _SERVER, never the process environment."""
    (output / 'server.php').write_text('<?php function leaf() {} leaf(); echo "ok";')
    for secret, cases, inherited in (
        (b'test-secret', [(None, False), (b'wrong', False), (b'test-secret,other', False), (b'test-secret', True)], None),
        # The built-in server reads headers only: even an inherited copy of the secret is ignored.
        (b'test-secret', [(None, False), (b'test-secret', True), (b'wrong', False)], 'test-secret'),
        # Latin-1 bytes are compared exactly, never through a lossy UTF-8 conversion.
        (b'caf\xe9', [(b'caf\xe9', True), ('caf\u00e9'.encode(), False)], None),
        # Unusable secrets are configuration errors, logged only when a capture is requested.
        (b'', [(None, False), (b'', False), (b'x', False)], None),
        (b'a,b', [(None, False), (b'a,b', False)], None),
        (b'x' * 4097, [(None, False), (b'x' * 4097, False)], None),
        # No header value can carry the NUL byte a quoted INI string can.
        (b'a\0b', [(None, False), (b'a', False)], None),
        # The longest usable secret still captures through a real header.
        (b'x' * 4096, [(b'x' * 4096, True)], None),
    ):
        environment = dict(php_environment, PHP_INI_SCAN_DIR='')
        environment.pop('HTTP_X_PHP_PROFILER_TRIGGER', None)
        if inherited:
            environment['HTTP_X_PHP_PROFILER_TRIGGER'] = inherited
        port = free_port()
        command = ['php', *trigger_settings(output, secret), '-S', f'127.0.0.1:{port}', '-t', str(output)]
        with tempfile.TemporaryFile() as errors, subprocess.Popen(
                command, env=environment, stdout=subprocess.DEVNULL, stderr=errors) as server:
            try:
                wait_until_ready(server, lambda: accepts(port), 'PHP HTTP server')
                for token, captured in cases:
                    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=30)
                    connection.putrequest('GET', '/server.php')
                    if token is not None:
                        connection.putheader('X-Php-Profiler-Trigger', token)
                    connection.endheaders()
                    response = connection.getresponse()
                    assert response.read() == b'ok', (secret, token)
                    identifier = response.getheader('X-Php-Profiler-Id')
                    connection.close()
                    assert (identifier is not None) == captured, (secret, token, identifier)
                    if captured:
                        # The built-in server persists reports after sending the response.
                        path = output / f'php-profile-{identifier}.json'
                        for attempt in range(500):
                            if path.exists():
                                break
                            time.sleep(0.01)
                        assert json.loads(path.read_text())['functions']['leaf']['calls'] == 1
                        path.unlink()
            finally:
                server.send_signal(signal.SIGINT)
                server.wait(timeout=10)
                errors.seek(0)
                log = errors.read()
        reject_sanitizer_diagnostics(log.decode(errors='replace'), 'automatic built-in server')
        assert not list(output.glob('*.json')), secret
        # One log line per requested capture with an unusable secret, none without a header.
        unusable = not secret or len(secret) > 4096 or b',' in secret or b'\0' in secret
        requested = sum(1 for token, captured in cases if token is not None)
        assert log.count(b'automatic capture failed') == (requested if unusable else 0), (secret, log)
        assert INHERITED not in log, (secret, log)
    print('automatic built-in server trigger checks passed')


class FastCgi(FpmServer):
    """php-cgi -b speaks the same FastCGI protocol as FPM but reads the process environment."""
    def __init__(self, settings, environment):
        super().__init__(environment=environment)
        self.settings = settings

    def __enter__(self):
        self.directory = tempfile.TemporaryDirectory(prefix='php-profiler-cgi-')
        self.address = os.path.join(self.directory.name, 'cgi.sock')
        self.errors = tempfile.TemporaryFile()
        self.process = subprocess.Popen(['php-cgi', *self.settings, '-b', self.address], env=self.environment,
                                        stdout=subprocess.DEVNULL, stderr=self.errors)
        try:
            wait_until_ready(self.process, lambda: os.path.exists(self.address), 'php-cgi')
        except BaseException as failure:
            self.__exit__(type(failure), failure, failure.__traceback__)
            raise
        return self

    def __exit__(self, exception_type, exception, traceback):
        try:
            super().__exit__(exception_type, exception, traceback)
        finally:
            self.errors.seek(0)
            log = self.errors.read().decode(errors='replace')
            self.errors.close()
        if exception is not None:
            exception.add_note('php-cgi stderr:\n' + log)
        else:
            reject_sanitizer_diagnostics(log, 'automatic php-cgi')


def inherited_environment_checks(output):
    """SAPIs that read the process environment never take a trigger from it, and say why."""
    environment = dict(php_environment, HTTP_X_PHP_PROFILER_TRIGGER='test-secret', PHP_INI_SCAN_DIR='')
    script = output / 'environment.php'
    script.write_text('<?php echo "ok";')
    settings = trigger_settings(output, b'test-secret')
    if shutil.which('phpdbg'):
        # phpdbg drops log messages unless error_log names a file.
        log = output / 'phpdbg.log'
        try:
            result = run_php(['phpdbg', *settings, '-d', f'error_log={log}', '-qrr', str(script)], env=environment,
                             capture_output=True, check=True)
            assert result.stdout.endswith(b'ok') and log.read_bytes().count(INHERITED) == 1, result
        finally:
            log.unlink(missing_ok=True)
        assert not list(output.glob('*.json')), 'phpdbg environment must not trigger a capture'
        print('automatic phpdbg environment check passed')
    else:
        print('automatic phpdbg environment check skipped: phpdbg unavailable')
    if shutil.which('php-cgi'):
        with FastCgi(settings, environment) as server:
            # The fallback is indistinguishable from a header, so every request is refused,
            # including one with a real FastCGI parameter; one log line per process.
            for logged, params in ((1, {}), (0, {}), (0, {'HTTP_X_PHP_PROFILER_TRIGGER': 'test-secret'})):
                response = server.request_raw(str(script), params)
                assert response['body'] == b'ok', response
                assert response['stderr'].count(INHERITED) == logged, response
                assert b'automatic capture failed' not in response['stderr'], response
                assert b'X-Php-Profiler-Id' not in response['headers'], response
        assert not list(output.glob('*.json')), 'php-cgi environment fallback must not trigger a capture'
        clean = dict(environment)
        clean.pop('HTTP_X_PHP_PROFILER_TRIGGER')
        with FastCgi(settings, clean) as server:
            response = server.request_raw(str(script), {'HTTP_X_PHP_PROFILER_TRIGGER': 'test-secret'})
            assert response['body'] == b'ok' and not response['stderr'], response
            assert b'X-Php-Profiler-Id' in response['headers'], response
        reports = list(output.glob('*.json'))
        assert len(reports) == 1, 'a real FastCGI trigger header must still capture'
        reports[0].unlink()
        print('automatic php-cgi environment check passed')
    else:
        print('automatic php-cgi environment check skipped: php-cgi unavailable')

extension = os.environ['PROFILER_EXTENSION']
with tempfile.TemporaryDirectory() as directory:
    output = Path(directory)
    command = ['php', '-n', '-d', 'extension=' + extension, '-d', 'php_profiler.mode=always',
               '-d', 'php_profiler.output_dir=' + directory, '-r', 'function leaf() {} leaf(); echo "ok";']
    started = run_php(command, capture_output=True, check=True)
    assert started.stdout == b'ok' and not started.stderr, started
    disabled = ['php', '-n', '-d', 'extension=' + extension, '-r',
                'if (ini_set("php_profiler.mode", "always") !== false) exit(1); echo "ok";']
    assert run_php(disabled, capture_output=True, check=True).stdout == b'ok'
    invalid = command.copy()
    invalid[invalid.index('php_profiler.output_dir=' + directory)] = 'php_profiler.output_dir=' + directory + '/missing'
    failed = run_php(invalid, capture_output=True, check=True)
    assert failed.stdout == b'ok'
    assert b'automatic capture failed' in failed.stderr
    files = list(output.glob('*.json'))
    assert len(files) == 1
    report = json.loads(files[0].read_text())
    assert report['termination_reason'] == 'request_complete'
    files[0].unlink()
    ignored = run_php(['php', '-n', '-d', 'extension=' + extension,
                      '-d', 'php_profiler.mode=trigger', '-d', 'php_profiler.trigger_secret=test-secret',
                      '-d', 'php_profiler.output_dir=' + directory, '-r', 'echo "ok";'],
                     env=dict(php_environment, HTTP_X_PHP_PROFILER_TRIGGER='test-secret'),
                     capture_output=True, check=True)
    assert ignored.stdout == b'ok' and not ignored.stderr
    assert not list(output.glob('*.json')), 'CLI environment must not activate HTTP trigger mode'
    # No trigger header reaches the CLI, so even an unusable secret requests nothing.
    for secret in ('', 'test,secret', 'x' * 4097, 'x' * 4096):
        checked = run_php(['php', '-n', '-d', 'extension=' + extension,
                           '-d', 'php_profiler.mode=trigger', '-d', 'php_profiler.trigger_secret=' + secret,
                           '-d', 'php_profiler.output_dir=' + directory, '-r', 'echo "ok";'],
                          capture_output=True, check=True)
        assert checked.stdout == b'ok' and not checked.stderr, (len(secret), checked)
        assert not list(output.glob('*.json'))
    built_in_server_checks(output)
    inherited_environment_checks(output)
    fixture = output / 'request.php'
    fixture.write_text('<?php function leaf() {} leaf(); echo "ok";')
    # PHP 8.5 always builds OPcache, and -n leaves it enabled for FPM. Once a script is cached,
    # its optimizer may inline the empty leaf() call, so cache state would decide the report.
    settings = {'opcache.enable': '0', 'php_profiler.mode': 'trigger', 'php_profiler.trigger_secret': 'test-secret',
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
        # FPM reads FastCGI parameters only: its startup environment, even holding the secret
        # itself, never shadows them. The worker writes error_log synchronously, before replying.
        leaked = dict(php_environment, HTTP_X_PHP_PROFILER_TRIGGER='test-secret')
        log = output / 'fpm-inherited.log'
        with FpmServer(extension, dict(settings, error_log=str(log)), environment=leaked) as server:
            for token, captured in ((None, False), ('test-secret', True)):
                params = {} if token is None else {'HTTP_X_PHP_PROFILER_TRIGGER': token}
                response = server.request_raw(str(fixture), params)
                assert response['body'] == b'ok' and not response['stderr'], response
                assert (b'X-Php-Profiler-Id' in response['headers']) == captured, response
            # A marker proves the worker writes this log, so the absence below is meaningful.
            marker = output / 'fpm-log-marker.php'
            marker.write_text("<?php error_log('fpm-log-marker'); echo 'ok';")
            marker.chmod(0o644)
            assert server.request_raw(str(marker))['body'] == b'ok'
            marker.unlink()
        assert b'fpm-log-marker' in log.read_bytes() and b'php_profiler' not in log.read_bytes(), log.read_bytes()
        log.unlink()
        reports = list(output.glob('*.json'))
        assert len(reports) == 1, 'FPM must capture a real header despite an inherited variable'
        reports[0].unlink()
        # `php-fpm -i` runs a request before any pool, so FPM's getenv still reads the
        # process environment: that request has no method and must not capture.
        information = subprocess.run(
            [os.environ.get('PHP_FPM', 'php-fpm'), '-n', '-d', 'extension=' + extension,
             *[f'-d{key}={value}' for key, value in settings.items()], '-i'],
            env=leaked, capture_output=True, timeout=60)
        reject_sanitizer_diagnostics(information.stderr.decode(errors='replace'), 'php-fpm -i')
        assert information.returncode == 0 and b'php_profiler' in information.stdout, information
        assert not list(output.glob('*.json')), 'php-fpm -i must not capture an inherited trigger'
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
        result = run_php(base + ['-r', source], capture_output=True)
        assert result.returncode == (255 if source.startswith('throw') else 0), result
        reports = list(output.glob('*.json'))
        assert len(reports) == count, (source, reports)
        for path in reports:
            report = json.loads(path.read_text())
            assert report['termination_reason'] == reason, report
            if source.startswith('register_shutdown'):
                assert report['functions']['shutdown_leaf']['calls'] == 1
            path.unlink()
    # PHP's INI parser passes unquoted Off/false/no/none as "" and On/true/yes as "1".
    for word in ('off', 'Off', 'false', 'none', ''):
        result = run_php(base + ['-d', 'php_profiler.mode=' + word, '-r', 'echo "ok";'],
                         capture_output=True, check=True)
        assert result.stdout == b'ok' and not result.stderr, (word, result)
        assert not list(output.glob('*.json'))
    for timeline, sampling, recorded in (('On', 'no', True), ('Off', 'false', False)):
        result = run_php(base + ['-d', 'php_profiler.timeline=' + timeline,
                                 '-d', 'php_profiler.sampling=' + sampling,
                                 '-r', 'function leaf() {} leaf(); echo "ok";'],
                         capture_output=True, check=True)
        assert result.stdout == b'ok' and not result.stderr, (timeline, result)
        reports = list(output.glob('*.json'))
        assert len(reports) == 1, reports
        assert bool(json.loads(reports[0].read_text())['timeline']) == recorded, timeline
        reports[0].unlink()
    # phpinfo() masks a configured trigger secret and still reports an unset one.
    for secret, shown in (('hidden-secret-value', '********'), ('', 'no value')):
        info = run_php(['php', '-n', '-d', 'extension=' + extension,
                        '-d', 'php_profiler.trigger_secret=' + secret, '-i'],
                       capture_output=True, check=True).stdout.decode()
        assert f'php_profiler.trigger_secret => {shown} => {shown}' in info, info[-2000:]
        assert 'hidden-secret-value' not in info
    os.mkdir(output / 'relative-directory')
    for name, value in [('mode', 'invalid'), ('max_events', '0'), ('max_events', '8193'),
                        ('timeline', 'invalid'), ('sampling', 'invalid'), ('output_dir', 'relative'), ('output_dir', directory + '/missing')]:
        result = run_php(base + ['-d', f'php_profiler.{name}={value}', '-r', 'echo "ok";'], capture_output=True)
        assert result.returncode == 0 and result.stdout == b'ok', result
        assert b'automatic capture failed' in result.stderr, result
        assert not list(output.glob('*.json'))
    # Exact INI bytes: values that are not UTF-8 or not numeric are configuration errors.
    for setting in (b'php_profiler.max_events=\xff', b'php_profiler.max_events=many',
                    b'php_profiler.output_dir=' + directory.encode() + b'/\xff'):
        result = run_php(base + ['-d', setting, '-r', 'echo "ok";'], capture_output=True, check=True)
        assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr, (setting, result)
        assert not list(output.glob('*.json'))
    relative = run_php(base + ['-d', 'php_profiler.output_dir=relative-directory', '-r', 'echo "ok";'], cwd=directory, capture_output=True, check=True)
    assert relative.stdout == b'ok' and b'automatic capture failed' in relative.stderr
    assert not list((output / 'relative-directory').glob('*.json'))
    result = run_php(base + ['-d', 'open_basedir=/nonexistent-profiler-test', '-r', 'echo "ok";'], capture_output=True, check=True)
    assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr
    assert not list(output.glob('*.json'))
    result = run_php(base + ['-d', 'php_profiler.timeline=1' , '-d', 'php_profiler.max_events=1',
                                  '-r', 'function a() {} a();'], capture_output=True, check=True)
    report = json.loads(next(output.glob('*.json')).read_text())
    assert report['timeline'], report
    for path in output.glob('*.json'):
        path.unlink()
    result = run_php(base + ['-d', 'php_profiler.sampling=1', '-r', 'usleep(20000);'], capture_output=True, check=True)
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
    result = run_php(base + ['-r', 'ini_set("open_basedir", "/nonexistent-profiler-test"); echo "ok";'], capture_output=True, check=True)
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
    with tempfile.TemporaryFile() as server_errors, subprocess.Popen(arguments, stdout=subprocess.DEVNULL, stderr=server_errors, env=php_environment) as server:
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
            server_errors.seek(0)
            reject_sanitizer_diagnostics(server_errors.read().decode(errors='replace'), 'automatic HTTP server')
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
        result = run_php(['php', '-n', '-d', 'extension=' + extension,
            '-d', 'php_profiler.mode=always', '-d', 'php_profiler.output_dir=' + str(current),
            '-r', 'echo "ok";'], capture_output=True, check=True)
        assert result.stdout == b'ok' and b'automatic capture failed' in result.stderr
        assert not list(current.glob('*.json'))
    print('automatic native path admission boundary passed')

# FrankenPHP's _SERVER starts from the process environment and ends with its configured env
# values, so its headers come from frankenphp_request_headers().
# Its PHP build must match the extension's, so this runs only where FRANKENPHP names one.
if os.environ.get('FRANKENPHP'):
    def stop_frankenphp(process, errors, failure=None):
        """Stops FrankenPHP and returns its log; diagnostics never mask an earlier failure."""
        process.send_signal(signal.SIGINT)
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        errors.seek(0)
        log = errors.read()
        errors.close()
        if failure is not None:
            failure.add_note(log.decode(errors='replace'))
        else:
            reject_sanitizer_diagnostics(log.decode(errors='replace'), 'FrankenPHP')
        return log

    def frankenphp(directory, environment, configured=None, mode='trigger', worker=None):
        """Serves root/ with php-server, or through a Caddyfile when `configured` names an env value.
        A `worker` script serves every request from one worker thread."""
        configuration = Path(directory, 'conf')
        root = Path(directory, 'root')
        configuration.mkdir()
        root.mkdir()
        (configuration / 'profiler.ini').write_text(
            f'extension={extension}\nphp_profiler.mode={mode}\n'
            f'php_profiler.trigger_secret=franken-secret\nphp_profiler.output_dir={directory}\n')
        (root / 'index.php').write_text(worker or '<?php echo "ok";')
        port = free_port()
        command = [os.environ['FRANKENPHP'], 'php-server', '--listen', f'127.0.0.1:{port}', '--root', str(root)]
        if worker:
            command += ['--worker', f'{root / "index.php"},1']
        if configured:
            caddyfile = Path(directory, 'Caddyfile')
            caddyfile.write_text(
                '{\n\tadmin off\n\tauto_https off\n}\n'
                f'http://127.0.0.1:{port} {{\n\troot * {root}\n'
                f'\tphp_server {{\n\t\tenv HTTP_X_PHP_PROFILER_TRIGGER {configured}\n\t}}\n}}\n')
            command = [os.environ['FRANKENPHP'], 'run', '--config', str(caddyfile), '--adapter', 'caddyfile']
        errors = tempfile.TemporaryFile()
        process = subprocess.Popen(
            command,
            cwd=root, stdout=subprocess.DEVNULL, stderr=errors,
            env=dict(environment, PHP_INI_SCAN_DIR=str(configuration), PHPRC=str(configuration)))
        try:
            wait_until_ready(process, lambda: accepts(port), 'FrankenPHP')
        except BaseException as error:
            stop_frankenphp(process, errors, error)
            raise
        return process, port, errors

    def franken_request(port, token, path='/'):
        headers = {} if token is None else {'X-Php-Profiler-Trigger': token}
        request = urllib.request.Request(f'http://127.0.0.1:{port}{path}', headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                body = response.read()
                # A worker reports its handler's uncaught exception in that response.
                if path == '/throw':
                    assert b'worker handler failure' in body, (token, body)
                else:
                    assert body == b'ok', (path, token, body)
                return response.headers.get('X-Php-Profiler-Id')
        except urllib.error.HTTPError as error:
            # Only the throwing handler may fail, and its response still names the capture.
            assert path == '/throw' and error.code == 500, (path, error.code)
            return error.headers.get('X-Php-Profiler-Id')

    for inherited, configured in ((None, None), ('franken-secret', None), (None, 'franken-secret')):
        environment = dict(php_environment)
        environment.pop('HTTP_X_PHP_PROFILER_TRIGGER', None)
        if inherited:
            environment['HTTP_X_PHP_PROFILER_TRIGGER'] = inherited
        with tempfile.TemporaryDirectory(prefix='profiler-franken-') as directory:
            process, port, errors = frankenphp(directory, environment, configured)
            try:
                captured = [franken_request(port, token) is not None
                            for token in (None, 'wrong', 'franken-secret', 'franken-secret')]
            except BaseException as error:
                stop_frankenphp(process, errors, error)
                raise
            log = stop_frankenphp(process, errors)
            reports = list(Path(directory).glob('*.json'))
            # Only real headers capture: neither an inherited nor a configured env value
            # stands in for a missing one, and neither is reported.
            expected = [False, False, True, True]
            assert captured == expected, (inherited, configured, captured, log)
            assert len(reports) == expected.count(True), (inherited, configured, reports)
            assert INHERITED not in log, (inherited, configured, log)
    print('automatic FrankenPHP trigger checks passed')

    # Worker mode serves every request inside the PHP request running the worker script; each
    # one is still its own profiler request, past the 16 sessions a request may hold.
    WORKER = """<?php
$handler = static function (): void {
    if ($_SERVER['REQUEST_URI'] === '/throw') {
        throw new RuntimeException('worker handler failure');
    }
    echo 'ok';
};
$between = false;
while (frankenphp_handle_request($handler)) {
    // A session started between requests ends before the worker waits for the next one.
    if (!$between) {
        PhpProfiler\Profiler::start(__DIR__ . '/between.json');
        $between = true;
    }
}
"""

    def franken_worker(mode, requests, delay=0):
        """Sends (token, path) requests to one worker thread, the first after `delay` seconds;
        returns the response IDs, the reports keyed by file name, the session started between
        requests, and FrankenPHP's log."""
        environment = dict(php_environment)
        environment.pop('HTTP_X_PHP_PROFILER_TRIGGER', None)
        with tempfile.TemporaryDirectory(prefix='profiler-franken-worker-') as directory:
            process, port, errors = frankenphp(directory, environment, mode=mode, worker=WORKER)
            try:
                time.sleep(delay)
                ids = [franken_request(port, token, path) for token, path in requests]
            except BaseException as error:
                stop_frankenphp(process, errors, error)
                raise
            log = stop_frankenphp(process, errors)
            reports = {path.name: json.loads(path.read_text()) for path in Path(directory).glob('*.json')}
            between = Path(directory, 'root', 'between.json')
            between = json.loads(between.read_text()) if between.exists() else None
        assert INHERITED not in log and b'automatic capture failed' not in log, log
        assert all(report['termination_reason'] == 'request_complete' for report in reports.values()), reports
        assert between is not None and between['termination_reason'] == 'request_shutdown', (between, log)
        return ids, reports, log

    secret = 'franken-secret'
    requests = [(None, '/'), ('wrong', '/'), (secret, '/'), (secret, '/'), (None, '/')]
    requests += [(secret, '/')] * 20 + [(secret, '/throw'), (secret, '/')]
    ids, reports, log = franken_worker('trigger', requests)
    expected = [False, False, True, True, False] + [True] * 22
    assert [identifier is not None for identifier in ids] == expected, (ids, log)
    captured = [identifier for identifier in ids if identifier is not None]
    assert len(set(captured)) == len(captured), captured
    assert set(reports) == {f'php-profile-{identifier}.json' for identifier in captured}, (sorted(reports), captured)
    # In always mode the worker script's start, up to its first request, is captured as well.
    # The start's report ends before the worker waits for its first request.
    ids, reports, log = franken_worker('always', [(None, '/')] * 5, delay=1.5)
    assert None not in ids and len(set(ids)) == 5, ids
    served = {f'php-profile-{identifier}.json' for identifier in ids}
    assert len(reports) == 6 and served < set(reports), (ids, sorted(reports))
    start = next(report for name, report in reports.items() if name not in served)
    assert start['elapsed_wall_ns'] < 1_000_000_000, start['elapsed_wall_ns']
    print('automatic FrankenPHP worker checks passed')

# Apache's mod_php answers getenv from the request, so an inherited variable shadows nothing.
# It needs an Apache built for this PHP, so this runs only where APACHE and APACHE_PHP_MODULE name one.
if os.environ.get('APACHE') and os.environ.get('APACHE_PHP_MODULE'):
    def stop_apache(process, directory, failure=None):
        """Stops Apache and returns PHP's log; diagnostics never mask an earlier failure."""
        process.send_signal(signal.SIGTERM)
        try:
            process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
        process.console.close()
        # Apache writes its own log once configured, and startup errors to the console before that.
        server_log = b''.join(path.read_bytes() for path in (directory / 'console.log', directory / 'apache.log')
                              if path.exists())
        log = (directory / 'php.log').read_bytes()
        if failure is not None:
            failure.add_note((server_log + log).decode(errors='replace'))
        else:
            reject_sanitizer_diagnostics((server_log + log).decode(errors='replace'), 'Apache')
            # A worker crash after its response only shows in Apache's log (AH00052).
            assert b' exit signal ' not in server_log, server_log
        return log

    def apache_modules():
        """Returns the directory holding Apache's own modules, which Homebrew and RHEL keep apart from libphp.so."""
        if os.environ.get('APACHE_MODULES'):
            return Path(os.environ['APACHE_MODULES'])
        beside_php = Path(os.environ['APACHE_PHP_MODULE']).parent
        if (beside_php / 'mod_mpm_prefork.so').exists():
            return beside_php
        # Homebrew and source builds install apxs beside httpd; RHEL puts it on PATH with httpd-devel.
        beside_apache = Path(shutil.which(os.environ['APACHE']) or os.environ['APACHE']).parent / 'apxs'
        apxs = os.environ.get('APXS') or (str(beside_apache) if beside_apache.exists() else shutil.which('apxs'))
        if apxs:
            modules = subprocess.run([apxs, '-q', 'LIBEXECDIR'], check=True, capture_output=True,
                                     text=True).stdout.strip()
            if (Path(modules) / 'mod_mpm_prefork.so').exists():
                return Path(modules)
        raise SystemExit('Apache modules not found beside APACHE_PHP_MODULE or through apxs; '
                         'set APACHE_MODULES to the directory holding mod_mpm_prefork.so')

    def apache(directory, environment):
        directory.chmod(0o755)
        output = directory / 'out'
        root = directory / 'root'
        configuration = directory / 'conf'
        for path in (output, root, configuration):
            path.mkdir()
        output.chmod(0o777)
        log = directory / 'php.log'
        log.touch()
        log.chmod(0o666)
        (configuration / 'php.ini').write_text(
            f'extension={extension}\nopcache.enable=0\nerror_log={log}\nphp_profiler.mode=trigger\n'
            f'php_profiler.trigger_secret=apache-secret\nphp_profiler.output_dir={output}\n')
        (root / 'index.php').write_text('<?php echo "ok";')
        (root / 'marker.php').write_text("<?php error_log('apache-log-marker'); echo 'ok';")
        port = free_port()
        modules = apache_modules()
        # Running as root, Apache serves from an unprivileged user, as in production.
        user = 'User www-data\nGroup www-data\n' if os.geteuid() == 0 else ''
        (directory / 'httpd.conf').write_text(
            f'ServerRoot {directory}\nServerName localhost\nListen 127.0.0.1:{port}\n'
            f'PidFile {directory}/httpd.pid\nDefaultRuntimeDir {directory}\nErrorLog {directory}/apache.log\n'
            f'LoadModule mpm_prefork_module {modules}/mod_mpm_prefork.so\n'
            f'LoadModule authz_core_module {modules}/mod_authz_core.so\n'
            f'LoadModule php_module {os.environ["APACHE_PHP_MODULE"]}\n{user}'
            f'DocumentRoot {root}\n<Directory {root}>\n  Require all granted\n</Directory>\n'
            'StartServers 2\n<FilesMatch "\\.php$">\n  SetHandler application/x-httpd-php\n</FilesMatch>\n')
        console = (directory / 'console.log').open('wb')
        process = subprocess.Popen(
            [os.environ['APACHE'], '-f', str(directory / 'httpd.conf'), '-DFOREGROUND'],
            stdout=console, stderr=subprocess.STDOUT,
            env=dict(environment, PHPRC=str(configuration), PHP_INI_SCAN_DIR=''))
        process.console = console
        try:
            wait_until_ready(process, lambda: accepts(port), 'Apache')
        except BaseException as error:
            stop_apache(process, directory, error)
            raise
        return process, port, output

    def apache_request(port, page, token):
        headers = {} if token is None else {'X-Php-Profiler-Trigger': token}
        request = urllib.request.Request(f'http://127.0.0.1:{port}/{page}', headers=headers)
        with urllib.request.urlopen(request, timeout=5) as response:
            assert response.read() == b'ok'
            return response.headers.get('X-Php-Profiler-Id')

    for inherited in (None, 'apache-secret'):
        environment = dict(php_environment)
        environment.pop('HTTP_X_PHP_PROFILER_TRIGGER', None)
        if inherited:
            environment['HTTP_X_PHP_PROFILER_TRIGGER'] = inherited
        with tempfile.TemporaryDirectory(prefix='profiler-apache-') as name:
            directory = Path(name)
            process, port, output = apache(directory, environment)
            try:
                captured = [apache_request(port, 'index.php', token) is not None
                            for token in (None, 'wrong', 'apache-secret,other', 'apache-secret', None)]
                # A marker proves the workers write this log, so the absence below is meaningful.
                assert apache_request(port, 'marker.php', None) is None
            except BaseException as error:
                stop_apache(process, directory, error)
                raise
            log = stop_apache(process, directory)
            # Apache reads request headers only: even an inherited copy of the secret neither
            # captures a request without the header nor stops a real header from capturing.
            assert captured == [False, False, False, True, False], (inherited, captured, log)
            assert len(list(output.glob('*.json'))) == 1, (inherited, list(output.iterdir()))
            assert b'apache-log-marker' in log and b'php_profiler' not in log, (inherited, log)
    print('automatic Apache trigger checks passed')
