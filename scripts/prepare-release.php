<?php

declare(strict_types=1);

// Prepares release metadata and a narrowly proven version-only ledger transition.
require_once __DIR__ . '/lib/Arguments.php';
require_once __DIR__ . '/lib/Fail.php';
require_once __DIR__ . '/lib/Process.php';

use PhpProfiler\Tooling\Arguments;
use PhpProfiler\Tooling\Process;

$usage = 'prepare-release.php <version>';
['positionals' => $positionals] = Arguments::parse($argv, [], usage: $usage);
$version = $positionals[0] ?? '';
if (count($positionals) !== 1 || !preg_match('/\A0\.\d+\.\d+-alpha\.[1-9]\d*\z/', $version)) {
    Arguments::fail('Only the reviewed alpha channel is supported', $usage);
}
$root = dirname(__DIR__);
$git = static fn(string ...$arguments): string => Process::output(['git', '-C', $root, ...$arguments]);
if (trim($git('status', '--porcelain')) !== '') {
    Arguments::fail('Release preparation requires a clean source tree', $usage);
}
$changelog = (string) file_get_contents("{$root}/CHANGELOG.md");
$heading = '## [Unreleased]';
if (substr_count($changelog, $heading) !== 1 || str_contains($changelog, "## [{$version}]")) {
    Arguments::fail('Changelog must contain a unique Unreleased section and no duplicate version', $usage);
}
$date = trim($git('show', '-s', '--format=%cs', 'HEAD'));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
    throw new RuntimeException('Invalid commit date: ' . $date);
}
$tags = array_values(array_filter(explode("\n", $git(
    'tag',
    '--merged',
    'HEAD',
    '--sort=-version:refname',
    '--list',
    'v*',
))));
$commits = $git('log', '--format=%s%x1f%b%x1e', $tags === [] ? 'HEAD' : $tags[0] . '..HEAD');
// List every commit release-plan.mjs releases for, including a breaking change named only in its body.
$notes = [];
foreach (explode("\x1e", $commits) as $entry) {
    if (trim($entry) === '') {
        continue;
    }
    [$subject, $body] = array_pad(explode("\x1f", trim($entry, "\n"), 2), 2, '');
    if (
        preg_match('/\A(?:(?:feat|fix)(?:\([^\n]+\))?!?: |[a-z]+(?:\([^\n]+\))?!: )/', $subject)
        || preg_match('/^BREAKING(?: CHANGE|-CHANGE): .+/m', $body)
    ) {
        $notes[$subject] = true;
    }
}
$generated = $notes === []
    ? ''
    : "\n\n### Commits\n\n"
    . implode("\n", array_map(
        static fn(string $subject): string => '- ' . $subject,
        array_map(strval(...), array_keys($notes)),
    ));
$unreleased = explode("\n## ", explode($heading, $changelog, 2)[1], 2)[0];
// finalize-release.php publishes this section as the release notes; refuse an empty one
// before anything is written, rather than after the version bump has merged.
if ($generated === '' && trim($unreleased) === '') {
    Arguments::fail(
        "No release notes for {$version}: no releasable commit subject and an empty Unreleased section",
        $usage,
    );
}
$position = strpos($changelog, $heading);
$changelog = substr_replace(
    $changelog,
    "{$heading}\n\n## [{$version}] - {$date}{$generated}",
    $position,
    strlen($heading),
);
$changelog = str_replace('No version has been published. ', '', $changelog);
$manifest = (string) file_get_contents("{$root}/Cargo.toml");
$manifest = preg_replace('/^version = "[^"\n]+"$/m', 'version = "' . $version . '"', $manifest, -1, $count);
if ($count !== 1) {
    Arguments::fail('Expected exactly one workspace version', $usage);
}
file_put_contents("{$root}/Cargo.toml", $manifest);
// Cargo updates both workspace package entries while preserving the locked dependency graph.
$metadata = Process::run(['cargo', 'metadata', '--offline', '--format-version=1'], directory: $root);
if ($metadata['status'] !== 0) {
    fwrite(STDERR, $metadata['stderr']);
    exit($metadata['status'] ?? 1);
}
file_put_contents("{$root}/CHANGELOG.md", $changelog);
// This refuses stale proofs; it is not a general source or formatting proof renewal.
Process::check([
    __DIR__ . '/php-tool.sh',
    __DIR__ . '/validate-version-transition.php',
    'HEAD',
    $version,
    '--apply',
], directory: $root);
