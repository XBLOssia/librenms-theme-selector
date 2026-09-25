#!/usr/bin/env python3
"""List rules in a LibreNMS stylesheet whose selectors are CANDIDATES for dead.

A selector is a CANDIDATE if it requires a class or id that never appears
inside a string literal or quoted attribute in any markup-emitting source
(PHP, Blade, JS, Vue, HTML - first-party and vendored). Prose in translation
files and YAML device definitions does not count, and neither do CSS files or
<style> blocks, which name their own selectors.

This produces candidates, not verdicts. Minified vendor JS builds class names
by concatenation - select2 is the proof, and its placeholder is demonstrably
live - so every candidate needs a manual read and a live-page check before it
can be called dead. See docs/FINDINGS.md for how that went.

Classes that could be assembled at runtime are reported as AT RISK, not dead:
if any source concatenates or interpolates a matching prefix ('label-' . $c,
`btn-${x}`, "text-{$c}", sprintf('bg-%s')) or suffix ({{ $x }}-danger), the
class is set aside for a human.

Known residual risk, which this script cannot see:
  - composer vendor/ views (not in a git checkout) - pass --extra vendor/
  - HTML stored in the database (alert templates, notes, custom maps)
  - classes built with no hyphen boundary ('text' + colour)

  python scripts/dead-css.py /path/to/librenms
  python scripts/dead-css.py /path/to/librenms --css other.css --extra /path/to/librenms/vendor
  python scripts/dead-css.py ... --tsv out.tsv
"""
import argparse
import os
import re
import sys

EMIT_EXT = ('.php', '.js', '.vue', '.ts', '.html', '.inc')
SKIP_DIRS = {'.git', 'node_modules', 'tests', 'doc', 'docs', 'storage', 'logs', 'rrd',
             'cache', '.github', 'lang', 'definitions'}
SKIP_PARTS = (os.sep + os.path.join('html', 'css') + os.sep,)   # stylesheets name their own selectors

TOKEN = re.compile(r'[A-Za-z0-9_-]+')
STRING = re.compile(r"""'(?:[^'\\\n]|\\.)*'|"(?:[^"\\\n]|\\.)*"|`(?:[^`\\]|\\.)*`""", re.S)
STYLE_BLOCK = re.compile(r'<style\b.*?</style>', re.S | re.I)
DYN_PREFIX = [
    re.compile(r"""([A-Za-z][A-Za-z0-9_-]*[-_])['"`]\s*(?:\.|\+)"""),          # 'label-' . $c   "btn-" + x
    re.compile(r"""([A-Za-z][A-Za-z0-9_-]*[-_])(?:\{\$|\$\{|\$[A-Za-z_]|\{\{|\{!!)"""),  # "label-{$c}" `x-${c}` label-{{ $c }}
    re.compile(r"""([A-Za-z][A-Za-z0-9_-]*[-_])%[sd]"""),                        # sprintf('bg-%s')
]
DYN_SUFFIX = [
    re.compile(r"""(?:\.|\+)\s*['"`]([-_][A-Za-z][A-Za-z0-9_-]*)"""),           # $c . '-danger'
    re.compile(r"""(?:\}\}|\}|\$[A-Za-z_]\w*)([-_][A-Za-z][A-Za-z0-9_-]*)"""),   # {{ $c }}-danger  "$c-danger"
]


def corpus(roots):
    tokens, pre, suf, files = set(), set(), set(), 0
    for root in roots:
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
            if any(p in dirpath + os.sep for p in SKIP_PARTS):
                continue
            for fn in filenames:
                if not fn.endswith(EMIT_EXT) or fn.endswith(('.map', '.min.css')):
                    continue
                try:
                    with open(os.path.join(dirpath, fn), encoding='utf-8', errors='replace') as fh:
                        text = fh.read()
                except OSError:
                    continue
                files += 1
                text = STYLE_BLOCK.sub('', text)
                rel = os.path.relpath(os.path.join(dirpath, fn), root).replace(os.sep, '/')
                if fn.endswith('.min.js') or rel.startswith(('html/js/', 'html/build/')):
                    # Minified and vendored JS: no prose to exclude, and nested backtick
                    # templates (esbuild emits them everywhere) defeat string matching.
                    # Every token counts. vue-multiselect's classes are the proof.
                    tokens.update(TOKEN.findall(text))
                else:
                    for m in STRING.finditer(text):
                        tokens.update(TOKEN.findall(m.group(0)))
                for rx in DYN_PREFIX:
                    pre.update(rx.findall(text))
                for rx in DYN_SUFFIX:
                    suf.update(rx.findall(text))
    return tokens, pre, suf, files


