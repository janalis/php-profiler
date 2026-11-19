<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use PhpProfiler\Tooling\Process;

/** C mutation evidence must cover the entire runtime registry without survivors. */
function runMutationGate(
    string $status = 'Killed',
    bool $missing = false,
    bool $empty = false,
    bool $extra = false,
): array {
    $root = temporaryDirectory();
    $source = "{$root}/crates/adapter/src/bridge.c";
    writeFile($source, 'int native(void) { return 1; }');
    $files = $missing ? [] : [$source => ['mutants' => $empty ? [] : [['status' => $status, 'id' => 'only']]]];
    if ($extra) {
        $files["{$root}/tests/fault.c"] = ['mutants' => [['status' => 'Killed']]];
    }
    writeFile("{$root}/reports/elements.json", json_encode(['files' => (object) $files]));
    return Process::run([
        ROOT . '/scripts/php-tool.sh',
        ROOT . '/scripts/check-c-mutations.php',
        "{$root}/reports",
        '--workspace-root',
        $root,
    ]);
}

function auditedContext(): array
{
    return [
        'compiler_sha256' => 'compiler',
        'compiler_version' => 'version',
        'mull_version' => 'mull',
        'plugin_sha256' => 'audited',
        'compiler_flags' => ['-O0'],
        'native_compile_commands' => ['bridge-unit.c' => ['-O0'], 'sampling-unit.c' => ['-O0']],
        'native_fixture_symbols' => ['__wrap_clock_gettime'],
        'system' => 'Linux',
        'machine' => 'x86_64',
        'php_zts' => false,
        'php_version_id' => 80336,
        'php_int_size' => 8,
        'rustc_version' => 'stable',
        'rustflags' => '',
        'cargo_encoded_rustflags' => '',
        'libc_sha256' => 'libc',
    ];
}

/** @param array<string, mixed>|null $context */
function runLedger(?Closure $change = null, mixed $context = null): array
{
    $root = temporaryDirectory();
    $source = "{$root}/crates/adapter/src/bridge.c";
    writeFile($source, "audited compare exchange loop\n");
    writeFile("{$root}/docs/equivalence.md", 'reviewed proof');
    $entry = [
        'source' => 'crates/adapter/src/bridge.c',
        'source_sha256' => hash_file('sha256', $source),
        'mutatorName' => 'cxx_init_const',
        'location' => ['start' => ['line' => 1, 'column' => 1], 'end' => ['line' => 1, 'column' => 6]],
        'replacement' => '42',
        'proof' => 'docs/equivalence.md',
    ];
    $mutant = [
        'mutatorName' => $entry['mutatorName'],
        'location' => $entry['location'],
        'replacement' => $entry['replacement'],
        'id' => 'equivalent-initial-guess',
        'status' => 'Survived',
    ];
    $mutants = [['id' => 'ordinary', 'status' => 'Killed'], $mutant];
    $ledger = ['schema_version' => 1, 'equivalents' => [$entry]];
    if ($change !== null) {
        $change($ledger, $mutants);
    }
    if ($context !== null) {
        writeFile(
            "{$root}/reports/provenance/equivalence-context.json",
            json_encode($context, JSON_PRESERVE_ZERO_FRACTION),
        );
    }
    writeFile("{$root}/reports/elements.json", json_encode(['files' => [$source => ['mutants' => $mutants]]]));
    writeFile("{$root}/ledger.json", json_encode($ledger));
    $result = Process::run([
        ROOT . '/scripts/php-tool.sh',
        ROOT . '/scripts/check-c-mutations.php',
        "{$root}/reports",
        '--workspace-root',
        $root,
        '--equivalence-ledger',
        "{$root}/ledger.json",
    ]);
    $classification = "{$root}/reports/provenance/classification.json";
    return [$result, is_file($classification) ? json_decode((string) file_get_contents($classification), true) : null];
}

