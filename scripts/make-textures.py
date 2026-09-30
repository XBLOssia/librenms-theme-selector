"""Generate the other seamless background tiles: diamond plate, crystal, and water.

    python scripts/make-textures.py plate   skins/terran/textures/plate.png
    python scripts/make-textures.py crystal skins/protoss/textures/crystal.png
    python scripts/make-textures.py waves   path/to/waves.png
    add --preview for a 3 x 3 repetition next to the file (plate-preview.png...)

Like scripts/make-creep.py, every tile is computed from fixed numbers, so there is no
image source or licence to credit, the same command writes the same file, and every
layer is periodic in both axes, so the tile repeats without a seam.

  plate    64 x 64   tread ("diamond") plate: raised lozenges in a 2 x 2 pattern of
                     alternating 45 degree angles, lit from the top left.
  crystal  256 x 256 angular facets (an anisotropic cellular field), each a slightly
                     different shade, with bright edges and a few gold ones.
  waves    256 x 128 gentle ripples on water: thin pale crests on sine paths with
                     whole-number wavelengths, and broad faint swells between them.

Each one is written as an indexed PNG with no metadata (what the plugin requires of a
texture that ships in this package) and is mostly transparent, so the skin's own
background shows through. Needs numpy and Pillow (development only).
"""
import os
import sys

import numpy as np
from PIL import Image


def smoothstep(a, b, x):
    t = np.clip((x - a) / (b - a), 0, 1)
    return t * t * (3 - 2 * t)


def over(layers):
    """Composite RGBA float layers (each HxWx4, straight alpha 0..1), first is the bottom."""
    out = layers[0]
    for top in layers[1:]:
        a_t = top[..., 3:4]
        a_o = out[..., 3:4]
        a = a_t + a_o * (1 - a_t)
        rgb = (top[..., :3] * a_t + out[..., :3] * a_o * (1 - a_t)) / np.maximum(a, 1e-6)
        out = np.concatenate([rgb, a], axis=-1)
    return out


def solid(w, h, rgb, alpha):
    a = np.broadcast_to(np.asarray(alpha, dtype=np.float64), (h, w))[..., None]
    c = np.broadcast_to(np.asarray(rgb, dtype=np.float64) / 255.0, (h, w, 3))
    return np.concatenate([c, a], axis=-1)


def to_image(rgba):
    a = np.clip(rgba, 0, 1)
    arr = np.dstack([a[..., :3] * 255, a[..., 3] * 255]).round().astype(np.uint8)
    return Image.fromarray(arr, 'RGBA')


# ---- diamond plate ---------------------------------------------------------------

def plate():
    n = 64
    ys, xs = np.mgrid[0:n, 0:n].astype(np.float64)
    height = np.zeros((n, n))
    half_len, half_w = 13.5, 4.6
    # a 2 x 2 arrangement: the angle alternates in a checkerboard
    lugs = [(16, 16, 45), (48, 16, -45), (16, 48, -45), (48, 48, 45)]
    for cx, cy, deg in lugs:
        ang = np.radians(deg)
        ux, uy = np.cos(ang), np.sin(ang)
        best = np.full((n, n), 1e9)
        for ox in (-n, 0, n):
            for oy in (-n, 0, n):
                px = xs - (cx + ox)
                py = ys - (cy + oy)
                t = np.clip(px * ux + py * uy, -half_len + half_w, half_len - half_w)
                dx = px - t * ux
                dy = py - t * uy
                best = np.minimum(best, np.sqrt(dx * dx + dy * dy))
        height = np.maximum(height, smoothstep(half_w, half_w - 3.4, best))
    # normals by wrapped differences
    gx = (np.roll(height, -1, axis=1) - np.roll(height, 1, axis=1)) / 2
    gy = (np.roll(height, -1, axis=0) - np.roll(height, 1, axis=0)) / 2
    light = -(gx * 0.707 + gy * 0.707) * 2.6          # from the top left
    lit = np.clip(light, 0, 1)
    dark = np.clip(-light, 0, 1)
    top = height * 0.5
    return over([
        solid(n, n, (0, 0, 0), 0.16 * (1 - height)),    # the flat between the lugs sits a little darker
        solid(n, n, (150, 165, 180), 0.13 * top),       # a sheen on the raised tops
        solid(n, n, (235, 243, 250), 0.50 * lit),       # the lit flank
        solid(n, n, (0, 0, 0), 0.55 * dark),            # the shaded flank
    ])


# ---- crystal ---------------------------------------------------------------------

