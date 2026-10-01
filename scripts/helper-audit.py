#!/usr/bin/env python3
"""Which shared graph helpers hard-code colours, and who sets the rest?

    scripts/helper-audit.py /path/to/librenms          # table + summary
    scripts/helper-audit.py /path/to/librenms --lines  # also every literal, by line

Reads includes/html/graphs/ under a LibreNMS checkout and changes nothing. It
reproduces the figures in docs/FINDINGS.md section 5:

  * per generic_* helper: hex literals, whether it reads graph_colours, and how
    many graph definitions include it;
  * for the helpers that read no config: what each literal is (series fill,
    percentile line, previous-period line, rule or other), and how many of their
    callers set a colour variable from a hex literal (that is where the series
    colours of generic_simplex / generic_duplex / generic_multi_data live).

The classification of a literal is by the text of its line, so it is a guide to
read, not a proof; --lines prints the lines so you can check it.
"""
import argparse
import glob
import os
import re
import sys

HEX = re.compile(r'#[0-9A-Fa-f]{6,8}\b')
ASSIGN = re.compile(r"\$colou?r\w*\s*=\s*['\"]#?[0-9A-Fa-f]{6}['\"]")


def kind(line):
    if 'percentile' in line:
        return 'percentile line'
    if re.search(r"X#|Prev|X'", line):
        return 'previous-period line'
    if 'HRULE' in line or 'speed' in line:
        return 'rule / speed line'
    if re.search(r"_max#|AREA:(in|dout)|LINE:(in|dout)|AREA:' \. \$ds", line):
        return 'series or band fill'
    return 'other'


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('librenms')
    ap.add_argument('--lines', action='store_true', help='print every literal of the config-blind helpers')
    a = ap.parse_args()

    root = os.path.join(a.librenms, 'includes', 'html', 'graphs')
    if not os.path.isdir(root):
        sys.exit('no includes/html/graphs under ' + a.librenms)
    files = glob.glob(os.path.join(root, '**', '*.php'), recursive=True)
    text = {p: open(p, encoding='utf-8', errors='replace').read() for p in files}
    callers = {p: s for p, s in text.items() if not os.path.basename(p).startswith('generic_')}
    helpers = sorted(os.path.basename(p) for p in glob.glob(os.path.join(root, 'generic_*.inc.php')))

    rows = []
    for h in helpers:
        s = text[os.path.join(root, h)]
        inc = [p for p, t in callers.items() if re.search(r'graphs/' + re.escape(h), t)]
        rows.append((h, len(HEX.findall(s)), 'graph_colours' in s, inc))

    print('%-42s %8s %5s %9s' % ('helper', 'literals', 'reads', 'includers'))
    for h, n, r, inc in rows:
        print('%-42s %8d %5s %9d' % (h, n, 'yes' if r else 'NO', len(inc)))
    reading = [r for r in rows if r[2]]
    blind = [r for r in rows if not r[2]]
    print()
    print('%d helpers; %d read graph_colours (%d literals left in them); %d do not (%d literals)' % (
        len(rows), len(reading), sum(r[1] for r in reading), len(blind), sum(r[1] for r in blind)))

    blind_callers = set()
    for _, _, _, inc in blind:
        blind_callers.update(inc)
    setting = [p for p in blind_callers if ASSIGN.search(text[p])]
    print('graph definitions that include any generic_* helper:',
          len({p for _, _, _, inc in rows for p in inc}))
    print('graph definitions that include a config-blind helper: %d, of which %d set a colour variable from a hex literal'
          % (len(blind_callers), len(setting)))

    print()
    print('what the config-blind helpers hard-code themselves, and where their series colours come from:')
    for h, n, r, inc in blind:
        kinds = {}
        for l in text[os.path.join(root, h)].splitlines():
            for _ in HEX.finditer(l):
                k = kind(l)
                kinds[k] = kinds.get(k, 0) + 1
        mine = sum(1 for p in inc if ASSIGN.search(text[p]))
        print('  %-26s %-70s callers setting a colour: %d of %d' % (
            h, ', '.join('%s %d' % (k, v) for k, v in sorted(kinds.items())), mine, len(inc)))
        if a.lines:
            for i, l in enumerate(text[os.path.join(root, h)].splitlines(), 1):
                if HEX.search(l):
                    print('      %3d  [%s]  %s' % (i, kind(l), l.strip()[:100]))


if __name__ == '__main__':
    main()
