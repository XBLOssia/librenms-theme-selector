"""Text-level check: base.css with a skin's tokens substituted back must
reproduce every declaration of the original skin, rule by rule. Covers what
the rendered comparison cannot: hover/focus/active states and elements the
harness pages don't render.

Usage: python roundtrip.py <repo>
"""
import collections, json, re, sys

REPO = sys.argv[1]
SKINS = {'terran': 'tn', 'protoss': 'pr', 'zerg': 'zg'}
SPLIT = json.load(open('split.json'))
NORMALIZE = json.load(open('normalize.json'))
RESOLVED = json.load(open('resolved.json'))
try:  # after phase1b.py, tokens carry new names
    _ren = json.load(open('phase1b-map.json'))['renames']
    RESOLVED = {k: {_ren.get(n, n): v for n, v in d.items()} for k, d in RESOLVED.items()}
except FileNotFoundError:
    pass
EXTRA_SELECTORS = {e['selector'] for e in json.load(open('extra.json'))}


def parse(css):
    """-> {context tuple: {prop: 'value[ !important]'}} (later decl wins)."""
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    rules = collections.OrderedDict()
    stack, buf = [], ''
    for tok in re.finditer(r'[{};]|[^{};]+', css):
        t = tok.group(0)
        if t == '{':
            stack.append(' '.join(buf.split())); buf = ''
        elif t in ';}':
            d = ' '.join(buf.split()); buf = ''
            if d:
                p, v = d.split(':', 1)
                rules.setdefault(tuple(stack), collections.OrderedDict())[p.strip()] = v.strip()
            if t == '}':
                stack.pop()
        else:
            buf += t
    return rules


def original(skin):
    s = open(f'{REPO}/skins/{skin}/{skin}.css', encoding='utf-8').read().replace(f'--{SKINS[skin]}-', '--p-')
    rules = collections.OrderedDict()
    for ctx, decls in parse(s).items():
        parts = [ctx] if ctx[-1] not in SPLIT else [ctx[:-1] + (p.strip(),) for p in ctx[-1].split(',')]
        for c in parts:
            nd = collections.OrderedDict()
            for p, v in decls.items():
                plain = v.replace('!important', '').strip()
                rep = NORMALIZE.get(f'{ctx[-1]} :: {p} :: {plain}')
                for rp, rv in (rep or [[p, plain]]):
                    nd[rp] = rv + (' !important' if v.endswith('!important') else '')
            rules.setdefault(c, collections.OrderedDict()).update(nd)
    return rules


def tokens_of(skin):
    # base.css defaults first; the skin, loaded after it, overrides them
    t = {p: v for p, v in parse(open(f'{REPO}/base/base.css', encoding='utf-8').read()).get(('html.dark',), {}).items()
         if p.startswith('--ts-')}
    for ctx, decls in parse(open(f'{REPO}/skins/{skin}/skin.css', encoding='utf-8').read()).items():
        if ctx == ('html.dark',):
            t.update({p: v for p, v in decls.items() if p.startswith('--ts-')})
    return t


VAR = re.compile(r'var\((--ts-[\w-]+)(?:,\s*([^()]*(?:\([^()]*\))*[^()]*))?\)')


def substitute(value, toks):
    for _ in range(10):
        new = VAR.sub(lambda m: toks.get(m.group(1), m.group(2) or f'<UNDEFINED {m.group(1)}>'), value)
        if new == value:
            break
        value = new
    return value


def norm(v):
    return ' '.join(v.replace('!important', ' !important').split())


def canon(v):
    """Undo the base's role routing for comparison: var(--ts-x) values that
    are themselves var(--p-...) have already been substituted."""
    return norm(v)


base = parse(open(f'{REPO}/base/base.css', encoding='utf-8').read())
failures = 0
for skin in SKINS:
    toks = tokens_of(skin)
    resolved = RESOLVED.get(skin, {})
    orig = original(skin)
    problems = []
    for ctx, decls in orig.items():
        if ctx == (':root',) or ctx == ('@font-face',):
            continue
        b = base.get(ctx)
        if b is None:
            problems.append(f'missing rule in base: {ctx}')
            continue
        for p, v in decls.items():
            got = substitute(b.get(p, '<absent>'), toks)
            if norm(got) != norm(v):
                problems.append(f'{ctx[-1][:70]} :: {p}\n      original: {norm(v)[:110]}\n      base+tok: {norm(got)[:110]}')
    # extras: declarations in base the original did not have, for this skin
    extras = collections.Counter()
    for ctx, decls in base.items():
        if ctx[0].startswith('@keyframes') or ctx[0].startswith('@media (prefers-reduced-motion'):
            continue
        if ctx[-1] in EXTRA_SELECTORS:
            extras['extra rule'] += 1
            continue
        od = orig.get(ctx, {})
        for p, v in decls.items():
            if p in od or p.startswith('--ts-'):
                continue
            m = VAR.search(v)
            name = m.group(1)[5:] if m else None
            if name in resolved:
                extras['measured stock value'] += 1
            else:
                problems.append(f'unexpected extra declaration: {ctx[-1][:70]} :: {p}: {v[:80]}')
    print(f'{skin}: {len(problems)} problems; extras: {dict(extras)}')
    for pr in problems[:40]:
        print('   ', pr)
    failures += len(problems)
sys.exit(1 if failures else 0)
