<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/FinalizeRelease.php';

use PhpProfiler\Tooling\FinalizeRelease;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\ReleaseRunner;
use PhpProfiler\Tooling\SystemReleaseRunner;
use PhpProfiler\Tooling\Tar;

/** A runner whose commands, downloads and clock are closures; it records every command. */
final class ScriptedRunner implements ReleaseRunner
{
    public array $commands = [];
    public array $sleeps = [];
    public array $urls = [];

    public function __construct(
        public ?Closure $outputs = null,
        public ?Closure $runs = null,
        public ?Closure $fetches = null,
        public ?Closure $clock = null,
    ) {}

    public function output(array $command, ?string $directory = null): string
    {
        return ($this->outputs ?? throw new LogicException('no output expected: ' . json_encode($command)))(
            $command,
            $directory,
        );
    }

    public function run(array $command, ?string $directory = null): int
    {
        $this->commands[] = $command;
        return $this->runs === null ? 0 : ($this->runs)($command, $directory);
    }

    public function fetch(string $url): string
    {
        $this->urls[] = $url;
        return ($this->fetches ?? throw new LogicException('no download expected'))($url);
    }

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
    }

    public function timeNs(): int
    {
        return $this->clock === null ? 0 : ($this->clock)();
    }
}

const RELEASE_VERSION = '0.1.0-alpha.1';

function packagist(string $source): array
{
    return [
        'package' => [
            'name' => 'janalis/php-profiler',
            'versions' => [
                RELEASE_VERSION => [
                    'version' => RELEASE_VERSION,
                    'source' => ['reference' => $source, 'url' => 'https://github.com/janalis/php-profiler.git'],
                ],
            ],
        ],
    ];
}

/** Scripted GitHub and git answers for a finalization of RELEASE_VERSION at $source. */
function finalizeRunner(string $source, array &$refs, array &$releases): ScriptedRunner
{
    return new ScriptedRunner(
        outputs: static function (array $command) use ($source, &$refs, &$releases): string {
            $last = (string) end($command);
            return match (true) {
                array_slice($command, 0, 2) === ['git', 'rev-parse'] => $source,
                array_slice($command, 0, 2) === ['git', 'cat-file'] => 'commit',
                str_contains($last, '/actions/runs/') => json_encode([
                    'name' => 'quality',
                    'conclusion' => 'success',
                    'head_sha' => $source,
                    'head_branch' => 'main',
                    'head_repository' => ['full_name' => 'janalis/php-profiler'],
                    'event' => 'workflow_dispatch',
                ]),
                str_ends_with($last, '/git/ref/heads/main') => json_encode(['object' => ['sha' => $source]]),
                str_contains($last, 'matching-refs') => json_encode($refs),
                str_contains($last, 'releases?') => json_encode([$releases]),
                default => throw new LogicException(json_encode($command)),
            };
        },
        fetches: static fn(string $url): string => str_contains($url, 'packagist')
            ? json_encode(packagist($source))
            : 'archive',
    );
}

function finalizer(ReleaseRunner $runner): FinalizeRelease
{
    $finalize = new FinalizeRelease($runner, ROOT);
    $finalize->verifyCheckout = static function (): void {};
    return $finalize;
}

function quietly(Closure $action): mixed
{
    ob_start();
    try {
        return $action();
    } finally {
        ob_end_clean();
    }
}

function sourceArchive(array $files): string
{
    $entries = [];
    foreach ($files as $name => $data) {
        $entries[] = ['name' => 'php-profiler-' . RELEASE_VERSION . '/' . $name, 'data' => $data];
    }
    return (string) gzencode(Tar::write($entries));
}

