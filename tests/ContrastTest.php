<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Modes;
use Xblossia\ThemeSelector\Skin\GraphConf;
use Xblossia\ThemeSelector\Skin\Report;

/** Resolve the hex, var() and two-colour sRGB mixes used by these fixtures, not arbitrary CSS. */
function contrast_colour(string $value, array $tokens = [], array $seen = []): ?array
{
    $value = trim($value);
    if (preg_match('/^var\((--[a-z0-9-]+)\)$/', $value, $m)) {
        if (isset($seen[$m[1]]) || ! isset($tokens[$m[1]])) {
            return null;
        }
        $seen[$m[1]] = true;

        return contrast_colour($tokens[$m[1]], $tokens, $seen);
    }
    if (preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $value, $m)) {
        $hex = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

        return array_map('hexdec', str_split($hex, 2));
    }
    $colour = '(var\(--[a-z0-9-]+\)|#[a-f0-9]{3,6})';
    if (preg_match('/^color-mix\(in srgb, ' . $colour . ' ([0-9.]+)%, ' . $colour . '\)$/i', $value, $m)) {
        $a = contrast_colour($m[1], $tokens, $seen);
        $b = contrast_colour($m[3], $tokens, $seen);
        $weight = (float) $m[2] / 100;
        if ($a !== null && $b !== null && $weight >= 0 && $weight <= 1) {
            return array_map(fn ($x, $y) => $x * $weight + $y * (1 - $weight), $a, $b);
        }
    }

    return null;
}

function contrast_ratio(?array $a, ?array $b): float
{
    if ($a === null || $b === null) {
        return 0; // An unsupported or missing colour fails the assertion, never silently passes.
    }
    $lum = function (array $rgb): float {
        $c = array_map(fn ($v) => $v / 255 <= .04045 ? $v / 255 / 12.92 : (($v / 255 + .055) / 1.055) ** 2.4, $rgb);

        return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2];
    };
    $x = $lum($a);
    $y = $lum($b);

    return (max($x, $y) + .05) / (min($x, $y) + .05);
}

function test_daylight_contrast(): void
{
    T::group('Daylight: rendered colour pairs, including its dark-mode mirror');
    T::ok('contrast maths has the black/white reference ratio', abs(contrast_ratio([0, 0, 0], [255, 255, 255]) - 21) < .001);
    T::ok('unresolved and cyclic colours fail closed', contrast_colour('var(--missing)') === null && contrast_colour('var(--loop)', ['--loop' => 'var(--loop)']) === null && contrast_ratio(null, [255, 255, 255]) === 0.0);
    $base = (string) file_get_contents(__DIR__ . '/../base/base.css');
    $skin = (string) file_get_contents(__DIR__ . '/../skins/clock-tower-daylight/skin.css');
    foreach (['dark', 'light'] as $mode) {
        $css = ($mode === 'dark' ? $base : Modes::lightBase($base) . file_get_contents(__DIR__ . '/../base/light.css'))
            . ($mode === 'dark' ? Modes::mirror($skin) : $skin);
        preg_match_all('/(--[a-z0-9-]+):\s*([^;{}]+);/', $css, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }
        $check = function (string $label, string $fg, string $bg, float $minimum = 4.5) use ($mode, $tokens): void {
            $ratio = contrast_ratio(contrast_colour($fg, $tokens), contrast_colour($bg, $tokens));
            T::ok("$mode: $label is at least $minimum:1", $ratio >= $minimum, sprintf('%.2f:1', $ratio));
        };
        $wrap = Modes::selector($mode);
        foreach (['success', 'warning', 'info', 'danger'] as $status) {
            $rule = preg_match('/' . preg_quote("$wrap .alert-$status", '/') . '\s*\{([^}]*)\}/', $css, $m) ? $m[1] : '';
            T::ok("$mode: $status banner uses the colour pair being measured", str_contains($rule, "background-color: var(--ts-$status-deep);") && str_contains($rule, "color: var(--ts-alert-$status-fg);"));
            $check("$status banner", "var(--ts-alert-$status-fg)", "var(--ts-$status-deep)");
            $check("$status table row", 'var(--ts-text-bright)', "var(--ts-$status-deep)");
            $check("hovered $status table row", 'var(--ts-text-bright)', "color-mix(in srgb, var(--ts-$status) 45%, var(--ts-bg))");
        }
        // label-default actually uses surface-hover, not the generator's old unused label_default colour.
        T::ok("$mode: the default badge uses the colour pair being measured", (bool) preg_match('/' . preg_quote("$wrap .label-default, $wrap .badge-default", '/') . '\s*\{\s*background-color: var\(--ts-surface-hover\);\s*color: var\(--ts-label-default-fg\);/', $css));
        $check('ignored/default badge', 'var(--ts-label-default-fg)', 'var(--ts-surface-hover)');
        $check('normal input', 'var(--ts-text)', 'var(--ts-input-bg)');
        $check('placeholder', 'var(--ts-text-mute)', 'var(--ts-input-bg)');
        $check('read-only input', 'var(--ts-input-disabled-fg)', 'var(--ts-input-disabled-bg)');
        $check('table heading', 'var(--ts-accent)', 'var(--ts-table-head-bg)');
        $check('table link', 'var(--ts-link)', 'var(--ts-surface)');

        foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $state => $role) {
            foreach (['control-label', 'help-block'] as $element) {
                T::ok("$mode: $state $element uses its skin role instead of a stock colour", (bool) preg_match('/' . preg_quote("$wrap .has-$state .$element", '/') . '[^{]*\{\s*color: var\(--ts-' . $role . '\);/', $css));
            }
            $check("$state validation text", "var(--ts-$role)", 'var(--ts-input-bg)');
        }
        T::ok("$mode: banner links inherit the banner foreground, not stock white", (bool) preg_match('/' . preg_quote("$wrap .alert .alert-link", '/') . '\s*\{\s*color: inherit;/', $css));

        $report = new Report();
        $graph = GraphConf::forMode(GraphConf::parse((string) file_get_contents(__DIR__ . '/../skins/clock-tower-daylight/graph.conf'), $report) ?? [], $mode);
        $suffix = $mode === 'dark' ? '_dark' : '';
        preg_match('/-c BACK(#[A-Fa-f0-9]{6})/', $graph["rrdgraph_def_text$suffix"] ?? '', $back);
        $check('graph axis and legend text', '#' . ($graph["rrdgraph_def_text_color$suffix"] ?? ''), $back[1] ?? '');
        foreach (['port_in', 'port_out'] as $series) {
            $check("$series graph outline", '#' . ($graph["graph_colours.$series"][2] ?? ''), $back[1] ?? '', 3);
        }
    }
}
