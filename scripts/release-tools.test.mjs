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
  const { mkdirSync, writeFileSync, readFileSync, copyFileSync } = await import('node:fs');
  const cwd = mkdtempSync(join(tmpdir(), 'profiler-prepare-'));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  try {
    mkdirSync(join(cwd, 'scripts'));
    mkdirSync(join(cwd, 'core', 'src'), {recursive: true});
    mkdirSync(join(cwd, 'ext', 'src'), {recursive: true});
    copyFileSync(new URL('./prepare-release.py', import.meta.url), join(cwd, 'scripts', 'prepare-release.py'));
    writeFileSync(join(cwd, 'Cargo.toml'), '[workspace]\nmembers = ["core", "ext"]\nresolver = "2"\n[workspace.package]\nversion = "0.1.0"\n');
    for (const name of ['core', 'ext']) {
      writeFileSync(join(cwd, name, 'Cargo.toml'), `[package]\nname = "test-${name}"\nversion.workspace = true\nedition = "2021"\n`);
      writeFileSync(join(cwd, name, 'src', 'lib.rs'), '');
    }
    writeFileSync(join(cwd, 'CHANGELOG.md'), '# Changelog\n\n## [Unreleased]\n\n- Initial feature\n');
    execFileSync('cargo', ['metadata', '--offline', '--format-version=1'], {cwd, stdio: 'pipe'});
    git('init', '-b', 'main');
    git('config', 'user.name', 'Release Test');
    git('config', 'user.email', 'release-test@example.invalid');
    // Explicit fixture-only paths, never stage ambient user work.
    git('add', 'Cargo.toml', 'Cargo.lock', 'CHANGELOG.md', 'scripts', 'core', 'ext');
    git('commit', '-m', 'feat: initial');
    execFileSync('python3', ['scripts/prepare-release.py', '0.1.0-alpha.1'], {cwd, stdio: 'pipe'});
    const lock = readFileSync(join(cwd, 'Cargo.lock'), 'utf8');
    assert.equal((lock.match(/version = "0.1.0-alpha.1"/g) ?? []).length, 2);
    assert.match(readFileSync(join(cwd, 'CHANGELOG.md'), 'utf8'), /## \[0.1.0-alpha.1\] - \d{4}-\d{2}-\d{2}/);
    assert.throws(() => execFileSync('python3', ['scripts/prepare-release.py', '0.1.0-alpha.2'], {cwd, stdio: 'pipe'}));
  } finally {
    rmSync(cwd, { recursive: true, force: true });
  }
});

test('registry receipts reject mismatched bytes, absent versions, and unrelated source', () => {
  const script = `
import importlib.util
spec = importlib.util.spec_from_file_location('release', 'scripts/finalize-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.verify_pecl(b'archive', b'archive')
try:
    module.verify_pecl(b'archive', b'wrong')
except ValueError:
    pass
else:
    raise AssertionError('mismatched archive accepted')
valid = {'package': {'name': 'janalis/php-profiler', 'versions': {'v0.1.0-alpha.1': {'version': 'v0.1.0-alpha.1', 'source': {'reference': 'abc', 'url': 'https://github.com/janalis/php-profiler.git'}}}}}
module.verify_packagist(valid, '0.1.0-alpha.1', 'abc')
for payload, source in [(valid, 'wrong'), ({}, 'abc')]:
    try:
        module.verify_packagist(payload, '0.1.0-alpha.1', source)
    except ValueError:
        pass
    else:
        raise AssertionError('invalid registry receipt accepted')
`;
  execFileSync('python3', ['-c', script], {cwd: new URL('..', import.meta.url), stdio: 'pipe'});
});

test('finalization retries verify existing tags and assets without replacing them', () => {
  const script = `
import importlib.util, json, sys, tempfile
from pathlib import Path
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('release', 'scripts/finalize-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
version = '0.1.0-alpha.1'
source = 'a' * 40
refs = [{'ref': 'refs/tags/v' + version, 'object': {'sha': source, 'type': 'commit'}}]
release = [{'tag_name': 'v' + version, 'assets': [{'name': 'source.tgz', 'browser_download_url': 'https://example.invalid/archive'}]}]
packagist = {'package': {'name': 'janalis/php-profiler', 'versions': {version: {'version': version, 'source': {'reference': source, 'url': 'https://github.com/janalis/php-profiler.git'}}}}}
def output(args, **kwargs):
    if args[:2] == ['git', 'rev-parse']:
        return source
    if 'matching-refs' in args[-1]:
        return json.dumps(refs)
    if 'releases?' in args[-1]:
        return json.dumps(release)
    raise AssertionError(args)
def fetch(url):
    return json.dumps(packagist).encode() if 'packagist' in url else b'archive'
with tempfile.TemporaryDirectory() as directory:
    archive = Path(directory) / 'source.tgz'
    archive.write_bytes(b'archive')
    with patch.object(sys, 'argv', ['finalize', version, str(archive)]), patch.object(module.subprocess, 'check_output', side_effect=output), patch.object(module.subprocess, 'run') as run, patch.object(module, 'fetch', side_effect=fetch), patch.object(module, 'verify_checkout'):
        module.main()
        assert run.call_count == 1
        assert run.call_args.args[0][:3] == ['gh', 'release', 'upload']
        assert '--clobber' not in run.call_args.args[0]
        refs[0]['object']['sha'] = 'b' * 40
        run.reset_mock()
        try:
            module.main()
        except ValueError:
            pass
        else:
            raise AssertionError('existing mismatched tag accepted')
        run.assert_not_called()
`;
  execFileSync('python3', ['-c', script], {cwd: new URL('..', import.meta.url), stdio: 'pipe'});
});

test('finalizer independently rejects dirty candidates, wrong versions and changed archive sources', () => {
  const script = `
import importlib.util, io, tarfile
from pathlib import Path
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('release', 'scripts/finalize-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
version = '0.1.0-alpha.1'
committed = {'Cargo.toml': b'version bytes', 'packaging/config.m4': b'config', 'packaging/Makefile.frag': b'fragment'}
def build(changed=False):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w:gz') as archive:
        entries = {'package.xml': b'<package xmlns="http://pear.php.net/dtd/package-2.0"><name>php_profiler</name><version><release>0.1.0alpha1</release></version></package>'}
        for name, data in committed.items():
            entries['php_profiler-0.1.0alpha1/' + name] = data
        entries['php_profiler-0.1.0alpha1/config.m4'] = b'config'
        entries['php_profiler-0.1.0alpha1/Makefile.frag'] = b'fragment'
        if changed:
            entries['php_profiler-0.1.0alpha1/Cargo.toml'] = b'changed'
        for name, data in entries.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
    return output.getvalue()
module.verify_archive(build(), version, committed)
try:
    module.verify_archive(build(True), version, committed)
except ValueError:
    pass
else:
    raise AssertionError('different source accepted')
for results in [[b'M Cargo.toml'], [b'', b'[workspace.package]\\nversion = "0.1.0-alpha.2"']]:
    with patch.object(module.subprocess, 'check_output', side_effect=results):
        try:
            module.verify_checkout(Path('.'), version, build())
        except ValueError:
            pass
        else:
            raise AssertionError('dirty or wrong-version candidate accepted')
`;
  execFileSync('python3', ['-c', script], {cwd: new URL('..', import.meta.url), stdio: 'pipe'});
});
