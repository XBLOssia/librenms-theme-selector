#!/usr/bin/env python3
"""Tell us when LibreNMS changes how it looks, so the skins can follow.

    python scripts/upstream-drift.py /path/to/librenms                # compare with the baseline, exit 1 on drift
    python scripts/upstream-drift.py /path/to/librenms --report r.md  # and write the report as Markdown
    python scripts/upstream-drift.py /path/to/librenms --update       # we have caught up: record this as the baseline
    python scripts/upstream-drift.py --selftest                       # check the checker (no LibreNMS needed)

The nightly check (.github/workflows/nightly.yml) runs the live suites against the newest *release* and finds
what breaks. This one looks at LibreNMS's *master*, which is what a host that updates itself nightly is on, and
finds what merely looks wrong: a skin is a set of colours laid over LibreNMS's own markup and stylesheets, and
nothing fails when upstream moves a badge, adds a component with its own colours or deletes a class we style.
It reads a checkout of LibreNMS (resources/, html/css/, includes/html/, html/js/); it never runs it.

What it watches, each against docs/data/upstream-baseline.json (what we last looked at and answered):

  * watched files        the layout, the navbar template and the three stylesheets a skin sits on. A change is
                         not necessarily a problem; it is the cheapest signal that something visual moved.
  * palette gaps         Tailwind colour utilities (`tw:dark:bg-amber-950/40`) take their colour from
                         `--tw-color-<scale>-<shade>`. base.css (dark) and base/light.css (light) re-point the
                         ones they know to a skin's roles; a utility that reaches a shade we have not mapped
                         shows in stock Tailwind colours, whatever the skin. New ones are listed.
  * new classes          classes upstream's stylesheets now define, with the colour-bearing ones that base.css
                         does not mention.
  * dead selectors       classes base.css styles that nothing upstream uses any more (a removed component:
                         harmless, but a rule written for something that is no longer there).

The baseline holds answers, not wishes: `--update` records the current gaps as acknowledged, so run it after
the skins have caught up (or after deciding that a gap does not matter), in the same change that fixed them.
Stdlib only.
"""
import hashlib
import json
import os
import re
import sys
import tempfile

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
BASELINE = os.path.join(ROOT, 'docs', 'data', 'upstream-baseline.json')
UPSTREAM = 'https://github.com/librenms/librenms'

WATCHED = [
    'resources/views/layouts/menu.blade.php',
    'resources/views/layouts/librenmsv1.blade.php',
    'html/css/styles.css',
    'html/css/tw_dark.css',
    'resources/css/app.css',
]
# Where markup and its classes live. (Not app/: its PHP builds data, and the views it renders are in resources/.)
SOURCE_DIRS = ['resources/views', 'resources/js', 'resources/css', 'includes/html', 'html/js']
SOURCE_EXT = ('.php', '.js', '.vue', '.css', '.html')

SCALES = r'(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone|dark-gray|dark-white)'
PROPS = r'(?:bg|text|border(?:-[trblxy])?|ring|from|to|via|fill|stroke|outline|divide|placeholder|decoration|accent|caret|shadow)'
TW = re.compile(r'(?<![\w-])tw:((?:[\w\[\]&>*()=:.-]*?:)*)!?(' + PROPS + r')-(' + SCALES + r')-(\d{2,3})(?:/[\w.\[\]%]+)?!?(?![\w-])')
# Scales whose every shade base.css already points at a role (they are the skin's own palette).
OWN = {'dark-gray', 'dark-white'}


def read(path):
    with open(path, encoding='utf-8', errors='replace', newline='') as f:
        return f.read()


def digest(text):
    return hashlib.sha256(text.replace('\r\n', '\n').encode('utf-8')).hexdigest()


def strip_comments(css):
    return re.sub(r'/\*.*?\*/', '', css, flags=re.S)


def css_classes(css, selectors_only=True):
    """Class names in a stylesheet's selectors (not in its declarations, where `.5s` and urls look like classes)."""
    css = strip_comments(css)
    found = set()
    for m in re.finditer(r'(?:^|\})\s*([^{}@;]+)\{', css, flags=re.M):
        found.update(re.findall(r'\.([A-Za-z_][\w-]*)', m.group(1)))
    return found


