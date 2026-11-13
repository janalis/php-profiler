"""Small standard-library FastCGI client and isolated FPM pool for local tests."""
import json
import os
import socket
import struct
import subprocess
import tempfile
import time


def record(kind, body=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(body), 0, 0) + body


def pair(key, value):
    key, value = key.encode(), value.encode()
    def length(number):
        return bytes([number]) if number < 128 else struct.pack('!I', number | 0x80000000)
    return length(len(key)) + length(len(value)) + key + value


def exact(connection, length):
    result = b''
    while len(result) < length:
        part = connection.recv(length - len(result))
        if not part:
            raise RuntimeError('truncated FastCGI response')
        result += part
    return result


class FpmServer:
    def __init__(self, extension=None, ini_settings=None, workers=1):
        if type(workers) is not int or workers < 1:
            raise ValueError("FPM worker count must be a positive integer")
        self.workers = workers
        self.extension = extension
        self.ini_settings = dict(ini_settings or {})
        self.process = None
        self.directory = None

    def __enter__(self):
        self.directory = tempfile.TemporaryDirectory(prefix='php-profiler-fpm-')
        directory = self.directory.name
        self.address = os.path.join(directory, 'fpm.sock')
        config = os.path.join(directory, 'fpm.conf')
        with open(config, 'w') as output:
            output.write(f'''[global]
error_log = {directory}/error.log
[www]
user = www-data
group = www-data
listen = {self.address}
pm = static
pm.max_children = {self.workers}
catch_workers_output = yes
clear_env = no
''')
        command = [os.environ.get('PHP_FPM', 'php-fpm'), '-n', '-F', '-y', config]
        if self.extension:
            command += ['-d', 'extension=' + self.extension]
        for name, value in self.ini_settings.items():
            command += ['-d', f'{name}={value}']
        self.process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            for attempt in range(100):
                if os.path.exists(self.address):
                    return self
                if self.process.poll() is not None:
                    raise RuntimeError(self.process.communicate()[1].decode())
                time.sleep(0.05)
            raise RuntimeError('FPM socket startup timed out')
        except BaseException:
            self.__exit__(None, None, None)
            raise

    def __exit__(self, exception_type, exception, traceback):
        diagnostics = ""
        if self.directory:
            log = os.path.join(self.directory.name, "error.log")
            if os.path.exists(log):
                with open(log) as source:
                    diagnostics = source.read()
        if self.process is not None and self.process.poll() is None:
            self.process.terminate()
            try:
                self.process.communicate(timeout=10)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.communicate()
        if exception is not None and diagnostics:
            exception.add_note("FPM master diagnostics:\n" + diagnostics)
        if self.directory:
            self.directory.cleanup()

    def request_raw(self, script, extra_params=None):
        parameters = {'SCRIPT_FILENAME': os.path.abspath(script), 'REQUEST_METHOD': 'GET',
                      'SCRIPT_NAME': '/request.php', 'SERVER_PROTOCOL': 'HTTP/1.1',
                      'GATEWAY_INTERFACE': 'CGI/1.1'}
        parameters.update(extra_params or {})
        with socket.socket(socket.AF_UNIX) as connection:
            connection.settimeout(30)
            connection.connect(self.address)
            connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) +
                               record(4, b''.join(pair(k, v) for k, v in parameters.items())) + record(4) + record(5))
            output, errors = b'', b''
            while True:
                version, kind, identifier, length, padding, reserved = struct.unpack('!BBHHBB', exact(connection, 8))
                body = exact(connection, length)
                exact(connection, padding)
                if kind == 6:
                    output += body
                elif kind == 7:
                    errors += body
                elif kind == 3:
                    application_status, protocol_status = struct.unpack('!IB3x', body)
                    break
            headers, separator, body = output.partition(b'\r\n\r\n')
            if not separator:
                raise RuntimeError('missing FastCGI response header boundary')
            return {'headers': headers, 'body': body, 'stderr': errors,
                    'application_status': application_status, 'protocol_status': protocol_status}

    def request(self, script, extra_params=None):
        result = self.request_raw(script, extra_params)
        if result['stderr']:
            raise RuntimeError(result['stderr'].decode())
        if result['application_status'] or result['protocol_status']:
            raise RuntimeError('FastCGI application failure')
        try:
            return json.loads(result['body'])
        except ValueError as failure:
            raise RuntimeError('invalid FastCGI response: ' + result['body'].decode(errors='replace')) from failure
