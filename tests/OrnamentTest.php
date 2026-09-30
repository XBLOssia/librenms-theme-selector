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
            'animation: none',
        ],
        "$gate .grid-stack .grid-stack-item-content" => [
            'background-image: var(--ts-widget-frame-tl), var(--ts-widget-frame-tr), var(--ts-widget-frame-bl), var(--ts-widget-frame-br), var(--ts-widget-frame-top), var(--ts-widget-frame-right), var(--ts-widget-frame-bottom), var(--ts-widget-frame-left), var(--ts-widget-bg-image)',
            'background-size: 32px 32px, 32px 32px, 32px 32px, 32px 32px, 100% 12px, 12px 100%, 100% 12px, 12px 100%, auto, auto, auto, auto, auto, auto, auto, auto',
            'background-position: left top, right top, left bottom, right bottom, left top, right top, left bottom, left top, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0, 0 0',
            'background-repeat: no-repeat, no-repeat, no-repeat, no-repeat, no-repeat, no-repeat, no-repeat, no-repeat, repeat, repeat, repeat, repeat, repeat, repeat, repeat, repeat',
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
    $paint = array_merge($slotTokens, $headingTokens, $navTokens, $widgetSlots, $chamferTokens, ['--ts-widget-bg-image']);
    $read = array_keys($tokenUses);
    sort($read);
    $want = $paint;
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

    T::group('ornaments: the bundled skins are untouched');
    foreach (glob(__DIR__ . '/../skins/*/skin.css') ?: [] as $file) {
        $text = (string) file_get_contents($file);
        T::ok(basename(dirname($file)) . ' sets no frame token', ! str_contains($text, '--ts-frame-') && ! str_contains($text, '--ts-panel-radius-'));
    }
}
