#!/usr/bin/env python3
"""Show the one-helper change to LibreNMS for port series colours, as a diff.

    dev/port-colours-diff.py /path/to/librenms               # print the diff
    dev/port-colours-diff.py /path/to/librenms --out DIR     # also write the two changed files under DIR

This is for understanding the change and testing it; it never writes inside the
checkout you point it at. It is not the text of anything submitted upstream.

The change, in two files:

  includes/html/graphs/generic_data.inc.php
      the six in/out series colours (three tones each) are read from
      graph_colours.port_in.0..2 and graph_colours.port_out.0..2, the way the
      sibling helpers read graph_colours.$colours.$iter, instead of being hex
      literals;
  resources/definitions/config_definitions.json
      declares graph_colours.port_in and graph_colours.port_out, in alphabetical
      place, with the old literals as the defaults. Declared defaults are what make
      an unconfigured install draw exactly what it drew before.

To check "exactly what it drew before" on a real install, see the end of
dev/README.md ("Checking that a graph is unchanged").
"""
import argparse
import difflib
import os
import sys

GD = os.path.join('includes', 'html', 'graphs', 'generic_data.inc.php')
CD = os.path.join('resources', 'definitions', 'config_definitions.json')

# (old line, new line): the six series lines of generic_data.inc.php.
LINES = [
    ("$rrd_options[] = 'AREA:in' . $format . '_max#D7FFC7' . $stacked['transparency'] . ':';",
     "$rrd_options[] = 'AREA:in' . $format . '_max#' . LibrenmsConfig::get('graph_colours.port_in.0') . $stacked['transparency'] . ':';"),
    ("$rrd_options[] = 'AREA:in' . $format . '#90B040' . $stacked['transparency'] . ':';",
     "$rrd_options[] = 'AREA:in' . $format . '#' . LibrenmsConfig::get('graph_colours.port_in.1') . $stacked['transparency'] . ':';"),
    ("$rrd_options[] = 'LINE:in' . $format . '#608720:In ';",
     "$rrd_options[] = 'LINE:in' . $format . '#' . LibrenmsConfig::get('graph_colours.port_in.2') . ':In ';"),
    ("$rrd_options[] = 'AREA:dout' . $format . '_max#E0E0FF' . $stacked['transparency'] . ':';",
     "$rrd_options[] = 'AREA:dout' . $format . '_max#' . LibrenmsConfig::get('graph_colours.port_out.0') . $stacked['transparency'] . ':';"),
    ("$rrd_options[] = 'AREA:dout' . $format . '#8080C0' . $stacked['transparency'] . ':';",
     "$rrd_options[] = 'AREA:dout' . $format . '#' . LibrenmsConfig::get('graph_colours.port_out.1') . $stacked['transparency'] . ':';"),
    ("$rrd_options[] = 'LINE:dout' . $format . '#606090:Out';",
     "$rrd_options[] = 'LINE:dout' . $format . '#' . LibrenmsConfig::get('graph_colours.port_out.2') . ':Out';"),
]

DECLARE = [
    ('port_in', ['D7FFC7', '90B040', '608720']),
    ('port_out', ['E0E0FF', '8080C0', '606090']),
]
ANCHOR = '        "graph_colours.psychedelic": {'


def change_helper(s):
    for old, new in LINES:
        if s.count(old) != 1:
            sys.exit('generic_data.inc.php does not look as expected (line not found exactly once):\n  ' + old)
        s = s.replace(old, new)
    return s


def change_definitions(s):
    nl = '\n'
    if s.count(ANCHOR) != 1:
        sys.exit('config_definitions.json: cannot find graph_colours.psychedelic to insert before')
    block = ''
    for name, tones in DECLARE:
        block += nl.join(['        "graph_colours.%s": {' % name, '            "default": [']
                         + ['                "%s"%s' % (t, ',' if i < len(tones) - 1 else '') for i, t in enumerate(tones)]
                         + ['            ],', '            "type": "array"', '        },', ''])
    return s.replace(ANCHOR, block + ANCHOR)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('librenms')
    ap.add_argument('--out', help='write the two changed files under this directory (never inside the checkout)')
    a = ap.parse_args()

    changed = {}
    for rel, fn in ((GD, change_helper), (CD, change_definitions)):
        path = os.path.join(a.librenms, rel)
        if not os.path.isfile(path):
            sys.exit('missing ' + path)
        # LF throughout: a Windows checkout (autocrlf) has CRLF files, but git and upstream use LF.
        old = open(path, encoding='utf-8', newline='').read().replace('\r\n', '\n')
        changed[rel] = (old, fn(old))

    for rel, (old, new) in changed.items():
        sys.stdout.writelines(difflib.unified_diff(
            old.splitlines(True), new.splitlines(True), 'a/' + rel.replace(os.sep, '/'), 'b/' + rel.replace(os.sep, '/')))

    if a.out:
        out = os.path.abspath(a.out)
        if os.path.commonpath([out, os.path.abspath(a.librenms)]) == os.path.abspath(a.librenms):
            sys.exit('--out must be outside the LibreNMS checkout')
        for rel, (_, new) in changed.items():
            dest = os.path.join(out, rel)
            os.makedirs(os.path.dirname(dest), exist_ok=True)
            open(dest, 'w', encoding='utf-8', newline='').write(new)
        print('\nwrote the changed files under ' + out, file=sys.stderr)


if __name__ == '__main__':
    main()
