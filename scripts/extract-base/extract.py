"""One-off: merge the three standalone skins into base.css + per-skin token files.

Usage: python extract.py <repo>
Writes <repo>/base/base.css and <repo>/skins/<id>/skin.css from the original
<repo>/skins/<id>/<id>.css files. Hand decisions live in the JSON files beside
this script (see README.md). Unresolved gaps are listed in missing.json.
"""
import collections, json, re, sys, os

REPO = sys.argv[1]
SKINS = {'terran': 'tn', 'protoss': 'pr', 'zerg': 'zg'}
ORDER = list(SKINS)

# Hand-named roles for "each skin references its own palette entry here".
ROLE_NAMES = json.load(open('roles.json')) if os.path.exists('roles.json') else {}
RESOLVED = json.load(open('resolved.json')) if os.path.exists('resolved.json') else {}
MISSING = []
FALLBACKS = json.load(open('fallbacks.json')) if os.path.exists('fallbacks.json') else {}
COMPOSITE_NAMES = json.load(open('composites.json')) if os.path.exists('composites.json') else {}


def load(skin):
    s = open(f'{REPO}/skins/{skin}/{skin}.css', encoding='utf-8').read()
    s = s.replace(f'--{SKINS[skin]}-', '--p-')
    s = re.sub(r'/\*.*?\*/', '', s, flags=re.S)
    return s


def parse(css):
    """-> ordered list of (context tuple, [(prop, value, important)])"""
    blocks = []
    stack = []
    buf = ''
    cur = None
    for tok in re.finditer(r'[{};]|[^{};]+', css):
        t = tok.group(0)
        if t == '{':
            stack.append(' '.join(buf.split()))
            buf = ''
            cur = None
        elif t in ';}':
            d = ' '.join(buf.split())
            buf = ''
            if d:
                if cur is None:
                    cur = (tuple(stack), [])
                    blocks.append(cur)
                p, v = d.split(':', 1)
                v = v.strip()
                imp = v.endswith('!important')
                if imp:
                    v = v[: -len('!important')].strip()
                cur[1].append((p.strip(), v, imp))
            if t == '}':
                stack.pop()
                cur = None
        else:
            buf += t
    return blocks


SPLIT = json.load(open('split.json')) if os.path.exists('split.json') else []


def split_blocks(blocks):
    out = []
    for ctx, decls in blocks:
        if ctx[-1] in SPLIT:
            for part in ctx[-1].split(','):
                out.append((ctx[:-1] + (part.strip(),), list(decls)))
        else:
            out.append((ctx, decls))
    return out


NORMALIZE = json.load(open('normalize.json')) if os.path.exists('normalize.json') else {}


def normalize(blocks):
    out = []
    for ctx, decls in blocks:
        nd = []
        for p, v, imp in decls:
            rep = NORMALIZE.get(f'{ctx[-1]} :: {p} :: {v}')
            if rep:
                nd.extend((rp, rv, imp) for rp, rv in rep)
            else:
                nd.append((p, v, imp))
        out.append((ctx, nd))
    return out


parsed = {k: normalize(split_blocks(parse(load(k)))) for k in ORDER}

# palette = :root block of each skin
palette = {}
for k in ORDER:
    root = [b for b in parsed[k] if b[0] == (':root',)]
    assert len(root) == 1
    palette[k] = root[0][1]
    parsed[k] = [b for b in parsed[k] if b[0] != (':root',)]

# font-face blocks are per-skin (bundle-local fonts) -> token file verbatim
fontfaces = {k: [b for b in parsed[k] if b[0] == ('@font-face',)] for k in ORDER}
for k in ORDER:
    parsed[k] = [b for b in parsed[k] if b[0] != ('@font-face',)]

# merge block order: terran order, others' unique blocks inserted after predecessor
def keyed(k):
    seen = collections.Counter()
    out = []
    for ctx, decls in parsed[k]:
        seen[ctx] += 1
        out.append(((ctx, seen[ctx]), decls))
    return out

K = {k: keyed(k) for k in ORDER}
merged = [key for key, _ in K['terran']]
for k in ORDER[1:]:
    prev = None
    for key, _ in K[k]:
        if key not in merged:
            idx = merged.index(prev) + 1 if prev in merged else len(merged)
            merged.insert(idx, key)
        prev = key
D = {k: dict(K[k]) for k in ORDER}

roles = collections.OrderedDict()      # tuple -> name
tokens = {k: collections.OrderedDict() for k in ORDER}
report = collections.defaultdict(list)
base = []

VARREF = re.compile(r'var\(--p-([\w-]+)\)')


def selector_slug(ctx):
    sel = ctx[-1].split(',')[0]
    sel = sel.replace('html.dark', '').strip()
    sel = re.sub(r'[^a-z0-9]+', '-', sel.lower()).strip('-')
    return sel[:40] or 'root'


def prop_slug(p):
    return {'background-color': 'bg', 'background-image': 'bg-image', 'background': 'bg',
            'border-color': 'border', 'box-shadow': 'shadow', 'color': 'fg'}.get(p, p)


