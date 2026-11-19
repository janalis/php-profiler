<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/CoverageAnalysis.php';

use PhpProfiler\Tooling\CoverageAnalysis;

const ANALYSIS_NOTES = <<<'TXT'
    FUNCTION ident=1, lineno_checksum=0xab, cfg_checksum=0xcd, `f' src/bridge.c:1:1-3:1
    block 6: 7:0004(fall) 8:0001(tree)
    block 6:`src/bridge.c':2

    TXT;

const ANALYSIS_TREE = <<<'TXT'
    ;; Function f (f, funcdef_no=1)
    <bb 6> :
    persistent_30 = 0;
    [/usr/include/php/Zend/zend_string.h:176:15] if (persistent_30 != 0)
     goto <bb 7>; [INV]
    else
     goto <bb 8>; [INV]

    TXT;

function analysisData(bool $complete = false): array
{
    return [
        'gcc_version' => '12.2.0',
        'files' => [[
            'file' => 'src/bridge.c',
            'functions' => [['name' => 'f', 'execution_count' => 1]],
            'lines' => [[
                'line_number' => 2,
                'function_name' => 'f',
                'count' => 1,
                'branches' => [['count' => (int) $complete], ['count' => 1]],
            ]],
        ]],
    ];
}

function analyzeFixture(string $tree = ANALYSIS_TREE, string $rebuilt = ANALYSIS_NOTES): array
{
    return CoverageAnalysis::analyze(
        analysisData(),
        'src/bridge.c',
        CoverageAnalysis::notes(ANALYSIS_NOTES),
        CoverageAnalysis::notes($rebuilt),
        CoverageAnalysis::treeFunctions($tree),
    );
}

return [
    'constant header proof does not change raw gate' => static function (): void {
        $result = analyzeFixture();
        same(false, $result['raw_gate_pass']);
        same(['covered' => 1, 'count' => 2], $result['raw_counts']['branches']);
        same(1, $result['explained_constant_header_outcomes']);
        same(0, $result['unresolved_outcomes']);
        same(null, $result['adjusted_coverage_percentage']);
        same('persistent_30=0', $result['uncovered_outcomes'][0]['evidence']['constant_assignment']);
        same(7, $result['uncovered_outcomes'][0]['evidence']['untaken_destination']);
    },
    'first party constant is never classified as external' => static function (): void {
        same(
            1,
            analyzeFixture(tree: str_replace(
                '/usr/include/php/Zend/zend_string.h',
                'src/bridge.c',
                ANALYSIS_TREE,
            ))['unresolved_outcomes'],
        );
    },
    'stale cfg and nonconstant ssa fail closed' => static function (): void {
        same(1, analyzeFixture(rebuilt: str_replace('0xcd', '0xef', ANALYSIS_NOTES))['unresolved_outcomes']);
        same(
            1,
            analyzeFixture(tree: str_replace(
                'persistent_30 = 0;',
                'persistent_30 = input;',
                ANALYSIS_TREE,
            ))['unresolved_outcomes'],
        );
    },
    'ssa alias chain is resolved but cycle is not' => static function (): void {
        $alias = str_replace('persistent_30 = 0;', "initial_1 = 0;\npersistent_30 = initial_1;", ANALYSIS_TREE);
        same(1, analyzeFixture(tree: $alias)['explained_constant_header_outcomes']);
        same(
            ['persistent_30', 'initial_1'],
            analyzeFixture(tree: $alias)['uncovered_outcomes'][0]['evidence']['ssa_alias_chain'],
        );
        $cycle = str_replace(
            'persistent_30 = 0;',
            "initial_1 = persistent_30;\npersistent_30 = initial_1;",
            ANALYSIS_TREE,
        );
        same(1, analyzeFixture(tree: $cycle)['unresolved_outcomes']);
    },
    'missing evidence does not explain gap' => static function (): void {
        $result = CoverageAnalysis::analyze(analysisData(), 'src/bridge.c');
        same(false, $result['raw_gate_pass']);
        same(1, $result['unresolved_outcomes']);
    },
    'complete raw data passes without adjustment' => static function (): void {
        $result = CoverageAnalysis::analyze(analysisData(true), 'src/bridge.c');
        same(true, $result['raw_gate_pass']);
        same([], $result['uncovered_outcomes']);
    },
    'ambiguous source block and missing source fail closed' => static function (): void {
        $extra = ANALYSIS_NOTES . "block 9: 10:0004(fall) 11:0001(tree)\nblock 9:`src/bridge.c':2\n";
        $result = CoverageAnalysis::analyze(
            analysisData(),
            'src/bridge.c',
            CoverageAnalysis::notes($extra),
            CoverageAnalysis::notes($extra),
            CoverageAnalysis::treeFunctions(ANALYSIS_TREE),
        );
        same(1, $result['unresolved_outcomes']);
        throws(InvalidArgumentException::class, static fn() => CoverageAnalysis::analyze(
            ['files' => []],
            'src/bridge.c',
        ));
    },
];
