<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/Paths.php';

/**
 * An isolated static FPM pool on a private socket, with a small FastCGI client.
 * Concurrent requests share one event loop over several connections, so a pool of
 * N workers really serves N requests at once.
 */
final class FpmServer
{
    private const int BEGIN_REQUEST = 1;
    private const int END_REQUEST = 3;
    private const int PARAMS = 4;
    private const int STDIN = 5;
    private const int STDOUT = 6;
    private const int STDERR = 7;

    private string $directory;
    private string $address;
    /** @var resource|null */
    private $process = null;

    /**
     * @param array<string, string|int> $iniSettings
     * @param array<string, string>|null $environment
     */
    public function __construct(
        private readonly ?string $extension = null,
        private readonly array $iniSettings = [],
        private readonly int $workers = 1,
        private readonly ?array $environment = null,
    ) {
        if ($workers < 1) {
            throw new InvalidArgumentException('FPM worker count must be a positive integer');
        }
    }

    /**
     * Runs $body against a started pool and always stops it; a failure carries the
     * master's diagnostics.
     *
     * @template T
     * @param callable(self): T $body
     * @return T
     */
    public function serve(callable $body): mixed
    {
        $this->start();
        try {
            $result = $body($this);
        } catch (\Throwable $failure) {
            $diagnostics = $this->stop();
            throw $diagnostics === ''
                ? $failure
                : new RuntimeException(
                    $failure->getMessage() . "\nFPM master diagnostics:\n" . $diagnostics,
                    0,
                    $failure,
                );
        }
        $this->stop();
        return $result;
    }

    /** @param array<string, string> $parameters */
    public function request(string $script, array $parameters = []): array
    {
        return self::decode($this->requestRaw($script, $parameters));
    }

    /**
     * @param array<string, string> $parameters
     * @return array{headers: string, body: string, stderr: string, application_status: int, protocol_status: int}
     */
    public function requestRaw(string $script, array $parameters = []): array
    {
        return $this->concurrent([[$script, $parameters]], 1)[0];
    }

    /**
     * Sends every request with at most $concurrency connections open at once and
     * returns the raw responses in request order.
     *
     * @param list<array{string, array<string, string>}> $requests
     * @return list<array{headers: string, body: string, stderr: string, application_status: int, protocol_status: int}>
     */
    public function concurrent(array $requests, int $concurrency, float $timeout = 30.0): array
    {
        $results = [];
        $open = [];
        $next = 0;
        while (count($results) < count($requests)) {
            while (count($open) < $concurrency && $next < count($requests)) {
                [$script, $parameters] = $requests[$next];
                $socket = stream_socket_client('unix://' . $this->address, $code, $message, $timeout);
                if ($socket === false) {
                    throw new RuntimeException("connect FPM: {$message}");
                }
                fwrite($socket, self::encodeRequest($script, $parameters));
                stream_set_blocking($socket, false);
                $open[$next] = ['socket' => $socket, 'buffer' => '', 'stdout' => '', 'stderr' => ''];
                $next++;
            }
            $read = array_column($open, 'socket');
            $write = $except = null;
            if (stream_select($read, $write, $except, (int) $timeout, (int) (fmod($timeout, 1) * 1e6)) === 0) {
                throw new RuntimeException('FastCGI response timed out');
            }
            foreach ($open as $index => &$connection) {
                if (!in_array($connection['socket'], $read, true)) {
                    continue;
                }
                $data = fread($connection['socket'], 65536);
                if ($data === '' || $data === false) {
                    if (feof($connection['socket'])) {
                        throw new RuntimeException('truncated FastCGI response');
                    }
                    continue;
                }
                $connection['buffer'] .= $data;
                $ended = self::consume($connection);
                if ($ended !== null) {
                    fclose($connection['socket']);
                    $results[$index] = $ended;
                    unset($open[$index]);
                }
            }
            unset($connection);
        }
        ksort($results);
        return array_values($results);
    }

