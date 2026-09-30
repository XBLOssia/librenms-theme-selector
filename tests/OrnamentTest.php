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
    T::ok('exactly two rules carry the gate', count($rules) === 2, implode(' | ', array_keys($rules)));

    // Every declaration of the ornament layer, written out. A change to any of
    // them (a higher z-index, pointer-events, text in content, a bigger
    // overhang, a token in place of a constant) fails here and needs a review.
    $expected = [
        "$gate .panel" => ['position: relative', 'isolation: isolate'],
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
    ];
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
    T::ok('the only tokens it reads are the eight paint slots', array_keys($tokenUses) === $slotTokens, implode(',', array_keys($tokenUses)));
    T::ok('the layer is painted behind content (negative z-index)', in_array('z-index: -1', $rules["$gate .panel::before"] ?? [], true));
    T::ok('and cannot take a click', in_array('pointer-events: none', $rules["$gate .panel::before"] ?? [], true));
    T::ok('and has no text to show', in_array('content: ""', $rules["$gate .panel::before"] ?? [], true));

    T::group('ornaments: what an upload may set');
    $cat = catalog();
    foreach ($slotTokens as $t) {
        T::ok("$t exists and is settable by upload", $cat->has($t) && ! $cat->isStructural($t));
        T::ok("$t is an image token", in_array('image', $cat->kinds($t), true), json_encode($cat->kinds($t)));
    }
    foreach (['tl', 'tr', 'br', 'bl'] as $c) {
        T::ok("--ts-panel-radius-$c is settable and is a length", ! $cat->isStructural("--ts-panel-radius-$c") && in_array('length', $cat->kinds("--ts-panel-radius-$c"), true));
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

    css_bad('a url() in a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: url(http://evil.example/a.png);'));
    css_bad('a url() with no scheme in a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: url(a.png);'), 'url');
    css_bad('an image-set() in a frame slot', dark('--ts-bg: #000;', '--ts-frame-top: image-set(url(a.png) 1x);'));
    css_bad('an over-size length in a frame slot', dark('--ts-bg: #000;', '--ts-frame-top: linear-gradient(90deg, red 900px, blue 901px);'), 'out of range');
    css_bad('a corner radius over the cap', dark('--ts-bg: #000;', '--ts-panel-radius-tl: 65px;'), 'out of range');
    css_bad('a var() into a structural token from a frame slot', dark('--ts-bg: #000;', '--ts-frame-tl: var(--ts-panel-before-width);'), 'not defined or not allowed');
    css_bad('text where a frame slot wants an image', dark('--ts-bg: #000;', '--ts-frame-tl: "Session expired";'));
    css_bad('the old pseudo-element tokens are still closed to uploads', dark('--ts-bg: #000;', '--ts-panel-before-content: "x";'), 'structural');
    css_bad('a made-up frame slot', dark('--ts-bg: #000;', '--ts-frame-center: linear-gradient(red, blue);'), 'not a Theme Selector token');

    T::group('ornaments: the bundled skins are untouched');
    foreach (glob(__DIR__ . '/../skins/*/skin.css') ?: [] as $file) {
        $text = (string) file_get_contents($file);
        T::ok(basename(dirname($file)) . ' sets no frame token', ! str_contains($text, '--ts-frame-') && ! str_contains($text, '--ts-panel-radius-'));
    }
}
