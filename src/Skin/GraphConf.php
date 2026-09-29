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
    private const COLOUR_TAGS = ['BACK', 'CANVAS', 'SHADEA', 'SHADEB', 'GRID', 'MGRID', 'FONT', 'AXIS', 'FRAME', 'ARROW'];

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

            if ($key === 'rrdgraph_def_text_dark') {
                $v = self::chrome($value);
            } elseif ($key === 'rrdgraph_def_text_color_dark') {
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
            if ($key === 'rrdgraph_def_text_dark' && is_string($value) && ($v = self::chrome($value)) !== null) {
                $out[$key] = $v;
            } elseif ($key === 'rrdgraph_def_text_color_dark' && is_string($value) && preg_match('/^[0-9A-Fa-f]{6}\z/', $value)) {
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
