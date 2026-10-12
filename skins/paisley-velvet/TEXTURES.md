# Textures — Paisley

`textures/paisley.png` is a faint print of paisley (boteh) motifs in three sizes, tiled over the page
background behind a pool of light. It is a seamless 256 x 256 indexed PNG in three inks (violet, peach and
gold) with seven levels of transparency each, and nothing else.

It is **computed, not drawn, photographed or traced**. `scripts/make-paisley.py` writes it (and both Paisley
skins' `skin.css`, `skin.json` and `graph.conf`, from one template) from fixed numbers: a paisley is a run of
discs along an arc, their radius shrinking to a point; it is drawn as an outline, an inner outline, a row of
beads down its spine and a ring round its head, and a few small florets and dots fill the gaps. There is no
image source to credit or license, and running the script again gives the same file. Every shape wraps
round the tile's edges, so it has no seam.

```
python scripts/make-paisley.py                      # rewrite both skins
python scripts/make-paisley.py --check              # fail if the files differ from what the script writes
python scripts/make-paisley.py --preview some/dir   # and a 3 x 3 repetition of each tile there
```

(Needs numpy and Pillow to write the textures; `--check` needs only Python. The unit run calls it. Put the
preview somewhere else: the plugin refuses a second PNG in a skin's `textures/`.) `skin.css` declares the
texture like any skin:

```css
--tx-paisley: url("textures/paisley.png");
--ts-body-bg-image: var(--tx-paisley), var(--p-page);
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it in the published
`skin.css` as a `data:` URL, so no image file is served.