def rules(css):
    """Yield (selector_text, first_line, last_line) for every style rule, recursing into @media."""
    out = []

    def walk(text, offset_line):
        i, n = 0, len(text)
        while i < n:
            j = text.find('{', i)
            if j < 0:
                break
            head = text[i:j].strip()
            depth, k = 1, j + 1
            while k < n and depth:
                depth += {'{': 1, '}': -1}.get(text[k], 0)
                k += 1
            start = offset_line + text.count('\n', 0, i + (len(text[i:j]) - len(text[i:j].lstrip())))
            end = offset_line + text.count('\n', 0, k)
            if head.startswith('@media') or head.startswith('@supports'):
                walk(text[j + 1:k - 1], offset_line + text.count('\n', 0, j + 1))
            elif head and not head.startswith('@'):
                out.append((head, start + 1, end + 1))
            i = k
    walk(css, 0)
    return out


def split_selectors(head):
    parts, depth, cur = [], 0, ''
    for ch in head:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        if ch == ',' and depth == 0:
            parts.append(cur.strip())
            cur = ''
        else:
            cur += ch
    parts.append(cur.strip())
    return [p for p in parts if p]


def needs(selector):
    s = re.sub(r'\[[^\]]*\]', '', selector)          # attribute selectors
    s = re.sub(r':not\([^)]*\)', '', s)                # negations require nothing
    s = re.sub(r'::?[A-Za-z-]+(\([^)]*\))?', '', s)    # pseudo-classes / elements
    classes = [c for c in re.findall(r'\.([A-Za-z_-][A-Za-z0-9_-]*)', s) if c != 'dark']
    ids = re.findall(r'#([A-Za-z_-][A-Za-z0-9_-]*)', s)
    return classes, ids


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('checkout')
    ap.add_argument('--css', help='stylesheet to survey (default: <checkout>/html/css/tw_dark.css)')
    ap.add_argument('--extra', action='append', default=[], help='additional source roots, e.g. vendor/')
    ap.add_argument('--tsv', help='write per-selector results here')
    a = ap.parse_args()

    css_path = a.css or os.path.join(a.checkout, 'html', 'css', 'tw_dark.css')
    css = open(css_path, encoding='utf-8').read()
    # Blank out comments but keep their newlines, so reported line numbers stay true.
    css = re.sub(r'/\*.*?\*/', lambda m: '\n' * m.group(0).count('\n'), css, flags=re.S)
    tokens, pre, suf, nfiles = corpus([a.checkout] + a.extra)

    def status(name):
        if name in tokens:
            return 'live'
        if any(name.startswith(p) and len(name) > len(p) for p in pre) or any(name.endswith(q) for q in suf):
            return 'risk'
        # camelCase joins have no - or _ to anchor on: geo-map.blade.php builds
        # "greenCluster" as colour + "Cluster marker-cluster ...". If either half of
        # a camelCase split is a token anywhere, a human has to look.
        for i in range(1, len(name)):
            if name[i].isupper() and name[i - 1].islower() and (name[:i] in tokens or name[i:] in tokens):
                return 'risk'
        return 'absent'

    rows, summary = [], {'dead': [0, 0], 'partial': [0, 0], 'risk': [0, 0], 'live': [0, 0]}
    for head, l0, l1 in rules(css):
        sels = split_selectors(head)
        verdicts = []
        for sel in sels:
            classes, ids = needs(sel)
            st = [(c, status(c)) for c in classes] + [('#' + i, status(i)) for i in ids]
            missing = [c for c, v in st if v == 'absent']
            risky = [c for c, v in st if v == 'risk']
            v = 'dead' if missing else ('risk' if risky else 'live')
            verdicts.append(v)
            rows.append((l0, v, sel, ' '.join(missing) or ' '.join(risky) or '-'))
        if all(v == 'dead' for v in verdicts):
            kind = 'dead'
        elif any(v == 'dead' for v in verdicts):
            kind = 'partial'
        elif any(v == 'risk' for v in verdicts):
            kind = 'risk'
        else:
            kind = 'live'
        summary[kind][0] += 1
        summary[kind][1] += (l1 - l0 + 1) if kind == 'dead' else verdicts.count('dead')

    print('stylesheet : %s (%d lines)' % (css_path, css.count('\n')))
    print('scanned    : %d source files, %d distinct tokens' % (nfiles, len(tokens)))
    print('dynamic    : %d concatenated prefixes, %d suffixes' % (len(pre), len(suf)))
    print()
    print('%-10s %6s  %s' % ('rules', 'count', 'lines if confirmed'))
    label = {'dead': 'candidate', 'partial': 'partial', 'risk': 'risk', 'live': 'live'}
    for k in ('dead', 'partial', 'risk', 'live'):
        c, lines = summary[k]
        print('%-10s %6d  %s' % (label[k], c, lines if k in ('dead', 'partial') else '-'))
    print()
    for line, v, sel, ev in rows:
        if v == 'dead':
            print('  candidate  line %-5d %-60s needs: %s' % (line, sel[:60], ev))
    if a.tsv:
        with open(a.tsv, 'w', encoding='utf-8', newline='\n') as fh:
            fh.write('line\tverdict\tselector\tevidence\n')
            for r in rows:
                fh.write('%d\t%s\t%s\t%s\n' % r)
        print('\nper-selector results -> %s' % a.tsv)


if __name__ == '__main__':
    sys.exit(main())
