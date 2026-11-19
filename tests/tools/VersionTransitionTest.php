<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once ROOT . '/scripts/lib/VersionTransition.php';

use PhpProfiler\Tooling\Paths;
use PhpProfiler\Tooling\Process;
use PhpProfiler\Tooling\VersionTransition;

/** Version-only metadata migration cannot renew stale or changed native proofs. */
final class TransitionFixture
{
    public array $base = [];
    public array $target = [];
    public array $ledger;
    public string $version = '0.1.0-alpha.1';

    public function __construct()
    {
        foreach (VersionTransition::DEPENDENCIES as $name) {
            $this->base[$name] = $name . "\n";
        }
        $this->base['Cargo.toml'] = "[workspace.package]\nversion = \"0.1.0\"\nedition = \"2024\"\n";
        $this->base['Cargo.lock'] = "version = 4\n\n[[package]]\nname = \"php-profiler\"\nversion = \"0.1.0\"\n\n[[package]]\nname = \"profiler-core\"\nversion = \"0.1.0\"\n\n[[package]]\nname = \"registry-dependency\"\nversion = \"1.0.0\"\nsource = \"registry+https://github.com/rust-lang/crates.io-index\"\n";
        $hashes = [];
        foreach ($this->base as $name => $body) {
            $hashes[$name] = hash('sha256', $body);
        }
        $this->ledger = [
            'schema_version' => 2,
            'contexts' => ['nts' => ['flags' => ['-O0']], 'zts' => ['flags' => ['-DZTS']]],
            'equivalents' => [[
                'source' => 'bridge.c',
                'status' => 'equivalent',
                'replacement' => '0',
                'location' => ['line' => 10],
            ]],
            'dependency_sha256' => $hashes,
        ];
        $this->syncLedger();
        $this->target = $this->base;
        $this->target['Cargo.toml'] = str_replace('"0.1.0"', '"0.1.0-alpha.1"', $this->base['Cargo.toml']);
        $this->target['Cargo.lock'] = str_replace('"0.1.0"', '"0.1.0-alpha.1"', $this->base['Cargo.lock']);
    }

