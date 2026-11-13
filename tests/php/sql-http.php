<?php

declare(strict_types=1);

use PhpProfiler\Profiler;

foreach (['pdo_sqlite', 'curl', 'pcntl'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException("Required native integration capability unavailable: $extension");
    }
}
$database = new PDO('sqlite::memory:');
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($server, false);
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('fork failed');
}
if ($pid === 0) {
    $connection = stream_socket_accept($server, 5);
    if ($connection === false) {
        exit(2);
    }
    stream_set_timeout($connection, 5);
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
    }
    $body = 'private_http_response';
    fwrite(
        $connection,
        "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
    );
    fclose($connection);
    fclose($server);
    exit(0);
}
fclose($server);
$handle = curl_init('http://' . $address . '/private_http_path?token=private_http_token');
curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
try {
    $session = Profiler::start();
    $database->exec('CREATE TABLE private_sql_marker (value INTEGER)');
    $statement = $database->prepare('INSERT INTO private_sql_marker VALUES (?)');
    $statement->execute([42]);
    $result = $database->query('SELECT value FROM private_sql_marker')->fetchColumn();
    $body = curl_exec($handle);
    $session->stop();
    $report = $session->getReport();
    if ((int) $result !== 42 || $body !== 'private_http_response') {
        throw new RuntimeException('SQL or HTTP semantics changed');
    }
    if ($report['operations']['sql']['calls'] !== 3 || $report['operations']['http']['calls'] !== 1) {
        throw new RuntimeException('native SQL/HTTP operation counts wrong');
    }
    $json = $session->getReportJson();
    foreach ([
        'private_sql_marker',
        'private_http_response',
        'private_http_path',
        'private_http_token',
        $address,
    ] as $private) {
        if (str_contains($json, $private)) {
            throw new RuntimeException('sensitive operation data leaked');
        }
    }
    $session->reset();
    Profiler::disable();
} finally {
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('local HTTP fixture server failed');
    }
}
echo "real SQLite and loopback HTTP categories and privacy passed\n";
