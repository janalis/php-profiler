<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use RuntimeException;

/** A minimal HTTP/1.1 client that sends header bytes exactly as given. */
final class HttpClient
{
    /**
     * @param array<string, string> $headers sent byte for byte, in order
     * @return array{status: int, headers: list<array{string, string}>, body: string}
     */
    public static function get(int $port, string $path, array $headers = [], float $timeout = 5.0): array
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, $timeout);
        if ($socket === false) {
            throw new RuntimeException("connect 127.0.0.1:{$port}: {$message}");
        }
        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1e6));
        $request = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept-Encoding: identity\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        fwrite($socket, $request . "\r\n");
        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || $chunk === '' && stream_get_meta_data($socket)['timed_out']) {
                fclose($socket);
                throw new RuntimeException('HTTP response timed out');
            }
            $response .= $chunk;
        }
        fclose($socket);
        $separator = strpos($response, "\r\n\r\n");
        if ($separator === false || !preg_match('#\AHTTP/1\.[01] (\d{3})#', $response, $status)) {
            throw new RuntimeException('malformed HTTP response: ' . substr($response, 0, 200));
        }
        $parsed = [];
        foreach (array_slice(explode("\r\n", substr($response, 0, $separator)), 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $parsed[] = [$name, trim($value)];
        }
        $body = substr($response, $separator + 4);
        if (strcasecmp((string) self::header($parsed, 'Transfer-Encoding'), 'chunked') === 0) {
            $body = self::dechunk($body);
        }
        return ['status' => (int) $status[1], 'headers' => $parsed, 'body' => $body];
    }

    /** The first value of a header, compared case-insensitively, like email.message.get(). */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as [$candidate, $value]) {
            if (strcasecmp($candidate, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    private static function dechunk(string $body): string
    {
        $decoded = '';
        while (preg_match('/\A([0-9a-fA-F]+)[^\r\n]*\r\n/', $body, $size)) {
            $length = hexdec($size[1]);
            if ($length === 0) {
                return $decoded;
            }
            $decoded .= substr($body, strlen($size[0]), $length);
            $body = substr($body, strlen($size[0]) + $length + 2);
        }
        throw new RuntimeException('malformed chunked HTTP body');
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message)
        ?: throw new RuntimeException("bind: {$message}");
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public static function accepts(int $port): bool
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 1);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
