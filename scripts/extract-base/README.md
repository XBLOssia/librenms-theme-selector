# extract-base

Phase 1 and 1b tooling (docs/PLUGIN.md). It turned the three standalone skins
(`skins/<id>/<id>.css`) into the shared `base/base.css` plus a token file per
skin (`skins/<id>/skin.css`), and it checks that the result is equivalent.

> **The equivalence checks below are historical.** `base.css` deliberately
> diverged from the original `<id>.css` files on 2026-09-29, when the
> table/input/list-group fixes went in (the original skins had them wrong).
> `roundtrip.py` and `compare.html` now report exactly those rules as
> differences and nothing else. The regression check going forward is
> `harness/leaks.html`.

> **`base/base.css` and the `skin.css` files are now the source.** Edit them
> directly. Don't re-run `extract.py` or `phase1b.py`: together they
> regenerate both from the originals and would overwrite any edit. They're
> kept as the record of how the split was made.

## Checking equivalence

As long as the original `<id>.css` files exist (until production migrates),
any change to `base.css` or a `skin.css` that is meant to leave the bundled
skins unchanged can be checked two ways:

```bash
cd scripts/extract-base
python roundtrip.py ../..   # text-level; exit 1 on any mismatch
```

and `harness/compare.html?all=1` (harness server, port 8777) for the rendered
check: computed styles of every element, original vs base + tokens.

`roundtrip.py` reads `phase1b-map.json` to follow the token renames.

## How the split was made

1. **`extract.py`** merged the three skins rule by rule. Values all three
   shared stayed literal; the rest became tokens. It writes `base.css`, the
   `skin.css` files, and `harness/.missing.json` for any gap it couldn't fill.
2. The gaps (properties one skin never declared) were resolved in the harness
   by measuring the original skin's computed value, then recorded in
   `resolved.json`.
3. **`phase1b.py`** renamed the tokens (`renames.py`), added the defaults
   block at the top of `base.css`, and dropped every skin token equal to its
   default.

| File | What it holds |
|---|---|
| `roles.json` | Names for the shared roles: which palette entry each skin uses for the same job (e.g. `edge`/`edge`/`carapace` → `border`). |
| `resolved.json` | Stock values for properties a skin never declared but another did, per skin and token (pre-rename names). Measured in the harness where the element renders there, otherwise read from stock CSS (`hr`, `.modal-content`, `.label-info`). |
| `split.json` | Rules split into one rule per selector because their parts have different stock values (`html`/`body` font, `.btn-*`/`.lnms-btn-*` shadows, `.label`/`.badge` padding). |
| `normalize.json` | Declarations rewritten before merging so one token can express every skin (`hr { border: 0 }` → `border-width`). |
| `extra.json` | Rules added to the base that no original had: `.badge-navbar-user` padding, so Terran's stock 4px survives the shared badge padding. |
| `renames.py` | Generated names → readable `component-part-property` names. |
| `designed-defaults.json` | Defaults for the 20 core roles (stock LibreNMS dark), the derived roles, and every token the three skins all set differently. |
| `phase1b-map.json` | The rename map and each token's default source, written by `phase1b.py`. |
| `inventory.py` | The analysis that sorted tokens into those default sources. |
