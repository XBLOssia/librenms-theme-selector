"""Generate skins/zerg/textures/creep.png: a seamless, tileable "creep" texture.

    python scripts/make-creep.py [out.png] [--preview]

Nothing here is a photograph, a scan or a model's output, so the texture has no
licence history: it is computed, from a fixed random seed, by the code below, and
the result is the same every time. It is a 256x256 tile that repeats without a
seam, because every layer is periodic by construction (noise filtered in the
frequency domain, and distortions sampled modulo the tile size).

What it draws, in the Zerg palette: a dark, organic membrane (soft blotches of
plum), a network of fine veins (the zero-contours of a warped noise field, which
always close up into cells and branches), and scattered small luminous nodes.
It is mostly transparent so it sits on top of the skin's background gradients.

Needs numpy and Pillow (development only; the plugin itself never runs this).
"""
import sys

import numpy as np
from PIL import Image

N = 256
SEED = 42


def periodic_noise(rng, beta):
    """Zero-mean, unit-variance noise whose spectrum falls off as 1/f^beta. Periodic in both axes."""
    white = rng.standard_normal((N, N))
    f = np.fft.fft2(white)
    fx = np.fft.fftfreq(N)[:, None]
    fy = np.fft.fftfreq(N)[None, :]
    r = np.sqrt(fx * fx + fy * fy)
    r[0, 0] = 1
    f = f / r ** beta
    f[0, 0] = 0
    out = np.fft.ifft2(f).real
    return (out - out.mean()) / out.std()


def warp(field, dx, dy):
    """Sample `field` at (x + dx, y + dy), wrapping at the edges (bilinear)."""
    ys, xs = np.mgrid[0:N, 0:N].astype(np.float64)
    sx = (xs + dx) % N
    sy = (ys + dy) % N
    x0 = np.floor(sx).astype(int)
    y0 = np.floor(sy).astype(int)
    x1 = (x0 + 1) % N
    y1 = (y0 + 1) % N
    fx = sx - x0
    fy = sy - y0
    return (field[y0, x0] * (1 - fx) * (1 - fy) + field[y0, x1] * fx * (1 - fy)
            + field[y1, x0] * (1 - fx) * fy + field[y1, x1] * fx * fy)


def worley(rng, cells=9):
    """Cellular noise on a torus: distances to the nearest (F1) and second-nearest (F2) of a jittered grid of points."""
    step = N / cells
    gy, gx = np.mgrid[0:cells, 0:cells]
    px = ((gx + rng.uniform(0.15, 0.85, gx.shape)) * step).ravel()
    py = ((gy + rng.uniform(0.15, 0.85, gy.shape)) * step).ravel()
    ys, xs = np.mgrid[0:N, 0:N].astype(np.float64)
    d = np.empty((px.size, N, N), dtype=np.float32)
    for k in range(px.size):
        dx = np.abs(xs - px[k])
        dx = np.minimum(dx, N - dx)
        dy = np.abs(ys - py[k])
        dy = np.minimum(dy, N - dy)
        d[k] = np.sqrt(dx * dx + dy * dy)
    d.sort(axis=0)
    return d[0].astype(np.float64) / step, d[1].astype(np.float64) / step


def smoothstep(a, b, x):
    t = np.clip((x - a) / (b - a), 0, 1)
    return t * t * (3 - 2 * t)


