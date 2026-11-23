<?php

declare(strict_types=1);

// Converts a JSON report into Callgrind, Chrome Trace Event JSON or folded stacks.
// Runs on a plain PHP 8.3+ CLI with no extension: php -n scripts/export-report.php ...
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/ReportExport.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\ReportExport;
use PhpProfiler\Tooling\ReportExportError;

$usage = 'export-report.php <report.json> --format callgrind|chrome-trace|collapsed [--output <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse($argv, ['format', 'output'], usage: $usage);
if (count($positionals) !== 1) {
    Arguments::fail('expected exactly one report path', $usage);
}
$format = $options['format'] ?? Arguments::fail('--format is required', $usage);
if (!is_string($format) || !in_array($format, ReportExport::FORMATS, true)) {
    Arguments::fail('--format must be one of: ' . implode(', ', ReportExport::FORMATS), $usage);
}
$output = $options['output'] ?? null;
try {
    $result = ReportExport::exportFile($positionals[0], $format);
    if (is_string($output)) {
        ReportExport::write($output, $result['output']);
    } else {
        fwrite(STDOUT, $result['output']);
    }
} catch (ReportExportError $error) {
    fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");
    exit(1);
}
foreach ($result['notes'] as $note) {
    fwrite(STDERR, "note: {$note}\n");
}
