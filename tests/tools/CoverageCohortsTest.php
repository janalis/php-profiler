<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/CoverageCohorts.php';

use PhpProfiler\Tooling\CoverageCohorts;

/** Derived source coverage unions retain actual cohort counters and identities. */
const COHORT_SOURCE = '/coverage-fixture/runtime.rs';

function cohortReport(string $name = 'function::<u32>', int $count = 1, int $true = 1, int $false = 0): array
{
    return [
        'type' => 'llvm.coverage.json.export',
        'data' => [[
            'functions' => [[
                'name' => $name,
                'count' => $count,
                'filenames' => [COHORT_SOURCE],
                'regions' => [[1, 1, 1, 9, $count, 0, 0, 0]],
                'branches' => [[1, 2, 1, 4, $true, $false, 0, 0, 4]],
            ]],
            'files' => [[
                'filename' => COHORT_SOURCE,
                'segments' => [[1, 1, $count, true, true, false], [1, 9, 0, false, false, false]],
                'branches' => [[1, 2, 1, 4, $true, $false, 0, 0, 4]],
                'summary' => [
                    'lines' => ['count' => 1, 'covered' => (int) ($count > 0)],
                    'functions' => ['count' => 1, 'covered' => (int) ($count > 0)],
                    'branches' => ['count' => 2, 'covered' => (int) ($true > 0) + (int) ($false > 0)],
                ],
            ]],
        ]],
    ];
}

function rejects(string $pattern, array $reports, array $expected = [COHORT_SOURCE]): void
{
    $error = throws(InvalidArgumentException::class, static fn() => CoverageCohorts::merge($reports, $expected));
    same(1, preg_match($pattern, $error->getMessage()), $error->getMessage() . ':');
}

return [
    'complementary cohorts union coverage and sum actual counters' => static function (): void {
        $merged = CoverageCohorts::merge([cohortReport(true: 2, false: 0), cohortReport(true: 0, false: 3)], [
            COHORT_SOURCE,
        ]);
        same(['count' => 2, 'covered' => 2], $merged['data'][0]['totals']['branches']);
        same(2, $merged['instantiations'][0]['count']);
        same([2, 3], array_slice($merged['instantiations'][0]['branches'][0], 4, 2));
    },
    'repeated unit does not inflate source denominators' => static function (): void {
        $merged = CoverageCohorts::merge([cohortReport(count: 2), cohortReport(count: 2)], [COHORT_SOURCE]);
        same(['count' => 1, 'covered' => 1], $merged['data'][0]['totals']['functions']);
        same(4, $merged['instantiations'][0]['regions'][0][4]);
        same(['count' => 2, 'covered' => 1], $merged['data'][0]['totals']['branches']);
    },
    'generic instances and raw names are preserved' => static function (): void {
        $merged = CoverageCohorts::merge([cohortReport('function::<u32>'), cohortReport('function::<u64>')], [
            COHORT_SOURCE,
        ]);
        same(2, count($merged['instantiations']));
        same(1, $merged['data'][0]['totals']['functions']['count']);
        $merged = CoverageCohorts::merge(
            [cohortReport('_Runit'), cohortReport('_Rnative')],
            [COHORT_SOURCE],
            static fn(): string => 'function::<u32>',
        );
        same(['_Rnative', '_Runit'], $merged['instantiations'][0]['raw_names']);
    },
    'same instantiation with incompatible boundaries fails' => static function (): void {
        $changed = cohortReport();
        $changed['data'][0]['functions'][0]['regions'][] = [2, 1, 2, 4, 1, 0, 0, 0];
        rejects('/Incompatible coverage boundaries/', [cohortReport(), $changed]);
    },
    'changed function endpoint cannot create a new identity' => static function (): void {
        $changed = cohortReport();
        $changed['data'][0]['functions'][0]['regions'][0][3] = 10;
        rejects('/Incompatible coverage boundaries/', [cohortReport(), $changed]);
    },
    'input summary is verified not trusted' => static function (): void {
        $changed = cohortReport();
        $changed['data'][0]['files'][0]['summary']['branches']['covered'] = 2;
        rejects('/Cannot reconstruct LLVM branches/', [$changed]);
    },
    'missing duplicate and unexpected datasets fail' => static function (): void {
        rejects('/absent/', [cohortReport()], [COHORT_SOURCE, '/coverage-fixture/missing.rs']);
        $changed = cohortReport();
        $changed['data'][0]['files'][] = $changed['data'][0]['files'][0];
        rejects('/Duplicate/', [$changed]);
        $changed['type'] = 'invented';
        rejects('/raw LLVM/', [$changed]);
    },
    'multiline function lines and shared physical lines remain distinct' => static function (): void {
        $changed = cohortReport();
        $second = $changed['data'][0]['functions'][0];
        $second['name'] = 'closure';
        array_splice($second['regions'][0], 0, 4, [1, 10, 1, 15]);
        $second['branches'] = [];
        $changed['data'][0]['functions'][] = $second;
        $changed['data'][0]['files'][0]['summary']['lines'] = ['count' => 2, 'covered' => 2];
        $changed['data'][0]['files'][0]['summary']['functions'] = ['count' => 2, 'covered' => 2];
        same(
            ['count' => 2, 'covered' => 2],
            CoverageCohorts::merge([$changed], [COHORT_SOURCE])['data'][0]['totals']['lines'],
        );
    },
    'invalid region and branch layouts fail' => static function (): void {
        foreach (['regions', 'branches'] as $key) {
            $changed = cohortReport();
            $changed['data'][0]['functions'][0][$key][0][] = 0;
            rejects('/Unsupported LLVM function region/', [$changed]);
        }
    },
    'non-integer counters are refused' => static function (): void {
        $changed = cohortReport();
        $changed['data'][0]['functions'][0]['count'] = 1.5;
        rejects('/Unsupported LLVM counter value/', [$changed]);
    },
];
