"""Pack a skin directory into a zip the Theme Selector upload page will accept.

    python scripts/pack-skin.py examples/minimal              # -> minimal.zip
    python scripts/pack-skin.py path/to/my-skin -o my-skin.zip

The directory holds exactly:

    skin.json     required   id and name are required; description, author, version, license, family and mode ("dark" or "light") are optional
    skin.css      required   html.dark { --ts-*: ...; --p-*: ...; } (or html:not(.dark) { ... } for a light-mode skin) and @font-face
    graph.conf    optional   graph colour palette
    LICENSE.txt   optional   licence notice for the fonts (stored and shown to admins, never served)
    fonts/*.woff2 optional   the fonts skin.css refers to (also .woff)
    textures/*.png optional  repeating background tiles, declared in skin.css as
                             --tx-<name>: url("textures/<name>.png"); (at most 4, 256x256 px each)

Anything else in the directory is an error, not silently skipped: the upload
page rejects a bundle with any other entry, and it is better to hear about it
here. Archives are written the way the upload page's reader expects (deflate,
plain regular-file entries, no extra fields, fixed timestamps so the same input
gives the same bytes).

This only packs. Check the result the way the upload page will, without
installing it, with:  lnms theme-selector:validate my-skin.zip
"""
import os
import re
import sys
import zipfile

NAME = re.compile(r'^(skin\.json|skin\.css|graph\.conf|LICENSE\.txt|fonts/[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(woff2|woff)|textures/[a-z0-9][a-z0-9-]{0,40}\.png)$')
LIMITS = {'skin.json': 4096, 'skin.css': 98304, 'graph.conf': 8192, 'LICENSE.txt': 20480}
FONT_LIMIT = 409600
TEXTURE_LIMIT = 262144


def die(msg):
    print('error: ' + msg, file=sys.stderr)
    sys.exit(1)


def main(argv):
    if not argv or argv[0] in ('-h', '--help'):
        print(__doc__)
        return 0
    src = argv[0]
    out = None
    if '-o' in argv:
        out = argv[argv.index('-o') + 1]
    if not os.path.isdir(src):
        die(f'{src} is not a directory')
    out = out or os.path.basename(os.path.normpath(src)) + '.zip'

    entries = []
    for dirpath, dirnames, filenames in os.walk(src):
        dirnames.sort()
        for f in sorted(filenames):
            full = os.path.join(dirpath, f)
            rel = os.path.relpath(full, src).replace(os.sep, '/')
            entries.append((rel, full))

    problems = []
    for rel, full in entries:
        if not NAME.match(rel):
            problems.append(f'{rel}: not allowed in a skin bundle (allowed: skin.json, skin.css, graph.conf, LICENSE.txt, fonts/*.woff2, fonts/*.woff, textures/*.png)')
            continue
        limit = LIMITS.get(rel, TEXTURE_LIMIT if rel.startswith('textures/') else FONT_LIMIT)
        if os.path.getsize(full) > limit:
            problems.append(f'{rel}: larger than {limit} bytes')
        if os.path.islink(full):
            problems.append(f'{rel}: is a symlink')
    names = {rel for rel, _ in entries}
    for required in ('skin.json', 'skin.css'):
        if required not in names:
            problems.append(f'{required} is missing')
    if len([n for n in names if n.startswith('fonts/')]) > 8:
        problems.append('more than 8 fonts')
    if len([n for n in names if n.startswith('textures/')]) > 4:
        problems.append('more than 4 textures')
    if problems:
        print('Not packed:', file=sys.stderr)
        for p in problems:
            print('  - ' + p, file=sys.stderr)
        return 1

    order = ['skin.json', 'skin.css', 'graph.conf', 'LICENSE.txt']
    entries.sort(key=lambda e: (order.index(e[0]) if e[0] in order else 99, e[0]))
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for rel, full in entries:
            info = zipfile.ZipInfo(rel, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            with open(full, 'rb') as fh:
                z.writestr(info, fh.read())
    print(f'wrote {out}: {len(entries)} files, {os.path.getsize(out)} bytes')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
