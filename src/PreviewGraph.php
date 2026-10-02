<?php

namespace Xblossia\ThemeSelector;

/**
 * A small sample traffic graph for the skin preview, drawn as inline SVG from a skin's own graph
 * palette (what GraphConf returns): the ground, grid and text colours from rrdgraph_def_text_dark
 * and the three tones of port_in and port_out. Real graphs are PNGs drawn by rrdtool on the
 * server, so a page can't show one in a skin's colours without a real device; this shows the same
 * colours on invented traffic.
 *
 * The output is built from fixed numbers and colours that matched a strict hex pattern, never from
 * the palette's text: nothing a skin says reaches the markup except as six or eight hex digits.
 */
final class PreviewGraph
{
    private const DEFAULTS = [
        'BACK' => '1C1C1C', 'GRID' => '3A3A3A', 'MGRID' => '555555', 'FONT' => 'CCCCCC',
        'IN' => ['A5D6A7', '4CAF50', '2E7D32'], 'OUT' => ['90CAF9', '42A5F5', '1565C0'],
    ];

    private const W = 480;
    private const H = 130;

    /** @param array<string, mixed> $palette */
    public static function svg(array $palette): string
    {
        $chrome = is_string($palette['rrdgraph_def_text_dark'] ?? null) ? $palette['rrdgraph_def_text_dark'] : '';
        $back = self::tag($chrome, 'BACK') ?? self::DEFAULTS['BACK'];
        $grid = self::tag($chrome, 'GRID') ?? self::DEFAULTS['GRID'];
        $mgrid = self::tag($chrome, 'MGRID') ?? self::DEFAULTS['MGRID'];
        $font = self::hex($palette['rrdgraph_def_text_color_dark'] ?? null) ?? self::DEFAULTS['FONT'];
        $in = self::ramp($palette['graph_colours.port_in'] ?? null, self::DEFAULTS['IN']);
        $out = self::ramp($palette['graph_colours.port_out'] ?? null, self::DEFAULTS['OUT']);

        $left = 40;
        $right = self::W - 10;
        $mid = 62;
        $steps = 96;
        $upper = [];
        $lower = [];
        for ($i = 0; $i <= $steps; $i++) {
            $x = $left + ($right - $left) * $i / $steps;
            $h = $i / $steps * 24;
            $base = max(0.08, 0.5 + 0.5 * sin(($h - 8) / 24 * 2 * M_PI));
            $spike = ($h > 20.5 && $h < 22.0) ? 1.7 : 1.0;
            $wobble = 1 + 0.1 * sin($i * 1.7) + 0.05 * sin($i * 0.31);
            $v = min(1.0, $base * $spike * $wobble * 0.62);
            $upper[] = [round($x, 1), round($mid - $v * 50, 1)];
            $lower[] = [round($x, 1), round($mid + $v * 0.24 * 50 * (1 + 0.2 * sin($i * 0.9)), 1)];
        }

        $area = function (array $points, string $fill, string $line, bool $fillArea = true) use ($mid): string {
            $path = 'M' . $points[0][0] . ',' . $mid;
            foreach ($points as [$x, $y]) {
                $path .= " L$x,$y";
            }
            $last = $points[count($points) - 1][0];
            $svg = '<path d="' . $path . " L$last,$mid Z\" fill=\"#$fill\"/>";
            $stroke = 'M' . $points[0][0] . ',' . $points[0][1];
            foreach ($points as [$x, $y]) {
                $stroke .= " L$x,$y";
            }

            return $svg . '<path d="' . $stroke . "\" fill=\"none\" stroke=\"#$line\" stroke-width=\"1\"/>";
        };

        $lines = '';
        foreach ([22, 42, 82, 102] as $y) {
            $lines .= "<line x1=\"$left\" y1=\"$y\" x2=\"$right\" y2=\"$y\" stroke=\"#$grid\" stroke-width=\"1\"/>";
        }
        foreach ([80, 160, 240, 320, 400] as $x) {
            $lines .= "<line x1=\"$x\" y1=\"10\" x2=\"$x\" y2=\"114\" stroke=\"#$grid\" stroke-width=\"1\"/>";
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-label="Sample traffic graph in this skin\'s graph colours">'
            . "<rect width=\"" . self::W . "\" height=\"" . self::H . "\" fill=\"#$back\"/>"
            . $lines
            . "<line x1=\"$left\" y1=\"$mid\" x2=\"$right\" y2=\"$mid\" stroke=\"#$mgrid\" stroke-width=\"1\"/>"
            . $area($upper, $in[1], $in[2])
            . $area($lower, $out[1], $out[2])
            . "<rect x=\"$left\" y=\"10\" width=\"" . ($right - $left) . "\" height=\"104\" fill=\"none\" stroke=\"#$mgrid\" stroke-width=\"1\"/>"
            . "<g font-family=\"monospace\" font-size=\"9\" fill=\"#$font\">"
            . '<text x="4" y="14">bits/s</text>'
            . "<rect x=\"$left\" y=\"119\" width=\"8\" height=\"8\" fill=\"#{$in[1]}\"/><text x=\"" . ($left + 12) . '" y="127">In</text>'
            . '<rect x="' . ($left + 44) . "\" y=\"119\" width=\"8\" height=\"8\" fill=\"#{$out[1]}\"/><text x=\"" . ($left + 56) . '" y="127">Out</text>'
            . '</g></svg>';
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#?([0-9A-Fa-f]{6})(?:[0-9A-Fa-f]{2})?\z/D', $value, $m) ? strtoupper($m[1]) : null;
    }

    /** The colour of `-c TAG#RRGGBB[AA]` in an rrdtool option string. */
    private static function tag(string $chrome, string $tag): ?string
    {
        return preg_match('/(?:^| )-c ' . $tag . '#([0-9A-Fa-f]{6})(?:[0-9A-Fa-f]{2})?(?= |\z)/D', $chrome, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * @param  string[]  $fallback
     * @return string[] three bare hex colours
     */
    private static function ramp(mixed $ramp, array $fallback): array
    {
        if (! is_array($ramp) || count($ramp) < 3) {
            return $fallback;
        }
        $colours = array_map(fn ($c) => self::hex($c), array_slice(array_values($ramp), 0, 3));

        return in_array(null, $colours, true) ? $fallback : $colours;
    }
}