    public function syncLedger(): void
    {
        $this->base[VersionTransition::LEDGER] =
            json_encode($this->ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public function derive(?string $version = null): array
    {
        return VersionTransition::expected(
            fn(string $name): string => $this->base[$name],
            fn(string $name): string => $this->target[$name],
            $version ?? $this->version,
        );
    }
}

function rejectsTransition(TransitionFixture $fixture, ?string $version = null): void
{
    throws(InvalidArgumentException::class, static fn() => $fixture->derive($version));
}

return [
    'only two hash bytes change and all proof values remain identical' => static function (): void {
        $fixture = new TransitionFixture();
        [$result, $updates] = $fixture->derive();
        $actual = json_decode($result, true);
        $hashes = $actual['dependency_sha256'];
        unset($actual['dependency_sha256']);
        $expected = $fixture->ledger;
        unset($expected['dependency_sha256']);
        same($expected, $actual);
        same(['Cargo.toml', 'Cargo.lock'], array_keys($updates));
        foreach (VersionTransition::DEPENDENCIES as $name) {
            same(hash('sha256', $fixture->target[$name]), $hashes[$name]);
        }
        $restored = $result;
        foreach ($updates as $update) {
            $restored = str_replace($update['new_sha256'], $update['old_sha256'], $restored);
        }
        same($fixture->base[VersionTransition::LEDGER], $restored);
    },
    'registry dependency version and unexpected entry are rejected' => static function (): void {
        foreach (["\n[[package]]\nname = \"unexpected\"\nversion = \"1\"\n", ''] as $suffix) {
            $fixture = new TransitionFixture();
            $fixture->target['Cargo.lock'] = $suffix !== ''
                ? $fixture->target['Cargo.lock'] . $suffix
                : str_replace('"1.0.0"', '"2.0.0"', $fixture->target['Cargo.lock']);
            rejectsTransition($fixture);
        }
    },
    'edition build flags and any other proof dependency change are rejected' => static function (): void {
        foreach (['Cargo.toml', 'crates/php-profiler/build.rs', 'tests/native/sampling-unit.c'] as $name) {
            $fixture = new TransitionFixture();
            $fixture->target[$name] = $name === 'Cargo.toml'
                ? str_replace('2024', '2021', $fixture->target[$name])
                : $fixture->target[$name] . "-DZTS\n";
            rejectsTransition($fixture);
        }
    },
    'missing extra and stale dependency hashes are rejected' => static function (): void {
        foreach (['missing', 'extra', 'stale'] as $mode) {
            $fixture = new TransitionFixture();
            match ($mode) {
                'missing' => $fixture->ledger['dependency_sha256'] = array_diff_key(
                    $fixture->ledger['dependency_sha256'],
                    ['Cargo.lock' => true],
                ),
                'extra' => $fixture->ledger['dependency_sha256']['unexpected'] = str_repeat('0', 64),
                'stale' => $fixture->ledger['dependency_sha256']['crates/php-profiler/build.rs'] = str_repeat('0', 64),
            };
            $fixture->syncLedger();
            rejectsTransition($fixture);
        }
    },
    'changed context status replacement or source span is rejected' => static function (): void {
        foreach (['context', 'status', 'replacement', 'span'] as $field) {
            $fixture = new TransitionFixture();
            $changed = $fixture->ledger;
            match ($field) {
                'context' => $changed['contexts']['nts']['flags'] = ['-O2'],
                'span' => $changed['equivalents'][0]['location']['line'] = 11,
                default => $changed['equivalents'][0][$field] = 'changed',
            };
            $fixture->target[VersionTransition::LEDGER] = json_encode($changed);
            rejectsTransition($fixture);
        }
    },
    'unexpected lock text edits are rejected even if the parsed document is equal' => static function (): void {
        $fixture = new TransitionFixture();
        $fixture->target['Cargo.lock'] .= "\n# harmless-looking unaudited edit\n";
        rejectsTransition($fixture);
    },
    'same or unsupported version is rejected' => static function (): void {
        foreach (['0.1.0', '1.0.0', '0.1.0-alpha.0'] as $version) {
            rejectsTransition(new TransitionFixture(), $version);
        }
    },
    'inventory matches the committed ledger' => static function (): void {
        // A proof dependency added without updating the inventory only fails at release time.
        $names = array_keys(
            json_decode((string) file_get_contents(ROOT . '/' . VersionTransition::LEDGER), true)['dependency_sha256'],
        );
        $inventory = VersionTransition::DEPENDENCIES;
        sort($names);
        sort($inventory);
        same($inventory, $names);
    },
    'cli applies only verified hashes, emits a receipt and rejects an extra build flag' => static function (): void {
        $fixture = new TransitionFixture();
        $root = temporaryDirectory();
        $files = [...$fixture->base, 'CHANGELOG.md' => "# Changelog\n", '.gitignore' => "target/\n"];
        foreach ($files as $name => $body) {
            writeFile("{$root}/{$name}", $body);
        }
        Paths::copyTree(ROOT . '/scripts/lib', "{$root}/scripts/lib");
        foreach (['php-tool.sh', 'validate-version-transition.php'] as $name) {
            Paths::copyFile(ROOT . "/scripts/{$name}", "{$root}/scripts/{$name}");
        }
        $git = static fn(string ...$arguments): string => Process::output(['git', '-C', $root, ...$arguments]);
        $git('init', '-b', 'main');
        $git('config', 'user.name', 'Release Test');
        $git('config', 'user.email', 'release@example.invalid');
        $git('add', '-A');
        $git('commit', '-m', 'feat: audited fixture');
        foreach (['Cargo.toml', 'Cargo.lock'] as $name) {
            file_put_contents("{$root}/{$name}", $fixture->target[$name]);
        }
        $helper = [
            "{$root}/scripts/php-tool.sh",
            "{$root}/scripts/validate-version-transition.php",
            'HEAD',
            $fixture->version,
        ];
        same(0, Process::run([...$helper, '--apply'])['status']);
        [$expected, $updates] = $fixture->derive();
        same($expected, file_get_contents("{$root}/" . VersionTransition::LEDGER));
        $receipt = json_decode((string) file_get_contents("{$root}/target/version-transition.json"), true);
        same($updates, $receipt['dependency_updates']);
        same(count(VersionTransition::DEPENDENCIES) - 2, $receipt['unchanged_dependency_count']);
        same(0, Process::run($helper)['status']);
        writeFile("{$root}/.cargo/config.toml", "[build]\nrustflags = [\"-Ctarget-cpu=native\"]\n");
        $git('add', '.cargo/config.toml');
        $result = Process::run([...$helper, '--apply']);
        same(1, $result['status']);
        same(true, str_contains($result['stderr'], 'Unexpected release transition files'), $result['stderr']);
        same($expected, file_get_contents("{$root}/" . VersionTransition::LEDGER));
    },
];
