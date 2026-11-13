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
  const { mkdirSync, writeFileSync, readFileSync, copyFileSync } = await import('node:fs');
  const cwd = mkdtempSync(join(tmpdir(), 'profiler-prepare-'));
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  try {
    mkdirSync(join(cwd, 'scripts'));
    mkdirSync(join(cwd, 'core', 'src'), {recursive: true});
    mkdirSync(join(cwd, 'ext', 'src'), {recursive: true});
    copyFileSync(new URL('./prepare-release.py', import.meta.url), join(cwd, 'scripts', 'prepare-release.py'));
    copyFileSync(new URL('./validate-version-transition.py', import.meta.url), join(cwd, 'scripts', 'validate-version-transition.py'));
    writeFileSync(join(cwd, 'Cargo.toml'), '[workspace]\nmembers = ["core", "ext"]\nresolver = "2"\n[workspace.package]\nversion = "0.1.0"\n');
    for (const name of ['core', 'ext']) {
      writeFileSync(join(cwd, name, 'Cargo.toml'), `[package]\nname = "${name === 'core' ? 'profiler-core' : 'php-profiler'}"\nversion.workspace = true\nedition = "2021"\n`);
      writeFileSync(join(cwd, name, 'src', 'lib.rs'), '');
    }
    writeFileSync(join(cwd, 'CHANGELOG.md'), '# Changelog\n\n## [Unreleased]\n\n- Initial feature\n');
    execFileSync('cargo', ['metadata', '--offline', '--format-version=1'], {cwd, stdio: 'pipe'});
    const ledgerFixture = `
import importlib.util, hashlib, json
from pathlib import Path
spec = importlib.util.spec_from_file_location('transition', 'scripts/validate-version-transition.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
for name in module.DEPENDENCIES:
    path = Path(name)
    if not path.exists():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text('fixture ' + name)
ledger = Path(module.LEDGER)
ledger.parent.mkdir(parents=True, exist_ok=True)
ledger.write_text(json.dumps({'schema_version': 2, 'contexts': {'nts': {}}, 'equivalents': [], 'dependency_sha256': {name: module.digest(Path(name).read_bytes()) for name in module.DEPENDENCIES}}, indent=2) + '\\n')
`;
    execFileSync('python3', ['-c', ledgerFixture], {cwd, stdio: 'pipe'});
    git('init', '-b', 'main');
    git('config', 'user.name', 'Release Test');
    git('config', 'user.email', 'release-test@example.invalid');
    // Explicit fixture-only paths, never stage ambient user work.
    git('add', 'Cargo.toml', 'Cargo.lock', 'CHANGELOG.md', 'scripts', 'core', 'ext', 'crates', 'docs', 'tests');
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

test('registry receipts reject absent versions and unrelated source', () => {
  const script = `
import importlib.util
spec = importlib.util.spec_from_file_location('release', 'scripts/finalize-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
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
    if args[:2] == ['git', 'cat-file']:
        return 'commit'
    if '/actions/runs/' in args[-1]:
        return json.dumps({'name': 'quality', 'conclusion': 'success', 'head_sha': source, 'head_branch': 'main', 'head_repository': {'full_name': 'janalis/php-profiler'}, 'event': 'workflow_dispatch'})
    if args[-1].endswith('/git/ref/heads/main'):
        return json.dumps({'object': {'sha': source}})
    if 'matching-refs' in args[-1]:
        return json.dumps(refs)
    if 'releases?' in args[-1]:
        return json.dumps([release])
    raise AssertionError(args)
def fetch(url):
    return json.dumps(packagist).encode() if 'packagist' in url else b'archive'
with tempfile.TemporaryDirectory() as directory:
    archive = Path(directory) / 'source.tgz'
    archive.write_bytes(b'archive')
    with patch.object(sys, 'argv', ['finalize', version, str(archive), '--quality-run-id', '42']), patch.object(module.subprocess, 'check_output', side_effect=output), patch.object(module.subprocess, 'run') as run, patch.object(module, 'fetch', side_effect=fetch), patch.object(module, 'verify_checkout'):
        run.return_value.returncode = 0
        module.main()
        assert run.call_count == 2
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
        entries = {}
        for name, data in committed.items():
            entries['php-profiler-0.1.0-alpha.1/' + name] = data
        if changed:
            entries['php-profiler-0.1.0-alpha.1/Cargo.toml'] = b'changed'
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

test('orchestration trusts exact source receipts and explicitly dispatches pinned quality runs', () => {
  const script = `
import importlib.util, subprocess
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('release', 'scripts/release-automation.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
source = 'a' * 40
run = {'name': 'quality', 'conclusion': 'success', 'head_sha': source, 'head_branch': 'main', 'event': 'push', 'head_repository': {'full_name': module.REPOSITORY}}
assert module.validate_run(run, source) == 'main'
for key, value in [('head_sha', 'wrong'), ('event', 'pull_request'), ('conclusion', 'failure')]:
    changed = dict(run)
    changed[key] = value
    try:
        module.validate_run(changed, source)
    except ValueError:
        pass
    else:
        raise AssertionError('untrusted quality run accepted')
run.update(head_branch='release/0.1.0-alpha.1-aaaaaaaaaaaa', event='workflow_dispatch', actor={'login': 'github-actions[bot]'})
assert module.validate_run(run, source).startswith('release/')
run['actor']['login'] = 'someone-else'
try:
    module.validate_run(run, source)
except ValueError:
    pass
else:
    raise AssertionError('non-bot candidate dispatch accepted')
with patch.object(module.subprocess, 'run') as dispatch:
    module.dispatch('main', source)
    assert dispatch.call_args.args[0] == ['gh', 'workflow', 'run', 'ci.yml', '--repo', module.REPOSITORY, '--ref', 'main', '-f', 'expected_sha=' + source]
`;
  execFileSync('python3', ['-c', script], {cwd: new URL('..', import.meta.url), stdio: 'pipe'});
});

test('lightweight tag publication uses a real non-force push, safely resumes and rejects changed tags', () => {
  const script = `
import importlib.util, json, subprocess, tempfile
from pathlib import Path
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('release', 'scripts/finalize-release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
original_output = subprocess.check_output
original_run = subprocess.run
repo = 'janalis/php-profiler'
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory) / 'source'
    root.mkdir()
    remote = Path(directory) / 'remote.git'
    def git(*args):
        return original_output(['git', *args], cwd=root, stderr=subprocess.PIPE, text=True).strip()
    git('init', '-b', 'main')
    git('config', 'user.name', 'Release Test')
    git('config', 'user.email', 'release@example.invalid')
    git('commit', '--allow-empty', '-m', 'feat: initial')
    source = git('rev-parse', 'HEAD')
    original_run(['git', 'init', '--bare', str(remote)], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    git('remote', 'add', 'origin', str(remote))
    commands = []
    current = source
    def output(args, **kwargs):
        if args[:3] == ['git', 'remote', 'get-url']:
            return 'https://github.com/' + repo + '.git'
        if args[:2] == ['gh', 'api']:
            return json.dumps({'object': {'sha': current}})
        return original_output(args, **kwargs)
    def run(args, **kwargs):
        commands.append(args)
        if args == ['gh', 'auth', 'setup-git']:
            return subprocess.CompletedProcess(args, 0)
        kwargs.setdefault('stdout', subprocess.PIPE)
        kwargs.setdefault('stderr', subprocess.PIPE)
        return original_run(args, **kwargs)
    tag = 'v0.1.0-alpha.1'
    reference = 'refs/tags/' + tag
    refs = [{'ref': reference, 'object': {'sha': source, 'type': 'commit'}}]
    with patch.object(module.subprocess, 'check_output', side_effect=output), patch.object(module.subprocess, 'run', side_effect=run):
        module.publish_tag(root, repo, source, tag, [])
        assert ['git', 'push', 'origin', reference] in commands
        assert all('--force' not in command and '-f' not in command for command in commands)
        assert original_output(['git', '--git-dir=' + str(remote), 'rev-parse', reference], text=True).strip() == source
        commands.clear()
        module.publish_tag(root, repo, source, tag, refs)
        assert not any(command[:2] == ['git', 'push'] for command in commands)
        assert not any(command[:2] == ['gh', 'auth'] for command in commands)
        git('commit', '--allow-empty', '-m', 'fix: another commit')
        other = git('rev-parse', 'HEAD')
        git('tag', '-f', tag, other)
        commands.clear()
        try:
            module.publish_tag(root, repo, source, tag, refs)
        except ValueError:
            pass
        else:
            raise AssertionError('wrong local tag accepted')
        assert not any(command[:2] == ['git', 'push'] for command in commands)
        current = other
        commands.clear()
        try:
            module.publish_tag(root, repo, source, 'v0.1.0-alpha.2', [])
        except ValueError:
            pass
        else:
            raise AssertionError('main race accepted')
        assert not any(command[:2] == ['git', 'push'] for command in commands)
        assert original_output(['git', '--git-dir=' + str(remote), 'rev-parse', reference], text=True).strip() == source
`;
  execFileSync('python3', ['-c', script], {cwd: new URL('..', import.meta.url), stdio: 'pipe'});
});
