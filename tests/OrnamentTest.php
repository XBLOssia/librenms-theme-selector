<?php

declare(strict_types=1);

/*
 * The ornament layer (docs/ORNAMENTS.md): what an uploaded skin may paint, and
 * the fixed mechanics in base.css that keep it decorative.
 */

const FRAME_SLOTS = ['tl', 'tr', 'bl', 'br', 'top', 'right', 'bottom', 'left'];

/** The rules in base.css that belong to the ornament layer, selector => declarations. */
function ornament_rules(): array
{
    $css = (string) file_get_contents(__DIR__ . '/../base/base.css');
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);
    $rules = [];
    foreach ($m as $rule) {
        $sel = trim(preg_replace('/\s+/', ' ', $rule[1]));
        if (str_contains($sel, 'data-ts-orn')) {
            $decls = [];
            foreach (explode(';', $rule[2]) as $d) {
                $d = trim(preg_replace('/\s+/', ' ', $d));
                if ($d !== '') {
                    $decls[] = $d;
                }
            }
            $rules[$sel] = $decls;
        }
    }

    return $rules;
}

/**
 * The clip-path polygon of a panel or widget, written out independently of base.css and of
 * scripts/gen-token-catalog.py: the box with a margin of 10000px and, into each corner, a notch that
 * removes the cut triangle grown by 2px on the two sides along the box edges (its hypotenuse stays on
 * the same line), joined to the outside by a zero-width slit. The growth is 2px at a corner that is
 * cut and none at one that is not (min(cut * 1000, 2px)), so an uncut corner removes nothing.
 */
function ornament_polygon(string $e): string
{
    $c = fn (string $k) => "var(--ts-$e-chamfer-$k, var(--ts-$e-chamfer))";
    $r = "var(--ts-$e-chamfer-rise, 1)";
    $v = fn (string $k) => "calc({$c($k)} * $r)";
    $g = fn (string $k) => "min(calc({$c($k)} * 1000), 2px)";
    $ng = fn (string $k) => "calc({$g($k)} * -1)";
    $pe = fn (string $k) => "calc(100% + {$g($k)})";
    $m = '10000px';
    $pm = "calc(100% + $m)";
    $pts = [
        ["-$m", "-$m"], [$pm, "-$m"],
        [$pm, $ng('tr')], ["calc(100% - {$c('tr')} - {$g('tr')} / $r)", $ng('tr')], [$pe('tr'), "calc({$v('tr')} + {$g('tr')} * $r)"], [$pe('tr'), $ng('tr')], [$pm, $ng('tr')],
        [$pm, $pe('br')], [$pe('br'), $pe('br')], [$pe('br'), "calc(100% - {$v('br')} - {$g('br')} * $r)"], ["calc(100% - {$c('br')} - {$g('br')} / $r)", $pe('br')], [$pm, $pe('br')],
        [$pm, $pm], ["-$m", $pm],
        ["-$m", $pe('bl')], ["calc({$c('bl')} + {$g('bl')} / $r)", $pe('bl')], [$ng('bl'), "calc(100% - {$v('bl')} - {$g('bl')} * $r)"], [$ng('bl'), $pe('bl')], ["-$m", $pe('bl')],
        ["-$m", $ng('tl')], [$ng('tl'), $ng('tl')], [$ng('tl'), "calc({$v('tl')} + {$g('tl')} * $r)"], ["calc({$c('tl')} + {$g('tl')} / $r)", $ng('tl')], ["-$m", $ng('tl')],
    ];

    return 'polygon(' . implode(', ', array_map(fn ($p) => "$p[0] $p[1]", $pts)) . ')';
}

/** The widget's background layers: the four cut-edge lines first (so they are drawn over the frame bars), then the eight frame slots, then the skin's own layers. */
function ornament_widget_background(): array
{
    $stroke = fn (string $dir) => "linear-gradient(to $dir, transparent 0, transparent calc(50% - 1px), var(--ts-widget-cut-stroke, transparent) calc(50% - 1px), var(--ts-widget-cut-stroke, transparent) calc(50% + 1px), transparent calc(50% + 1px))";
    $cut = fn (string $k) => "var(--ts-widget-chamfer-$k, var(--ts-widget-chamfer, 0px))";
    $slots = array_map(fn ($k) => "var(--ts-widget-frame-$k)", FRAME_SLOTS);
    $image = array_merge([$stroke('top right'), $stroke('top left'), $stroke('bottom right'), $stroke('bottom left')], $slots, ['var(--ts-widget-bg-image)']);
    $size = array_merge(
        array_map(fn ($k) => "{$cut($k)} calc({$cut($k)} * var(--ts-widget-chamfer-rise, 1))", ['bl', 'br', 'tl', 'tr']),
        ['32px 32px', '32px 32px', '32px 32px', '32px 32px', '100% 12px', '12px 100%', '100% 12px', '12px 100%'],
        array_fill(0, 8, 'auto'),
    );
    $position = array_merge(
        ['left bottom', 'right bottom', 'left top', 'right top'],
        ['left top', 'right top', 'left bottom', 'right bottom', 'left top', 'right top', 'left bottom', 'left top'],
        array_fill(0, 8, '0 0'),
    );
    $repeat = array_merge(array_fill(0, 12, 'no-repeat'), array_fill(0, 8, 'repeat'));

    return [
        'background-image: ' . implode(', ', $image),
        'background-size: ' . implode(', ', $size),
        'background-position: ' . implode(', ', $position),
        'background-repeat: ' . implode(', ', $repeat),
    ];
}