def build():
    rng = np.random.default_rng(SEED)

    # 1. The membrane: large soft blotches, bent by a second field so they look grown, not blurred.
    base = periodic_noise(rng, 2.3)
    bend_x = periodic_noise(rng, 2.0) * 9
    bend_y = periodic_noise(rng, 2.0) * 9
    blotch = smoothstep(-0.9, 1.3, warp(base, bend_x, bend_y))

    # 2. Veins: where a warped noise field crosses zero. Two scales, the finer one fainter.
    def veins(beta, width, amp, seed_bend):
        f = periodic_noise(rng, beta)
        f = warp(f, periodic_noise(rng, 2.0) * amp, periodic_noise(rng, 2.0) * amp)
        return 1 - smoothstep(0, width, np.abs(f))

    coarse = veins(1.9, 0.11, 14, 0)
    fine = veins(1.45, 0.07, 6, 1)
    vein = np.clip(coarse + 0.55 * fine, 0, 1) * (0.35 + 0.65 * blotch)

    # 2b. Tissue: irregular cells, each a soft dome, with a fine bright membrane between them.
    f1, f2 = worley(rng)
    bend = periodic_noise(rng, 2.0) * 7
    bend2 = periodic_noise(rng, 2.0) * 7
    f1 = warp(f1, bend, bend2)
    f2 = warp(f2, bend, bend2)
    dome = 1 - smoothstep(0.0, 0.62, f1)
    rim = 1 - smoothstep(0.0, 0.085, f2 - f1)
    vein = np.clip(vein * 0.7 + rim * (0.45 + 0.55 * blotch), 0, 1)

    # 3. Nodes: small bright spots where a high-frequency field peaks, more of them on the thick membrane.
    grain = periodic_noise(rng, 0.25)
    nodes = smoothstep(2.55, 3.3, grain + 0.6 * (blotch - 0.5))

    # 4. Colour. Premultiplied mixing of three layers, then back to straight alpha.
    def layer(rgb, alpha):
        return np.array(rgb, dtype=np.float64)[None, None, :] / 255.0 * alpha[..., None], alpha

    membrane_a = 0.05 + 0.20 * blotch + 0.13 * dome * (0.4 + 0.6 * blotch)
    vein_a = 0.54 * vein
    node_a = 0.80 * nodes

    m_rgb, _ = layer((92, 34, 82), membrane_a)
    v_rgb, _ = layer((205, 78, 150), vein_a)
    n_rgb, _ = layer((255, 168, 120), node_a)

    # "over" composition: nodes over veins over membrane
    a_v = vein_a + membrane_a * (1 - vein_a)
    rgb_v = v_rgb + m_rgb * (1 - vein_a)[..., None]
    a = node_a + a_v * (1 - node_a)
    rgb = n_rgb + rgb_v * (1 - node_a)[..., None]
    straight = np.where(a[..., None] > 1e-6, rgb / np.maximum(a[..., None], 1e-6), 0)
    out = np.dstack([np.clip(straight, 0, 1) * 255, np.clip(a, 0, 1) * 255]).round().astype(np.uint8)
    return Image.fromarray(out, 'RGBA')


def seam_error(img):
    """Mean absolute difference across the wrap-around edges vs. across an interior line."""
    a = np.asarray(img).astype(np.float64)
    edge = np.abs(a[:, 0] - a[:, -1]).mean() + np.abs(a[0] - a[-1]).mean()
    inner = np.abs(a[:, 100] - a[:, 101]).mean() + np.abs(a[100] - a[101]).mean()
    return edge, inner


def main(argv):
    out = next((a for a in argv if not a.startswith('--')), 'skins/zerg/textures/creep.png')
    img = build()
    edge, inner = seam_error(img)
    print(f'seam: {edge:.2f} across the wrap-around vs {inner:.2f} across an interior line (similar = seamless)')
    # An indexed image keeps it small; FASTOCTREE keeps the alpha.
    pal = img.quantize(colors=200, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    pal.save(out, 'PNG', optimize=True)
    import os
    print(f'wrote {out}: {os.path.getsize(out)} bytes')
    if '--preview' in argv:
        # 3x3 repetition over the skin's backdrop colour, to judge the tiling
        bg = Image.new('RGBA', (N * 3, N * 3), (23, 12, 23, 255))
        tile = Image.open(out).convert('RGBA')
        for i in range(3):
            for j in range(3):
                bg.alpha_composite(tile, (i * N, j * N))
        bg.convert('RGB').save(out.replace('.png', '-preview.png'))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