for key in merged:
    ctx, _ = key
    present = [k for k in ORDER if key in D[k]]
    if ctx[0].startswith('@keyframes') or ctx[0].startswith('@media (prefers-reduced-motion'):
        decls = D[present[0]][key]
        base.append((ctx, [f'{p}: {v}' + (' !important' if imp else '') for p, v, imp in decls]))
        continue
    props = []
    for k in present:
        for p, v, imp in D[k][key]:
            if p not in props:
                props.append(p)
    lines = []
    for p in props:
        vals = {}
        imps = set()
        for k in ORDER:
            m = [(v, imp) for pp, v, imp in D[k].get(key, []) if pp == p]
            if m:
                vals[k], imp = m[-1]
                imps.add(imp)
        if len(imps) > 1:
            report['mixed-important'].append((ctx, p, vals))
        imp = ' !important' if True in imps else ''
        if len(vals) == 3 and len(set(vals.values())) == 1:
            v = vals['terran']
            # a shared value may still reference per-skin palette names
            refs = VARREF.findall(v)
            if refs and not all(r in dict((x[0][3:], 1) for x in palette[k]) for k in ORDER for r in refs):
                report['shared-ref-missing'].append((ctx, p, v))
            if refs:
                # identical text, but palette entries differ per skin: route through roles
                v2 = v
                for r in refs:
                    tup = (r, r, r)
                    name = ROLE_NAMES.get('|'.join(tup), r)
                    roles.setdefault(tup, name)
                    v2 = v2.replace(f'var(--p-{r})', f'var(--ts-{name})')
                v = v2
            lines.append(f'{p}: {v}{imp}')
            continue
        # differs or missing somewhere
        m = [VARREF.fullmatch(vals[k]) for k in ORDER if k in vals]
        if len(vals) == 3 and all(m):
            tup = tuple(x.group(1) for x in m)
            name = ROLE_NAMES.get('|'.join(tup), 'role-' + '-'.join(tup))
            roles.setdefault(tup, name)
            lines.append(f'{p}: var(--ts-{name}){imp}')
            continue
        ckey = f'{ctx[-1]} :: {p}'
        name = COMPOSITE_NAMES.get(ckey) or f'{selector_slug(ctx)}-{prop_slug(p)}'
        base_name = name
        n = 2
        while any(name in tokens[k] and tokens[k][name] != vals.get(k) for k in ORDER) and COMPOSITE_NAMES.get(ckey) is None:
            name = f'{base_name}-{n}'; n += 1
        fb = ''
        for k in ORDER:
            if k in vals:
                tokens[k][name] = vals[k]
            else:
                report['missing'].append((k, ctx, p, name))
                if name in RESOLVED.get(k, {}):
                    tokens[k][name] = RESOLVED[k][name]
                else:
                    MISSING.append({'skin': k, 'ctx': list(ctx), 'prop': p, 'token': name})
        if len(vals) < 3:
            fb = ', ' + FALLBACKS.get(ckey, FALLBACKS.get(p, 'initial'))
        lines.append(f'{p}: var(--ts-{name}{fb}){imp}')
    base.append((ctx, lines))

EXTRA = json.load(open('extra.json')) if os.path.exists('extra.json') else []
for ex in EXTRA:
    idx = next(i for i, (c, _) in enumerate(base) if c[-1] == ex['after']) + 1
    lines = []
    for prop, name in ex['decls'].items():
        lines.append(f'{prop}: var(--ts-{name})')
        for k in ORDER:
            tokens[k][name] = ex['values'][k]
    base.insert(idx, ((ex['selector'],), lines))

# roles resolve per skin to the palette entry
for tup, name in roles.items():
    for k, ref in zip(ORDER, tup):
        tokens[k].setdefault(name, f'var(--p-{ref})')


def emit_block(ctx, lines, out):
    ind = ''
    for c in ctx[:-1]:
        out.append(f'{ind}{c} {{')
        ind += '  '
    out.append(f'{ind}{ctx[-1]} {{')
    for l in lines:
        out.append(f'{ind}  {l};')
    out.append(f'{ind}}}')
    for _ in ctx[:-1]:
        ind = ind[:-2]
        out.append(f'{ind}}}')

def emit_all(blocks, out):
    # consecutive blocks sharing an outer at-rule (@keyframes frames, @media
    # contents) go inside one wrapper: two @keyframes of the same name do not
    # merge, the later one replaces the earlier.
    i = 0
    while i < len(blocks):
        ctx, lines = blocks[i]
        if len(ctx) == 1:
            emit_block(ctx, lines, out)
            i += 1
            continue
        outer = ctx[:-1]
        out.append(' '.join(outer) + ' {')
        while i < len(blocks) and blocks[i][0][:-1] == outer:
            c, l = blocks[i]
            out.append(f'  {c[-1]} {{')
            out.extend(f'    {x};' for x in l)
            out.append('  }')
            i += 1
        out.append('}')

out = []
emit_all(base, out)
os.makedirs(f'{REPO}/base', exist_ok=True)
open(f'{REPO}/base/base.css', 'w', encoding='utf-8', newline='\n').write('\n'.join(out) + '\n')

for k in ORDER:
    o = []
    for ctx, decls in fontfaces[k]:
        emit_block(ctx, [f'{p}: {v}' for p, v, _ in decls], o)
    o.append('html.dark {')
    for p, v, _ in palette[k]:
        o.append(f'  {p}: {v};')
    o.append('')
    for name, v in tokens[k].items():
        o.append(f'  --ts-{name}: {v};')
    o.append('}')
    open(f'{REPO}/skins/{k}/skin.css', 'w', encoding='utf-8', newline='\n').write('\n'.join(o) + '\n')

print('base blocks', len(base), 'lines', len(out))
print('roles', len(roles), 'composite tokens', len(tokens['terran']) - len(roles))
for kind, items in report.items():
    print(f'== {kind}: {len(items)}')
    for it in items[:80]:
        print('  ', str(it)[:220])
json.dump(MISSING, open(f'{REPO}/harness/.missing.json', 'w'), indent=1)
print('unresolved missing', len(MISSING))