def colour_bearing(css):
    """Class names that sit in a rule that sets a colour literal."""
    css = strip_comments(css)
    found = set()
    for m in re.finditer(r'(?:^|\})\s*([^{}@;]+)\{([^}]*)\}', css, flags=re.M):
        if re.search(r'\b(?:background(?:-color)?|color|border(?:-[a-z]+)?-color|border)\s*:[^;]*(?:#[0-9a-fA-F]{3}|rgba?\(|hsla?\()', m.group(2)):
            found.update(re.findall(r'\.([A-Za-z_][\w-]*)', m.group(1)))
    return found


def mapped_palette(css, selector_prefix):
    """The `--tw-color-<scale>-<shade>` names a stylesheet re-points, inside rules whose selector starts so."""
    out = set()
    for m in re.finditer(r'(^|\n)([^{}\n@][^{}]*)\{([^{}]*)\}', strip_comments(css)):
        if m.group(2).strip().startswith(selector_prefix):
            out.update(f'{s}-{n}' for s, n in re.findall(r'--tw-color-([a-z-]+?)-(\d{2,3})\s*:', m.group(3)))
    return out


def source_files(root):
    for d in SOURCE_DIRS:
        for dp, dn, fn in os.walk(os.path.join(root, d)):
            dn[:] = [x for x in dn if x not in ('node_modules', 'lang', 'vendor')]
            for f in fn:
                if f.endswith(SOURCE_EXT) and not f.endswith('.min.js') and not f.endswith('.min.css'):
                    yield os.path.join(dp, f)


def surface(root, ours):
    """Everything the check compares: hashes, palette gaps, classes."""
    files = {}
    for rel in WATCHED:
        p = os.path.join(root, rel)
        files[rel] = digest(read(p)) if os.path.isfile(p) else None
    used = {'dark': set(), 'light': set()}
    tokens = set()
    for p in source_files(root):
        text = read(p)
        tokens.update(re.findall(r'[A-Za-z_][\w-]*', text))
        for m in TW.finditer(text):
            variants, scale, shade = m.group(1), m.group(3), m.group(4)
            if scale in OWN:
                continue
            used['dark' if re.search(r'(^|:)dark:', ':' + variants) else 'light'].add(f'{scale}-{shade}')
    dark_map = mapped_palette(read(os.path.join(ours, 'base.css')), 'html.dark')
    light_map = mapped_palette(read(os.path.join(ours, 'light.css')), 'html:not(.dark)')
    upstream_css = {}
    for rel in ('html/css/styles.css', 'html/css/tw_dark.css', 'resources/css/app.css'):
        p = os.path.join(root, rel)
        if os.path.isfile(p):
            upstream_css[rel] = read(p)
    classes = set()
    for rel, css in upstream_css.items():
        classes |= css_classes(css)
    # Anything the vendored stylesheets define counts as "exists upstream" for the dead-selector check.
    vendor = set()
    css_dir = os.path.join(root, 'html', 'css')
    if os.path.isdir(css_dir):
        for f in os.listdir(css_dir):
            if f.endswith('.css'):
                vendor |= css_classes(read(os.path.join(css_dir, f)))
    ours_css = read(os.path.join(ours, 'base.css'))
    ours_classes = css_classes(ours_css)
    colour = set()
    for css in upstream_css.values():
        colour |= colour_bearing(css)
    base_text = strip_comments(ours_css) + strip_comments(read(os.path.join(ours, 'light.css')))
    unstyled = {c for c in colour if not re.search(r'\.' + re.escape(c) + r'(?![\w-])', base_text)}
    dead = {c for c in ours_classes if c not in vendor and c not in classes and c not in tokens}
    return {
        'files': files,
        'dark_unmapped': sorted(used['dark'] - dark_map),
        'light_unmapped': sorted(used['light'] - light_map),
        'css_classes': sorted(classes),
        'unstyled_colour_classes': sorted(unstyled),
        'dead_selectors': sorted(dead),
    }


def git_head(root):
    try:
        import subprocess
        sha = subprocess.run(['git', '-C', root, 'rev-parse', 'HEAD'], capture_output=True, text=True, timeout=20).stdout.strip()
        date = subprocess.run(['git', '-C', root, 'log', '-1', '--format=%cI'], capture_output=True, text=True, timeout=20).stdout.strip()
        return (sha or None), (date or None)
    except (OSError, ValueError):
        return None, None


