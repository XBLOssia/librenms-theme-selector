import re, json, collections, sys
REPO = '../..'
SK = ['terran', 'protoss', 'zerg']
def toks(skin):
    s = open(f'{REPO}/skins/{skin}/skin.css', encoding='utf-8').read()
    body = s[s.index('html.dark {'):]
    return dict(re.findall(r'(--ts-[\w-]+):\s*([^;]+);', body))
T = {k: toks(k) for k in SK}
roles = set(json.load(open('roles.json')).values())
resolved = json.load(open('resolved.json'))
names = list(T['terran'])
cat = collections.Counter(); groups = collections.defaultdict(list)
for n in names:
    b = n[5:]
    if b in roles: cat['role'] += 1; continue
    measured = [k for k in SK if b in resolved.get(k, {})]
    vals = [T[k][n] for k in SK]
    if measured:
        c = 'stock-in-%d' % len(measured); cat[c] += 1; groups[c].append(b)
    elif len(set(vals)) < 3:
        cat['two-agree'] += 1; groups['two-agree'].append(b)
    else:
        cat['all-differ'] += 1; groups['all-differ'].append(b)
print(dict(cat), 'total', len(names))
for g in ['two-agree', 'all-differ']:
    print('==', g, len(groups[g])); print('  ', ' '.join(groups[g]))
