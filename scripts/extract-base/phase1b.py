"""Phase 1b, one-off: rename tokens, add a defaults block to base.css, and drop
skin tokens that equal their default.

Run once, after extract.py, from this directory:  python phase1b.py ../..
Idempotent only in the sense that re-running extract.py first starts over.
"""
import collections, json, re, sys

from renames import rename

REPO = sys.argv[1]
SKINS = ['terran', 'protoss', 'zerg']
MANUAL = {'dropdown-menu-divider-bg-2': 'dropdown-divider-background',
          'navbar-default-before-bg': 'navbar-before-background'}

roles = set(json.load(open('roles.json')).values())
resolved = json.load(open('resolved.json'))
designed = json.load(open('designed-defaults.json'))
core, derived = designed['core'], {k: v for k, v in designed['derived'].items() if not k.startswith('_')}

base_path = f'{REPO}/base/base.css'
base = open(base_path, encoding='utf-8').read()
skin_text = {k: open(f'{REPO}/skins/{k}/skin.css', encoding='utf-8').read() for k in SKINS}


def tokens(text):
    body = text[text.index('html.dark {'):]
    return collections.OrderedDict(re.findall(r'--ts-([\w-]+):\s*([^;]+);', body))


old_tokens = {k: tokens(t) for k, t in skin_text.items()}
names = list(old_tokens['terran'])

# 1. rename map (roles keep their names)
rmap = {}
for n in names:
    if n in roles:
        continue
    rmap[n] = MANUAL.get(n) or rename(n)
assert len(set(rmap.values())) == len(rmap)

def apply_renames(text):
    # longest first so a name that prefixes another is not clobbered
    for old in sorted(rmap, key=len, reverse=True):
        text = re.sub(rf'--ts-{re.escape(old)}(?![\w-])', f'--ts-{rmap[old]}', text)
    return text

base = apply_renames(base)
skin_text = {k: apply_renames(t) for k, t in skin_text.items()}
T = {k: tokens(t) for k, t in skin_text.items()}
new = lambda n: rmap.get(n, n)

# 2. defaults
defaults = collections.OrderedDict()
source = {}
for n in names:
    nn = new(n)
    if n in roles:
        if nn in core:
            defaults[nn] = core[nn]; source[nn] = 'core'
        elif nn in derived:
            defaults[nn] = derived[nn]; source[nn] = 'derived role'
        else:
            raise SystemExit(f'role without default: {nn}')
        continue
    if nn in derived:
        defaults[nn] = derived[nn]; source[nn] = 'designed'
        continue
    measured = [k for k in SKINS if n in resolved.get(k, {})]
    vals = [T[k][nn] for k in SKINS]
    if measured:
        defaults[nn] = T[measured[0]][nn]; source[nn] = 'stock'
    else:
        shared = max(set(vals), key=vals.count)
        assert vals.count(shared) >= 2 and 'var(--p-' not in shared, (nn, vals)
        defaults[nn] = shared; source[nn] = 'shared'
for h in derived:
    if h not in defaults:
        defaults[h] = derived[h]; source[h] = 'helper'

# 3. prune skin tokens equal to their default
norm = lambda v: ' '.join(v.split())
pruned = collections.Counter()
for k in SKINS:
    lines = skin_text[k].split('\n')
    out = []
    for line in lines:
        m = re.match(r'\s*--ts-([\w-]+):\s*(.+);\s*$', line)
        if m and m.group(1) in defaults and norm(m.group(2)) == norm(defaults[m.group(1)]):
            pruned[k] += 1
            continue
        out.append(line)
    skin_text[k] = '\n'.join(out)

# 4. inline fallbacks from extraction are redundant now every token has a default
base = re.sub(r'var\((--ts-[\w-]+), [^()]*(?:\([^()]*\))?[^()]*\)', r'var(\1)', base)

# 5. write the defaults block at the top of base.css
order = ['core', 'derived role', 'helper', 'designed', 'shared', 'stock']
titles = {
    'core': 'Core roles: a skin normally sets these; the defaults are stock LibreNMS dark.',
    'derived role': 'Derived roles: default to a mix of the core roles.',
    'helper': 'Shared effects: used by the defaults below.',
    'designed': 'Components: restrained defaults built from the roles.',
    'shared': 'Components: values two of the bundled skins share.',
    'stock': 'Components: default to stock styling; a skin sets them only to add something.',
}
block = ['/*',
         ' * Theme Selector for LibreNMS: base stylesheet.',
         ' *',
         ' * Applies a skin. A skin is a token file (skins/<id>/skin.css) that sets',
         ' * --ts-* custom properties on html.dark, loaded after this file, so any',
         ' * token it sets overrides the default below. Token reference: docs/TOKENS.md.',
         ' */',
         'html.dark {']
for grp in order:
    items = [(n, v) for n, v in defaults.items() if source[n] == grp]
    if grp == 'core':
        items.sort(key=lambda it: list(core).index(it[0]))
    if not items:
        continue
    block.append(f'  /* {titles[grp]} */')
    block += [f'  --ts-{n}: {v};' for n, v in items]
    block.append('')
block[-1] = '}'
base = '\n'.join(block) + '\n\n' + base

open(base_path, 'w', encoding='utf-8', newline='\n').write(base)
for k in SKINS:
    open(f'{REPO}/skins/{k}/skin.css', 'w', encoding='utf-8', newline='\n').write(skin_text[k])
json.dump({'renames': rmap, 'sources': source}, open('phase1b-map.json', 'w'), indent=1)
print('tokens', len(defaults), dict(collections.Counter(source.values())))
print('pruned per skin', dict(pruned), {k: len(tokens(skin_text[k])) for k in SKINS})