def compare(now, then):
    """What is new since the baseline: a dict of lists, empty when nothing needs answering."""
    drift = {}
    changed = [f for f, h in now['files'].items() if then['files'].get(f) != h]
    if changed:
        drift['files'] = changed
    for key in ('dark_unmapped', 'light_unmapped', 'unstyled_colour_classes', 'dead_selectors'):
        new = sorted(set(now[key]) - set(then.get(key, [])))
        if new:
            drift[key] = new
    added = sorted(set(now['css_classes']) - set(then.get('css_classes', [])))
    removed = sorted(set(then.get('css_classes', [])) - set(now['css_classes']))
    if added:
        drift['classes_added'] = added
    if removed:
        drift['classes_removed'] = removed
    return drift


def clip(items, n=40):
    items = list(items)
    shown = ', '.join(f'`{i}`' for i in items[:n])
    return shown + (f', and {len(items) - n} more' if len(items) > n else '')


def report(drift, then, head, date):
    base = then.get('commit')
    lines = ['# LibreNMS has changed how it looks', '']
    if base and head:
        lines += [f'Compared with what we last checked ({base[:9]}, {then.get("commit_date", "")[:10]}), '
                  f'LibreNMS master is now {head[:9]} ({(date or "")[:10]}): [the whole change]({UPSTREAM}/compare/{base}...{head}).', '']
    lines += ['Nothing here has failed; this is a list of what to look at. Update the skins (`base/base.css`, `base/light.css`, the harness '
              'markup in `harness/mockup.html`), then run `python scripts/upstream-drift.py <librenms checkout> --update` in the same change.', '']
    if 'files' in drift:
        lines += ['## Files that skins sit on have changed', '']
        for f in drift['files']:
            lines.append(f'- [`{f}`]({UPSTREAM}/commits/master/{f})' + (f' ([diff]({UPSTREAM}/compare/{base}...{head}#files_bucket))' if base and head else ''))
        lines.append('')
    if 'dark_unmapped' in drift:
        lines += ['## Dark mode: Tailwind colours that no skin re-points', '',
                  'Used with a `dark:` variant, so they show in stock Tailwind colours on every skin. Map them in the `html.dark` palette block '
                  'at the end of `base/base.css` (to the skin\'s roles), or leave them and run `--update` to say that is fine.', '', clip(drift['dark_unmapped']), '']
    if 'light_unmapped' in drift:
        lines += ['## Light mode: Tailwind colours that no skin re-points', '',
                  'Map them in `base/light.css`.', '', clip(drift['light_unmapped']), '']
    if 'unstyled_colour_classes' in drift:
        lines += ['## New classes with colours of their own that `base.css` does not mention', '', clip(drift['unstyled_colour_classes']), '']
    if 'classes_added' in drift:
        lines += ['## Classes upstream\'s stylesheets now define', '', clip(drift['classes_added']), '']
    if 'classes_removed' in drift or 'dead_selectors' in drift:
        lines += ['## Gone upstream', '']
        if 'classes_removed' in drift:
            lines += ['Classes the stylesheets no longer define: ' + clip(drift['classes_removed']), '']
        if 'dead_selectors' in drift:
            lines += ['Classes `base.css` styles that nothing upstream uses now (the rules are dead, not harmful; do not delete a token a skin may '
                      'set, that would break uploaded skins): ' + clip(drift['dead_selectors']), '']
    return '\n'.join(lines)


