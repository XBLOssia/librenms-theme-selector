<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * A skin's graph palette (graph.conf).
 *
 * These values end up in LibreNMS config and from there in RRDtool's command
 * line, so they are held to exact shapes, not just "looks like a colour":
 *
 *   rrdgraph_def_text_dark        a list of `-c NAME#RRGGBB[AA]` pairs, NAME from
 *                                 RRDtool's fixed set of colour tags
 *   rrdgraph_def_text_color_dark  six hex digits
 *   graph_colours.<name>          a JSON array of 1-40 six-digit hex colours
 *
 * Anything else is rejected, not ignored: an unrecognised key is either a
 * typo the author wants to know about or an attempt to set something else.
 *
 * The same parser reads bundled skins' graph.conf and validates a stored
 * palette again just before it is written to config, so the check does not
 * depend on how the data got there.
 */
final class GraphConf
{
    /** The graph chrome (ground, grid, frame) and its text colour, for light and for dark graphs. */
    public const CHROME_KEYS = ['rrdgraph_def_text', 'rrdgraph_def_text_dark'];
    public const FONT_KEYS = ['rrdgraph_def_text_color', 'rrdgraph_def_text_color_dark'];

    private const COLOUR_TAGS = ['BACK', 'CANVAS', 'SHADEA', 'SHADEB', 'GRID', 'MGRID', 'FONT', 'AXIS', 'FRAME', 'ARROW'];

    /**
     * A validated palette as it applies to graphs drawn in $mode: the series ramps as they are, and
     * the chrome and its text colour under $mode's own keys (`_dark` for dark graphs, plain for
     * light). If the palette has $mode's own keys they are used; otherwise the other mode's are
     * carried over, so a skin written for one mode colours the other mode's graphs with its own look
     * (a light skin put in the dark slot draws its light chrome on dark-mode graphs).
     *
     * @param  array<string, string|string[]>  $palette
     * @return array<string, string|string[]>
     */
    public static function forMode(array $palette, string $mode): array
    {
        $dark = $mode === 'dark';
        $own = $dark ? ['rrdgraph_def_text_dark', 'rrdgraph_def_text_color_dark'] : ['rrdgraph_def_text', 'rrdgraph_def_text_color'];
        $other = $dark ? ['rrdgraph_def_text', 'rrdgraph_def_text_color'] : ['rrdgraph_def_text_dark', 'rrdgraph_def_text_color_dark'];

        $out = [];
        foreach ($palette as $key => $value) {
            if (! in_array($key, self::CHROME_KEYS, true) && ! in_array($key, self::FONT_KEYS, true)) {
                $out[$key] = $value;
            }
        }
        foreach ([0, 1] as $i) {
            if (isset($palette[$own[$i]])) {
                $out[$own[$i]] = $palette[$own[$i]];
            } elseif (isset($palette[$other[$i]])) {
                $out[$own[$i]] = $palette[$other[$i]];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string|string[]>|null  key => canonical value
     */
    public static function parse(string $text, Report $report): ?array
    {
        if (strlen($text) > Limits::GRAPH_BYTES) {
            $report->error('graph.conf', 'is too large');

            return null;
        }
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $text)) {
            $report->error('graph.conf', 'contains non-ASCII or control characters');

            return null;
        }

        $palette = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $where = 'graph.conf line ' . ($n + 1);
            if (! str_contains($line, '=')) {
                $report->error($where, 'expected key=value');

                return null;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (isset($palette[$key])) {
                $report->error($where, Report::quote($key) . ' is set twice');

                return null;
            }

            if (in_array($key, self::CHROME_KEYS, true)) {
                $v = self::chrome($value);
            } elseif (in_array($key, self::FONT_KEYS, true)) {
                $v = preg_match('/^[0-9A-Fa-f]{6}\z/', $value) ? $value : null;
            } elseif (preg_match('/^graph_colours\.[a-z_]{1,30}\z/D', $key)) {
                $v = self::ramp($value);
            } else {
                $report->error($where, Report::quote($key) . ' is not a graph colour setting');

                return null;
            }
            if ($v === null) {
                $report->error($where, Report::quote($key) . ' has an invalid value');

                return null;
            }
            $palette[$key] = $v;
        }

        return $palette;
    }

    /**
     * Validate an already-decoded palette (what the database holds).
     *
     * @param  mixed  $stored
     * @return array<string, string|string[]>
     */
    public static function fromStored($stored): array
    {
        if (! is_array($stored)) {
            return [];
        }
        $out = [];
        foreach ($stored as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (in_array($key, self::CHROME_KEYS, true) && is_string($value) && ($v = self::chrome($value)) !== null) {
                $out[$key] = $v;
            } elseif (in_array($key, self::FONT_KEYS, true) && is_string($value) && preg_match('/^[0-9A-Fa-f]{6}\z/', $value)) {
                $out[$key] = $value;
            } elseif (preg_match('/^graph_colours\.[a-z_]{1,30}\z/D', $key) && is_array($value)
                && ($v = self::ramp(json_encode($value))) !== null) {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private static function chrome(string $value): ?string
    {
        if (strlen($value) > 400) {
            return null;
        }
        $parts = preg_split('/\s+(?=-c )/', $value);
        if ($parts === false || $parts === [] || count($parts) > 12) {
            return null;
        }
        $seen = [];
        foreach ($parts as $part) {
            if (! preg_match('/^-c ([A-Z]{2,6})#([0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})\z/', $part, $m)
                || ! in_array($m[1], self::COLOUR_TAGS, true) || isset($seen[$m[1]])) {
                return null;
            }
            $seen[$m[1]] = true;
        }

        return implode(' ', $parts);
    }

    /**
     * @return string[]|null
     */
    private static function ramp(string $value): ?array
    {
        $decoded = json_decode($value, true, 3);
        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === [] || count($decoded) > 40) {
            return null;
        }
        foreach ($decoded as $c) {
            if (! is_string($c) || ! preg_match('/^[0-9A-Fa-f]{6}\z/', $c)) {
                return null;
            }
        }

        return $decoded;
    }
}
