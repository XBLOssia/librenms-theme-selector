<?php

declare(strict_types=1);

/*
 * Releases: what hosts that follow a tag rely on. The workflows are YAML files nothing here can run, so
 * what is pinned is their shape: the checks a pull request must pass exist under the names the branch
 * ruleset asks for, every action is pinned to a commit, no workflow can do more than read the repository
 * except the two jobs that must, a release is only ever made from a tag after the tests, and the
 * script that makes one refuses to tag a commit that has not passed.
 */
function test_release(): void
{
    $root = __DIR__ . '/..';
    $read = fn (string $path): string => (string) @file_get_contents("$root/$path");

    T::group('workflows: pinned, read-only, and the right triggers');
    $files = ['ci', 'integration', 'release', 'nightly'];
    $yaml = [];
    foreach ($files as $name) {
        $yaml[$name] = $read(".github/workflows/$name.yml");
        T::ok("$name.yml exists", $yaml[$name] !== '');
    }
    foreach ($yaml as $name => $y) {
        T::ok("$name: the token can only read the repository, unless a job says otherwise", (bool) preg_match('/^permissions:\n  contents: read\n/m', $y));
        preg_match_all('/^\s*(?:- )?uses: (\S+)(.*)$/m', $y, $uses, PREG_SET_ORDER);
        $bad = [];
        foreach ($uses as $u) {
            $pinned = (bool) preg_match('~^[\w.-]+/[\w./-]+@[0-9a-f]{40}$~', $u[1]) && (bool) preg_match('/^\s+#\s*v?[\d.]+/', $u[2]);
            $local = (bool) preg_match('~^\./\.github/workflows/[\w-]+\.yml$~', $u[1]);
            if (! $pinned && ! $local) {
                $bad[] = $u[1];
            }
        }
        T::ok("$name: every action is pinned to a commit (with its version in a comment), or is a workflow in this repository", $bad === [], implode(', ', $bad));
        T::ok("$name: no pull_request_target, no secrets", ! str_contains($y, 'pull_request_target') && ! str_contains($y, 'secrets.'));
        T::ok("$name: nothing is piped into a shell from the network", ! preg_match('/(curl|wget)[^\n|]*\|\s*(ba)?sh/', $y));
    }
    $writers = [];
    foreach ($yaml as $name => $y) {
        if (preg_match_all('/^\s+(\w[\w-]*): write$/m', $y, $m)) {
            $writers[$name] = $m[1];
        }
    }
    T::ok('only the release job (contents) and the nightly report (issues) may write', $writers === ['release' => ['contents'], 'nightly' => ['issues']], json_encode($writers));

    T::group('workflows: CI');
    $ci = $yaml['ci'];
    T::ok('CI runs on pull requests, on pushes to main, and when called', str_contains($ci, "  pull_request:\n") && (bool) preg_match('/push:\n    branches: \[main\]/', $ci) && str_contains($ci, '  workflow_call:'));
    T::ok('its two jobs are called unit and mutate, the names the branch ruleset requires', (bool) preg_match('/^  unit:\n    name: unit \(PHP/m', $ci) && (bool) preg_match('/^  mutate:\n    name: mutation check/m', $ci));
    T::ok('it runs the unit tests and the mutation check', str_contains($ci, 'run: php tests/run.php') && str_contains($ci, 'run: sh tests/mutate.sh'));
    T::ok('it runs the generated-file checks', str_contains($ci, 'gen-token-catalog.py --check') && str_contains($ci, 'gen-token-docs.py --check') && str_contains($ci, 'make-clock-tower.py --check'));
    $composer = json_decode($read('composer.json'), true) ?: [];
    preg_match('/\^(\d+\.\d+)/', (string) ($composer['require']['php'] ?? ''), $min);
    T::ok('the PHP matrix starts at the lowest version composer.json allows', isset($min[1]) && str_contains($ci, "php: ['{$min[1]}'"), $min[1] ?? '?');
    T::ok('composer.json has no "version": Composer takes the version from the tag', ! array_key_exists('version', $composer));

    T::group('workflows: integration and nightly');
    $int = $yaml['integration'];
    T::ok('integration runs the live suites and the rehearsal, on main, on demand and when called', str_contains($int, 'sh dev/test.sh live') && str_contains($int, 'sh dev/test-update.sh') && (bool) preg_match('/push:\n    branches: \[main\]/', $int) && str_contains($int, '  workflow_dispatch:') && str_contains($int, '  workflow_call:'));
    T::ok('it fetches history, which the rehearsal starts from', str_contains($int, 'fetch-depth: 0'));
    preg_match("/LIBRENMS_VERSION: \\$\\{\\{ inputs.librenms_version \\|\\| '([\\d.]+)' \\}\\}/", $int, $v);
    $defaults = isset($v[1]) && str_contains($read('dev/compose.yml'), 'LIBRENMS_VERSION:-' . $v[1] . '}') && str_contains($read('dev/compose-clean.yml'), 'LIBRENMS_VERSION:-' . $v[1] . '}') && str_contains($read('dev/Dockerfile'), 'ARG LIBRENMS_VERSION=' . $v[1] . "
");
    T::ok('its default LibreNMS version is the one the dev stacks default to', $defaults, $v[1] ?? '?');
    $night = $yaml['nightly'];
    T::ok('the nightly check runs on a schedule, against the newest LibreNMS, with the network section', str_contains($night, 'schedule:') && (bool) preg_match('/- cron: \'\d+ \d+ \* \* \*\'/', $night) && str_contains($night, 'librenms_version: latest') && str_contains($night, 'network: true'));
    T::ok('it reports a failure as one issue and touches nothing else', str_contains($night, 'if: failure()') && str_contains($night, 'upstream-check') && ! str_contains($night, 'contents: write'));

    T::group('workflows: release');
    $rel = $yaml['release'];
    T::ok('a release is made only from a tag of the form vX.Y.Z, never from a branch', (bool) preg_match("/on:\n  push:\n    tags: \\['v\\[0-9\\]\\+\\.\\[0-9\\]\\+\\.\\[0-9\\]\\+'\\]\n\npermissions/", $rel) && ! str_contains($rel, 'branches:'));
    T::ok('it requires an annotated tag on main, newer than the last, with a changelog section', str_contains($rel, 'is not an annotated tag') && str_contains($rel, 'merge-base --is-ancestor') && str_contains($rel, 'is not newer than') && str_contains($rel, 'CHANGELOG.md has no section'));
    T::ok('it runs CI and the integration suites, and publishes only after both', str_contains($rel, 'uses: ./.github/workflows/ci.yml') && str_contains($rel, 'uses: ./.github/workflows/integration.yml') && str_contains($rel, 'needs: [verify, ci, integration]'));
    T::ok('the Release carries the changelog section as its notes, on the tag that was pushed', str_contains($rel, 'release.sh notes') && str_contains($rel, '--verify-tag') && str_contains($rel, '--notes-file'));
    T::ok('it never deletes or moves a tag', ! preg_match('/(tag -d|--delete|push[^\n]*--force|push[^\n]*:refs)/', $rel));
    T::ok('dependabot keeps the pinned actions current', str_contains($read('.github/dependabot.yml'), 'package-ecosystem: github-actions'));

    T::group('the changelog');
    $log = $read('CHANGELOG.md');
    T::ok('it has an Unreleased section, first', (bool) preg_match('/^## \[Unreleased\]\n/m', $log));
    preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d\d-\d\d)$/m', $log, $versions);
    T::ok('every release has a version and a date', count($versions[1]) >= 1 && (bool) preg_match('/^## \[Unreleased\]/m', $log) && strpos($log, '## [Unreleased]') < strpos($log, '## [' . ($versions[1][0] ?? '') . ']'));
    $sorted = $versions[1];
    usort($sorted, 'version_compare');
    T::ok('newest first, each newer than the next', array_reverse($sorted) === $versions[1], implode(' ', $versions[1]));
    T::ok('1.0.0 is the first release', ($versions[1] ? end($versions[1]) : '') === '1.0.0');

    T::group('scripts/release.sh');
    $script = $read('scripts/release.sh');
    T::ok('it stops on any error and unset variable', str_contains($script, "\nset -eu\n"));
    T::ok('it tags only from main, clean and equal to origin, and only a newer version', str_contains($script, 'not on main') && str_contains($script, 'working tree is not clean') && str_contains($script, 'main is not the same as origin/main') && str_contains($script, 'is not newer than the latest release'));
    T::ok('it requires CI and Integration to have passed on the commit, with no way round it', str_contains($script, 'for workflow in CI Integration') && str_contains($script, '"completed success") ;;') && ! preg_match('/--(skip|force|no-verify|ignore)/', $script));
    T::ok('it asks before it tags, unless told --yes', str_contains($script, 'Tag and push? [y/N]') && str_contains($script, '[ "$yes" != "--yes" ]'));
    T::ok('it pushes one tag and nothing else (no branch, no force)', substr_count($script, 'git push') === 2 && str_contains($script, 'git push -q origin "v$version"') && str_contains($script, 'git push -q -u origin "$branch"') && ! str_contains($script, '--force'));

    // The one command that can run without git or GitHub: notes. Run it on fixtures.
    $dir = sys_get_temp_dir() . '/ts-release-' . bin2hex(random_bytes(4));
    mkdir("$dir/scripts", 0755, true);
    copy("$root/scripts/release.sh", "$dir/scripts/release.sh");
    $run = function (string $args) use ($dir): array {
        $out = [];
        exec('cd ' . escapeshellarg($dir) . ' && sh scripts/release.sh ' . $args . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    };
    file_put_contents("$dir/CHANGELOG.md", "# Changelog\n\n## [Unreleased]\n\n- later\n\n## [1.0.10] - 2026-11-01\n\n- ten\n\n## [1.0.1] - 2026-10-20\n\n### Fixed\n- one\n- two\n\n## [1.0.0] - 2026-10-09\n\n- first\n\n## [0.9.0] - 2026-09-01\n\n");
    [$code, $out] = $run('notes 1.0.1');
    T::ok('notes: a version\'s section, and only that one (1.0.1 is not 1.0.10)', $code === 0 && str_contains($out, '- one') && str_contains($out, '- two') && ! str_contains($out, 'ten') && ! str_contains($out, 'first'), $out);
    [$code, $out] = $run('notes 1.0.0');
    T::ok('notes: the last section before another ends at it', $code === 0 && trim($out) === '- first', $out);
    [$code] = $run('notes 0.9.0');
    T::ok('notes: a section with nothing in it is refused', $code !== 0);
    [$code] = $run('notes 2.0.0');
    T::ok('notes: a version with no section is refused', $code !== 0);
    foreach (['v1.0.0', '1.0', '1.0.0.0', '01.0.0', '1.0.0; id', ''] as $bad) {
        [$code] = $run('notes ' . escapeshellarg($bad));
        T::ok('notes: ' . json_encode($bad) . ' is not a version', $code !== 0);
    }
    [$code] = $run('frobnicate');
    T::ok('an unknown command is refused', $code !== 0);
    [$code] = $run('');
    T::ok('no command is refused', $code !== 0);
    exec('rm -rf ' . escapeshellarg($dir));

    // The tag and prepare commands, in a throwaway repository with a GitHub CLI that says what the test tells it.
    exec('git --version 2>&1', $gv, $gitCode);
    if ($gitCode !== 0) {
        T::ok('(git is not installed here: the tag and prepare commands are not exercised)', true);

        return;
    }
    T::group('scripts/release.sh: tag and prepare, in a scratch repository');
    $box = sys_get_temp_dir() . '/ts-release-git-' . bin2hex(random_bytes(4));
    mkdir("$box/bin", 0755, true);
    file_put_contents("$box/bin/gh", <<<'SH'
#!/bin/sh
echo "$@" >> "$FAKE_GH_LOG"
case "$1 $2" in
  "run list")
    wf=""
    while [ $# -gt 0 ]; do [ "$1" = --workflow ] && wf="$2"; shift; done
    if [ "$wf" = CI ]; then printf '%s\n' "$FAKE_CI"; else printf '%s\n' "$FAKE_INTEGRATION"; fi ;;
  "pr create") echo "https://example.test/pull/1" ;;
esac
SH);
    chmod("$box/bin/gh", 0755);
    $env = 'HOME=' . escapeshellarg($box) . ' GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@example.test GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@example.test'
        . ' PATH=' . escapeshellarg("$box/bin") . ':$PATH FAKE_GH_LOG=' . escapeshellarg("$box/gh.log");
    $sh = function (string $cmd, string $in = 'work', string $extra = '') use ($box, $env): array {
        $out = [];
        exec("cd " . escapeshellarg("$box/$in") . " && env $env $extra sh -c " . escapeshellarg($cmd) . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    };
    $changelog = "# Changelog\n\n## [Unreleased]\n\n- a fix\n\n## [1.0.0] - 2026-10-09\n\n- first\n";
    $setup = $sh('git init -q --bare -b main origin.git', '.');
    $setup2 = $sh('git clone -q origin.git work 2>/dev/null; cd work && git switch -q -c main 2>/dev/null; mkdir -p scripts', '.');
    copy("$root/scripts/release.sh", "$box/work/scripts/release.sh");
    file_put_contents("$box/work/CHANGELOG.md", $changelog);
    $sh('git add -A && git commit -q -m first && git push -q -u origin main');
    $ok = 'completed success';
    $tag = fn (string $args, string $ci = 'completed success', string $int = 'completed success'): array => $sh("sh scripts/release.sh tag $args", 'work', 'FAKE_CI=' . escapeshellarg($ci) . ' FAKE_INTEGRATION=' . escapeshellarg($int));

    [$code, $out] = $tag('1.0.0 --yes', 'completed failure');
    T::ok('tag: refused when CI failed on the commit', $code !== 0 && str_contains($out, 'CI did not pass'), $out);
    [$code, $out] = $tag('1.0.0 --yes', $ok, 'in_progress -');
    T::ok('tag: refused while the integration suites are still running', $code !== 0 && str_contains($out, 'still running'), $out);
    [$code, $out] = $tag('1.0.0 --yes', $ok, '');
    T::ok('tag: refused when there is no integration run for the commit', $code !== 0 && str_contains($out, 'no Integration run'), $out);
    [$code, $out] = $tag('1.0.0 --yes', $ok, 'completed cancelled');
    T::ok('tag: a cancelled run is not a pass', $code !== 0, $out);
    [$code, $out] = $tag('1.0.1 --yes');
    T::ok('tag: refused when the changelog has no section for the version', $code !== 0 && str_contains($out, 'CHANGELOG.md has no section'), $out);
    [$code, $out] = $tag('v1.0.0 --yes');
    T::ok('tag: v1.0.0 is not a version', $code !== 0, $out);
    $sh('echo x > dirty.txt');
    [$code, $out] = $tag('1.0.0 --yes');
    T::ok('tag: refused with uncommitted changes', $code !== 0 && str_contains($out, 'not clean'), $out);
    $sh('rm dirty.txt');
    $sh('git switch -q -c other');
    [$code, $out] = $tag('1.0.0 --yes');
    T::ok('tag: refused off main', $code !== 0 && str_contains($out, 'not on main'), $out);
    $sh('git switch -q main');
    $sh('git clone -q origin.git other 2>/dev/null; cd other && echo y > y.txt && git add -A && git commit -q -m ahead && git push -q origin main', '.');
    [$code, $out] = $tag('1.0.0 --yes');
    T::ok('tag: refused when main is behind GitHub', $code !== 0 && str_contains($out, 'not the same as origin/main'), $out);
    $sh('git pull -q origin main');
    [$code, $out] = $sh('printf n | sh scripts/release.sh tag 1.0.0', 'work', 'FAKE_CI=' . escapeshellarg($ok) . ' FAKE_INTEGRATION=' . escapeshellarg($ok));
    T::ok('tag: asks first, and a no tags nothing', $code !== 0 && str_contains($out, 'Tag and push?') && trim((string) shell_exec('git --git-dir=' . escapeshellarg("$box/origin.git") . ' tag -l')) === '', $out);
    [$code, $out] = $tag('1.0.0 --yes');
    $tags = trim((string) shell_exec('git --git-dir=' . escapeshellarg("$box/origin.git") . ' tag -l'));
    $kind = trim((string) shell_exec('git --git-dir=' . escapeshellarg("$box/origin.git") . ' cat-file -t refs/tags/v1.0.0 2>&1'));
    T::ok('tag: with both green it pushes an annotated v1.0.0, and nothing else', $code === 0 && $tags === 'v1.0.0' && $kind === 'tag' && trim((string) shell_exec('git --git-dir=' . escapeshellarg("$box/origin.git") . ' branch --list')) !== '', $out . ' | ' . $tags . ' | ' . $kind);
    [$code, $out] = $tag('1.0.0 --yes');
    T::ok('tag: a version that exists is refused', $code !== 0 && str_contains($out, 'already exists'), $out);
    [$code, $out] = $tag('0.9.0 --yes');
    T::ok('tag: a version older than the latest is refused', $code !== 0, $out);

    [$code, $out] = $sh('sh scripts/release.sh prepare patch', 'work', 'FAKE_CI=x FAKE_INTEGRATION=x');
    $branch = trim((string) shell_exec('git -C ' . escapeshellarg("$box/work") . ' rev-parse --abbrev-ref HEAD'));
    $log = (string) file_get_contents("$box/work/CHANGELOG.md");
    T::ok('prepare patch: a branch release-v1.0.1 that dates the changelog and a pull request', $code === 0 && $branch === 'release-v1.0.1' && (bool) preg_match('/## \[Unreleased\]\n\n## \[1\.0\.1\] - \d{4}-\d\d-\d\d\n\n- a fix\n/', $log) && str_contains((string) @file_get_contents("$box/gh.log"), 'pr create'), $out . ' | ' . $branch);
    T::ok('prepare: the branch is on GitHub', str_contains((string) shell_exec('git --git-dir=' . escapeshellarg("$box/origin.git") . ' branch --list'), 'release-v1.0.1'));
    $sh('git switch -q main && git merge -q --ff-only release-v1.0.1 && git push -q origin main');
    [$code, $out] = $sh('sh scripts/release.sh prepare minor', 'work', 'FAKE_CI=x FAKE_INTEGRATION=x');
    T::ok('prepare: refused when there is nothing under Unreleased', $code !== 0 && str_contains($out, 'nothing under'), $out);
    [$code, $out] = $sh('sh scripts/release.sh prepare 1.0.0', 'work', 'FAKE_CI=x FAKE_INTEGRATION=x');
    T::ok('prepare: a version that is not newer than the latest tag is refused', $code !== 0, $out);
    exec('rm -rf ' . escapeshellarg($box));
}
