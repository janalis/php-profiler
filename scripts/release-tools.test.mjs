import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { nextRelease, plan } from './release-plan.mjs';

test('alpha channel transitions and maintenance commits', () => {
  assert.equal(nextRelease(null, ['feat: initial implementation']), '0.1.0-alpha.1');
  assert.equal(nextRelease(null, ['docs: initial documentation']), null);
  assert.equal(nextRelease(null, ['docs: commit examples\n\nfix: example\nfeat!: another example']), null);
  assert.equal(nextRelease('0.1.0-alpha.9', ['fix(api): isolation']), '0.1.0-alpha.10');
  assert.equal(nextRelease('0.1.0-alpha.9', ['feat: new measurement']), '0.1.0-alpha.10');
  assert.equal(nextRelease('0.1.0-alpha.9', ['refactor!: change API']), '0.2.0-alpha.1');
  assert.equal(nextRelease('0.1.0-alpha.9', ['refactor: API\n\nBREAKING-CHANGE: removed method']), '0.2.0-alpha.1');
  assert.equal(nextRelease('0.1.0-alpha.9', ['chore: deps', 'test: regression']), null);
  assert.throws(() => nextRelease('1.0.0', ['fix: issue']), /channel/);
});

test('planner ignores unreachable branch releases and refuses malformed channel tags', () => {
  const cwd = mkdtempSync(join(tmpdir(), 'profiler-release-'));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  try {
    git('init', '-b', 'main');
    git('config', 'user.name', 'Release Test');
    git('config', 'user.email', 'release-test@example.invalid');
    git('commit', '--allow-empty', '-m', 'feat: initial');
    assert.equal(plan(cwd).version, '0.1.0-alpha.1');
    git('tag', 'v0.1.0-alpha.1');
    git('switch', '-c', 'other');
    git('commit', '--allow-empty', '-m', 'feat: future');
    git('tag', 'v0.9.0-alpha.1');
    git('switch', 'main');
    git('commit', '--allow-empty', '-m', 'fix: issue');
    assert.equal(plan(cwd).version, '0.1.0-alpha.2');
    git('tag', 'v1.0.0');
    assert.throws(() => plan(cwd), /channel/);
  } finally {
    rmSync(cwd, { recursive: true, force: true });
  }
});

test('release preparation updates both lock entries and refuses dirty retries', async () => {
  const { mkdirSync, writeFileSync, readFileSync, copyFileSync, chmodSync, cpSync } = await import('node:fs');
  const cwd = mkdtempSync(join(tmpdir(), 'profiler-prepare-'));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  try {
    mkdirSync(join(cwd, 'scripts'));
    mkdirSync(join(cwd, 'core', 'src'), {recursive: true});
    mkdirSync(join(cwd, 'ext', 'src'), {recursive: true});
    for (const name of ['prepare-release.php', 'validate-version-transition.php', 'php-tool.sh']) {
      copyFileSync(new URL(`./${name}`, import.meta.url), join(cwd, 'scripts', name));
    }
    chmodSync(join(cwd, 'scripts', 'php-tool.sh'), 0o755);
    cpSync(new URL('./lib', import.meta.url), join(cwd, 'scripts', 'lib'), { recursive: true });
    writeFileSync(join(cwd, 'Cargo.toml'), '[workspace]\nmembers = ["core", "ext"]\nresolver = "2"\n[workspace.package]\nversion = "0.1.0"\n');
    for (const name of ['core', 'ext']) {
      writeFileSync(join(cwd, name, 'Cargo.toml'), `[package]\nname = "${name === 'core' ? 'profiler-core' : 'php-profiler'}"\nversion.workspace = true\nedition = "2021"\n`);
      writeFileSync(join(cwd, name, 'src', 'lib.rs'), '');
    }
    writeFileSync(join(cwd, 'CHANGELOG.md'), '# Changelog\n\n## [Unreleased]\n\n- Initial feature\n');
    execFileSync('cargo', ['metadata', '--offline', '--format-version=1'], {cwd, stdio: 'pipe'});
    const ledgerFixture = `
require 'scripts/lib/VersionTransition.php';
use PhpProfiler\\Tooling\\VersionTransition;
$hashes = [];
foreach (VersionTransition::DEPENDENCIES as $name) {
    if (!file_exists($name)) {
        @mkdir(dirname($name), 0777, true);
        file_put_contents($name, 'fixture ' . $name);
    }
    $hashes[$name] = hash('sha256', file_get_contents($name));
}
@mkdir(dirname(VersionTransition::LEDGER), 0777, true);
file_put_contents(VersionTransition::LEDGER, json_encode(['schema_version' => 2, 'contexts' => ['nts' => new stdClass()], 'equivalents' => [], 'dependency_sha256' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\\n");
`;
    execFileSync('scripts/php-tool.sh', ['-r', ledgerFixture], {cwd, stdio: 'pipe'});
    git('init', '-b', 'main');
    git('config', 'user.name', 'Release Test');
    git('config', 'user.email', 'release-test@example.invalid');
    // Explicit fixture-only paths, never stage ambient user work.
    git('add', 'Cargo.toml', 'Cargo.lock', 'CHANGELOG.md', 'scripts', 'core', 'ext', 'crates', 'docs', 'tests');
    git('commit', '-m', 'feat: initial');
    execFileSync('scripts/php-tool.sh', ['scripts/prepare-release.php', '0.1.0-alpha.1'], {cwd, stdio: 'pipe'});
    const lock = readFileSync(join(cwd, 'Cargo.lock'), 'utf8');
    assert.equal((lock.match(/version = "0.1.0-alpha.1"/g) ?? []).length, 2);
    assert.match(readFileSync(join(cwd, 'CHANGELOG.md'), 'utf8'), /## \[0.1.0-alpha.1\] - \d{4}-\d{2}-\d{2}/);
    assert.throws(() => execFileSync('scripts/php-tool.sh', ['scripts/prepare-release.php', '0.1.0-alpha.2'], {cwd, stdio: 'pipe'}));
    git('add', '-A');
    git('commit', '-m', 'chore(release): prepare 0.1.0-alpha.1');
    git('tag', 'v0.1.0-alpha.1');
    // release-plan.mjs releases for a breaking change named only in a commit body.
    git('commit', '--allow-empty', '-m', 'refactor: drop the old API', '-m', 'BREAKING CHANGE: the old API is gone');
    execFileSync('scripts/php-tool.sh', ['scripts/prepare-release.php', '0.2.0-alpha.1'], {cwd, stdio: 'pipe'});
    assert.match(readFileSync(join(cwd, 'CHANGELOG.md'), 'utf8'),
      /## \[0.2.0-alpha.1\] - \d{4}-\d{2}-\d{2}\n\n### Commits\n\n- refactor: drop the old API\n/);
    git('add', '-A');
    git('commit', '-m', 'chore(release): prepare 0.2.0-alpha.1');
    git('tag', 'v0.2.0-alpha.1');
    // Nothing to list and an empty Unreleased section would publish empty notes: refuse before writing.
    git('commit', '--allow-empty', '-m', 'chore: tidy');
    assert.throws(() => execFileSync('scripts/php-tool.sh', ['scripts/prepare-release.php', '0.2.0-alpha.2'], {cwd, stdio: 'pipe'}), /No release notes/);
    assert.equal(git('status', '--porcelain'), '');
  } finally {
    rmSync(cwd, { recursive: true, force: true });
  }
});

// Finalization, orchestration and registry checks run in the PHP suite: tests/tools/*Release*Test.php.