def crystal():
    n = 256
    rng = np.random.default_rng(1776)
    cells = 7
    step = n / cells
    gy, gx = np.mgrid[0:cells, 0:cells]
    px = ((gx + rng.uniform(0.1, 0.9, gx.shape)) * step).ravel()
    py = ((gy + rng.uniform(0.1, 0.9, gy.shape)) * step).ravel()
    shade = rng.uniform(0.0, 1.0, px.size)
    gold = rng.uniform(0, 1, px.size) < 0.14
    ys, xs = np.mgrid[0:n, 0:n].astype(np.float64)
    # anisotropic distance: facets run long at a steep angle, like shards
    ang = np.radians(62)
    ca, sa = np.cos(ang), np.sin(ang)
    d = np.empty((px.size, n, n))
    for k in range(px.size):
        dx = xs - px[k]
        dy = ys - py[k]
        dx = (dx + n / 2) % n - n / 2
        dy = (dy + n / 2) % n - n / 2
        u = dx * ca + dy * sa
        v = -dx * sa + dy * ca
        d[k] = np.sqrt((u / 1.9) ** 2 + (v * 1.0) ** 2)
    order = np.argsort(d, axis=0)
    f1 = np.take_along_axis(d, order[:1], axis=0)[0]
    f2 = np.take_along_axis(d, order[1:2], axis=0)[0]
    owner = order[0]
    ox = (xs - px[owner] + n / 2) % n - n / 2
    oy = (ys - py[owner] + n / 2) % n - n / 2
    across = np.clip(0.5 + (ox * 0.55 + oy * 0.83) / 36.0, 0, 1)      # light falls across each face
    edge = 1 - smoothstep(0.0, 2.6, f2 - f1)
    cell_shade = shade[owner]
    is_gold = gold[owner]
    body_a = 0.03 + 0.20 * cell_shade * (0.35 + 0.65 * across)
    # a brighter wash on one side of each facet (a cheap highlight across the face)
    face = smoothstep(0.0, 1.0, np.clip(f1 / 22.0, 0, 1))
    return over([
        solid(n, n, (55, 125, 215), body_a * (0.45 + 0.55 * face)),
        solid(n, n, (150, 215, 255), 0.24 * edge),
        solid(n, n, (236, 190, 92), 0.42 * edge * is_gold),
        solid(n, n, (230, 250, 255), 0.20 * (edge ** 6)),
        solid(n, n, (190, 230, 255), 0.10 * np.clip(across - 0.72, 0, 1) * 3.5 * cell_shade),
    ])


# ---- waves -----------------------------------------------------------------------

def waves():
    w, h = 256, 128
    ys, xs = np.mgrid[0:h, 0:w].astype(np.float64)
    tau = 2 * np.pi
    u = xs / w
    v = ys / h
    lines = np.zeros((h, w))
    # several crests; integer wavelengths in both axes keep the tile periodic
    specs = [(5, 2, 0.040, 0.00, 0.85), (4, 3, 0.035, 0.31, 0.60), (3, 5, 0.030, 0.67, 0.42)]
    for count, freq, amp, phase, strength in specs:
        path = v * count + amp * count * np.sin(tau * (u * freq + phase)) + 0.3 * amp * count * np.sin(tau * (u * (freq * 2 + 1) + phase * 3))
        crest = 1 - smoothstep(0.0, 0.09, np.abs(np.sin(np.pi * path)))
        lines = np.maximum(lines, crest * strength)
    swell = 0.5 + 0.5 * np.sin(tau * (v * 2 + 0.12 * np.sin(tau * u * 2)))
    swell2 = 0.5 + 0.5 * np.sin(tau * (v * 3 + u * 1 + 0.2))
    return over([
        solid(w, h, (60, 110, 190), 0.030 + 0.070 * swell * swell2),
        solid(w, h, (157, 184, 232), 0.26 * lines),
    ])


BUILDERS = {'plate': (plate, 255), 'crystal': (crystal, 255), 'waves': (waves, 200)}


def main(argv):
    kinds = [a for a in argv if not a.startswith('--')]
    if len(kinds) != 2 or kinds[0] not in BUILDERS:
        print(__doc__)
        return 1
    kind, out = kinds
    build, colours = BUILDERS[kind]
    img = to_image(build())
    w, h = img.size
    # wrap-around seam vs an interior line, as a sanity check
    a = np.asarray(img).astype(np.float64)
    print(f'{kind}: {w}x{h}; seam {np.abs(a[:, 0] - a[:, -1]).mean() + np.abs(a[0] - a[-1]).mean():.2f} '
          f'vs interior {np.abs(a[:, w // 2] - a[:, w // 2 + 1]).mean() + np.abs(a[h // 2] - a[h // 2 + 1]).mean():.2f}')
    pal = img.quantize(colors=colours, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    pal.save(out, 'PNG', optimize=True)
    print(f'wrote {out}: {os.path.getsize(out)} bytes')
    if '--preview' in argv:
        tile = Image.open(out).convert('RGBA')
        bg = Image.new('RGBA', (w * 3, h * 3), {'plate': (34, 38, 44, 255), 'crystal': (12, 14, 26, 255), 'waves': (0, 15, 38, 255)}[kind])
        for i in range(3):
            for j in range(3):
                bg.alpha_composite(tile, (i * w, j * h))
        pv = bg.convert('RGB')
        if w < 100:
            pv = pv.resize((pv.width * 3, pv.height * 3), Image.NEAREST)
        pv.save(out.replace('.png', '-preview.png'))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