    private function start(): void
    {
        $this->directory = sys_get_temp_dir() . '/php-profiler-fpm-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o700) || throw new RuntimeException('create ' . $this->directory);
        $this->address = $this->directory . '/fpm.sock';
        $config = $this->directory . '/fpm.conf';
        file_put_contents($config, <<<INI
            [global]
            error_log = {$this->directory}/error.log
            [www]
            user = www-data
            group = www-data
            listen = {$this->address}
            pm = static
            pm.max_children = {$this->workers}
            catch_workers_output = yes
            clear_env = no

            INI);
        $command = [(string) getenv('PHP_FPM') ?: 'php-fpm', '-n', '-F', '-y', $config];
        if ($this->extension !== null && $this->extension !== '') {
            array_push($command, '-d', 'extension=' . $this->extension);
        }
        foreach ($this->iniSettings as $name => $value) {
            array_push($command, '-d', "{$name}={$value}");
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/stdout', 'w'],
            2 => ['file', $this->directory . '/stderr', 'w'],
        ];
        $this->process = proc_open($command, $descriptors, $pipes, null, $this->environment)
        ?: throw new RuntimeException('start php-fpm');
        try {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                if (file_exists($this->address)) {
                    return;
                }
                if (!proc_get_status($this->process)['running']) {
                    throw new RuntimeException((string) file_get_contents($this->directory . '/stderr'));
                }
                usleep(50_000);
            }
            throw new RuntimeException('FPM socket startup timed out');
        } catch (\Throwable $failure) {
            $this->stop();
            throw $failure;
        }
    }

    /** Stops the master and returns its error log. */
    private function stop(): string
    {
        $log = $this->directory . '/error.log';
        $diagnostics = is_file($log) ? (string) file_get_contents($log) : '';
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGTERM);
                $deadline = hrtime(true) + 10_000_000_000;
                while (proc_get_status($this->process)['running'] && hrtime(true) < $deadline) {
                    usleep(10_000);
                }
                if (proc_get_status($this->process)['running']) {
                    proc_terminate($this->process, SIGKILL);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
        Paths::removeTree($this->directory);
        return $diagnostics;
    }

    /** @param array<string, string> $parameters */
    private static function encodeRequest(string $script, array $parameters): string
    {
        $parameters = [
            'SCRIPT_FILENAME' => Paths::resolve($script),
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => '/request.php',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            ...$parameters,
        ];
        $pairs = '';
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            $pairs .= self::length(strlen($key)) . self::length(strlen($value)) . $key . $value;
        }
        return (
            self::record(self::BEGIN_REQUEST, pack('nCx5', 1, 0))
            . self::record(self::PARAMS, $pairs)
            . self::record(self::PARAMS)
            . self::record(self::STDIN)
        );
    }

    private static function record(int $type, string $body = ''): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($body), 0, 0) . $body;
    }

    private static function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /**
     * Parses every complete record in the buffer; returns the response once the
     * end-of-request record arrives.
     *
     * @param array{buffer: string, stdout: string, stderr: string} $connection
     */
    private static function consume(array &$connection): ?array
    {
        while (strlen($connection['buffer']) >= 8) {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', $connection['buffer']);
            $size = 8 + $header['length'] + $header['padding'];
            if (strlen($connection['buffer']) < $size) {
                return null;
            }
            $body = substr($connection['buffer'], 8, $header['length']);
            $connection['buffer'] = substr($connection['buffer'], $size);
            if ($header['type'] === self::STDOUT) {
                $connection['stdout'] .= $body;
            } elseif ($header['type'] === self::STDERR) {
                $connection['stderr'] .= $body;
            } elseif ($header['type'] === self::END_REQUEST) {
                $status = unpack('Napplication/Cprotocol', $body);
                $separator = strpos($connection['stdout'], "\r\n\r\n");
                if ($separator === false) {
                    throw new RuntimeException('missing FastCGI response header boundary');
                }
                return [
                    'headers' => substr($connection['stdout'], 0, $separator),
                    'body' => substr($connection['stdout'], $separator + 4),
                    'stderr' => $connection['stderr'],
                    'application_status' => $status['application'],
                    'protocol_status' => $status['protocol'],
                ];
            }
        }
        return null;
    }

    public static function decode(array $result): array
    {
        if ($result['stderr'] !== '') {
            throw new RuntimeException($result['stderr']);
        }
        if ($result['application_status'] !== 0 || $result['protocol_status'] !== 0) {
            throw new RuntimeException('FastCGI application failure');
        }
        try {
            return json_decode($result['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw new RuntimeException('invalid FastCGI response: ' . $result['body'], 0, $failure);
        }
    }
}
