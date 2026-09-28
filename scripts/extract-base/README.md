# extract-base

Phase 1 tooling (docs/PLUGIN.md): turns the three standalone skins
(`skins/<id>/<id>.css`) into the shared `base/base.css` plus a token file per
skin (`skins/<id>/skin.css`), and checks the result is equivalent.

Run from this directory:

```bash
python extract.py ../..     # regenerate base.css and every skin.css
python roundtrip.py ../..   # text-level equivalence check; exit 1 on any mismatch
```

Then open `harness/compare.html?all=1` (harness server, port 8777) for the
rendered check: computed styles of every element, original vs base + tokens.

`extract.py` overwrites `base.css` and the `skin.css` files. Once Phase 1b
starts editing them by hand it's retired, and only the two checks stay useful,
for as long as the original `<id>.css` files exist.

## Hand decisions

| File | What it holds |
|---|---|
| `roles.json` | Names for the shared roles: which palette entry each skin uses for the same job (e.g. `edge`/`edge`/`carapace` → `border`). |
| `resolved.json` | Stock values for properties a skin never declared but another did, per skin and token. Measured from the original skin in the harness where the element renders there, otherwise read from stock CSS (`hr`, `.modal-content`, `.label-info`). |
| `split.json` | Rules split into one rule per selector because their parts have different stock values (`html`/`body` font, `.btn-*`/`.lnms-btn-*` shadows, `.label`/`.badge` padding). |
| `normalize.json` | Declarations rewritten before merging so one token can express every skin (`hr { border: 0 }` → `border-width`). |
| `extra.json` | Rules added to the base that no original had: `.badge-navbar-user` padding, so Terran's stock 4px survives the shared badge padding. |
| `fallbacks.json` | Fallbacks for tokens a skin doesn't set. Unused while every skin resolves every token; the starting point for Phase 1b. |
