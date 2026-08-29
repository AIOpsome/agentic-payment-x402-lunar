import {execFileSync} from 'node:child_process';
import {existsSync, readFileSync} from 'node:fs';

// This repo has no npm package to publish -- `changesets/action` runs this
// script (via `npm run release`) as the "publish" step once a Version
// Packages PR has been merged, i.e. once package.json's version no longer
// matches the latest git tag. The actual release artifact for a Composer
// package is a git tag: Packagist's GitHub webhook picks up a new semver
// tag automatically, no upload/build step required.
//
// This step runs on every push to master, not only after a Version Packages
// merge, so it must be a safe no-op whenever there is nothing new to release.

const pkg = JSON.parse(readFileSync('package.json', 'utf8'));
const tag = `v${pkg.version}`;

if (pkg.version === '0.0.0') {
    console.log('package.json is still at the placeholder version -- nothing to release.');
    process.exit(0);
}

const existingTags = execFileSync('git', ['tag', '-l'], {encoding: 'utf8'}).split('\n').filter(Boolean);
if (existingTags.includes(tag)) {
    console.log(`Tag ${tag} already exists -- nothing to release.`);
    process.exit(0);
}

if (!existsSync('CHANGELOG.md')) {
    console.log('CHANGELOG.md does not exist yet -- nothing to release.');
    process.exit(0);
}

const changelog = readFileSync('CHANGELOG.md', 'utf8');
const versionHeadingIndex = changelog.search(
    new RegExp(`^## ${pkg.version.replace(/\./g, '\\.')}\\s*$`, 'm')
);
let releaseNotes = `Release ${tag}.`;
if (versionHeadingIndex !== -1) {
    const rest = changelog.slice(versionHeadingIndex);
    const nextHeadingIndex = rest.indexOf('\n## ', 1);
    releaseNotes = rest.slice(0, nextHeadingIndex === -1 ? undefined : nextHeadingIndex).trim();
}

execFileSync('git', ['tag', tag], {stdio: 'inherit'});
execFileSync('git', ['push', 'origin', tag], {stdio: 'inherit'});
execFileSync('gh', ['release', 'create', tag, '--title', tag, '--notes-file', '-'], {
    input: releaseNotes,
    stdio: ['pipe', 'inherit', 'inherit'],
});

console.log(`Tagged and released ${tag}. Packagist will pick this up automatically via its webhook.`);
