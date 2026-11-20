<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use Closure;
use InvalidArgumentException;

require_once __DIR__ . '/Json.php';
require_once __DIR__ . '/Paths.php';
require_once __DIR__ . '/ReleaseRunner.php';
require_once __DIR__ . '/Tar.php';
require_once __DIR__ . '/Toml.php';

/** Resumes a validated PIE alpha release; never overwrites a version. */
final class FinalizeRelease
{
    public const string REPOSITORY = 'janalis/php-profiler';

    /** @var Closure(string, string, string): void */
    public Closure $verifyCheckout;

    public function __construct(
        private readonly ReleaseRunner $runner,
        private readonly string $root,
    ) {
        $this->verifyCheckout = $this->verifyCheckoutBytes(...);
    }

    /** Returns this version's CHANGELOG section, without its heading, as the release body. */
    public static function releaseNotes(string $changelog, string $version): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $changelog);
        if (end($lines) === '') {
            array_pop($lines);
        }
        // prepare-release.php dates every released heading; Unreleased never matches.
        $heading = '/\A## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}\z/';
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match($heading, $line)) {
                $start = $index;
                break;
            }
        }
        if ($start === null) {
            throw new InvalidArgumentException("CHANGELOG.md has no {$version} section");
        }
        [$end, $fenced] = [count($lines), false];
        for ($index = $start + 1; $index < count($lines); $index++) {
            if (str_starts_with($lines[$index], '```')) {
                $fenced = !$fenced;
            } elseif (!$fenced && str_starts_with($lines[$index], '## ')) {
                $end = $index;
                break;
            }
        }
        $notes = trim(implode("\n", array_slice($lines, $start + 1, $end - $start - 1)));
        if ($notes === '') {
            throw new InvalidArgumentException("CHANGELOG.md has an empty {$version} section");
        }
        return $notes . "\n";
    }

    public static function verifyPackagist(array $payload, string $version, string $source): void
    {
        $package = $payload['package'] ?? [];
        if (($package['name'] ?? null) !== 'janalis/php-profiler') {
            throw new InvalidArgumentException('Unexpected Packagist package');
        }
        foreach ($package['versions'] ?? [] as $candidate) {
            $candidateVersion = (string) ($candidate['version'] ?? '');
            if (
                (str_starts_with($candidateVersion, 'v') ? substr($candidateVersion, 1) : $candidateVersion)
                === $version
            ) {
                $metadata = $candidate['source'] ?? [];
                if (
                    ($metadata['reference'] ?? null) !== $source
                    || !in_array(
                        $metadata['url'] ?? null,
                        ['https://github.com/janalis/php-profiler.git', 'https://github.com/janalis/php-profiler'],
                        true,
                    )
                ) {
                    throw new InvalidArgumentException('Packagist source does not match the validated candidate');
                }
                return;
            }
        }
        throw new InvalidArgumentException(
            'Packagist has not indexed this alpha tag yet; retry after its webhook completes',
        );
    }

    /**
     * Checks every archive source byte against the immutable source commit.
     *
     * @param array<string, string|array{string, string}> $committed name => bytes, or ['symlink', target]
     */
    public static function verifyArchive(string $body, string $version, array $committed): void
    {
        $prefix = "php-profiler-{$version}/";
        $actual = [];
        foreach (Tar::read((string) gzdecode($body)) as $entry) {
            if (
                in_array('..', explode('/', $entry['name']), true)
                || !($entry['name'] === rtrim($prefix, '/') || str_starts_with($entry['name'], $prefix))
            ) {
                throw new InvalidArgumentException('Archive entry is outside the versioned source directory');
            }
            if ($entry['type'] === 'directory') {
                continue;
            }
            if (array_key_exists($entry['name'], $actual)) {
                throw new InvalidArgumentException('Archive has duplicate entries');
            }
            $actual[$entry['name']] = match ($entry['type']) {
                'file' => $entry['data'],
                'symlink' => ['symlink', $entry['link']],
                default => throw new InvalidArgumentException('Archive has unsupported entries'),
            };
        }
        $expected = [];
        foreach ($committed as $name => $data) {
            if (!in_array(explode('/', (string) $name)[0], ['target', 'mutants.out', '.github'], true)) {
                $expected[$prefix . $name] = $data;
            }
        }
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Archive source bytes do not match the committed candidate');
        }
    }

    private function verifyCheckoutBytes(string $root, string $version, string $body): void
    {
        $git = fn(string ...$arguments): string => $this->runner->output(['git', ...$arguments], $root);
        if (trim($git('status', '--porcelain', '--untracked-files=no')) !== '') {
            throw new InvalidArgumentException('Finalization requires a clean committed candidate');
        }
        if ((Toml::parse($git('show', 'HEAD:Cargo.toml'))['workspace']['package']['version'] ?? null) !== $version) {
            throw new InvalidArgumentException('Requested version differs from the committed candidate');
        }
        // git archive reads HEAD, never the mutable Git index or untracked files.
        $committed = [];
        foreach (Tar::read($git('archive', '--format=tar', 'HEAD')) as $entry) {
            if ($entry['type'] === 'file') {
                $committed[$entry['name']] = $entry['data'];
            } elseif ($entry['type'] === 'symlink') {
                $committed[$entry['name']] = ['symlink', $entry['link']];
            }
        }
        self::verifyArchive($body, $version, $committed);
    }

    /** Uses a real non-force lightweight tag push, so the Packagist push hook fires. */
    public function publishTag(string $root, string $repository, string $source, string $tag, array $refs): void
    {
        $existing = array_values(array_filter(
            $refs,
            static fn(array $ref): bool => $ref['ref'] === "refs/tags/{$tag}",
        ));
        if (
            $existing !== []
            && ($existing[0]['object']['sha'] !== $source || $existing[0]['object']['type'] !== 'commit')
        ) {
            throw new InvalidArgumentException('Existing tag belongs to another source; never overwrite it');
        }
        $reference = "refs/tags/{$tag}";
        $local = $this->runner->run(['git', 'show-ref', '--verify', '--quiet', $reference], $root);
        if ($local === 0) {
            $identity = trim($this->runner->output(['git', 'rev-parse', $reference], $root));
            $kind = trim($this->runner->output(['git', 'cat-file', '-t', $reference], $root));
            if ($identity !== $source || $kind !== 'commit') {
                throw new InvalidArgumentException('Existing local tag differs from the candidate lightweight tag');
            }
        } elseif ($local === 1) {
            $this->check(['git', '-c', 'tag.gpgSign=false', 'tag', $tag, $source], $root);
        } else {
            throw new InvalidArgumentException('Could not inspect the local tag');
        }
        if ($existing !== []) {
            return;
        }
        $origin = trim($this->runner->output(['git', 'remote', 'get-url', 'origin'], $root));
        if (!in_array(
            $origin,
            [
                "https://github.com/{$repository}",
                "https://github.com/{$repository}.git",
                "git@github.com:{$repository}.git",
            ],
            true,
        )) {
            throw new InvalidArgumentException('Origin does not identify the expected public repository');
        }
        // Credentials come from the ephemeral runner token; no token is embedded in the
        // remote URL or command line. Recheck main immediately before the push.
        $this->check(['gh', 'auth', 'setup-git'], $root);
        $latest = json_decode(
            $this->runner->output(['gh', 'api', "repos/{$repository}/git/ref/heads/main"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if ($latest['object']['sha'] !== $source) {
            throw new InvalidArgumentException('Main advanced before tag push; never publish a stale candidate');
        }
        $this->check(['git', 'push', 'origin', $reference], $root);
    }

    public function verifyPublishedPackage(string $version, string $source): void
    {
        // The full package API can retain a pre-release CDN snapshot for twelve hours. A
        // fresh query key reaches current registry data; every response still needs exact
        // package, version and source verification. Missing indexing still fails.
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                $url =
                    'https://packagist.org/packages/janalis/php-profiler.json?verification='
                    . $this->runner->timeNs()
                    . "-{$attempt}";
                self::verifyPackagist(
                    json_decode($this->runner->fetch($url), true, flags: JSON_THROW_ON_ERROR),
                    $version,
                    $source,
                );
                return;
            } catch (InvalidArgumentException|\JsonException|\RuntimeException $failure) {
                if ($attempt === 3) {
                    throw $failure;
                }
                $this->runner->sleep(10);
            }
        }
    }

    public function main(string $version, string $archivePath, int $qualityRunId): void
    {
        if (!preg_match('/\A0\.\d+\.\d+-alpha\.[1-9]\d*\z/', $version)) {
            throw new InvalidArgumentException('Only alpha publication is supported');
        }
        $root = $this->root;
        $repository = self::REPOSITORY;
        $source = trim($this->runner->output(['git', 'rev-parse', 'HEAD'], $root));
        $run = json_decode(
            $this->runner->output(['gh', 'api', "repos/{$repository}/actions/runs/{$qualityRunId}"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!ReleaseChecks::exactQualityRun($run, $source, $repository) || ($run['head_branch'] ?? null) !== 'main') {
            throw new InvalidArgumentException('Publication requires the exact successful main quality run');
        }
        $current = json_decode(
            $this->runner->output(['gh', 'api', "repos/{$repository}/git/ref/heads/main"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if ($current['object']['sha'] !== $source) {
            throw new InvalidArgumentException('Main advanced; never publish a stale candidate');
        }
        $tag = 'v' . $version;
        $body = (string) file_get_contents($archivePath);
        ($this->verifyCheckout)($root, $version, $body);
        // The release body is this version's section only, never the whole CHANGELOG. Read it
        // before tagging, so a missing section stops the release unpublished.
        $releaseBody = self::releaseNotes((string) file_get_contents("{$root}/CHANGELOG.md"), $version);
        // Tags and release assets are immutable. Registry indexing can resume after publication.
        $refs = json_decode(
            $this->runner->output(['gh', 'api', "repos/{$repository}/git/matching-refs/tags/{$tag}"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->publishTag($root, $repository, $source, $tag, $refs);
        $archive = Paths::resolve($archivePath);
        $checksum = $archive . '.sha256';
        file_put_contents($checksum, hash('sha256', $body) . '  ' . basename($archive) . "\n");
        $notes = $archive . '.notes.md';
        file_put_contents($notes, $releaseBody);
        $pages = json_decode(
            $this->runner->output(['gh', 'api', '--paginate', '--slurp', "repos/{$repository}/releases?per_page=100"]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $release = null;
        foreach ($pages as $page) {
            foreach ($page as $item) {
                if ($item['tag_name'] === $tag) {
                    $release ??= $item;
                }
            }
        }
        if ($release !== null) {
            // Verify already published asset bytes before any retry resumes.
            foreach ($release['assets'] as $asset) {
                if (in_array($asset['name'], [basename($archive), basename($checksum)], true)) {
                    $expected = $asset['name'] === basename($archive) ? $body : (string) file_get_contents($checksum);
                    if ($this->runner->fetch($asset['browser_download_url']) !== $expected) {
                        throw new InvalidArgumentException('Existing GitHub asset differs; never overwrite it');
                    }
                }
            }
            $names = array_column($release['assets'], 'name');
            $missing = array_values(array_filter(
                [$archive, $checksum],
                static fn(string $path): bool => !in_array(basename($path), $names, true),
            ));
            if ($missing !== []) {
                $this->check(['gh', 'release', 'upload', $tag, '--repo', $repository, ...$missing]);
            }
            // Unlike assets, notes are editable: a retry repairs them instead of failing.
            if (trim(str_replace("\r\n", "\n", (string) ($release['body'] ?? ''))) !== trim($releaseBody)) {
                $this->check(['gh', 'release', 'edit', $tag, '--repo', $repository, '--notes-file', $notes]);
            }
        } else {
            $this->check([
                'gh',
                'release',
                'create',
                $tag,
                '--repo',
                $repository,
                '--verify-tag',
                '--prerelease',
                '--title',
                $tag,
                '--notes-file',
                $notes,
                $archive,
                $checksum,
            ]);
        }
        $this->verifyPublishedPackage($version, $source);
        echo
            Json::encode([
                'version' => $version,
                'source' => $source,
                'sha256' => hash('sha256', $body),
                'packagist' => 'verified',
                'github' => 'published',
            ], sortKeys: false),
            "\n"
        ;
    }

    private function check(array $command, ?string $directory = null): void
    {
        ReleaseChecks::check($this->runner, $command, $directory);
    }
}
