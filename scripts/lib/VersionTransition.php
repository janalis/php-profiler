<?php

declare(strict_types=1);

namespace PhpProfiler\Tooling;

use InvalidArgumentException;

require_once __DIR__ . '/Json.php';
require_once __DIR__ . '/Toml.php';

/**
 * Proves and applies only a release-version change to an already-current C proof ledger.
 * This never renews C source spans, native contexts or equivalent classifications; fresh
 * native context validation and NTS and ZTS Mull remain mandatory afterwards.
 */
final class VersionTransition
{
    public const string LEDGER = 'tests/native/c-equivalents.json';
    public const array DEPENDENCIES = [
        'Cargo.lock',
        'Cargo.toml',
        'crates/php-profiler/Cargo.toml',
        'crates/php-profiler/build.rs',
        'crates/php-profiler/src/automatic.rs',
        'crates/php-profiler/src/bridge.c',
        'crates/php-profiler/src/lib.rs',
        'crates/php-profiler/src/sampling.c',
        'crates/php-profiler/src/sampling.rs',
        'crates/php-profiler/src/storage.rs',
        'crates/profiler-core/Cargo.toml',
        'crates/profiler-core/src/budget.rs',
        'crates/profiler-core/src/index.rs',
        'crates/profiler-core/src/lib.rs',
        'crates/profiler-core/src/sampling.rs',
        'crates/profiler-core/src/serialization.rs',
        'crates/profiler-core/src/storage.rs',
        'docs/mutation-equivalence.md',
        'scripts/c-mutation-tests.sh',
        'scripts/link-native-faults.sh',
        'scripts/native-bailout-tests.php',
        'scripts/native-fault-object.php',
        'scripts/owned-run.php',
        'tests/native/automatic-transports.c',
        'tests/native/bailout-fault.c',
        'tests/native/bridge-unit.c',
        'tests/native/c-boundaries.php',
        'tests/native/frames-native.c',
        'tests/native/sampling-lifecycle-native.c',
        'tests/native/sampling-unit.c',
        'tests/support/adapter-contract.rs',
        'tests/support/automatic-contract.c',
        'tests/support/automatic-contract.rs',
        'tests/support/sampling-adapter-contract.rs',
    ];
    public const array ALLOWED = ['Cargo.toml', 'Cargo.lock', 'CHANGELOG.md', self::LEDGER];
    public const string VERSION = '/\A0\.\d+\.\d+-alpha\.[1-9]\d*\z/';

    /**
     * Derives the exact expected ledger bytes from immutable base bytes, never from target claims.
     *
     * @param callable(string): string $base
     * @param callable(string): string $target
     * @return array{string, array<string, array{old_sha256: string, new_sha256: string}>}
     */
    public static function expected(callable $base, callable $target, string $version): array
    {
        if (!preg_match(self::VERSION, $version)) {
            throw new InvalidArgumentException('Only the reviewed alpha channel is supported');
        }
        $ledgerBytes = $base(self::LEDGER);
        $ledger = json_decode($ledgerBytes, false, flags: JSON_THROW_ON_ERROR);
        $hashes = (array) ($ledger->dependency_sha256 ?? []);
        $names = array_map(strval(...), array_keys($hashes));
        sort($names, SORT_STRING);
        $expectedNames = self::DEPENDENCIES;
        sort($expectedNames, SORT_STRING);
        if ($names !== $expectedNames) {
            throw new InvalidArgumentException('Base proof dependency inventory is incomplete or unexpected');
        }
        foreach (self::DEPENDENCIES as $name) {
            if ($hashes[$name] !== hash('sha256', $base($name))) {
                throw new InvalidArgumentException('Base proof hash is stale: ' . $name);
            }
            if (!in_array($name, ['Cargo.toml', 'Cargo.lock'], true) && $base($name) !== $target($name)) {
                throw new InvalidArgumentException('A non-version proof dependency changed: ' . $name);
            }
        }
        $oldManifest = $base('Cargo.toml');
        $newManifest = $target('Cargo.toml');
        $oldVersion = Toml::parse($oldManifest)['workspace']['package']['version'] ?? null;
        $expectedManifest = preg_replace(
            '/^version = "[^"\n]+"$/m',
            'version = "' . $version . '"',
            $oldManifest,
            -1,
            $count,
        );
        if ($count !== 1 || $oldVersion === $version || $newManifest !== $expectedManifest) {
            throw new InvalidArgumentException('Workspace manifest must change only its version');
        }
        $oldLock = $base('Cargo.lock');
        $newLock = $target('Cargo.lock');
        $expectedParsed = Toml::parse($oldLock);
        $internal = ['php-profiler', 'profiler-core'];
        $seen = [];
        foreach ($expectedParsed['package'] ?? [] as $index => $package) {
            if (in_array($package['name'] ?? null, $internal, true) && !array_key_exists('source', $package)) {
                if (isset($seen[$package['name']]) || ($package['version'] ?? null) !== $oldVersion) {
                    throw new InvalidArgumentException('Unexpected internal lock package identity/version');
                }
                $seen[$package['name']] = true;
                $expectedParsed['package'][$index]['version'] = $version;
            }
        }
        ksort($seen);
        if (array_keys($seen) !== $internal || !Toml::equal(Toml::parse($newLock), $expectedParsed)) {
            throw new InvalidArgumentException('Lock dependency graph differs beyond the two internal versions');
        }
        $blocks = explode('[[package]]', $oldLock);
        foreach ($blocks as $index => $block) {
            if (preg_match('/^name = "(php-profiler|profiler-core)"$/m', $block)) {
                $blocks[$index] = preg_replace(
                    '/^version = "' . preg_quote($oldVersion, '/') . '"$/m',
                    'version = "' . $version . '"',
                    $block,
                    -1,
                    $count,
                );
                if ($count !== 1) {
                    throw new InvalidArgumentException('Unexpected internal lock text');
                }
            }
        }
        if ($newLock !== implode('[[package]]', $blocks)) {
            throw new InvalidArgumentException('Lock text changed beyond the two internal version values');
        }
        $expected = $ledgerBytes;
        $updates = [];
        foreach (['Cargo.toml', 'Cargo.lock'] as $name) {
            $replacement = hash('sha256', $target($name));
            $before = $hashes[$name];
            // Match the dependency map key, not a coincidentally equal hash elsewhere.
            $expected = preg_replace(
                '/("' . preg_quote($name, '/') . '"\s*:\s*")' . $before . '(")/',
                '${1}' . $replacement . '${2}',
                $expected,
                -1,
                $count,
            );
            if ($count !== 1) {
                throw new InvalidArgumentException('Dependency hash is not uniquely represented: ' . $name);
            }
            $updates[$name] = ['old_sha256' => $before, 'new_sha256' => $replacement];
        }
        $restored = json_decode($expected, false, flags: JSON_THROW_ON_ERROR);
        $restored->dependency_sha256 = $ledger->dependency_sha256;
        if (Json::encode($restored) !== Json::encode($ledger)) {
            throw new InvalidArgumentException('Unexpected proof mutation');
        }
        // The byte substitution above preserves all source spans, contexts, replacements,
        // classifications, formatting and every other proof dependency exactly.
        if (!in_array($target(self::LEDGER), [$ledgerBytes, $expected], true)) {
            throw new InvalidArgumentException('Target proof ledger changes more than the two derived Cargo hashes');
        }
        return [$expected, $updates];
    }
}
