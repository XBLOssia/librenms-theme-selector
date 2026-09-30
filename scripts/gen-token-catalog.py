"""Generate resources/token-catalog.json from base/base.css.

The catalog is what the upload validator enforces: which --ts-* tokens exist,
which of them an UPLOADED skin may set, and how large a length in them may be.
It is derived from how base.css uses each token, not written by hand, so a new
token can't silently become settable in a dangerous place.

    python scripts/gen-token-catalog.py            # write resources/token-catalog.json
    python scripts/gen-token-catalog.py --check    # exit 1 if it is out of date

THE RULE
A token is STRUCTURAL if it can reach a CSS property that changes layout,
stacking, generated content or motion: position, size, offsets, z-index,
pointer-events, content, display, transform, clip-path, animation... Such
properties let a stylesheet cover or replace parts of the page (a fake
"session expired, sign in" panel, an invisible click target over a button).
Uploaded skins may not set structural tokens; bundled skins may.

Any property this script does not recognise counts as structural: deny by
default. A token reaches a property directly (`prop: var(--ts-x)`) or through
another token's value (`--ts-a: 1px solid var(--ts-x)`), and the latter
propagates until nothing changes.
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# property -> kind, for properties an uploaded skin may drive
KINDS = {}
for p in ['color', 'background-color', 'border-color', 'outline-color', 'fill', 'stroke', 'caret-color',
          'text-decoration-color', 'column-rule-color', 'accent-color', 'scrollbar-color']:
    KINDS[p] = 'color'
for side in ['top', 'right', 'bottom', 'left']:
    KINDS[f'border-{side}-color'] = 'color'
    KINDS[f'border-{side}'] = 'border'
    KINDS[f'border-{side}-width'] = 'border'
    KINDS[f'border-{side}-style'] = 'text'
    KINDS[f'padding-{side}'] = 'length'
for p in ['background-image', 'background']:
    KINDS[p] = 'image'
for p in ['box-shadow', 'text-shadow']:
    KINDS[p] = 'shadow'
for p in ['font-family']:
    KINDS[p] = 'font'
for p in ['border', 'border-width']:
    KINDS[p] = 'border'
for p in ['border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius']:
    KINDS[p] = 'length'
for p in ['border-radius', 'padding', 'letter-spacing', 'font-size', 'line-height', 'word-spacing']:
    KINDS[p] = 'length'
for p in ['font-weight', 'font-style', 'text-transform', 'text-decoration', 'font-variant-numeric',
          'border-style', 'opacity']:
    KINDS[p] = 'text'
# Backgrounds are painted inside the element they belong to: their size, position
# and repeat can't move, resize or raise anything.
for p in ['background-size', 'background-position']:
    KINDS[p] = 'length'
KINDS['background-repeat'] = 'text'
# A cut corner: clip-path is structural, except in the one fixed polygon that
# base.css writes for buttons, labels and badges. The tokens it reads are sizes
# in px; TOKEN_CAPS holds their (small) limits.
KINDS['chamfer-clip'] = 'chamfer'
KINDS['ratio-clip'] = 'ratio'
# Motion and glow for the ornament layers: `animation` is structural except in
# the one shape base.css writes (a fixed keyframe name and easing with the
# period as the only token), and `filter` except in the one fixed drop-shadow
# whose colour is the only token. A skin supplies a period or a plain colour.
ANIMATION_SHAPE = re.compile(r'^ts-(breathe|glow) var\((--ts-[\w-]+)\) ease-in-out infinite$')
GLOW_SHAPE = re.compile(r'^drop-shadow\(0 0 8px var\((--ts-[\w-]+)\)\)$')
KINDS['motion-layer'] = 'period'
KINDS['glow-filter'] = 'glowcolor'
# Tokens the keyframes read, which need a stricter grammar than their property's.
TOKEN_KINDS = [(re.compile(r'^--ts-breathe-(low|high)$'), 'level'),
               (re.compile(r'^--ts-alert-glow-(low|high)$'), 'glowcolor')]
TOKEN_CAPS = [(re.compile(r'^--ts-btn-chamfer'), 10), (re.compile(r'^--ts-(label|badge)-chamfer'), 6)]


def chamfer_template(e):
    def c(corner):
        return f'var(--ts-{e}-chamfer-{corner}, var(--ts-{e}-chamfer))'

    def v(corner):
        return f'calc({c(corner)} * var(--ts-{e}-chamfer-rise, 1))'
    return (f'polygon({c("tl")} 0, calc(100% - {c("tr")}) 0, 100% {v("tr")}, 100% calc(100% - {v("br")}), '
            f'calc(100% - {c("br")}) 100%, {c("bl")} 100%, 0 calc(100% - {v("bl")}), 0 {v("tl")})')


CHAMFER_TEMPLATES = {chamfer_template(e) for e in ('btn', 'label', 'badge')}
KINDS['transition'] = 'motion'
KINDS['filter'] = 'filter'
# A Tailwind theme variable the base retints; every one is a colour.
TAILWIND_COLOUR = re.compile(r'^--tw-color-')

# how large a px length may be in a token of each kind
MAX_PX = {'period': 800, 'level': 800, 'glowcolor': 800, 'ratio': 800, 'chamfer': 10, 'shadow': 100, 'border': 24, 'length': 64, 'motion': 800, 'filter': 800,
          'color': 800, 'image': 800, 'font': 800, 'text': 800}


def parse(css):
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)
    rules, stack, buf = [], [], ''
    for tok in re.finditer(r'[{};]|[^{};]+', css):
        t = tok.group(0)
        if t == '{':
            stack.append(' '.join(buf.split())); buf = ''
        elif t in ';}':
            d = ' '.join(buf.split()); buf = ''
            if d and ':' in d and stack:
                p, v = d.split(':', 1)
                rules.append((tuple(stack), p.strip(), v.strip()))
            if t == '}':
                stack.pop()
        else:
            buf += t
    return rules


def build():
    css = open(os.path.join(ROOT, 'base', 'base.css'), encoding='utf-8').read()
    rules = parse(css)

    defaults = {}          # token -> default value text
    direct = {}            # token -> set of properties it reaches directly
    for ctx, prop, val in rules:
        if ctx == ('html.dark',) and prop.startswith('--ts-') and val is not None and prop not in defaults:
            defaults[prop] = val
            continue
        flat = ' '.join(val.split())
        if prop == 'clip-path' and flat in CHAMFER_TEMPLATES:
            prop = 'chamfer-clip'
        elif prop == 'animation' and ANIMATION_SHAPE.match(flat):
            prop = 'motion-layer'
        elif prop == 'filter' and GLOW_SHAPE.match(flat):
            prop = 'glow-filter'
        for name in re.findall(r'var\((--ts-[\w-]+)', val):
            direct.setdefault(name, set()).add('ratio-clip' if prop == 'chamfer-clip' and name.endswith('-rise') else prop)

    # A token used by another token's value shares that token's destinations.
    feeds = {t: set(re.findall(r'var\((--ts-[\w-]+)', v)) for t, v in defaults.items()}
    reach = {t: set(direct.get(t, ())) for t in defaults}
    changed = True
    while changed:
        changed = False
        for t, refs in feeds.items():
            for r in refs:
                if r in reach and not reach[t] <= reach[r]:
                    reach[r] |= reach[t]
                    changed = True

    catalog = {}
    for t in sorted(defaults):
        props = reach[t]
        kinds, structural, why = set(), False, []
        for p in sorted(props):
            if TAILWIND_COLOUR.match(p):
                kinds.add('color')
            elif p in KINDS:
                kinds.add(KINDS[p])
            else:
                structural = True
                why.append(p)
        kinds |= {k for pat, k in TOKEN_KINDS if pat.match(t)}
        caps = [MAX_PX[k] for k in kinds] + [cap for pat, cap in TOKEN_CAPS if pat.match(t)]
        entry = {'structural': structural,
                 'maxPx': min(caps or [800]),
                 'kinds': sorted(kinds)}
        if structural:
            entry['why'] = why
        catalog[t] = entry
    return catalog


def main():
    catalog = build()
    text = json.dumps({'_generated': 'scripts/gen-token-catalog.py from base/base.css; do not edit',
                       'tokens': catalog}, indent=1, sort_keys=True) + '\n'
    path = os.path.join(ROOT, 'resources', 'token-catalog.json')
    if '--check' in sys.argv:
        current = open(path, encoding='utf-8').read() if os.path.exists(path) else ''
        if current != text:
            print('resources/token-catalog.json is out of date: run scripts/gen-token-catalog.py')
            sys.exit(1)
        print('token catalog is current')
        return
    os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8', newline='\n').write(text)
    structural = [t for t, e in catalog.items() if e['structural']]
    print(f'{len(catalog)} tokens, {len(structural)} structural, {len(catalog) - len(structural)} settable by upload')


if __name__ == '__main__':
    main()
