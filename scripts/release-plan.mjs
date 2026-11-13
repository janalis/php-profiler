import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import semver from 'semver';

export function nextRelease(previous, messages) {
  if (previous && !/^0\.\d+\.\d+-alpha\.[1-9]\d*$/.test(previous)) {
    throw new Error('Release channel needs explicit review');
  }
  const subjects = messages.map(message => message.trimStart().split(/\r?\n/, 1)[0]);
  const breaking = subjects.some(subject => /^[a-z]+(?:\([^\n]+\))?!: .+/.test(subject)) ||
    messages.some(message => /^BREAKING(?: CHANGE|-CHANGE): .+/m.test(message));
  const release = breaking || subjects.some(subject => /^(?:feat|fix)(?:\([^\n]+\))?: .+/.test(subject));
  if (!release) return null;
  if (!previous) return '0.1.0-alpha.1';
  if (breaking) return `0.${semver.minor(previous) + 1}.0-alpha.1`;
  return semver.inc(previous, 'prerelease', 'alpha');
}

export function plan(cwd = process.cwd()) {
  const git = (...args) => execFileSync('git', args, { cwd, encoding: 'utf8' }).trim();
  const source = git('rev-parse', 'HEAD');
  // Unrelated branch tags must never change the main channel version.
  const tags = git('tag', '--merged', 'HEAD', '--list', 'v*').split('\n').filter(Boolean);
  const versions = tags.map(tag => tag.slice(1));
  if (versions.some(version => !semver.valid(version) || !/^0\.\d+\.\d+-alpha\.[1-9]\d*$/.test(version))) {
    throw new Error('Release tags require an explicit channel review');
  }
  const previous = versions.sort(semver.rcompare)[0] ?? null;
  const messages = git('log', '--format=%B%x00', previous ? `v${previous}..HEAD` : 'HEAD').split('\0');
  return { previous, version: nextRelease(previous, messages), source };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  console.log(JSON.stringify(plan()));
}
