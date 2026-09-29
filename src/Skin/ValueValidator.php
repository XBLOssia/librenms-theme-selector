<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Validates one custom-property value against a small CSS value grammar.
 *
 * This is the injection boundary for skin CSS. It does not look for bad things
 * in a value; it tokenises the whole value and accepts it only if every token
 * is one of a short list of safe kinds. Anything the tokenizer can't classify,
 * or that isn't on the list, is an error, so a construct nobody thought of is
 * refused by default.
 *
 * Accepted tokens:
 *   numbers with a unit from a fixed set, each within bounds
 *   #hex colours (3, 4, 6 or 8 digits)
 *   plain identifiers (ease-in-out, solid, transparent, ...)
 *   quoted strings of letters, digits and a few punctuation marks, only where
 *     the caller says strings are allowed (font family names)
 *   , and /
 *   functions from an allowlist: colours, gradients, filters, var()
 *   (Bundled skins may also use calc() and polygon(), with + - * operators.)
 *
 * What that leaves out is the point: no url(), image-set(), element(),
 * attr(), env(), paint() or any function not listed; no backslash escapes (the
 * usual way to smuggle `url(` past a filter); no `;` `{` `}` `@` `<` `>` `:`
 * or `!`; no comments. var() takes exactly one --ts-* or --p-* name and no
 * fallback.
 *
 * Bounds keep a value from being used as a layout weapon: px lengths are capped
 * per token (a box-shadow can't be 5000px), filter arguments are limited to
 * sensible ranges, and durations are short.
 */
final class ValueValidator
{
    private const UNITS = ['px', 'em', 'rem', '%', 'deg', 'turn', 'ms', 's'];

    private const FUNCTIONS = [
        'var',
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix',
        'linear-gradient', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'conic-gradient',
        'brightness', 'contrast', 'saturate', 'sepia', 'hue-rotate', 'invert', 'grayscale',
        'cubic-bezier', 'steps',
    ];

    /** Only bundled skins: shape and arithmetic functions used by clip-path tokens. */
    private const TRUSTED_FUNCTIONS = ['calc', 'polygon'];

    /** Functions taking one bounded argument: name => [min, max] for a plain number, and whether an angle is expected. */
    private const FILTERS = [
        'brightness' => [0, 3], 'contrast' => [0, 3], 'saturate' => [0, 3],
        'sepia' => [0, 1], 'invert' => [0, 1], 'grayscale' => [0, 1],
        'hue-rotate' => null,
    ];

    /** var() names: the base stylesheet's tokens and the skin's own palette. */
    private const VAR_NAME = '/^--(?:ts|p)-[a-z0-9][a-z0-9-]{0,62}\z/D';

    public function __construct(private readonly Mode $mode)
    {
    }

    /**
     * @param  int  $maxPx  largest px length allowed in this value
     * @param  bool  $allowStrings  quoted strings (font family names) permitted
     * @return array{value: string, vars: string[], maxPx: float}|null  canonical value, the var() names it
     *                                                                    uses and the largest px length in it, or null
     */
    public function validate(string $raw, string $where, int $maxPx, bool $allowStrings, Report $report): ?array
    {
        $value = trim(preg_replace('/\s+/', ' ', $raw));
        $before = 'value of ' . $where;

        if ($value === '') {
            $report->error($before, 'is empty');

            return null;
        }
        if (strlen($value) > Limits::VALUE_BYTES) {
            $report->error($before, 'is too long');

            return null;
        }
        if (str_contains($value, '/*') || str_contains($value, '*/')) {
            $report->error($before, 'contains a comment');

            return null;
        }
        // Belt and braces before tokenising: the only characters a value may
        // contain. (`+` and `*` only ever appear in trusted calc().)
        if (preg_match('/[^A-Za-z0-9 _.,#()\/"\'%+*-]/', $value, $m)) {
            $report->error($before, 'contains the character ' . Report::quote($m[0]) . ', which is not allowed');

            return null;
        }

        $trusted = $this->mode === Mode::Bundled;
        $allowedFns = $trusted ? [...self::FUNCTIONS, ...self::TRUSTED_FUNCTIONS] : self::FUNCTIONS;

        $stack = [];        // open functions: [name, arguments seen]
        $vars = [];
        $maxSeen = 0.0;
        $count = 0;
        $i = 0;
        $n = strlen($value);

        while ($i < $n) {
            $c = $value[$i];

            if ($c === ' ') {
                $i++;
                continue;
            }
            if (++$count > Limits::VALUE_TOKENS) {
                $report->error($before, 'has too many parts');

                return null;
            }

            // string
            if ($c === '"' || $c === "'") {
                $end = strpos($value, $c, $i + 1);
                if ($end === false) {
                    $report->error($before, 'has an unterminated string');

                    return null;
                }
                $body = substr($value, $i + 1, $end - $i - 1);
                if (! $allowStrings && ! $trusted) {
                    $report->error($before, 'may not contain a quoted string');

                    return null;
                }
                if (! preg_match('/^[A-Za-z0-9 _.,-]*\z/D', $body)) {
                    $report->error($before, 'has a string with characters that are not allowed');

                    return null;
                }
                $i = $end + 1;
                continue;
            }

            // #hex
            if ($c === '#') {
                if (! preg_match('/\G#([0-9A-Fa-f]{3,8})(?![A-Za-z0-9_-])/', $value, $m, 0, $i) || ! in_array(strlen($m[1]), [3, 4, 6, 8], true)) {
                    $report->error($before, 'has an invalid # colour');

                    return null;
                }
                $i += strlen($m[0]);
                continue;
            }

            // number, with optional unit
            if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $value, $m, 0, $i)) {
                $num = (float) $m[0];
                $i += strlen($m[0]);
                $unit = '';
                if (preg_match('/\G(px|em|rem|%|deg|turn|ms|s)(?![A-Za-z0-9_-])/i', $value, $u, 0, $i)) {
                    $unit = strtolower($u[1]);
                    $i += strlen($u[0]);
                } elseif ($i < $n && preg_match('/[A-Za-z_-]/', $value[$i])) {
                    $report->error($before, 'has a number with an unknown unit');

                    return null;
                }
                if ($unit === 'px') {
                    $maxSeen = max($maxSeen, abs($num));
                }
                if (! $this->inBounds($num, $unit, $maxPx)) {
                    $report->error($before, 'has a number out of range: ' . Report::quote($m[0] . $unit));

                    return null;
                }
                if ($stack !== []) {
                    $stack[count($stack) - 1][1][] = [$num, $unit];
                }
                continue;
            }

            // identifier or function
            if (preg_match('/\G-{0,2}[A-Za-z_][A-Za-z0-9_-]*/', $value, $m, 0, $i)) {
                $word = $m[0];
                $i += strlen($word);
                if (strlen($word) > 40) {
                    $report->error($before, 'has a name that is too long');

                    return null;
                }
                if ($i < $n && $value[$i] === '(') {
                    $fn = strtolower($word);
                    if (! in_array($fn, $allowedFns, true)) {
                        $report->error($before, 'uses the function ' . Report::quote($fn) . '(), which is not allowed');

                        return null;
                    }
                    if (count($stack) >= Limits::NESTING) {
                        $report->error($before, 'is nested too deeply');

                        return null;
                    }
                    $i++;
                    if ($fn === 'var') {
                        // var( --name ) and nothing else: no fallback.
                        if (! preg_match('/\G *(--[A-Za-z0-9-]+) *\)/', $value, $v, 0, $i) || ! preg_match(self::VAR_NAME, $v[1])) {
                            $report->error($before, 'uses var() with something other than one --ts-* or --p-* name');

                            return null;
                        }
                        $vars[] = $v[1];
                        $i += strlen($v[0]);
                        if ($stack !== []) {
                            $stack[count($stack) - 1][1][] = 'var';
                        }
                        continue;
                    }
                    $stack[] = [$fn, []];
                    continue;
                }
                if (str_starts_with($word, '--')) {
                    $report->error($before, 'has a bare custom property name outside var()');

                    return null;
                }
                if ($stack !== []) {
                    $stack[count($stack) - 1][1][] = 'ident';
                }
                continue;
            }

            if ($c === '(') {
                // A bare group: only inside trusted calc().
                if (! $trusted) {
                    $report->error($before, 'has a parenthesis that is not part of a function');

                    return null;
                }
                if (count($stack) >= Limits::NESTING) {
                    $report->error($before, 'is nested too deeply');

                    return null;
                }
                $stack[] = ['(', []];
                $i++;
                continue;
            }
            if ($c === ')') {
                if ($stack === []) {
                    $report->error($before, 'has an unbalanced )');

                    return null;
                }
                [$fn, $args] = array_pop($stack);
                if (! $this->checkFunction($fn, $args, $before, $report)) {
                    return null;
                }
                $i++;
                continue;
            }
            if ($c === ',' || $c === '/') {
                $i++;
                continue;
            }
            if ($c === '+' || $c === '*' || $c === '-') {
                if (! $trusted) {
                    $report->error($before, 'may not use arithmetic operators');

                    return null;
                }
                $i++;
                continue;
            }

            $report->error($before, 'has something that is not allowed near ' . Report::quote(substr($value, $i, 12)));

            return null;
        }

        if ($stack !== []) {
            $report->error($before, 'has an unclosed parenthesis');

            return null;
        }

        return ['value' => $value, 'vars' => $vars, 'maxPx' => $maxSeen];
    }

    private function inBounds(float $num, string $unit, int $maxPx): bool
    {
        return match ($unit) {
            'px' => abs($num) <= $maxPx,
            'em', 'rem' => abs($num) <= 40,
            '%' => $num >= -1000 && $num <= 10000,
            'deg' => abs($num) <= 3600,
            'turn' => abs($num) <= 10,
            's' => $num >= 0 && $num <= ($this->mode === Mode::Bundled ? 120 : 5),
            'ms' => $num >= 0 && $num <= ($this->mode === Mode::Bundled ? 120000 : 5000),
            default => abs($num) <= 1000,
        };
    }

    /**
     * Per-function argument checks that the flat token pass can't express.
     *
     * @param  array<int, mixed>  $args
     */
    private function checkFunction(string $fn, array $args, string $where, Report $report): bool
    {
        if (! array_key_exists($fn, self::FILTERS)) {
            return true;
        }
        // A filter takes exactly one number, percentage or angle.
        if (count($args) !== 1 || ! is_array($args[0])) {
            $report->error($where, Report::quote($fn) . '() takes exactly one number');

            return false;
        }
        [$num, $unit] = $args[0];
        $range = self::FILTERS[$fn];
        if ($range === null) {  // hue-rotate: an angle (or 0)
            $ok = ($unit === 'deg' || $unit === 'turn' || ($unit === '' && $num == 0));
        } else {
            $scaled = $unit === '%' ? $num / 100 : $num;
            $ok = ($unit === '' || $unit === '%') && $scaled >= $range[0] && $scaled <= $range[1];
        }
        if (! $ok) {
            $report->error($where, Report::quote($fn) . '() argument is out of range');
        }

        return $ok;
    }
}
