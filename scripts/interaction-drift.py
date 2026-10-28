#!/usr/bin/env python3
"""List upstream page and interaction sources changed since a recorded checkpoint.

    python scripts/interaction-drift.py /path/to/librenms
    python scripts/interaction-drift.py /path/to/librenms --base <commit> --head <commit>
    python scripts/interaction-drift.py /path/to/librenms --json
    python scripts/interaction-drift.py --selftest

This is a review queue, not a behavioral test. It compares committed Git trees,
including new, removed and renamed files (renames are shown as a removal and an
addition). Every changed watched source is listed, including changes without an
obvious event marker. Unmapped paths need classification; a mapped path may
still contain a new interaction. It reads no production instance and does not
change the checkpoint or fetch anything.

Exit 0 means no watched source changed, 1 means candidates need review, and 2
means the comparison could not be made. A quiet diff is not a test pass.
"""
import argparse
import fnmatch
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
CHECKLIST = ROOT / 'docs' / 'data' / 'interaction-checklist.json'
WATCHED = (
    'resources/views', 'resources/js', 'resources/css', 'includes/html',
    'html/js', 'html/css', 'app/View/Components', 'app/Http/Controllers',
    'app/Livewire', 'routes', 'package.json', 'package-lock.json',
    'pnpm-lock.yaml', 'yarn.lock', 'vite.config.js', 'vite.config.ts',
)
SOURCE_EXTENSIONS = ('.php', '.js', '.mjs', '.cjs', '.jsx', '.ts', '.tsx', '.vue', '.svelte',
                     '.css', '.scss', '.sass', '.less', '.html', '.svg', '.json', '.yaml', '.yml')


class CheckError(Exception):
    """A comparison could not be completed; it must not look like no drift."""


def git(repo, *args):
    try:
        # Git may otherwise lazily fetch missing objects in a partial clone or
        # update index metadata, despite this being a read-only comparison.
        env = dict(os.environ, GIT_NO_LAZY_FETCH='1', GIT_ALLOW_PROTOCOL='',
                   GIT_OPTIONAL_LOCKS='0', GIT_TERMINAL_PROMPT='0')
        result = subprocess.run(['git', '-C', str(repo), *args], stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, check=False, timeout=60, env=env)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise CheckError(f'could not run git: {exc}') from exc
    if result.returncode:
        raise CheckError(result.stderr.decode('utf-8', errors='replace').strip() or 'git failed')
    return result.stdout


def commit(repo, ref):
    # Resolve first so a supplied ref cannot be interpreted as an option by diff.
    try:
        out = git(repo, 'rev-parse', '--verify', '--end-of-options', f'{ref}^{{commit}}')
    except CheckError as exc:
        raise CheckError(f'commit {ref!r} is unavailable in {repo}; fetch it into the upstream checkout first') from exc
    sha = out.decode('ascii').strip()
    if len(sha) not in (40, 64) or any(ch not in '0123456789abcdef' for ch in sha):
        raise CheckError(f'git returned an invalid commit for {ref!r}')
    return sha


def load_checklist(path=CHECKLIST):
    try:
        data = json.loads(Path(path).read_text(encoding='utf-8'))
    except (OSError, ValueError) as exc:
        raise CheckError(f'cannot read interaction checklist: {exc}') from exc
    families = data.get('families')
    if data.get('version') != 1 or not isinstance(data.get('comparison_base'), str) or not isinstance(families, list):
        raise CheckError('invalid interaction checklist version, base or family list')
    base = data['comparison_base']
    if len(base) not in (40, 64) or any(ch not in '0123456789abcdef' for ch in base):
        raise CheckError('the recorded comparison base must be a full commit hash')
    seen = set()
    for family in families:
        if (not isinstance(family, dict) or not isinstance(family.get('id'), str)
                or not isinstance(family.get('name'), str) or not isinstance(family.get('sources'), list)
                or not family['sources'] or any(not isinstance(p, str) or not p for p in family['sources'])
                or family['id'] in seen):
            raise CheckError('invalid or duplicate interaction family')
        seen.add(family['id'])
    return data