return [
    'registry receipts reject absent versions and unrelated sources' => static function (): void {
        $valid = [
            'package' => [
                'name' => 'janalis/php-profiler',
                'versions' => [
                    'v0.1.0-alpha.1' => [
                        'version' => 'v0.1.0-alpha.1',
                        'source' => ['reference' => 'abc', 'url' => 'https://github.com/janalis/php-profiler.git'],
                    ],
                ],
            ],
        ];
        FinalizeRelease::verifyPackagist($valid, RELEASE_VERSION, 'abc');
        foreach ([[$valid, 'wrong'], [[], 'abc']] as [$payload, $source]) {
            throws(InvalidArgumentException::class, static fn() => FinalizeRelease::verifyPackagist(
                $payload,
                RELEASE_VERSION,
                $source,
            ));
        }
    },
    'finalization retries verify existing tags and assets without replacing them' => static function (): void {
        $source = str_repeat('a', 40);
        $refs = [['ref' => 'refs/tags/v' . RELEASE_VERSION, 'object' => ['sha' => $source, 'type' => 'commit']]];
        $releases = [[
            'tag_name' => 'v' . RELEASE_VERSION,
            'body' => FinalizeRelease::releaseNotes(
                (string) file_get_contents(ROOT . '/CHANGELOG.md'),
                RELEASE_VERSION,
            ),
            'assets' => [['name' => 'source.tgz', 'browser_download_url' => 'https://example.invalid/archive']],
        ]];
        $runner = finalizeRunner($source, $refs, $releases);
        $directory = temporaryDirectory();
        file_put_contents("{$directory}/source.tgz", 'archive');
        quietly(static fn() => finalizer($runner)->main(RELEASE_VERSION, "{$directory}/source.tgz", 42));
        same(2, count($runner->commands));
        same(['gh', 'release', 'upload'], array_slice(end($runner->commands), 0, 3));
        same(false, in_array('--clobber', end($runner->commands), true));
        $refs[0]['object']['sha'] = str_repeat('b', 40);
        $runner->commands = [];
        throws(InvalidArgumentException::class, static fn() => quietly(static fn() => finalizer($runner)->main(
            RELEASE_VERSION,
            "{$directory}/source.tgz",
            42,
        )));
        same([], $runner->commands);
    },
    'finalizer independently rejects dirty candidates, wrong versions and changed archive sources' =>
        static function (): void {
            $committed = [
                'Cargo.toml' => 'version bytes',
                'packaging/config.m4' => 'config',
                'packaging/Makefile.frag' => 'fragment',
            ];
            FinalizeRelease::verifyArchive(sourceArchive($committed), RELEASE_VERSION, $committed);
            throws(InvalidArgumentException::class, static fn() => FinalizeRelease::verifyArchive(
                sourceArchive([...$committed, 'Cargo.toml' => 'changed']),
                RELEASE_VERSION,
                $committed,
            ));
            throws(InvalidArgumentException::class, static fn() => FinalizeRelease::verifyArchive(
                (string) gzencode(Tar::write([['name' => '../escape', 'data' => 'x']])),
                RELEASE_VERSION,
                [],
            ));
            foreach ([['M Cargo.toml'], ['', "[workspace.package]\nversion = \"0.1.0-alpha.2\""]] as $results) {
                $runner = new ScriptedRunner(outputs: static function () use (&$results): string {
                    return array_shift($results);
                });
                $finalize = new FinalizeRelease($runner, ROOT);
                throws(InvalidArgumentException::class, static fn() => ($finalize->verifyCheckout)(
                    '.',
                    RELEASE_VERSION,
                    sourceArchive($committed),
                ));
            }
        },
    'lightweight tag publication uses a real non-force push, safely resumes and rejects changed tags' =>
        static function (): void {
            $directory = temporaryDirectory();
            $root = "{$directory}/source";
            $remote = "{$directory}/remote.git";
            mkdir($root);
            $git = static fn(string ...$arguments): string => trim(Process::output([
                'git',
                '-C',
                $root,
                ...$arguments,
            ]));
            $git('init', '-b', 'main');
            $git('config', 'user.name', 'Release Test');
            $git('config', 'user.email', 'release@example.invalid');
            $git('commit', '--allow-empty', '-m', 'feat: initial');
            $source = $git('rev-parse', 'HEAD');
            Process::output(['git', 'init', '--bare', $remote]);
            $git('remote', 'add', 'origin', $remote);
            $current = $source;
            $system = new SystemReleaseRunner();
            $runner = new ScriptedRunner(
                outputs: static function (array $command, ?string $cwd) use (&$current, $system): string {
                    return match (true) {
                        array_slice($command, 0, 3) === ['git', 'remote', 'get-url']
                            => 'https://github.com/janalis/php-profiler.git',
                        array_slice($command, 0, 2) === ['gh', 'api'] => json_encode(['object' => ['sha' => $current]]),
                        default => $system->output($command, $cwd),
                    };
                },
                runs: static fn(array $command, ?string $cwd): int => $command === ['gh', 'auth', 'setup-git']
                    ? 0
                    : Process::run($command, directory: $cwd)['status'],
            );
            $finalize = new FinalizeRelease($runner, $root);
            $tag = 'v' . RELEASE_VERSION;
            $reference = "refs/tags/{$tag}";
            $refs = [['ref' => $reference, 'object' => ['sha' => $source, 'type' => 'commit']]];
            $finalize->publishTag($root, 'janalis/php-profiler', $source, $tag, []);
            same(true, in_array(['git', 'push', 'origin', $reference], $runner->commands, true));
            same(
                [],
                array_filter(
                    $runner->commands,
                    static fn(array $command): bool => (
                        in_array('--force', $command, true) || in_array('-f', $command, true)
                    ),
                ),
            );
            same($source, trim(Process::output(['git', '--git-dir=' . $remote, 'rev-parse', $reference])));
            $pushes = static fn(): array => array_filter(
                $runner->commands,
                static fn(array $command): bool => (
                    array_slice($command, 0, 2) === ['git', 'push']
                    || array_slice($command, 0, 2) === ['gh', 'auth']
                ),
            );
            $runner->commands = [];
            $finalize->publishTag($root, 'janalis/php-profiler', $source, $tag, $refs);
            same([], $pushes());
            $git('commit', '--allow-empty', '-m', 'fix: another commit');
            $other = $git('rev-parse', 'HEAD');
            $git('tag', '-f', $tag, $other);
            $runner->commands = [];
            throws(InvalidArgumentException::class, static fn() => $finalize->publishTag(
                $root,
                'janalis/php-profiler',
                $source,
                $tag,
                $refs,
            ));
            same(
                [],
                array_filter(
                    $runner->commands,
                    static fn(array $command): bool => array_slice($command, 0, 2) === ['git', 'push'],
                ),
            );
            $current = $other;
            $runner->commands = [];
            throws(InvalidArgumentException::class, static fn() => $finalize->publishTag(
                $root,
                'janalis/php-profiler',
                $source,
                'v0.1.0-alpha.2',
                [],
            ));
            same(
                [],
                array_filter(
                    $runner->commands,
                    static fn(array $command): bool => array_slice($command, 0, 2) === ['git', 'push'],
                ),
            );
            same($source, trim(Process::output(['git', '--git-dir=' . $remote, 'rev-parse', $reference])));
        },
    'release notes hold only the released version section' => static function (): void {
        $changelog = "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- Pending.\n\n## [0.1.0-alpha.2] - 2026-01-02\n\n### Commits\n\n- fix: second (#2)\n\n### Fixed\n\n- Second.\n\n## [0.1.0-alpha.5]\n\n- Undated.\n\n## [0.1.0-alpha.1] - 2026-01-01\n\n### Added\n\n- First.\n";
        same("### Commits\n\n- fix: second (#2)\n\n### Fixed\n\n- Second.\n", FinalizeRelease::releaseNotes(
            $changelog,
            '0.1.0-alpha.2',
        ));
        same("### Added\n\n- First.\n", FinalizeRelease::releaseNotes($changelog, '0.1.0-alpha.1'));
        // A heading-like line inside a code fence belongs to the section.
        $fenced = "## [0.1.0-alpha.3] - 2026-01-03\n\n- Example:\n\n```md\n## [x]\n```\n\n## [0.1.0-alpha.2] - 2026-01-02\n\n- Second.\n";
        same("- Example:\n\n```md\n## [x]\n```\n", FinalizeRelease::releaseNotes($fenced, '0.1.0-alpha.3'));
        foreach (['0.1.0-alpha.3', '0.1.0-alpha', 'Unreleased', '0.1.0-alpha.5'] as $version) {
            throws(InvalidArgumentException::class, static fn() => FinalizeRelease::releaseNotes($changelog, $version));
        }
        throws(InvalidArgumentException::class, static fn() => FinalizeRelease::releaseNotes(
            "## [0.1.0-alpha.4] - 2026-01-04\n\n## [0.1.0-alpha.3]\n",
            '0.1.0-alpha.4',
        ));
        // Every published body is the real CHANGELOG section: on creation, and repaired on retry.
        $source = str_repeat('a', 40);
        $expected = FinalizeRelease::releaseNotes((string) file_get_contents(ROOT . '/CHANGELOG.md'), RELEASE_VERSION);
        same([], array_filter(explode("\n", $expected), static fn(string $line): bool => str_starts_with(
            $line,
            '## ',
        )));
        same(false, str_contains($expected, 'Unreleased'));
        $refs = [['ref' => 'refs/tags/v' . RELEASE_VERSION, 'object' => ['sha' => $source, 'type' => 'commit']]];
        $releases = [];
        $runner = finalizeRunner($source, $refs, $releases);
        $published = [];
        $runner->runs = static function (array $command) use (&$published): int {
            if (in_array(array_slice($command, 0, 3), [['gh', 'release', 'create'], ['gh', 'release', 'edit']], true)) {
                $published[] = [
                    $command[2],
                    file_get_contents($command[array_search('--notes-file', $command, true) + 1]),
                ];
            }
            return 0;
        };
        $directory = temporaryDirectory();
        file_put_contents("{$directory}/source.tgz", 'archive');
        quietly(static fn() => finalizer($runner)->main(RELEASE_VERSION, "{$directory}/source.tgz", 42));
        same([['create', $expected]], $published);
        $assets = array_map(static fn(string $name): array => [
            'name' => $name,
            'browser_download_url' => 'https://example.invalid/' . $name,
        ], ['source.tgz', 'source.tgz.sha256']);
        $checksum = (string) file_get_contents("{$directory}/source.tgz.sha256");
        $runner->fetches = static fn(string $url): string => (
            str_ends_with($url, '.sha256')
                ? $checksum
                : (str_contains($url, 'packagist') ? json_encode(packagist($source)) : 'archive')
        );
        foreach ([
            [(string) file_get_contents(ROOT . '/CHANGELOG.md'), [['edit', $expected]]],
            [str_replace("\n", "\r\n", $expected), []],
        ] as [$body, $edits]) {
            $published = [];
            $releases = [['tag_name' => 'v' . RELEASE_VERSION, 'body' => $body, 'assets' => $assets]];
            quietly(static fn() => finalizer($runner)->main(RELEASE_VERSION, "{$directory}/source.tgz", 42));
            same($edits, $published);
        }
    },
    'registry verification refreshes each cache key and keeps bounded exact-source validation' =>
        static function (): void {
            $source = str_repeat('a', 40);
            $missing = json_encode(['package' => ['name' => 'janalis/php-profiler', 'versions' => new stdClass()]]);
            $responses = [$missing, json_encode(packagist($source))];
            $times = [100, 101];
            $runner = new ScriptedRunner(fetches: static function () use (&$responses): string {
                return array_shift($responses);
            }, clock: static function () use (&$times): int {
                return array_shift($times);
            });
            (new FinalizeRelease($runner, ROOT))->verifyPublishedPackage(RELEASE_VERSION, $source);
            same(2, count(array_unique($runner->urls)));
            foreach ($runner->urls as $attempt => $url) {
                same(
                    'https://packagist.org/packages/janalis/php-profiler.json?verification='
                    . (100 + $attempt)
                    . '-'
                    . $attempt,
                    $url,
                );
            }
            same([10], $runner->sleeps);
            // Fresh data with a wrong source must fail; a cache refresh never weakens identity.
            foreach ([[$missing, 'not indexed'], [json_encode(packagist(str_repeat('b', 40))), 'does not match']] as [
                $response,
                $error,
            ]) {
                $runner = new ScriptedRunner(fetches: static fn(): string => $response, clock: static fn(): int => 100);
                $failure = throws(InvalidArgumentException::class, static fn() => (new FinalizeRelease(
                    $runner,
                    ROOT,
                ))->verifyPublishedPackage(RELEASE_VERSION, $source));
                same(true, str_contains($failure->getMessage(), $error), $failure->getMessage());
                same([4, 4, [10, 10, 10]], [count($runner->urls), count(array_unique($runner->urls)), $runner->sleeps]);
            }
        },
];
