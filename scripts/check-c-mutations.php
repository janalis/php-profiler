<?php

declare(strict_types=1);

// Requires every non-equivalent C mutant killed; never rewrites raw outcomes.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Json.php';
require_once __DIR__ . '/lib/Paths.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Json;
use PhpProfiler\Tooling\Paths;

const CONTEXT_KEYS = [
    'compiler_sha256',
    'compiler_version',
    'mull_version',
    'plugin_sha256',
    'compiler_flags',
    'native_compile_commands',
    'native_fixture_symbols',
    'system',
    'machine',
    'php_zts',
    'php_version_id',
    'php_int_size',
    'rustc_version',
    'rustflags',
    'cargo_encoded_rustflags',
    'libc_sha256',
];

function gateFail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function within(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
}

/** The audited identity of a mutant: source, mutator, exact span and replacement. */
function signature(string $source, mixed $mutant): string
{
    $location = is_object($mutant) ? $mutant->location ?? null : $mutant['location'] ?? null;
    $location = json_decode(json_encode($location), true);
    $coordinates = [];
    foreach (['start', 'end'] as $side) {
        foreach (['line', 'column'] as $axis) {
            $value = $location[$side][$axis] ?? null;
            if (!is_int($value) || $value <= 0) {
                gateFail('C mutation gate: invalid exact source span');
            }
            $coordinates[] = $value;
        }
    }
    $fields = is_object($mutant) ? (array) $mutant : $mutant;
    if (!array_key_exists('mutatorName', $fields) || !array_key_exists('replacement', $fields)) {
        gateFail('C mutation gate: mutant identity lacks a mutator or replacement');
    }
    return json_encode([
        $source,
        $fields['mutatorName'] ?? null,
        $coordinates,
        $fields['replacement'] ?? null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** Exact typed equality, like comparing type() and json.dumps(sort_keys=True) in Python. */
function sameTypedValue(mixed $actual, mixed $expected): bool
{
    return get_debug_type($actual) === get_debug_type($expected) && Json::encode($actual) === Json::encode($expected);
}

$usage = 'check-c-mutations.php <report-directory> [--workspace-root <root>] [--equivalence-ledger <path>]';
['options' => $options, 'positionals' => $positionals] = Arguments::parse(
    $argv,
    ['workspace-root', 'equivalence-ledger'],
    usage: $usage,
);
if (count($positionals) !== 1) {
    Arguments::fail('expected one report directory', $usage);
}
$reportDirectory = $positionals[0];
$workspace = $options['workspace-root'] ?? null;
$ledgerPath = $options['equivalence-ledger'] ?? null;
$expected = is_string($workspace) ? array_fill_keys(Paths::runtimeSources($workspace, 'c'), true) : null;
$ledger = [];
if (is_string($ledgerPath)) {
    if ($expected === null) {
        gateFail('C mutation gate: equivalence classification requires an exhaustive workspace registry');
    }
    $root = Paths::resolve($workspace);
    // Objects keep {} distinct from [] for exact typed comparison and faithful evidence.
    $document = json_decode((string) file_get_contents($ledgerPath), false, flags: JSON_THROW_ON_ERROR);
    $schema = $document->schema_version ?? null;
    if (
        !$document instanceof stdClass
        || !in_array($schema, [1, 2], true)
        || !is_array($document->equivalents ?? null)
    ) {
        gateFail('C mutation gate: unsupported equivalence ledger');
    }
    $contextPath = "{$reportDirectory}/provenance/equivalence-context.json";
    $context = is_file($contextPath)
        ? json_decode((string) file_get_contents($contextPath), false, flags: JSON_THROW_ON_ERROR)
        : new stdClass();
    $auditedContexts = $document->contexts ?? new stdClass();
    $dependencies = $document->dependency_sha256 ?? new stdClass();
    if ($schema === 2) {
        $complete = $auditedContexts instanceof stdClass && (array) $auditedContexts !== [];
        foreach ($complete ? (array) $auditedContexts : [] as $value) {
            $complete =
                $complete && $value instanceof stdClass && array_diff(CONTEXT_KEYS, array_keys((array) $value)) === [];
        }
        if (!$complete) {
            gateFail('C mutation gate: equivalence contexts must pin the complete native configuration');
        }
        if (!$dependencies instanceof stdClass || (array) $dependencies === []) {
            gateFail('C mutation gate: equivalence dependency hashes missing');
        }
        foreach ((array) $dependencies as $name => $digest) {
            $dependency = Paths::resolve("{$root}/{$name}");
            if (!within($dependency, $root) || !is_file($dependency) || hash_file('sha256', $dependency) !== $digest) {
                gateFail('C mutation gate: equivalence dependency changed; new audit required');
            }
        }
    }
    foreach ($document->equivalents as $entry) {
        if ($schema === 2) {
            // A missing or different context never grants an equivalent classification.
            $names = $entry->contexts ?? null;
            if (!is_array($names) || $names === []) {
                gateFail('C mutation gate: unknown or missing equivalence context reference');
            }
            foreach ($names as $name) {
                if (!is_string($name) || !property_exists($auditedContexts, $name)) {
                    gateFail('C mutation gate: unknown or missing equivalence context reference');
                }
            }
            if (count($names) !== count(array_unique($names))) {
                gateFail('C mutation gate: duplicate equivalence context reference');
            }
            $matches = false;
            foreach ($names as $name) {
                $variant = (array) $auditedContexts->{$name};
                $all = true;
                foreach ($variant as $key => $value) {
                    $all =
                        $all
                        && $context instanceof stdClass
                        && property_exists($context, (string) $key)
                        && sameTypedValue($context->{$key}, $value);
                }
                $matches = $matches || $all;
            }
            if (!$matches) {
                continue;
            }
        }
        $source = Paths::resolve("{$root}/" . ($entry->source ?? ''));
        if (!isset($expected[$source])) {
            gateFail('C mutation gate: equivalence source outside runtime registry');
        }
        if (hash_file('sha256', $source) !== ($entry->source_sha256 ?? null)) {
            gateFail('C mutation gate: equivalence source hash changed; new audit required');
        }
        $proof = Paths::resolve("{$root}/" . explode('#', (string) ($entry->proof ?? ''), 2)[0]);
        if (!within($proof, $root) || !is_file($proof)) {
            gateFail('C mutation gate: audited equivalence proof missing');
        }
        if ($schema === 2 && !property_exists($dependencies, substr($proof, strlen($root) + 1))) {
            gateFail('C mutation gate: equivalence proof hash missing');
        }
        $key = signature($source, $entry);
        if (isset($ledger[$key])) {
            gateFail('C mutation gate: duplicate equivalence classification');
        }
        $ledger[$key] = $entry;
    }
}

$files = glob("{$reportDirectory}/*.json") ?: [];
sort($files, SORT_STRING);
if ($files === []) {
    gateFail('C mutation gate: no Elements JSON report produced');
}
$statuses = [];
$seen = [];
$equivalent = [];
$failures = [];
$classified = [];
foreach ($files as $path) {
    $report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($report) || !array_key_exists('files', $report)) {
        gateFail("C mutation gate: malformed Elements report {$path}");
    }
    foreach ($report['files'] as $filename => $source) {
        $resolved = Paths::resolve((string) $filename);
        if ($expected !== null) {
            if (!isset($expected[$resolved]) || isset($seen[$resolved])) {
                gateFail("C mutation gate: unexpected or duplicate runtime source: {$filename}");
            }
            $seen[$resolved] = true;
            if (($source['mutants'] ?? []) === []) {
                gateFail("C mutation gate: runtime source generated no mutations: {$filename}");
            }
        }
        foreach ($source['mutants'] as $mutant) {
            $status = $mutant['status'];
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            if ($status === 'Killed') {
                continue;
            }
            if ($status === 'Survived' && $ledger !== [] && isset($ledger[$key = signature($resolved, $mutant)])) {
                if (isset($classified[$key])) {
                    gateFail('C mutation gate: duplicate audited mutant match');
                }
                $classified[$key] = true;
                $equivalent[] = [
                    'raw_id' => $mutant['id'],
                    'source' => $resolved,
                    'raw_status' => $status,
                    'classification' => 'Equivalent',
                    'ledger' => $ledger[$key],
                ];
            } else {
                $failures[] = ($mutant['id'] ?? $filename) . ': ' . $status;
            }
        }
    }
}
// Every seen source is already a registered one, so equal counts mean equal sets.
if ($expected !== null && ($expected === [] || count($seen) !== count($expected))) {
    $missing = array_map(strval(...), array_keys(array_diff_key($expected, $seen)));
    sort($missing, SORT_STRING);
    gateFail(
        'C mutation gate: exhaustive runtime registry mismatch; missing='
            . json_encode($missing, JSON_UNESCAPED_SLASHES),
    );
}
$rawTotal = array_sum($statuses);
$killed = $statuses['Killed'] ?? 0;
$nonEquivalent = $rawTotal - count($equivalent);
echo 'C mutation raw outcomes: ', Json::encode((object) $statuses, sortKeys: false), "\n";
$classification = [
    'raw_outcomes' => (object) $statuses,
    'raw_killed' => $killed,
    'raw_generated' => $rawTotal,
    'audited_equivalent_count' => count($equivalent),
    'audited_equivalents' => $equivalent,
    'non_equivalent_generated' => $nonEquivalent,
    'non_equivalent_killed' => $killed,
    'unclassified_failures' => $failures,
];
$provenance = "{$reportDirectory}/provenance";
if (!is_dir($provenance)) {
    mkdir($provenance);
}
if (is_string($ledgerPath)) {
    copy($ledgerPath, "{$provenance}/audited-equivalence-ledger.json")
        || gateFail('C mutation gate: could not retain the ledger');
    $classification['audit_manifest_sha256'] = hash_file('sha256', $ledgerPath);
}
file_put_contents(
    "{$provenance}/classification.json",
    Json::encode($classification, indent: 2, sortKeys: false) . "\n",
);
if ($rawTotal === 0 || $nonEquivalent === 0) {
    gateFail('C mutation gate: no non-equivalent mutants; instrumentation/filter not verified');
}
if ($failures !== [] || $killed !== $nonEquivalent) {
    gateFail('C mutation gate: non-equivalent mutants not killed: ' . implode('; ', $failures));
}
printf(
    "C mutation gate: raw %d/%d killed; %d audited equivalent; non-equivalent %d/%d killed\n",
    $killed,
    $rawTotal,
    count($equivalent),
    $killed,
    $nonEquivalent,
);