def compare(repo, base_ref, head_ref, families):
    base, head = commit(repo, base_ref), commit(repo, head_ref)
    raw = git(repo, 'diff', '--no-ext-diff', '--no-renames', '--name-status', '-z', base, head, '--', *WATCHED)
    fields = raw.split(b'\0')
    if fields and fields[-1] == b'':
        fields.pop()
    if len(fields) % 2:
        raise CheckError('unexpected git diff output')
    changes = []
    for status_raw, path_raw in zip(fields[::2], fields[1::2]):
        status = status_raw.decode('ascii', errors='replace')
        path = path_raw.decode('utf-8', errors='surrogateescape')
        if not path.endswith(SOURCE_EXTENSIONS) and path not in ('pnpm-lock.yaml', 'yarn.lock'):
            continue
        matched = [f['id'] for f in families if any(fnmatch.fnmatchcase(path, pattern) for pattern in f['sources'])]
        changes.append({'status': status, 'path': path, 'families': matched})
    return {'base': base, 'head': head, 'changes': changes}


def render(result):
    changes = result['changes']
    lines = [f"Compared upstream {result['base']} to {result['head']}.",
             'This lists changed source candidates, not verified or broken interactions.']
    if not changes:
        lines.append('No watched source changed. Dynamic behavior and untested checklist items still need review.')
    else:
        unmapped = sum(not item['families'] for item in changes)
        lines.append(f'{len(changes)} changed source paths; {unmapped} do not map to a checklist family.')
        lines.append('A new path within a known family can still contain a new interaction; inspect every relevant change.')
        for item in changes:
            families = ', '.join(item['families']) if item['families'] else 'UNMAPPED: classify this path'
            lines.append(f"{item['status']} {item['path']}  [{families}]")
    return '\n'.join(lines)


def selftest():
    # Tiny real Git history exercises new, changed, removed, moved and unmapped
    # source paths. The temporary repository never touches the working tree.
    load_checklist()
    with tempfile.TemporaryDirectory(prefix='ts-interaction-drift-') as tmp:
        repo = Path(tmp)
        git(repo, 'init', '-q')

        def write(name, value):
            file = repo / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(value, encoding='utf-8')

        def record(message):
            git(repo, 'add', '-A')
            git(repo, '-c', 'user.name=Interaction Test', '-c', 'user.email=test@example.invalid', 'commit', '-q', '-m', message)
            return commit(repo, 'HEAD')

        write('resources/views/settings/index.blade.php', '<button>Before</button>\n')
        write('resources/views/alerts/old.blade.php', '<button>Alert</button>\n')
        write('README.md', 'Before\n')
        before = record('before')
        write('resources/views/settings/index.blade.php', '<button x-on:click="open()">After</button>\n')
        write('resources/views/new-area/new.blade.php', '<button>New</button>\n')
        write('routes/new.php', '<?php // route\n')
        write('README.md', 'After\n')
        (repo / 'resources/views/alerts/old.blade.php').rename(repo / 'resources/views/alerts/new.blade.php')
        after = record('after')
        families = [
            {'id': 'settings', 'sources': ['resources/views/settings/*']},
            {'id': 'alerts', 'sources': ['resources/views/alerts/*']},
            {'id': 'routes', 'sources': ['routes/*']},
        ]
        same = compare(repo, before, before, families)
        assert same['changes'] == [], same
        result = compare(repo, before, after, families)
        found = {(item['status'], item['path']): item['families'] for item in result['changes']}
        assert found == {
            ('M', 'resources/views/settings/index.blade.php'): ['settings'],
            ('A', 'resources/views/new-area/new.blade.php'): [],
            ('D', 'resources/views/alerts/old.blade.php'): ['alerts'],
            ('A', 'resources/views/alerts/new.blade.php'): ['alerts'],
            ('A', 'routes/new.php'): ['routes'],
        }, found
        assert 'UNMAPPED' in render(result)
        try:
            compare(repo, 'missing-revision', after, families)
            raise AssertionError('missing revision was accepted')
        except CheckError:
            pass
    print('interaction-drift selftest: ok')


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('checkout', nargs='?', help='local LibreNMS Git checkout (committed trees are compared)')
    parser.add_argument('--base', help='override the recorded comparison commit')
    parser.add_argument('--head', default='HEAD', help='target commit or ref, default HEAD')
    parser.add_argument('--json', action='store_true', help='emit machine-readable JSON to stdout')
    parser.add_argument('--selftest', action='store_true', help='exercise detection using a temporary Git repository')
    args = parser.parse_args(argv)
    if args.selftest:
        selftest()
        return 0
    if not args.checkout:
        parser.error('a LibreNMS Git checkout is required')
    try:
        checklist = load_checklist()
        result = compare(Path(args.checkout), args.base or checklist['comparison_base'], args.head, checklist['families'])
    except CheckError as exc:
        print(f'interaction-drift: {exc}', file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2) if args.json else render(result))
    return 1 if result['changes'] else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
