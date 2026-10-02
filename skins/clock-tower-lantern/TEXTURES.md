# Textures — Clock Tower

`textures/gears.png` is a faint tile of two cogwheel outlines and a ring of hour ticks, tiled over the
page background behind a pool of light. It is a seamless 256 x 256 indexed PNG in the skin's brass with
sixteen levels of transparency, a few hundred pixels of ink and nothing else.

It is **computed, not drawn or photographed**. `scripts/make-clock-tower.py` writes it (and both Clock
Tower skins' `skin.css`, `skin.json` and `graph.conf`, from one template) from fixed numbers: a gear is
a circle whose radius follows a rounded square wave in the angle, with a hub and spokes. There is no
image source to credit or license, and running the script again gives the same file. Nothing touches
the tile's edge, so it has no seam.

```
python scripts/make-clock-tower.py            # rewrite both skins
python scripts/make-clock-tower.py --check    # fail if the files differ from what the script writes
```

(Needs numpy and Pillow to write the textures; `--check` needs only Python. The unit run calls it.)
`skin.css` declares the texture like any skin:

```css
--tx-gears: url("textures/gears.png");
--ts-body-bg-image: var(--tx-gears), var(--p-page);
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it in the published
`skin.css` as a `data:` URL, so no image file is served.