function contextAudit(?array $contexts = null, ?array $dependencies = null): Closure
{
    return static function (array &$ledger, array &$mutants) use ($contexts, $dependencies): void {
        $ledger['schema_version'] = 2;
        $ledger['contexts'] = (object) array_map(strval(...), []);
        $values = $contexts ?? [auditedContext()];
        $ledger['contexts'] = [];
        foreach ($values as $index => $value) {
            $ledger['contexts'][(string) $index] = $value;
        }
        $ledger['contexts'] = (object) $ledger['contexts'];
        $ledger['equivalents'][0]['contexts'] = array_map(strval(...), array_keys((array) $ledger['contexts']));
        $ledger['dependency_sha256'] = (object) (
            $dependencies ?? [
                $ledger['equivalents'][0]['source'] => $ledger['equivalents'][0]['source_sha256'],
                'docs/equivalence.md' => hash('sha256', 'reviewed proof'),
            ]
        );
    };
}

return [
    'all registered source mutants must be killed' => static function (): void {
        $result = runMutationGate();
        same(0, $result['status'], $result['stderr']);
    },
    'survivor is not treated as equivalent' => static function (): void {
        same(1, runMutationGate(status: 'Survived')['status']);
    },
    'missing empty or extra source is rejected' => static function (): void {
        same(1, runMutationGate(missing: true)['status']);
        same(1, runMutationGate(empty: true)['status']);
        same(1, runMutationGate(extra: true)['status']);
    },
    'scoped equivalence requires exact typed context' => static function (): void {
        $valid = [...auditedContext(), 'extra' => 'allowed'];
        [$result, $evidence] = runLedger(contextAudit(), $valid);
        same(0, $result['status'], $result['stderr']);
        same(1, $evidence['audited_equivalent_count']);
        foreach ([
            null,
            new stdClass(),
            [...$valid, 'php_zts' => 0],
            [...$valid, 'php_zts' => true],
            [...$valid, 'plugin_sha256' => 'different'],
            [...$valid, 'php_int_size' => 8.0],
        ] as $context) {
            same(1, runLedger(contextAudit(), $context)[0]['status'], json_encode($context) . ':');
        }
    },
    'nested configuration types are exact' => static function (): void {
        $audit = contextAudit([[...auditedContext(), 'flags' => ['enabled' => false]]]);
        same(1, runLedger($audit, [...auditedContext(), 'flags' => ['enabled' => 0]])[0]['status']);
        same(1, runLedger($audit, [...auditedContext(), 'flags' => [false]])[0]['status']);
        same(0, runLedger($audit, [...auditedContext(), 'flags' => ['enabled' => false]])[0]['status']);
        $empty = contextAudit([[...auditedContext(), 'flags' => new stdClass()]]);
        same(
            1,
            runLedger($empty, [...auditedContext(), 'flags' => []])[0]['status'],
            'an empty list must not match an empty map:',
        );
        same(0, runLedger($empty, [...auditedContext(), 'flags' => new stdClass()])[0]['status']);
    },
    'scoped equivalence rejects missing or changed dependencies' => static function (): void {
        foreach ([
            [],
            ['missing' => str_repeat('0', 64)],
            ['../outside' => str_repeat('0', 64)],
            ['crates/adapter/src/bridge.c' => str_repeat('0', 64)],
        ] as $dependencies) {
            same(
                1,
                runLedger(contextAudit(dependencies: $dependencies), auditedContext())[0]['status'],
                json_encode($dependencies) . ':',
            );
        }
    },
    'scoped equivalence requires the proof bytes to be hashed' => static function (): void {
        $change = static function (array &$ledger, array &$mutants): void {
            contextAudit()($ledger, $mutants);
            $dependencies = (array) $ledger['dependency_sha256'];
            unset($dependencies['docs/equivalence.md']);
            $ledger['dependency_sha256'] = (object) $dependencies;
        };
        same(1, runLedger($change, auditedContext())[0]['status']);
    },
    'scoped equivalence requires nonempty context maps' => static function (): void {
        foreach ([[], [[]], [['php_zts' => false]], ['invalid']] as $contexts) {
            same(1, runLedger(contextAudit($contexts), new stdClass())[0]['status'], json_encode($contexts) . ':');
        }
        $schemaOnly = static function (array &$ledger): void {
            $ledger['schema_version'] = 2;
        };
        same(1, runLedger($schemaOnly, new stdClass())[0]['status']);
    },
    'unknown and duplicate context references fail closed' => static function (): void {
        foreach ([['missing'], ['0', '0'], []] as $names) {
            $change = static function (array &$ledger, array &$mutants) use ($names): void {
                contextAudit()($ledger, $mutants);
                $ledger['equivalents'][0]['contexts'] = $names;
            };
            same(1, runLedger($change, auditedContext())[0]['status'], json_encode($names) . ':');
        }
    },
    'exact audit preserves raw survived and separate denominator' => static function (): void {
        [$result, $evidence] = runLedger();
        same(0, $result['status'], $result['stderr']);
        same(['Killed' => 1, 'Survived' => 1], $evidence['raw_outcomes']);
        same([1, 2, 1, 1], [
            $evidence['raw_killed'],
            $evidence['raw_generated'],
            $evidence['audited_equivalent_count'],
            $evidence['non_equivalent_generated'],
        ]);
        same('Equivalent', $evidence['audited_equivalents'][0]['classification']);
        same('cxx_init_const', $evidence['audited_equivalents'][0]['ledger']['mutatorName']);
    },
    'changed source hash requires a new audit' => static function (): void {
        same(
            1,
            runLedger(static function (array &$ledger): void {
                $ledger['equivalents'][0]['source_sha256'] = str_repeat('0', 64);
            })[0]['status'],
        );
    },
    'changed span replacement or mutator is not equivalent' => static function (): void {
        $changes = [
            static function (array &$ledger): void {
                $ledger['equivalents'][0]['location']['start']['column'] = 2;
            },
            static function (array &$ledger): void {
                $ledger['equivalents'][0]['replacement'] = '0';
            },
            static function (array &$ledger): void {
                $ledger['equivalents'][0]['mutatorName'] = 'another_mutator';
            },
        ];
        foreach ($changes as $change) {
            same(1, runLedger($change)[0]['status']);
        }
    },
    'extra survivor or timeout cannot use the ledger' => static function (): void {
        foreach (['Survived', 'Timeout'] as $status) {
            same(
                1,
                runLedger(static function (array &$ledger, array &$mutants) use ($status): void {
                    $mutants[] = [
                        'id' => 'unclassified',
                        'status' => $status,
                        'mutatorName' => 'different',
                        'location' => $ledger['equivalents'][0]['location'],
                        'replacement' => '42',
                    ];
                })[0]['status'],
            );
        }
    },
    'duplicate audits are rejected' => static function (): void {
        same(
            1,
            runLedger(static function (array &$ledger): void {
                $ledger['equivalents'][] = $ledger['equivalents'][0];
            })[0]['status'],
        );
    },
    'duplicate raw equivalent matches are rejected' => static function (): void {
        same(
            1,
            runLedger(static function (array &$ledger, array &$mutants): void {
                $mutants[] = $mutants[count($mutants) - 1];
            })[0]['status'],
        );
    },
    'zero non-equivalent candidates is not a score' => static function (): void {
        same(
            1,
            runLedger(static function (array &$ledger, array &$mutants): void {
                array_shift($mutants);
            })[0]['status'],
        );
    },
    'invalid audited span is rejected' => static function (): void {
        same(
            1,
            runLedger(static function (array &$ledger, array &$mutants): void {
                $ledger['equivalents'][0]['location']['end']['line'] = true;
            })[0]['status'],
        );
    },
];