def selftest():
    """Build a tiny LibreNMS, take a baseline, change it, and check that each change is noticed."""
    tmp = tempfile.mkdtemp(prefix='drift-')
    up, ours = os.path.join(tmp, 'up'), os.path.join(tmp, 'ours')

    def w(base, rel, text):
        p = os.path.join(base, rel)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, 'w', encoding='utf-8', newline='') as f:
            f.write(text)

    w(up, 'resources/views/layouts/menu.blade.php', '<span class="badge badge-danger">1</span>\n')
    w(up, 'resources/views/layouts/librenmsv1.blade.php', '<html></html>\n')
    w(up, 'html/css/styles.css', '.old-widget { color: #fff; }\n.kept { margin: 0; }\n')
    w(up, 'html/css/tw_dark.css', '.dark .kept { color: #eee; }\n')
    w(up, 'resources/css/app.css', '.lnms-thing { color: red; }\n')
    w(up, 'resources/views/page.blade.php', '<div class="kept old-widget tw:dark:bg-amber-950/40 tw:text-blue-600 tw:dark:text-dark-white-100">\n')
    w(ours, 'base.css', 'html.dark .kept { color: red; }\nhtml.dark .old-widget { color: red; }\nhtml.dark {\n  --tw-color-blue-400: var(--ts-link);\n}\n')
    w(ours, 'light.css', 'html:not(.dark) {\n  --tw-color-blue-600: var(--ts-link);\n}\n')
    now = surface(up, ours)
    assert now['dark_unmapped'] == ['amber-950'], now['dark_unmapped']
    assert now['light_unmapped'] == [], now['light_unmapped']
    assert compare(now, now) == {}, 'a surface differs from itself'
    # 1. a new Tailwind shade in both modes, a changed watched file, a new class with a colour, a removed class
    w(up, 'resources/views/page.blade.php', '<div class="kept tw:dark:bg-amber-950/40 tw:text-blue-600 tw:text-red-500 tw:dark:border-fuchsia-300 tw:hover:bg-lime-50">\n')
    w(up, 'resources/views/layouts/menu.blade.php', '<i class="fa fa-exclamation-circle text-danger"></i>\n')
    w(up, 'html/css/styles.css', '.kept { margin: 0; }\n.brand-new { background: #123456; }\n')
    after = surface(up, ours)
    d = compare(after, now)
    assert sorted(d['files']) == ['html/css/styles.css', 'resources/views/layouts/menu.blade.php'], d
    assert d['light_unmapped'] == ['lime-50', 'red-500'], d
    assert d['dark_unmapped'] == ['fuchsia-300'], d
    assert d['classes_added'] == ['brand-new'] and d['classes_removed'] == ['old-widget'], d
    assert d['unstyled_colour_classes'] == ['brand-new'], d
    assert d['dead_selectors'] == ['old-widget'], d
    text = report(d, {'commit': 'a' * 40, 'commit_date': '2026-10-12'}, 'b' * 40, '2026-10-19')
    assert 'compare/' + 'a' * 40 + '...' + 'b' * 40 in text and 'fuchsia-300' in text and 'brand-new' in text
    # 2. answering them (the baseline is replaced) makes the next comparison clean
    assert compare(after, after) == {}
    # 3. line endings are not a change
    w(up, 'html/css/tw_dark.css', '.dark .kept { color: #eee; }\r\n')
    assert compare(surface(up, ours), after) == {}, compare(surface(up, ours), after)
    print('upstream-drift selftest: ok')


def main(argv):
    if '--selftest' in argv:
        selftest()
        return 0
    args, opts, i = [], {}, 0
    while i < len(argv):
        if argv[i] in ('--report', '--baseline') and i + 1 < len(argv):
            opts[argv[i]] = argv[i + 1]
            i += 2
        elif argv[i].startswith('--'):
            opts[argv[i]] = True
            i += 1
        else:
            args.append(argv[i])
            i += 1
    if len(args) != 1:
        print(__doc__)
        return 2
    root = args[0]
    baseline_path = opts.get('--baseline') or BASELINE
    if not os.path.isdir(os.path.join(root, 'resources', 'views')):
        print(f'{root} does not look like a LibreNMS checkout (no resources/views)', file=sys.stderr)
        return 2
    now = surface(root, os.path.join(ROOT, 'base'))
    head, date = git_head(root)
    if '--update' in opts:
        record = dict(now, version=1, commit=head, commit_date=date)
        os.makedirs(os.path.dirname(baseline_path), exist_ok=True)
        with open(baseline_path, 'w', encoding='utf-8', newline='') as f:
            json.dump(record, f, indent=1, sort_keys=True)
            f.write('\n')
        print(f'baseline recorded: {head or "unknown commit"} ({len(now["css_classes"])} classes, {len(now["dark_unmapped"])} dark and '
              f'{len(now["light_unmapped"])} light palette gaps acknowledged)')
        return 0
    if not os.path.isfile(baseline_path):
        print(f'no baseline at {baseline_path}; run with --update once the skins match LibreNMS', file=sys.stderr)
        return 2
    then = json.loads(read(baseline_path))
    drift = compare(now, then)
    if not drift:
        print(f'no design drift since {(then.get("commit") or "?")[:9]} ({head[:9] if head else "unknown commit"} is what was checked)')
        return 0
    text = report(drift, then, head, date)
    print(text)
    if opts.get('--report'):
        with open(opts['--report'], 'w', encoding='utf-8', newline='') as f:
            f.write(text + '\n')
    return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
