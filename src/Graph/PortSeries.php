<?php

namespace Xblossia\ThemeSelector\Graph;

/**
 * Recolours the port traffic series in an rrdtool option list.
 *
 * includes/html/graphs/generic_data.inc.php (port_bits and 19 other graph types) writes its six
 * in/out series colours as literals and reads no config, so config alone can't reach them. This
 * rewrites exactly those six options just before rrdtool runs, and nothing else, which needs no
 * edit to any LibreNMS file (see RecolouringRrd, PortSeriesSupport and docs/PLUGIN.md).
 *
 * The six options look like (the optional two hex digits are the stacked-graph alpha):
 *
 *   AREA:inbits_max#D7FFC7[88]:      AREA:dout... _max#E0E0FF[88]:
 *   AREA:inbits#90B040[88]:          AREA:dout... #8080C0[88]:
 *   LINE:inbits#608720:In            LINE:dout... #606090:Out
 *
 * An option is only rewritten when its colour is still the stock literal for its role, so
 * - a caller that passes its own colour to a similar helper keeps it,
 * - a helper that already reads the palette itself (an upstream change, or the old core patch)
 *   is left alone,
 * - and whatever core changes about these lines, the worst outcome is that nothing matches and
 *   the graph is drawn in stock colours.
 */
final class PortSeries
{
    /** The literals generic_data.inc.php hard-codes, palest first: max fill, area fill, outline. */
    public const STOCK = [
        'in' => ['D7FFC7', '90B040', '608720'],
        'out' => ['E0E0FF', '8080C0', '606090'],
    ];

    private const OPTION = '~^(AREA|LINE):((?:in|dout)(?:bits|octets))(_max)?#([0-9A-Fa-f]{6})([0-9A-Fa-f]{2})?(:.*)?$~s';

    /**
     * @param  array<int, mixed>  $options  the rrdtool graph options
     * @param  mixed  $in  graph_colours.port_in: at least three six-digit hex strings, or anything else to leave the direction alone
     * @param  mixed  $out  graph_colours.port_out
     * @return array<int, mixed>
     */
    public static function recolour(array $options, mixed $in, mixed $out): array
    {
        $palette = ['in' => self::tones($in), 'out' => self::tones($out)];
        if ($palette['in'] === null && $palette['out'] === null) {
            return $options;
        }

        foreach ($options as $i => $option) {
            if (! is_string($option) || ! preg_match(self::OPTION, $option, $m)) {
                continue;
            }

            $direction = str_starts_with($m[2], 'in') ? 'in' : 'out';
            $max = ($m[3] ?? '') !== '';
            if ($m[1] === 'LINE' && $max) {
                continue;
            }
            $role = $m[1] === 'LINE' ? 2 : ($max ? 0 : 1);

            $tones = $palette[$direction];
            if ($tones === null || strtoupper($m[4]) !== self::STOCK[$direction][$role]) {
                continue;
            }

            $options[$i] = $m[1] . ':' . $m[2] . ($m[3] ?? '') . '#' . $tones[$role] . ($m[5] ?? '') . ($m[6] ?? '');
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null three six-digit hex colours, or null
     */
    private static function tones(mixed $palette): ?array
    {
        if (! is_array($palette)) {
            return null;
        }
        $palette = array_values($palette);
        if (count($palette) < 3) {
            return null;
        }
        $tones = [];
        for ($k = 0; $k < 3; $k++) {
            if (! is_string($palette[$k]) || ! preg_match('/^[0-9A-Fa-f]{6}\z/', $palette[$k])) {
                return null;
            }
            $tones[] = strtoupper($palette[$k]);
        }

        return $tones;
    }
}
