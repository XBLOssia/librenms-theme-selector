<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Parses a skin's token file (skin.css) and writes the stylesheet that is
 * actually served.
 *
 * The grammar is deliberately tiny. A token file is a sequence of:
 *
 *     html.dark { --ts-name: value; --p-name: value; ... }
 *     @font-face { font-family: "Name"; src: url("fonts/file.woff2") format("woff2"); ... }
 *
 * and nothing else: no other selector, no other at-rule, no property that
 * isn't a custom property, no comment inside a value. That leaves a skin no way
 * to reach an element, only to supply values to hooks the base stylesheet
 * already exposes.
 *
 * WHAT IS SERVED IS NEVER WHAT WAS UPLOADED. The file is scanned by a
 * character-level reader (not a regex over the whole file), every value goes
 * through ValueValidator, and the stylesheet is then re-emitted from the parsed
 * result. Fonts are embedded in it as base64 data: URLs, so no uploaded byte
 * is ever a file in the web root. The output is then checked once more by
 * OutputGuard, so a bug here still can't publish something the rules forbid.
 *
 * Names:
 *   --ts-*  must be in the token catalog (unknown is rejected); in Upload mode
 *           it must not be structural. A value may reference other tokens only
 *           through var(), and only known non-structural ones.
 *   --p-*   the skin's own palette, free-form names, used through var().
 *   any other custom property, or any property at all, is rejected. In
 *   particular a skin can't set core's --tw-* colours: base.css does that.
 */
final class TokenFile
{
    private const PRIVATE_NAME = '/^--p-[a-z0-9][a-z0-9-]{0,40}\z/D';
    private const FACE_PROPS = ['font-family', 'src', 'font-weight', 'font-style', 'font-display', 'unicode-range'];

    private readonly ValueValidator $values;

    public function __construct(private readonly TokenCatalog $catalog, private readonly Mode $mode)
    {
        $this->values = new ValueValidator($mode);
    }

    /**
     * @param  array<string, string>  $fonts  file name (as in the bundle, "fonts/x.woff2") => bytes, already checked by FontFile
     * @return string|null  the stylesheet to serve, or null (see $report)
     */
    public function compile(string $css, array $fonts, Report $report): ?string
    {
        $local = new Report();
        $out = $this->run($css, $fonts, $local);
        $report->merge($local);

        return $local->ok() ? $out : null;
    }

