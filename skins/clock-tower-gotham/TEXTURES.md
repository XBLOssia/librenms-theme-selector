# Textures — Clock Tower Gotham

`textures/tracery.png` is a faint 128 x 256 tile of a masonry wall with one lit window in it, tiled over
the page background behind a pool of lamplight. The stone is the blue of masonry in shadow (courses of
ashlar 32px high, vertical joints staggered by half a block); the window is a pointed (lancet) arch with a
central mullion, an oculus in its head and a sill, its glass a faint wash of the lamp's yellow. It is an
indexed PNG of two colours with sixteen levels of transparency each, about a kilobyte, and nothing else.

It is **computed, not drawn or photographed**. `scripts/make-clock-tower.py` writes it (and all three Clock
Tower skins' `skin.css`, `skin.json` and `graph.conf`, from their templates) from fixed numbers: the window
is set out the way a mason sets out a lancet, two arcs each struck from the opposite springing point, and the
joints are straight lines. There is no image source to credit or license, and running the script again gives
the same file. Only the horizontal joints reach the tile's edges, and they repeat, so it has no seam.

```
python scripts/make-clock-tower.py            # rewrite every Clock Tower skin
python scripts/make-clock-tower.py --check    # fail if the files differ from what the script writes
```

(Needs numpy and Pillow to write the textures; `--check` needs only Python. The unit run calls it.)
`skin.css` declares the texture like any skin:

```css
--tx-tracery: url("textures/tracery.png");
--ts-body-bg-image: var(--tx-tracery), var(--p-page);
--ts-body-bg-size: 128px 256px, 100% 100%, 100% 100%;
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it in the published
`skin.css` as a `data:` URL, so no image file is served.