function test_ornaments(): void
{
    T::group('ornaments: the fixed mechanics in base.css');
    $rules = ornament_rules();
    $gate = 'html.dark:has(link[data-ts-orn])';

    // Every declaration of the ornament layer, written out. A change to any of
    // them (a higher z-index, pointer-events, text in content, a bigger
    // overhang, a token in place of a constant) fails here and needs a review.
    $expected = [
        "$gate .panel" => [
            'position: relative',
            'isolation: isolate',
            'clip-path: ' . ornament_polygon('panel'),
        ],
        "$gate .panel::before" => [
            'content: ""',
            'position: absolute',
            'inset: -8px',
            'z-index: -1',
            'pointer-events: none',
            'background-image: var(--ts-frame-tl), var(--ts-frame-tr), var(--ts-frame-bl), var(--ts-frame-br), var(--ts-frame-top), var(--ts-frame-right), var(--ts-frame-bottom), var(--ts-frame-left)',
            'background-size: 32px 32px, 32px 32px, 32px 32px, 32px 32px, 100% 12px, 12px 100%, 100% 12px, 12px 100%',
            'background-position: left top, right top, left bottom, right bottom, left top, right top, left bottom, left top',
            'background-repeat: no-repeat',
            'clip-path: polygon(evenodd, 0 0, 100% 0, 100% 100%, 0 100%, 0 0, 32px 32px, 32px calc(100% - 32px), calc(100% - 32px) calc(100% - 32px), calc(100% - 32px) 32px, 32px 32px)',
            'filter: drop-shadow(0 0 8px var(--ts-frame-glow))',
            'animation: ts-breathe var(--ts-frame-breathe) ease-in-out infinite',
        ],
        "$gate .panel:is(:hover, :has(.open))" => [
            'z-index: 1035',
        ],
        "$gate .grid-stack .grid-stack-item:hover" => [
            'z-index: 1035',
        ],
        "$gate .panel > .panel-heading" => [
            'isolation: isolate',
        ],
        "$gate .panel > .panel-heading::before" => [
            'content: ""',
            'position: absolute',
            'left: 0',
            'top: 0',
            'bottom: 0',
            'width: 12px',
            'z-index: -1',
            'pointer-events: none',
            'background-color: transparent',
            'background-image: var(--ts-heading-marker)',
            'background-size: var(--ts-heading-marker-size)',
            'background-position: var(--ts-heading-marker-position)',
            'background-repeat: no-repeat',
            'box-shadow: none',
            'border-radius: 0',
            'filter: drop-shadow(0 0 8px var(--ts-heading-marker-glow))',
            'animation: ts-breathe var(--ts-heading-marker-breathe) ease-in-out infinite',
        ],
        "$gate .panel > .panel-heading::after" => [
            'content: ""',
            'position: absolute',
            'left: 0',
            'right: 0',
            'top: 0',
            'bottom: 0',
            'z-index: -1',
            'pointer-events: none',
            'background-color: transparent',
            'background-image: var(--ts-heading-strip)',
            'background-size: var(--ts-heading-strip-size)',
            'background-position: var(--ts-heading-strip-position)',
            'background-repeat: var(--ts-heading-strip-repeat)',
            'opacity: var(--ts-heading-strip-opacity)',
            'filter: drop-shadow(0 0 8px var(--ts-heading-strip-glow))',
            'animation: ts-breathe var(--ts-heading-strip-breathe) ease-in-out infinite',
        ],
        "$gate .navbar-default" => [
            'isolation: isolate',
        ],
        "$gate .navbar-default::before" => [
            'content: ""',
            'display: block',
            'position: absolute',
            'left: 0',
            'right: 0',
            'top: 0',
            'height: 8px',
            'z-index: -1',
            'pointer-events: none',
            'background-color: transparent',
            'background-image: var(--ts-navbar-strip-top)',
            'background-size: var(--ts-navbar-strip-top-size)',
            'background-position: left top',
            'background-repeat: var(--ts-navbar-strip-top-repeat)',
            'opacity: var(--ts-navbar-strip-top-opacity)',
            'filter: drop-shadow(0 0 8px var(--ts-navbar-strip-top-glow))',
            'animation: ts-breathe var(--ts-navbar-strip-top-breathe) ease-in-out infinite',
        ],
        "$gate .navbar-default::after" => [
            'content: ""',
            'display: block',
            'position: absolute',
            'left: 0',
            'right: 0',
            'bottom: -8px',
            'height: 12px',
            'z-index: -1',
            'pointer-events: none',
            'background-color: transparent',
            'background-image: var(--ts-navbar-strip-bottom)',
            'background-size: var(--ts-navbar-strip-bottom-size)',
            'background-position: left bottom',
            'background-repeat: var(--ts-navbar-strip-bottom-repeat)',
            'opacity: var(--ts-navbar-strip-bottom-opacity)',
            'box-shadow: none',
            'filter: drop-shadow(0 0 8px var(--ts-navbar-strip-bottom-glow))',
            'animation: ts-breathe var(--ts-navbar-strip-bottom-breathe) ease-in-out infinite',
        ],
        "$gate .grid-stack .grid-stack-item-content" => [
            ...ornament_widget_background(),
            'clip-path: ' . ornament_polygon('widget'),
        ],
        "$gate .btn" => [
            'clip-path: polygon(var(--ts-btn-chamfer-tl, var(--ts-btn-chamfer)) 0, calc(100% - var(--ts-btn-chamfer-tr, var(--ts-btn-chamfer))) 0, 100% calc(var(--ts-btn-chamfer-tr, var(--ts-btn-chamfer)) * var(--ts-btn-chamfer-rise, 1)), 100% calc(100% - calc(var(--ts-btn-chamfer-br, var(--ts-btn-chamfer)) * var(--ts-btn-chamfer-rise, 1))), calc(100% - var(--ts-btn-chamfer-br, var(--ts-btn-chamfer))) 100%, var(--ts-btn-chamfer-bl, var(--ts-btn-chamfer)) 100%, 0 calc(100% - calc(var(--ts-btn-chamfer-bl, var(--ts-btn-chamfer)) * var(--ts-btn-chamfer-rise, 1))), 0 calc(var(--ts-btn-chamfer-tl, var(--ts-btn-chamfer)) * var(--ts-btn-chamfer-rise, 1)))',
        ],
        "$gate .label" => [
            'clip-path: polygon(var(--ts-label-chamfer-tl, var(--ts-label-chamfer)) 0, calc(100% - var(--ts-label-chamfer-tr, var(--ts-label-chamfer))) 0, 100% calc(var(--ts-label-chamfer-tr, var(--ts-label-chamfer)) * var(--ts-label-chamfer-rise, 1)), 100% calc(100% - calc(var(--ts-label-chamfer-br, var(--ts-label-chamfer)) * var(--ts-label-chamfer-rise, 1))), calc(100% - var(--ts-label-chamfer-br, var(--ts-label-chamfer))) 100%, var(--ts-label-chamfer-bl, var(--ts-label-chamfer)) 100%, 0 calc(100% - calc(var(--ts-label-chamfer-bl, var(--ts-label-chamfer)) * var(--ts-label-chamfer-rise, 1))), 0 calc(var(--ts-label-chamfer-tl, var(--ts-label-chamfer)) * var(--ts-label-chamfer-rise, 1)))',
        ],
        "$gate .badge" => [
            'clip-path: polygon(var(--ts-badge-chamfer-tl, var(--ts-badge-chamfer)) 0, calc(100% - var(--ts-badge-chamfer-tr, var(--ts-badge-chamfer))) 0, 100% calc(var(--ts-badge-chamfer-tr, var(--ts-badge-chamfer)) * var(--ts-badge-chamfer-rise, 1)), 100% calc(100% - calc(var(--ts-badge-chamfer-br, var(--ts-badge-chamfer)) * var(--ts-badge-chamfer-rise, 1))), calc(100% - var(--ts-badge-chamfer-br, var(--ts-badge-chamfer))) 100%, var(--ts-badge-chamfer-bl, var(--ts-badge-chamfer)) 100%, 0 calc(100% - calc(var(--ts-badge-chamfer-bl, var(--ts-badge-chamfer)) * var(--ts-badge-chamfer-rise, 1))), 0 calc(var(--ts-badge-chamfer-tl, var(--ts-badge-chamfer)) * var(--ts-badge-chamfer-rise, 1)))',
        ],
        "$gate .badge-navbar-user.badge-danger" => [
            'animation: ts-glow var(--ts-alert-glow-period) ease-in-out infinite',
        ],
        "$gate .panel::before, html.dark:has(link[data-ts-orn]) .panel > .panel-heading::before, html.dark:has(link[data-ts-orn]) .panel > .panel-heading::after, html.dark:has(link[data-ts-orn]) .navbar-default::before, html.dark:has(link[data-ts-orn]) .navbar-default::after, html.dark:has(link[data-ts-orn]) .badge-navbar-user.badge-danger" => [
            'animation: none',
        ],
        "$gate .panel::after" => [
            'content: ""',
            'position: absolute',
            'inset: -1px',
            'z-index: 1',
            'pointer-events: none',
            'width: auto',
            'height: auto',
            'border: 0',
            'background-image: linear-gradient(to top right, transparent 50%, var(--ts-panel-cut-stroke, transparent) 50%, var(--ts-panel-cut-stroke, transparent) calc(50% + 2px), transparent calc(50% + 2px)), linear-gradient(to top left, transparent 50%, var(--ts-panel-cut-stroke, transparent) 50%, var(--ts-panel-cut-stroke, transparent) calc(50% + 2px), transparent calc(50% + 2px)), linear-gradient(to bottom right, transparent 50%, var(--ts-panel-cut-stroke, transparent) 50%, var(--ts-panel-cut-stroke, transparent) calc(50% + 2px), transparent calc(50% + 2px)), linear-gradient(to bottom left, transparent 50%, var(--ts-panel-cut-stroke, transparent) 50%, var(--ts-panel-cut-stroke, transparent) calc(50% + 2px), transparent calc(50% + 2px))',
            'background-size: var(--ts-panel-chamfer-bl, var(--ts-panel-chamfer, 0px)) calc(var(--ts-panel-chamfer-bl, var(--ts-panel-chamfer, 0px)) * var(--ts-panel-chamfer-rise, 1)), var(--ts-panel-chamfer-br, var(--ts-panel-chamfer, 0px)) calc(var(--ts-panel-chamfer-br, var(--ts-panel-chamfer, 0px)) * var(--ts-panel-chamfer-rise, 1)), var(--ts-panel-chamfer-tl, var(--ts-panel-chamfer, 0px)) calc(var(--ts-panel-chamfer-tl, var(--ts-panel-chamfer, 0px)) * var(--ts-panel-chamfer-rise, 1)), var(--ts-panel-chamfer-tr, var(--ts-panel-chamfer, 0px)) calc(var(--ts-panel-chamfer-tr, var(--ts-panel-chamfer, 0px)) * var(--ts-panel-chamfer-rise, 1))',
            'background-position: left bottom, right bottom, left top, right top',
            'background-repeat: no-repeat',
        ],
    ];
    T::ok('no rule carries the gate that has not been reviewed', array_keys($rules) === array_keys($expected), implode(' | ', array_diff(array_keys($rules), array_keys($expected))));
    foreach ($expected as $sel => $decls) {
        T::ok("the rule for $sel is exactly as reviewed", ($rules[$sel] ?? null) === $decls, json_encode($rules[$sel] ?? null));
    }
    $tokenUses = [];
    foreach ($rules as $decls) {
        foreach ($decls as $d) {
            if (preg_match_all('/var\((--[\w-]+)/', $d, $mm)) {
                foreach ($mm[1] as $name) {
                    $tokenUses[$name] = true;
                }
            }
        }
    }
    $slotTokens = array_map(fn ($s) => "--ts-frame-$s", FRAME_SLOTS);
    $widgetSlots = array_map(fn ($s) => "--ts-widget-frame-$s", FRAME_SLOTS);
    $headingTokens = ['--ts-heading-marker', '--ts-heading-marker-size', '--ts-heading-marker-position',
        '--ts-heading-strip', '--ts-heading-strip-size', '--ts-heading-strip-position', '--ts-heading-strip-repeat', '--ts-heading-strip-opacity'];
    $navTokens = [];
    foreach (['top', 'bottom'] as $edge) {
        foreach (['', '-size', '-repeat', '-opacity'] as $suffix) {
            $navTokens[] = "--ts-navbar-strip-$edge$suffix";
        }
    }
    $chamferTokens = [];
    foreach (['btn', 'label', 'badge'] as $e) {
        foreach (['', '-tl', '-tr', '-br', '-bl', '-rise'] as $k) {
            $chamferTokens[] = "--ts-$e-chamfer$k";
        }
    }
    $motionTokens = ['--ts-breathe-low', '--ts-breathe-high', '--ts-alert-glow-period', '--ts-alert-glow-low', '--ts-alert-glow-high'];
    $layerNames = ['frame', 'heading-marker', 'heading-strip', 'navbar-strip-top', 'navbar-strip-bottom'];
    foreach ($layerNames as $n) {
        $motionTokens[] = "--ts-$n-breathe";
        $motionTokens[] = "--ts-$n-glow";
    }
    $cutTokens = ['--ts-panel-cut-stroke', '--ts-widget-cut-stroke'];
    foreach (['panel', 'widget'] as $e) {
        foreach (['', '-tl', '-tr', '-br', '-bl', '-rise'] as $k) {
            $cutTokens[] = "--ts-$e-chamfer$k";
        }
    }
    $paint = array_merge($slotTokens, $headingTokens, $navTokens, $widgetSlots, $chamferTokens, $motionTokens, $cutTokens, ['--ts-widget-bg-image']);
    $read = array_keys($tokenUses);
    sort($read);
    // The four the keyframes read (not a gated rule) are checked in the motion group below.
    $want = array_values(array_diff($paint, ['--ts-breathe-low', '--ts-breathe-high', '--ts-alert-glow-low', '--ts-alert-glow-high']));
    sort($want);
    T::ok('the only tokens the layers read are paint tokens', $read === $want, implode(',', array_diff($read, $want)) . ' / ' . implode(',', array_diff($want, $read)));
    T::ok('the layer is painted behind content (negative z-index)', in_array('z-index: -1', $rules["$gate .panel::before"] ?? [], true));
    T::ok('and cannot take a click', in_array('pointer-events: none', $rules["$gate .panel::before"] ?? [], true));
    T::ok('and has no text to show', in_array('content: ""', $rules["$gate .panel::before"] ?? [], true));

    T::group('ornaments: what an upload may set');
    $cat = catalog();
    foreach ($slotTokens as $t) {
        T::ok("$t exists and is settable by upload", $cat->has($t) && ! $cat->isStructural($t));
        T::ok("$t is an image token", in_array('image', $cat->kinds($t), true), json_encode($cat->kinds($t)));
    }
    foreach (array_merge($widgetSlots, ['--ts-heading-marker', '--ts-heading-strip', '--ts-navbar-strip-top', '--ts-navbar-strip-bottom']) as $t) {
        T::ok("$t is settable by upload and is an image token", $cat->has($t) && ! $cat->isStructural($t) && in_array('image', $cat->kinds($t), true));
    }
    foreach (['--ts-heading-marker-size', '--ts-heading-marker-position', '--ts-heading-strip-size', '--ts-heading-strip-position', '--ts-navbar-strip-top-size', '--ts-navbar-strip-bottom-size'] as $t) {
        T::ok("$t is settable and bounded like a length", $cat->has($t) && ! $cat->isStructural($t) && $cat->maxPx($t) <= 64, json_encode($cat->kinds($t)));
    }
    foreach (['--ts-heading-strip-opacity', '--ts-navbar-strip-top-opacity', '--ts-navbar-strip-bottom-opacity', '--ts-heading-strip-repeat', '--ts-navbar-strip-top-repeat'] as $t) {
        T::ok("$t is settable by upload", $cat->has($t) && ! $cat->isStructural($t));
    }
    foreach (['tl', 'tr', 'br', 'bl'] as $c) {
        T::ok("--ts-panel-radius-$c is settable and is a length", ! $cat->isStructural("--ts-panel-radius-$c") && in_array('length', $cat->kinds("--ts-panel-radius-$c"), true));
        T::ok("--ts-widget-radius-$c is settable and is a length", ! $cat->isStructural("--ts-widget-radius-$c") && in_array('length', $cat->kinds("--ts-widget-radius-$c"), true));
    }

    $all = [];
    foreach (FRAME_SLOTS as $i => $s) {
        $all[] = "--ts-frame-$s: linear-gradient(" . ($i % 2 ? '90deg' : '180deg') . ", #e50832 0, #e50832 3px, #f37c2f 3px, #f37c2f 6px, transparent 6px);";
    }
    $out = css_good('a skin painting all eight slots', dark('--ts-bg: #000f26;', ...$all));
    T::ok('its output carries them', $out !== null && substr_count($out, '--ts-frame-') === 8);
    T::ok('and passes the output guard', $out !== null && Xblossia\ThemeSelector\Skin\OutputGuard::safe($out, 0));
    css_good('layered gradients in one slot', dark('--ts-bg: #000;', '--ts-frame-tl: linear-gradient(90deg, #e50832 2px, transparent 2px), linear-gradient(180deg, #e50832 2px, transparent 2px);'));
    css_good('per-corner radii', dark('--ts-bg: #000;', '--ts-panel-radius-tl: 0;', '--ts-panel-radius-tr: 16px;', '--ts-panel-radius-br: 0;', '--ts-panel-radius-bl: 64px;'));

    css_good('a heading marker: a 3px bar, 60% tall, centred', dark('--ts-bg: #000;', '--ts-heading-marker: linear-gradient(90deg, #e50832, #f37c2f);', '--ts-heading-marker-size: 3px 60%;', '--ts-heading-marker-position: left center;'));
    css_good('a heading strip of rivets', dark('--ts-bg: #000;', '--ts-heading-strip: radial-gradient(circle, #8ea0c2 0, #8ea0c2 1.5px, transparent 1.6px);', '--ts-heading-strip-size: 34px 6px;', '--ts-heading-strip-position: 10px 5px;', '--ts-heading-strip-repeat: repeat-x;', '--ts-heading-strip-opacity: .5;'));
    css_good('navbar strips', dark('--ts-bg: #000;', '--ts-navbar-strip-top: linear-gradient(90deg, transparent, #f37c2f, transparent);', '--ts-navbar-strip-top-size: 100% 3px;', '--ts-navbar-strip-top-opacity: .8;', '--ts-navbar-strip-bottom: linear-gradient(90deg, #e50832 0, #e50832 50%, #f37c2f 50%, #f37c2f 100%);', '--ts-navbar-strip-bottom-size: 100% 4px;', '--ts-navbar-strip-bottom-repeat: no-repeat;'));
    css_good('widget frames and radii', dark('--ts-bg: #000;', '--ts-widget-frame-bl: linear-gradient(90deg, #e50832 6px, transparent 6px);', '--ts-widget-frame-bottom: linear-gradient(0deg, #f37c2f 2px, transparent 2px);', '--ts-widget-radius-tl: 0;', '--ts-widget-radius-br: 10px;'));
    css_bad('a url() in a heading strip', dark('--ts-bg: #000;', '--ts-heading-strip: url(http://evil.example/a.png);'));
    css_bad('a heading marker size over the cap', dark('--ts-bg: #000;', '--ts-heading-marker-size: 65px 10px;'), 'out of range');
    css_bad('a navbar strip with a url()', dark('--ts-bg: #000;', '--ts-navbar-strip-bottom: url(a.png);'));
    css_bad('a widget frame over the size cap', dark('--ts-bg: #000;', '--ts-widget-frame-tl: linear-gradient(90deg, red 900px, blue 901px);'), 'out of range');
    css_bad('text where a repeat keyword belongs', dark('--ts-bg: #000;', '--ts-heading-strip-repeat: "Session expired";'));
    css_bad('a url() in a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: url(http://evil.example/a.png);'));
    css_bad('a url() with no scheme in a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: url(a.png);'), 'url');
    css_bad('an image-set() in a frame slot', dark('--ts-bg: #000;', '--ts-frame-top: image-set(url(a.png) 1x);'));
    css_bad('an over-size length in a frame slot', dark('--ts-bg: #000;', '--ts-frame-top: linear-gradient(90deg, red 900px, blue 901px);'), 'out of range');
    css_bad('a corner radius over the cap', dark('--ts-bg: #000;', '--ts-panel-radius-tl: 65px;'), 'out of range');
    css_bad('a var() into a structural token from a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: var(--ts-panel-before-width);'), 'not defined or not allowed');
    css_bad('text where a frame slot wants an image', dark('--ts-bg: #000;', '--ts-frame-tl: "Session expired";'));
    css_bad('the old pseudo-element tokens are still closed to uploads', dark('--ts-bg: #000;', '--ts-panel-before-content: "x";'), 'structural');
    css_bad('a made-up frame slot', dark('--ts-bg: #000;', '--ts-frame-center: linear-gradient(red, blue);'), 'not a Theme Selector token');

    T::group('ornaments: cut corners (phase C)');
    foreach ([['btn', '.btn', 10], ['label', '.label', 6], ['badge', '.badge', 6]] as [$e, $sel, $cap]) {
        $c = fn (string $corner) => "var(--ts-$e-chamfer-$corner, var(--ts-$e-chamfer))";
        $v = fn (string $corner) => "calc({$c($corner)} * var(--ts-$e-chamfer-rise, 1))";
        $polygon = "polygon({$c('tl')} 0, calc(100% - {$c('tr')}) 0, 100% {$v('tr')}, 100% calc(100% - {$v('br')}), "
            . "calc(100% - {$c('br')}) 100%, {$c('bl')} 100%, 0 calc(100% - {$v('bl')}), 0 {$v('tl')})";
        T::ok("the $sel polygon is the fixed template, written out independently", ($rules["$gate $sel"] ?? null) === ["clip-path: $polygon"], json_encode($rules["$gate $sel"] ?? null));
        foreach (['', '-tl', '-tr', '-br', '-bl'] as $k) {
            $t = "--ts-$e-chamfer$k";
            T::ok("$t is settable by upload, in px, capped at {$cap}px", $cat->has($t) && ! $cat->isStructural($t) && $cat->kinds($t) === ['chamfer'] && $cat->maxPx($t) === $cap, json_encode([$cat->kinds($t), $cat->maxPx($t)]));
        }
        T::ok("--ts-$e-chamfer-rise is settable by upload and is a ratio", $cat->has("--ts-$e-chamfer-rise") && ! $cat->isStructural("--ts-$e-chamfer-rise") && $cat->kinds("--ts-$e-chamfer-rise") === ['ratio']);
        T::ok("--ts-$e-chamfer-rise defaults to 1 (a 45 degree cut)", str_contains((string) file_get_contents(__DIR__ . '/../base/base.css'), "  --ts-$e-chamfer-rise: 1;\n"));
        T::ok("--ts-$e-clip-path (the raw one the bundled skins use) is still structural", $cat->isStructural("--ts-$e-clip-path"));
    }
    $base = (string) file_get_contents(__DIR__ . '/../base/base.css');
    foreach (['btn', 'label', 'badge'] as $e) {
        foreach (['', '-tl', '-tr', '-br', '-bl'] as $k) {
            T::ok("--ts-$e-chamfer$k defaults to initial, so an unchamfered control has no clip-path (and keeps its focus ring)", str_contains($base, "  --ts-$e-chamfer$k: initial;\n"));
        }
    }
    css_good('Protoss-style cuts: top-left and bottom-right', dark('--ts-bg: #000;', '--ts-btn-chamfer: 0px;', '--ts-btn-chamfer-tl: 8px;', '--ts-btn-chamfer-br: 8px;', '--ts-label-chamfer: 5px;', '--ts-badge-chamfer: 5px;'));
    css_good('every corner of a button at the cap', dark('--ts-bg: #000;', '--ts-btn-chamfer-tl: 10px;', '--ts-btn-chamfer-tr: 10px;', '--ts-btn-chamfer-br: 10px;', '--ts-btn-chamfer-bl: 10px;'));
    css_good('a 60 degree cut on the bottom-left of buttons, labels and badges', dark('--ts-bg: #000;',
        '--ts-btn-chamfer: 0px;', '--ts-btn-chamfer-bl: 8px;', '--ts-btn-chamfer-rise: 1.732;',
        '--ts-label-chamfer: 0px;', '--ts-label-chamfer-bl: 5px;', '--ts-label-chamfer-rise: 1.732;',
        '--ts-badge-chamfer: 0px;', '--ts-badge-chamfer-bl: 5px;', '--ts-badge-chamfer-rise: 1.732;'));
    css_good('the ends of the ratio range', dark('--ts-bg: #000;', '--ts-btn-chamfer-rise: 0.5;', '--ts-label-chamfer-rise: 2;', '--ts-badge-chamfer-rise: .577;'));
    foreach (['0.49', '2.01', '3', '10', '0', '-1', '50%', '1.7321', '1.7px', 'calc(1 + 1)', 'inherit', '1e1'] as $bad) {
        css_bad("a cut steepness of $bad", dark('--ts-bg: #000;', "--ts-btn-chamfer-rise: $bad;"), 'plain number');
    }
    css_bad('a steepness through var()', dark('--ts-bg: #000;', '--p-r: 1.5;', '--ts-btn-chamfer-rise: var(--p-r);'), 'plain number');
    css_bad('a button cut over its cap', dark('--ts-bg: #000;', '--ts-btn-chamfer: 11px;'), 'out of range');
    css_bad('a label cut over its cap', dark('--ts-bg: #000;', '--ts-label-chamfer-tl: 7px;'), 'out of range');
    css_bad('a badge cut over its cap', dark('--ts-bg: #000;', '--ts-badge-chamfer: 12px;'), 'out of range');
    css_bad('a percentage cut (scales with the control)', dark('--ts-bg: #000;', '--ts-btn-chamfer: 50%;'), 'px');
    css_bad('an em cut', dark('--ts-bg: #000;', '--ts-label-chamfer: 1em;'), 'px');
    css_bad('a cut through var()', dark('--ts-bg: #000;', '--p-x: 5px;', '--ts-btn-chamfer: var(--p-x);'), 'px');
    css_bad('a cut through a palette percentage', dark('--ts-bg: #000;', '--p-x: 90%;', '--ts-btn-chamfer: var(--p-x);'), 'px');
    css_bad('a calc() cut', dark('--ts-bg: #000;', '--ts-btn-chamfer: calc(100% - 4px);'), 'px');
    css_bad('a bare 0 (invalid inside the polygon)', dark('--ts-bg: #000;', '--ts-btn-chamfer: 0;'), 'px');
    css_bad('a negative cut', dark('--ts-bg: #000;', '--ts-btn-chamfer: -5px;'), 'px');
    css_bad('a keyword cut', dark('--ts-bg: #000;', '--ts-btn-chamfer: inherit;'), 'px');
    css_bad('a raw clip-path token is still closed to uploads', dark('--ts-bg: #000;', '--ts-btn-clip-path: polygon(0 0, 100% 0, 0 100%);'), 'structural');

    T::group('ornaments: motion and glow (phase D)');
    $css = (string) file_get_contents(__DIR__ . '/../base/base.css');
    // Every animation in a gated rule is one of two fixed shapes; every filter the fixed drop-shadow.
    $animated = [];
    foreach ($rules as $sel => $decls) {
        foreach ($decls as $d) {
            if (str_starts_with($d, 'animation:') && $d !== 'animation: none') {
                T::ok("$sel: the animation is a fixed keyframe and easing with the period as its only token", (bool) preg_match('/^animation: ts-(breathe|glow) var\(--ts-[a-z-]+\) ease-in-out infinite$/', $d), $d);
                $animated[] = $sel;
            }
            if (str_starts_with($d, 'filter:')) {
                T::ok("$sel: the filter is the fixed 8px drop-shadow with the colour as its only token", (bool) preg_match('/^filter: drop-shadow\(0 0 8px var\(--ts-[a-z-]+-glow\)\)$/', $d), $d);
            }
        }
    }
    T::ok('five layers breathe and the alert badge pulses', count($animated) === 6, json_encode($animated));
    $reduced = [];
    if (preg_match('/@media \(prefers-reduced-motion: reduce\) \{\s*(html\.dark:has\(link\[data-ts-orn\]\)[^{}]*)\{\s*animation: none;\s*\}\s*\}/', $css, $mm)) {
        foreach (explode(',', $mm[1]) as $one) {
            $reduced[] = trim(preg_replace('/\s+/', ' ', $one));
        }
    }
    T::ok('a reduced-motion rule turns the animation off, inside @media, for every animated selector', $reduced !== [] && array_diff($animated, $reduced) === [], json_encode([$animated, $reduced]));
    T::ok('the fade keyframes move opacity and nothing else', (bool) preg_match('/@keyframes ts-breathe \{\s*0%, 100% \{\s*opacity: var\(--ts-breathe-low\);\s*\}\s*50% \{\s*opacity: var\(--ts-breathe-high\);\s*\}\s*\}/', $css));
    T::ok('the pulse keyframes move box-shadow and nothing else', (bool) preg_match('/@keyframes ts-glow \{\s*0%, 100% \{\s*box-shadow: 0 0 7px var\(--ts-alert-glow-low\);\s*\}\s*50% \{\s*box-shadow: 0 0 16px var\(--ts-alert-glow-high\);\s*\}\s*\}/', $css));
    foreach ($layerNames as $n) {
        T::ok("--ts-$n-breathe is a period, settable by upload", $cat->has("--ts-$n-breathe") && ! $cat->isStructural("--ts-$n-breathe") && $cat->kinds("--ts-$n-breathe") === ['period']);
        T::ok("--ts-$n-glow is a colour, settable by upload", $cat->has("--ts-$n-glow") && ! $cat->isStructural("--ts-$n-glow") && $cat->kinds("--ts-$n-glow") === ['glowcolor']);
        T::ok("--ts-$n-breathe and -glow default to initial (off)", str_contains($css, "  --ts-$n-breathe: initial;\n") && str_contains($css, "  --ts-$n-glow: initial;\n"));
    }
    foreach (['--ts-navbar-after-animation', '--ts-alert-badge-animation', '--ts-btn-clip-path'] as $t) {
        T::ok("$t (what the bundled skins use) is still structural", $cat->isStructural($t));
    }
    css_good('slow fades on the navbar and heading layers', dark('--ts-bg: #000;', '--ts-navbar-strip-bottom-breathe: 6s;', '--ts-heading-marker-breathe: 2s;', '--ts-frame-breathe: 59.5s;', '--ts-heading-strip-breathe: 60s;', '--ts-navbar-strip-top-breathe: 3.25s;', '--ts-breathe-low: .45;', '--ts-breathe-high: 1;'));
    css_good('the ends of the fade depth', dark('--ts-bg: #000;', '--ts-breathe-low: 0.3;', '--ts-breathe-high: 1.0;'));
    css_good('glow colours', dark('--ts-bg: #000;', '--ts-frame-glow: #f37c2f;', '--ts-heading-marker-glow: rgba(255, 92, 122, .6);', '--ts-navbar-strip-top-glow: hsl(20 90% 55%);', '--ts-heading-strip-glow: #f37c2f80;', '--ts-navbar-strip-bottom-glow: rgb(1 2 3 / 50%);'));
    css_good('an alert badge pulse', dark('--ts-bg: #000;', '--ts-alert-glow-period: 3s;', '--ts-alert-glow-low: rgba(255, 92, 122, .5);', '--ts-alert-glow-high: rgba(255, 92, 122, .95);'));
    foreach (['1.9s', '1s', '0.5s', '100ms', '0s', '61s', '60.5s', '6', '6 s', '6s, 1s', '6s 1s', 'infinite', 'var(--p-t)', 'calc(3s)', '-3s', '6S', '1e1s'] as $bad) {
        css_bad("a breathe period of $bad", dark('--ts-bg: #000;', '--p-t: 6s;', "--ts-frame-breathe: $bad;"), 'period of 2s to 60s');
    }
    css_bad('an alert pulse faster than 2s', dark('--ts-bg: #000;', '--ts-alert-glow-period: 1s;'), 'period of 2s to 60s');
    foreach (['.29', '0.1', '1.1', '2', '0', '-.5', '50%', 'var(--p-l)', '1e-1'] as $bad) {
        css_bad("a fade depth of $bad", dark('--ts-bg: #000;', '--p-l: .5;', "--ts-breathe-low: $bad;"), 'from .3 to 1');
    }
    foreach (['red', 'currentColor', 'transparent', 'var(--p-c)', '#ff0000, 0 0 90px #00f', 'rgba(255, 0, 0, .5), 0 0 90px blue', '#ff0000 0 0 90px', 'url(a.png)', 'color-mix(in srgb, red, blue)', '#12345', 'rgb(var(--p-c))'] as $bad) {
        css_bad("a glow colour of $bad", dark('--ts-bg: #000;', '--p-c: #f00, 0 0 90px blue;', "--ts-frame-glow: $bad;"), 'plain colour');
    }
    css_bad('a glow colour that closes the function early', dark('--ts-bg: #000;', '--ts-frame-glow: rgb(1, 2, 3)) blur(50px;'));
    css_bad('an alert glow colour carrying a second shadow', dark('--ts-bg: #000;', '--ts-alert-glow-high: #f00, 0 0 100px 60px #00f;'), 'plain colour');
    css_bad('an alert glow colour through the palette', dark('--ts-bg: #000;', '--p-c: #f00, 0 0 100px 60px #00f;', '--ts-alert-glow-low: var(--p-c);'), 'plain colour');
    css_bad('the raw animation token is still closed to uploads', dark('--ts-bg: #000;', '--ts-navbar-after-animation: zg-breathe 1s linear infinite;'), 'structural');
    css_bad('the raw alert animation token is still closed to uploads', dark('--ts-bg: #000;', '--ts-alert-badge-animation: zg-swell .1s infinite;'), 'structural');

    T::group('ornaments: cut corners on panels and widgets');
    // The clip-path for widgets and panels (ornament_polygon, above): the box with a margin of
    // 10000px (so nothing that hangs out of it is clipped), and into each CUT corner a notch that
    // removes the cut triangle grown by 2px on the two sides along the box edges, joined to the outside
    // by a zero-width slit that runs 2px outside the box. The clip edge therefore never lies on the
    // border's outer edge, where a border snapped to a device pixel and an unsnapped clip disagree at
    // fractional zoom levels and leave a hairline behind. A corner with no cut loses nothing, not even
    // the sliver outside it, so the bars that jut out of an uncut corner are not bitten.
    $polygonFor = fn (string $e): string => ornament_polygon($e);
    $widgetDecls = $rules["$gate .grid-stack .grid-stack-item-content"] ?? [];
    T::ok('the widget clip-path is the fixed polygon', in_array('clip-path: ' . $polygonFor('widget'), $widgetDecls, true));
    T::ok('a widget clip-path is only in that one declaration', count(array_filter($widgetDecls, fn ($d) => str_starts_with($d, 'clip-path:'))) === 1);
    // What each corner's notch is made of, checked by reading the polygon rather than comparing it
    // with a copy: no bare 2px outside the min(), and a min() for every corner of both polygons.
    foreach (['panel' => $panelDeclsForShape = $rules["$gate .panel"] ?? [], 'widget' => $widgetDecls] as $kind => $decls) {
        $poly = '';
        foreach ($decls as $d) {
            if (str_starts_with($d, 'clip-path:')) {
                $poly = $d;
            }
        }
        $withoutGrowth = preg_replace('/min\(calc\(var\(--ts-' . $kind . '-chamfer-(tl|tr|br|bl), var\(--ts-' . $kind . '-chamfer\)\) \* 1000\), 2px\)/', '', $poly, -1, $growths);
        T::ok("$kind: the 2px growth of a notch is only ever min(cut * 1000, 2px), so a corner with no cut grows nothing", $poly !== '' && ! str_contains($withoutGrowth, '2px') && $growths > 0, $withoutGrowth);
        $corners = [];
        preg_match_all('/min\(calc\(var\(--ts-' . $kind . '-chamfer-(tl|tr|br|bl),/', $poly, $mm);
        $corners = array_unique($mm[1]);
        sort($corners);
        T::ok("$kind: all four corners have their own growth", $corners === ['bl', 'br', 'tl', 'tr'], json_encode($corners));
    }
    // The cut's edge line must be drawn over the frame bars (the first layers are the topmost), or a bar
    // runs over the line at its end and pokes out of the cut.
    $bgDecl = '';
    foreach ($widgetDecls as $d) {
        if (str_starts_with($d, 'background-image:')) {
            $bgDecl = substr($d, strlen('background-image: '));
        }
    }
    $layers = [];
    $depth = 0;
    $cur = '';
    foreach (str_split($bgDecl) as $ch) {
        $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
        if ($ch === ',' && $depth === 0) {
            $layers[] = trim($cur);
            $cur = '';
        } else {
            $cur .= $ch;
        }
    }
    $layers[] = trim($cur);
    $edge = fn (string $l) => str_contains($l, '--ts-widget-cut-stroke');
    T::ok('the four cut-edge lines are the first (topmost) background layers of a widget', count($layers) === 13 && count(array_filter(array_slice($layers, 0, 4), $edge)) === 4 && count(array_filter(array_slice($layers, 4), $edge)) === 0, json_encode(array_map(fn ($l) => substr($l, 0, 30), $layers)));
    $panelDecls = $rules["$gate .panel"] ?? [];
    T::ok('the panel clip-path is the same fixed polygon, over the panel tokens', in_array('clip-path: ' . $polygonFor('panel'), $panelDecls, true));
    T::ok('a panel clip-path is only in that one declaration', count(array_filter($panelDecls, fn ($d) => str_starts_with($d, 'clip-path:'))) === 1);
    T::ok('the clip margin is large enough that a dropdown or a fixed dialog inside a panel is not cut off', in_array('clip-path: ' . $polygonFor('panel'), $panelDecls, true) && str_starts_with($polygonFor('panel'), 'polygon(-10000px -10000px, calc(100% + 10000px) -10000px, ') && str_contains($polygonFor('panel'), 'calc(100% + 10000px) calc(100% + 10000px), -10000px calc(100% + 10000px)'));

    // A panel and a widget are stacking contexts, so a hover card or menu inside one is trapped
    // under every later panel unless the panel that holds it is raised while it is open.
    T::group('ornaments: a card opened inside a panel is not trapped under the next one');
    foreach (["$gate .panel:is(:hover, :has(.open))", "$gate .grid-stack .grid-stack-item:hover"] as $sel) {
        $z = array_values(array_filter($rules[$sel] ?? [], fn ($d) => str_starts_with($d, 'z-index:')));
        T::ok("$sel is raised above the sticky navbar (1030) and below modals (1040)", count($z) === 1 && preg_match('/^z-index: (\d+)$/', $z[0], $zm) === 1 && (int) $zm[1] > 1030 && (int) $zm[1] < 1040, json_encode($z));
    }
    T::ok('the panel itself is positioned, so its z-index applies', in_array('position: relative', $panelDecls, true));

    // The panel's ::after draws only the edge lines of the cut (the clip makes the cut).
    $panelAfter = $rules["$gate .panel::after"] ?? [];
    $want = ['content: ""', 'position: absolute', 'inset: -1px', 'z-index: 1', 'pointer-events: none'];
    T::ok('the panel overlay is above the content, inert and text-free', array_intersect($want, $panelAfter) === $want);
    $c = fn (string $k) => "var(--ts-panel-chamfer-$k, var(--ts-panel-chamfer, 0px))";
    $r = 'var(--ts-panel-chamfer-rise, 1)';
    $sizes = implode(', ', array_map(fn ($k) => "{$c($k)} calc({$c($k)} * $r)", ['bl', 'br', 'tl', 'tr']));
    T::ok('its four cells are exactly the cuts, falling back to 0px (never auto), so a corner with no cut gets none', in_array('background-size: ' . $sizes, $panelAfter, true));
    T::ok('they sit at the four corners of the panel', in_array('background-position: left bottom, right bottom, left top, right top', $panelAfter, true));
    $images = array_values(array_filter($panelAfter, fn ($d) => str_starts_with($d, 'background-image:')));
    T::ok('it paints only four edge-line gradients, in the stroke colour, inside their cells', count($images) === 1 && substr_count($images[0], 'linear-gradient(') === 4 && ! str_contains($images[0], 'url(') && ! str_contains($images[0], 'panel-cut-fill') && substr_count($images[0], 'var(--ts-panel-cut-stroke, transparent)') === 8, $images[0] ?? '');
    // tw's rounded-* utilities are !important inside the utilities layer (the device page header is
    // tw:rounded-2xl!), so the radius tokens only reach them from a re-opened utilities layer.
    T::ok('the radius tokens reach panels and widgets that carry a tw:rounded-* utility', (bool) preg_match('/@layer utilities \{\s*html\.dark \.panel\[class\*="tw:rounded"\] \{(\s*border-(?:top-left|top-right|bottom-right|bottom-left)-radius: var\(--ts-panel-radius-(?:tl|tr|br|bl)\) !important;){4}\s*\}\s*html\.dark \.grid-stack-item-content\[class\*="tw:rounded"\] \{(\s*border-(?:top-left|top-right|bottom-right|bottom-left)-radius: var\(--ts-widget-radius-(?:tl|tr|br|bl)\) !important;){4}\s*\}/', $css));
    foreach (['panel', 'widget'] as $e) {
        foreach (['', '-tl', '-tr', '-br', '-bl'] as $k) {
            $t = "--ts-$e-chamfer$k";
            T::ok("$t is a px size, at most 12px, settable by upload", $cat->has($t) && ! $cat->isStructural($t) && in_array('chamfer', $cat->kinds($t), true) && $cat->maxPx($t) === 12, json_encode([$cat->kinds($t), $cat->maxPx($t)]));
            T::ok("$t defaults to initial", str_contains($css, "  $t: initial;\n"));
        }
        T::ok("--ts-$e-chamfer-rise is a ratio, settable by upload", in_array('ratio', $cat->kinds("--ts-$e-chamfer-rise"), true) && ! $cat->isStructural("--ts-$e-chamfer-rise"));
    }
    T::ok('--ts-panel-cut-fill is still accepted (skins written for the overlay cut use it) but nothing reads it any more', $cat->has('--ts-panel-cut-fill') && ! $cat->isStructural('--ts-panel-cut-fill') && ! in_array('--ts-panel-cut-fill', array_keys($tokenUses), true));
    foreach (array_merge($cutTokens, ['--ts-panel-cut-fill']) as $t) {
        if (str_contains($t, '-cut-')) {
            T::ok("$t takes one literal colour", in_array('glowcolor', $cat->kinds($t), true) && ! $cat->isStructural($t));
        }
    }
    $uncapped = array_filter($cat->names(), fn ($n) => in_array('chamfer', $cat->kinds($n), true) && $cat->maxPx($n) > 12);
    T::ok('no cut-size token in the catalog is larger than 12px', $uncapped === [], json_encode(array_values($uncapped)));
    css_good('a cut bottom-left on panels and widgets, with fill and stroke', dark('--ts-bg: #000;',
        '--ts-panel-chamfer: 0px;', '--ts-panel-chamfer-bl: 12px;', '--ts-panel-chamfer-rise: 1.732;', '--ts-panel-cut-fill: #000f26;', '--ts-panel-cut-stroke: #34497a;',
        '--ts-widget-chamfer: 0px;', '--ts-widget-chamfer-bl: 12px;', '--ts-widget-chamfer-rise: 1.732;', '--ts-widget-cut-stroke: rgba(52, 73, 122, .9);'));
    css_bad('a panel cut over 12px', dark('--ts-bg: #000;', '--ts-panel-chamfer-bl: 13px;'), 'out of range');
    css_bad('a widget cut over 12px', dark('--ts-bg: #000;', '--ts-widget-chamfer: 24px;'), 'out of range');
    css_bad('a panel cut in %', dark('--ts-bg: #000;', '--ts-panel-chamfer-tl: 50%;'), 'px');
    css_bad('a widget cut through var()', dark('--ts-bg: #000;', '--p-x: 5px;', '--ts-widget-chamfer: var(--p-x);'), 'px');
    css_bad('a bare 0 panel cut', dark('--ts-bg: #000;', '--ts-panel-chamfer: 0;'), 'px');
    css_bad('a panel steepness over 2', dark('--ts-bg: #000;', '--ts-panel-chamfer-rise: 3;'), 'plain number');
    css_bad('a widget steepness in px', dark('--ts-bg: #000;', '--ts-widget-chamfer-rise: 2px;'), 'plain number');
    foreach (['red', 'var(--p-c)', '#000, 0 0 90px blue', 'linear-gradient(red, blue)', 'url(a.png)', 'transparent', 'currentColor'] as $bad) {
        css_bad("a panel cut fill of $bad", dark('--ts-bg: #000;', '--p-c: #000;', "--ts-panel-cut-fill: $bad;"), 'plain colour');
        css_bad("a widget cut stroke of $bad", dark('--ts-bg: #000;', '--p-c: #000;', "--ts-widget-cut-stroke: $bad;"), 'plain colour');
    }

    T::group('ornaments: the first three bundled skins are untouched');
    foreach (['terran', 'protoss', 'zerg'] as $id) {
        $text = (string) file_get_contents(__DIR__ . "/../skins/$id/skin.css");
        T::ok("$id sets no frame token", ! str_contains($text, '--ts-frame-') && ! str_contains($text, '--ts-panel-radius-'));
    }

    // Phase F: a drifting page layer ("rain"). Its mechanics are fixed in base.css; a skin gives an image,
    // a tile size and a period, and nothing else reaches it.
    T::group('page layer: a drifting tile behind the page');
    T::ok('exactly one rule styles body::before', substr_count($css, 'body::before') === 2, (string) substr_count($css, 'body::before')); // the rule and the reduced-motion rule
    $layer = [];
    if (preg_match('/\nhtml\.dark body::before \{([^{}]*)\}/', $css, $m)) {
        $layer = array_values(array_filter(array_map(fn ($d) => trim(preg_replace('/\s+/', ' ', $d)), explode(';', $m[1]))));
    }
    $wantLayer = ['content: ""', 'position: fixed', 'left: 0', 'top: 0', 'width: 100%', 'height: calc(100% + var(--ts-rain-tile))', 'z-index: -1', 'pointer-events: none',
        'background-image: var(--ts-rain-image)', 'background-size: var(--ts-rain-tile) var(--ts-rain-tile)', 'background-repeat: repeat', 'will-change: transform',
        'animation: ts-rain var(--ts-rain-period) linear infinite'];
    T::ok('the layer is exactly this rule, every declaration written out (behind everything, click-through, empty, moved by transform)', $layer === $wantLayer, json_encode($layer));
    // With a background on <html> as well, the body's would paint over the layer (z-index -1) wherever the body is.
    T::ok('<html> has no background of its own, so the body\'s is the canvas\'s and the layer sits on it', (bool) preg_match('/\nhtml\.dark \{([^{}]*font-family: var\(--ts-root-font-family\)[^{}]*)\}/', $css, $hm) && ! str_contains($hm[1], 'background'), $hm[1] ?? 'no root rule');
    T::ok('the keyframes move transform down by one tile (from a tile above to rest) and nothing else', (bool) preg_match('/@keyframes ts-rain \{\s*from \{\s*transform: translateY\(calc\(var\(--ts-rain-tile\) \* -1\)\);\s*\}\s*to \{\s*transform: translateY\(0\);\s*\}\s*\}/', $css));
    T::ok('a reduced-motion rule stops it', (bool) preg_match('/@media \(prefers-reduced-motion: reduce\) \{\s*html\.dark body::before \{\s*animation: none;\s*\}\s*\}/', $css));
    T::ok('every rain token is off by default', str_contains($css, "  --ts-rain-image: none;\n") && str_contains($css, "  --ts-rain-tile: initial;\n") && str_contains($css, "  --ts-rain-period: initial;\n"));
    T::ok('the layer has no other token: --ts-rain-image, -tile and -period only', array_values(array_filter($cat->names(), fn ($n) => str_starts_with($n, '--ts-rain-'))) === ['--ts-rain-image', '--ts-rain-period', '--ts-rain-tile']);
    foreach (['--ts-rain-image' => ['image'], '--ts-rain-tile' => ['length', 'tile'], '--ts-rain-period' => ['period']] as $t => $kinds) {
        T::ok("$t is settable by upload and is a " . implode('+', $kinds), $cat->has($t) && ! $cat->isStructural($t) && $cat->kinds($t) === $kinds, json_encode($cat->kinds($t)));
    }
    T::ok('the tile is at most 512px', $cat->maxPx('--ts-rain-tile') === 512);
    css_good('a rain layer', dark('--ts-bg: #000;', '--ts-rain-image: linear-gradient(180deg, transparent, rgba(0, 255, 65, .2));', '--ts-rain-tile: 256px;', '--ts-rain-period: 40s;'));
    css_good('a rain layer through the palette', dark('--ts-bg: #000;', '--p-g: linear-gradient(180deg, transparent, #0f0);', '--ts-rain-image: var(--p-g);', '--ts-rain-tile: 64px;', '--ts-rain-period: 60s;'));
    foreach (['64px', '256px', '512px'] as $good) {
        css_good("a rain tile of $good", dark('--ts-bg: #000;', "--ts-rain-tile: $good;"));
    }
    foreach (['63px', '513px', '600px', '5000px', '64.5px', '50%', '10em', '4rem', '256', '0', '0px', '-256px', 'calc(100px * 2)', 'var(--p-t)', '256px 256px', 'auto'] as $bad) {
        css_bad("a rain tile of $bad", dark('--ts-bg: #000;', '--p-t: 256px;', "--ts-rain-tile: $bad;"), 'from 64px to 512px');
    }
    foreach (['1s', '1.9s', '61s', '100ms', '0s', '-30s', 'infinite', 'var(--p-t)', '30'] as $bad) {
        css_bad("a rain period of $bad", dark('--ts-bg: #000;', '--p-t: 30s;', "--ts-rain-period: $bad;"), 'period of 2s to 60s');
    }
    css_bad('a rain image from a url', dark('--ts-bg: #000;', '--ts-rain-image: url(a.png);'), 'url');
}