    /**
     * @param  array<string, string>  $fonts
     */
    private function run(string $css, array $fonts, Report $report): ?string
    {
        if (strlen($css) > Limits::CSS_BYTES) {
            $report->error('skin.css', 'is larger than ' . Limits::CSS_BYTES . ' bytes');

            return null;
        }
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $css, $m, PREG_OFFSET_CAPTURE)) {
            $report->error('skin.css', 'contains a non-ASCII or control character on line ' . (substr_count($css, "\n", 0, $m[0][1]) + 1));

            return null;
        }
        $css = str_replace(["\r\n", "\r"], "\n", $css);

        $parsed = $this->scan($css, $report);
        if ($parsed === null) {
            return null;
        }
        [$root, $faces] = $parsed;

        if (count($root) > Limits::DECLARATIONS) {
            $report->error('skin.css', 'has more than ' . Limits::DECLARATIONS . ' declarations');

            return null;
        }
        if (count($faces) > Limits::FONT_FACES) {
            $report->error('skin.css', 'has more than ' . Limits::FONT_FACES . ' @font-face blocks');

            return null;
        }

        $decls = $this->declarations($root, $report);
        $fontCss = $this->fontFaces($faces, $fonts, $report);
        if ($decls !== null && $report->ok() && ! array_filter(array_keys($decls), fn ($n) => str_starts_with($n, '--ts-'))) {
            $report->error('skin.css', 'sets no --ts-* tokens, so it would change nothing');
        }
        if (! $report->ok() || $decls === null || $fontCss === null) {
            return null;
        }

        $out = "/* Theme Selector skin: generated from a validated bundle. Do not edit. */\n";
        foreach ($fontCss as $block) {
            $out .= $block;
        }
        $out .= "html.dark {\n";
        foreach ($decls as $name => $value) {
            $out .= "  $name: $value;\n";
        }
        $out .= "}\n";

        if (strlen($out) > Limits::OUTPUT_BYTES) {
            $report->error('skin.css', 'produces a stylesheet larger than allowed');

            return null;
        }
        if ($this->mode === Mode::Upload && ! OutputGuard::safe($out, count($fontCss))) {
            $report->error('skin.css', 'failed the final safety check (this is a bug; please report it)');

            return null;
        }

        return $out;
    }

    // ---- scanning ---------------------------------------------------------------

    /**
     * @return array{0: array<int, array{0: string, 1: string, 2: int}>, 1: array<int, array<int, array{0: string, 1: string, 2: int}>>}|null
     */
    private function scan(string $css, Report $report): ?array
    {
        $n = strlen($css);
        $pos = 0;
        $root = [];
        $faces = [];
        $line = fn (int $p): int => substr_count($css, "\n", 0, min($p, $n)) + 1;

        $skip = function () use ($css, $n, &$pos, $report, $line): bool {
            while ($pos < $n) {
                $c = $css[$pos];
                if ($c === ' ' || $c === "\n" || $c === "\t") {
                    $pos++;
                } elseif ($c === '/' && ($css[$pos + 1] ?? '') === '*') {
                    $end = strpos($css, '*/', $pos + 2);
                    if ($end === false) {
                        $report->error('skin.css', 'has an unterminated comment on line ' . $line($pos));

                        return false;
                    }
                    $pos = $end + 2;
                } else {
                    break;
                }
            }

            return true;
        };

        while (true) {
            if (! $skip()) {
                return null;
            }
            if ($pos >= $n) {
                break;
            }

            if ($css[$pos] === '@') {
                preg_match('/\G@[A-Za-z-]*/', $css, $m, 0, $pos);
                if ($m[0] !== '@font-face') {
                    $report->error('skin.css', 'line ' . $line($pos) . ': ' . Report::quote($m[0]) . ' is not allowed (only @font-face)');

                    return null;
                }
                $pos += strlen($m[0]);
                $kind = 'face';
            } else {
                $brace = strpos($css, '{', $pos);
                if ($brace === false) {
                    $report->error('skin.css', 'line ' . $line($pos) . ': expected a block');

                    return null;
                }
                $selector = trim(preg_replace('/\s+/', ' ', substr($css, $pos, $brace - $pos)));
                if ($selector !== 'html.dark') {
                    $report->error('skin.css', 'line ' . $line($pos) . ': the selector ' . Report::quote($selector) . ' is not allowed; a skin may only use "html.dark { ... }" and @font-face');

                    return null;
                }
                $pos = $brace;
                $kind = 'root';
            }

            if (! $skip()) {
                return null;
            }
            if (($css[$pos] ?? '') !== '{') {
                $report->error('skin.css', 'line ' . $line($pos) . ': expected {');

                return null;
            }
            $pos++;

            $block = [];
            while (true) {
                if (! $skip()) {
                    return null;
                }
                if ($pos >= $n) {
                    $report->error('skin.css', 'ends inside a block (missing })');

                    return null;
                }
                if ($css[$pos] === '}') {
                    $pos++;
                    break;
                }
                if (! preg_match('/\G[A-Za-z-][A-Za-z0-9_-]*/', $css, $m, 0, $pos)) {
                    $report->error('skin.css', 'line ' . $line($pos) . ': expected a property name');

                    return null;
                }
                $name = $m[0];
                $declLine = $line($pos);
                $pos += strlen($name);
                if (! $skip() || ($css[$pos] ?? '') !== ':') {
                    $report->error('skin.css', 'line ' . $declLine . ': expected : after ' . Report::quote($name));

                    return null;
                }
                $pos++;

                // The value runs to the first ; or } outside parentheses and quotes.
                $start = $pos;
                $depth = 0;
                $quote = null;
                for (; ; $pos++) {
                    if ($pos >= $n) {
                        $report->error('skin.css', 'line ' . $declLine . ': the value of ' . Report::quote($name) . ' is not terminated');

                        return null;
                    }
                    $c = $css[$pos];
                    if ($quote !== null) {
                        if ($c === $quote) {
                            $quote = null;
                        } elseif ($c === "\n") {
                            $report->error('skin.css', 'line ' . $declLine . ': a string runs over a line break');

                            return null;
                        }
                        continue;
                    }
                    if ($c === '"' || $c === "'") {
                        $quote = $c;
                    } elseif ($c === '(') {
                        $depth++;
                    } elseif ($c === ')') {
                        if (--$depth < 0) {
                            $report->error('skin.css', 'line ' . $declLine . ': unbalanced )');

                            return null;
                        }
                    } elseif ($c === '{') {
                        $report->error('skin.css', 'line ' . $declLine . ': { is not allowed inside a value');

                        return null;
                    } elseif ($depth === 0 && ($c === ';' || $c === '}')) {
                        break;
                    }
                }
                $raw = substr($css, $start, $pos - $start);
                if ($css[$pos] === ';') {
                    $pos++;
                }
                $block[] = [$name, $raw, $declLine];
                if ($kind === 'root' && count($root) + count($block) > Limits::DECLARATIONS + 1) {
                    $report->error('skin.css', 'has too many declarations');

                    return null;
                }
            }

            if ($kind === 'root') {
                array_push($root, ...$block);
            } else {
                $faces[] = $block;
                if (count($faces) > Limits::FONT_FACES + 1) {
                    $report->error('skin.css', 'has too many @font-face blocks');

                    return null;
                }
            }
        }

        return [$root, $faces];
    }

    // ---- html.dark declarations ---------------------------------------------------

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $root
     * @return array<string, string>|null  name => canonical value, in source order
     */
    private function declarations(array $root, Report $report): ?array
    {
        $upload = $this->mode === Mode::Upload;
        $out = [];
        $refs = [];          // name => var() names it uses
        $private = [];       // --p-* name => largest px length in its value
        $caps = [];          // --ts-* name => its px cap
        $failed = false;

        foreach ($root as [$name, $raw, $line]) {
            $where = Report::quote($name) . ' (line ' . $line . ')';
            if (isset($out[$name])) {
                $report->error($where, 'is set more than once');
                $failed = true;
                continue;
            }

            if (preg_match(self::PRIVATE_NAME, $name)) {
                if (count($private) >= Limits::PRIVATE_TOKENS) {
                    $report->error('skin.css', 'has more than ' . Limits::PRIVATE_TOKENS . ' --p-* palette entries');

                    return null;
                }
                $res = $this->values->validate($raw, $where, 800, true, $report);
                if ($res === null) {
                    $failed = true;
                    continue;
                }
                $private[$name] = $res['maxPx'];
            } elseif (str_starts_with($name, '--ts-')) {
                if (! $this->catalog->has($name)) {
                    $report->error($where, 'is not a Theme Selector token (see docs/TOKENS.md)');
                    $failed = true;
                    continue;
                }
                if ($upload && $this->catalog->isStructural($name)) {
                    $report->error($where, 'is a structural token (position, size, generated content, clip-path or motion) that only bundled skins may set');
                    $failed = true;
                    continue;
                }
                // A cut corner is a size in px (0px for none). Not %, em, a bare 0 (which is
                // invalid inside the polygon's calc()) or a var(): those would scale past the
                // px cap, or make the whole clip-path invalid.
                if (in_array('chamfer', $this->catalog->kinds($name), true)
                    && ! preg_match('/^[0-9]{1,2}(\.[0-9]{1,3})?px$/D', trim($raw))) {
                    $report->error($where, 'must be a size in px, for example 6px (0px for a square corner)');
                    $failed = true;
                    continue;
                }
                // Motion and glow for the ornament layers: a period of 2s to 60s, a fade depth
                // of .3 to 1, or one literal colour. Nothing else, and never a var(): a
                // palette value could carry a comma and with it a second, enormous shadow.
                $strict = null;
                $kinds = $this->catalog->kinds($name);
                if (in_array('period', $kinds, true)) {
                    $strict = ['/^(?:[2-9]|[1-5][0-9])(?:\.[0-9]{1,2})?s$|^60s$/D', 'must be a period of 2s to 60s, for example 6s'];
                } elseif (in_array('level', $kinds, true)) {
                    $strict = ['/^(?:0?\.[3-9][0-9]{0,2}|1(?:\.0{1,3})?)$/D', 'must be a plain number from .3 to 1'];
                } elseif (in_array('glowcolor', $kinds, true)) {
                    $strict = ['/^(?:#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|(?:rgba?|hsla?)\([0-9.%, \/]{3,40}\))$/D', 'must be one plain colour (#rrggbb, rgb(), rgba(), hsl() or hsla()), not a var() or a list'];
                }
                if ($strict !== null) {
                    if (! preg_match($strict[0], trim($raw))) {
                        $report->error($where, $strict[1]);
                        $failed = true;
                        continue;
                    }
                    if (! in_array('glowcolor', $kinds, true)) {
                        // Fully determined by the pattern above; the generic bounds (5s at most
                        // for a time) are for other tokens.
                        $out[$name] = trim($raw);
                        $refs[$name] = [];
                        $caps[$name] = 800;
                        continue;
                    }
                }
                // The steepness of a cut: a plain number from 0.5 to 2 (1.732 is 60 degrees).
                if (in_array('ratio', $this->catalog->kinds($name), true)
                    && ! preg_match('/^(0?\.[5-9][0-9]{0,2}|1(\.[0-9]{1,3})?|2(\.0{1,3})?)$/D', trim($raw))) {
                    $report->error($where, 'must be a plain number from 0.5 to 2 (1.732 makes a 60 degree cut)');
                    $failed = true;
                    continue;
                }
                $res = $this->values->validate(
                    $raw,
                    $where,
                    $this->catalog->maxPx($name),
                    in_array('font', $this->catalog->kinds($name), true),
                    $report,
                );
                if ($res === null) {
                    $failed = true;
                    continue;
                }
                $caps[$name] = $this->catalog->maxPx($name);
            } else {
                $report->error($where, 'is not allowed: a skin may only set --ts-* tokens and its own --p-* palette');
                $failed = true;
                continue;
            }

            $out[$name] = $res['value'];
            $refs[$name] = $res['vars'];
        }
        if ($failed) {
            return null;
        }

        // var() may only point at something that exists (and, for uploads,
        // isn't structural); palette entries may not loop.
        foreach ($refs as $name => $vars) {
            foreach ($vars as $var) {
                $ok = str_starts_with($var, '--p-')
                    ? isset($private[$var])
                    : $this->catalog->has($var) && ! ($upload && $this->catalog->isStructural($var));
                if (! $ok) {
                    $report->error(Report::quote($name), 'refers to ' . Report::quote($var) . ', which is not defined or not allowed');
                    $failed = true;
                }
            }
        }
        if ($failed) {
            return null;
        }
        foreach (array_keys($private) as $start) {
            if ($this->reaches($start, $start, $refs, [])) {
                $report->error(Report::quote($start), 'refers to itself through other palette entries');

                return null;
            }
        }

        // A palette entry is only bounded at 800px on its own. Where a token
        // with a tighter cap uses it (a 100px shadow, a 24px border), the
        // entry's own lengths must fit that cap too, or the palette would be a
        // way round the token's limit.
        foreach ($caps as $name => $cap) {
            foreach ($this->privateClosure($refs[$name], $refs) as $p) {
                if (($private[$p] ?? 0) > $cap) {
                    $report->error(Report::quote($name), 'uses ' . Report::quote($p) . ', which holds a length larger than this token allows (' . $cap . 'px)');
                    $failed = true;
                }
            }
        }

        return $failed ? null : $out;
    }

    /**
     * @param  array<string, string[]>  $refs
     * @param  string[]  $path
     */
    private function reaches(string $from, string $target, array $refs, array $path): bool
    {
        foreach ($refs[$from] ?? [] as $var) {
            if (! str_starts_with($var, '--p-')) {
                continue;
            }
            if ($var === $target) {
                return true;
            }
            if (in_array($var, $path, true)) {
                continue;
            }
            if ($this->reaches($var, $target, $refs, [...$path, $var])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every --p-* name reachable from a list of var() names.
     *
     * @param  string[]  $vars
     * @param  array<string, string[]>  $refs
     * @return string[]
     */
    private function privateClosure(array $vars, array $refs): array
    {
        $seen = [];
        $queue = $vars;
        while ($queue !== []) {
            $v = array_pop($queue);
            if (! str_starts_with($v, '--p-') || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            array_push($queue, ...($refs[$v] ?? []));
        }

        return array_keys($seen);
    }

    // ---- @font-face ---------------------------------------------------------------

    /**
     * @param  array<int, array<int, array{0: string, 1: string, 2: int}>>  $faces
     * @param  array<string, string>  $fonts
     * @return string[]|null  one stylesheet block per face
     */
    private function fontFaces(array $faces, array $fonts, Report $report): ?array
    {
        $blocks = [];
        $used = [];
        $ok = true;

        foreach ($faces as $i => $decls) {
            $where = '@font-face #' . ($i + 1);
            $set = [];
            foreach ($decls as [$name, $raw, $line]) {
                if (! in_array($name, self::FACE_PROPS, true)) {
                    $report->error($where, Report::quote($name) . ' is not allowed here');
                    $ok = false;
                    continue 2;
                }
                if (isset($set[$name])) {
                    $report->error($where, Report::quote($name) . ' is set more than once');
                    $ok = false;
                    continue 2;
                }
                $set[$name] = trim(preg_replace('/\s+/', ' ', $raw));
            }
            if (! isset($set['font-family'], $set['src'])) {
                $report->error($where, 'needs font-family and src');
                $ok = false;
                continue;
            }

            if (! preg_match('/^(["\'])([A-Za-z0-9 ]{1,40})\1\z/D', $set['font-family'], $fam)) {
                $report->error($where, 'font-family must be one quoted name of letters, digits and spaces');
                $ok = false;
                continue;
            }
            if (! preg_match('#^url\(\s*(["\'])(fonts/([A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(woff2|woff)))\1\s*\)\s*format\(\s*(["\'])(woff2|woff)\5\s*\)\z#D', $set['src'], $src)
                || $src[4] !== $src[6]) {
                $report->error($where, 'src must be exactly url("fonts/<file>.woff2") format("woff2") (or woff)');
                $ok = false;
                continue;
            }
            $file = $src[2];
            if (! isset($fonts[$file])) {
                $report->error($where, 'refers to ' . Report::quote($file) . ', which is not in the bundle');
                $ok = false;
                continue;
            }
            $used[$file] = true;

            $extra = '';
            $checks = [
                'font-weight' => '/^(normal|bold|[1-9]00( [1-9]00)?)\z/D',
                'font-style' => '/^(normal|italic)\z/D',
                'font-display' => '/^(auto|block|swap|fallback|optional)\z/D',
                'unicode-range' => '/^U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(, ?U\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?){0,60}\z/D',
            ];
            foreach ($checks as $prop => $pattern) {
                if (! isset($set[$prop])) {
                    continue;
                }
                if (! preg_match($pattern, $set[$prop])) {
                    $report->error($where, Report::quote($prop) . ' has an invalid value');
                    $ok = false;
                    continue 2;
                }
                $extra .= "  $prop: {$set[$prop]};\n";
            }

            $url = $this->mode === Mode::Upload
                ? 'data:font/' . $src[6] . ';base64,' . base64_encode($fonts[$file])
                : $file;
            $blocks[] = "@font-face {\n  font-family: \"{$fam[2]}\";\n  src: url(\"$url\") format(\"{$src[6]}\");\n$extra}\n";
        }

        foreach (array_keys($fonts) as $file) {
            if (! isset($used[$file])) {
                $report->error('bundle', Report::quote($file) . ' is not used by any @font-face');
                $ok = false;
            }
        }

        return $ok ? $blocks : null;
    }
}
