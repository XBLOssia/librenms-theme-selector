<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\Mode;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\TokenFile;
use Xblossia\ThemeSelector\Skin\ZipBundleReader;

/**
 * The properties that must hold for ANY input, not just the ones we thought of.
 *
 * Mutation fuzzing: take valid inputs, corrupt them in random ways and splice
 * in known-dangerous fragments, and check that whatever the validator accepts
 * still satisfies the invariants. The seed is fixed so a failure is
 * reproducible; raise FUZZ_ROUNDS to run longer.
 *
 * Invariants for an ACCEPTED token file (upload mode):
 *   - the output passes OutputGuard (which knows nothing about the parser);
 *   - it contains exactly one html.dark block, no other selector or at-rule;
 *   - every declaration in it is a --ts-* or --p-* custom property;
 *   - none of the dangerous fragments survives verbatim.
 * Invariants for zip parsing: it never throws, never returns a name outside the
 * allowlist, and never returns more than the total limit.
 */
function test_fuzz(): void
{
    $rounds = (int) (getenv('FUZZ_ROUNDS') ?: 4000);
    mt_srand(20260929);

    T::group("fuzz: token files ($rounds mutations)");

    $seeds = [good_css(), (string) file_get_contents(__DIR__ . '/../skins/zerg/skin.css'), (string) file_get_contents(__DIR__ . '/../skins/terran/skin.css')];
    $bad = [
        'url(http://evil.example/x)', "url('//evil/x')", '@import "x";', '} body { display: none } html.dark {', '{', '}', ';', '\\75rl(x)', '\\', '/*', '*/',
        '!important', '<script>alert(1)</script>', 'javascript:alert(1)', 'expression(alert(1))', 'image-set(x)', "\0", "\xff\xfe", 'behavior:url(x)',
        '--ts-panel-before-content: "x";', '--ts-navbar-after-height: 9999px;', 'position: fixed;', 'var(--x, url(y))', 'calc(1px+2px)', ':', 'html.dark{', '@font-face{src:url(http://e/f)}',
        '--ts-bg: red; } body { color: red; ', '-moz-binding:url(x)', 'attr(x)', 'env(x)', '\\0075rl(', 'u\\rl(', 'ur\\6c(',
    ];
    $forbidden = ['url(http', "url('", '@import', '<script', 'javascript:', 'expression(', 'display: none', 'position: fixed', 'behavior', '-moz-binding', 'body {', 'body{'];

    $catalog = catalog();
    $accepted = 0;
    $violations = [];
    for ($i = 0; $i < $rounds; $i++) {
        $css = $seeds[mt_rand(0, count($seeds) - 1)];
        $n = strlen($css);
        for ($k = mt_rand(1, 4); $k > 0; $k--) {
            switch (mt_rand(0, 5)) {
                case 0:  // splice a dangerous fragment at a random position
                    $p = mt_rand(0, strlen($css));
                    $css = substr($css, 0, $p) . $bad[mt_rand(0, count($bad) - 1)] . substr($css, $p);
                    break;
                case 1:  // overwrite a byte with a printable one
                    $p = mt_rand(0, strlen($css) - 1);
                    $css[$p] = chr(mt_rand(0x20, 0x7e));
                    break;
                case 2:  // delete a chunk
                    $p = mt_rand(0, strlen($css) - 1);
                    $css = substr($css, 0, $p) . substr($css, $p + mt_rand(1, 40));
                    break;
                case 3:  // truncate
                    $css = substr($css, 0, mt_rand(0, strlen($css)));
                    break;
                case 4:  // duplicate a chunk
                    $p = mt_rand(0, strlen($css) - 1);
                    $len = mt_rand(1, 200);
                    $css = substr($css, 0, $p) . substr($css, $p, $len) . substr($css, $p);
                    break;
                default:  // insert a random printable string
                    $p = mt_rand(0, strlen($css));
                    $s = '';
                    for ($j = mt_rand(1, 12); $j > 0; $j--) {
                        $s .= chr(mt_rand(0x20, 0x7e));
                    }
                    $css = substr($css, 0, $p) . $s . substr($css, $p);
            }
            if ($css === '') {
                $css = 'x';
            }
        }

        $r = new Report();
        $out = (new TokenFile($catalog, Mode::Upload))->compile($css, [], $r);
        if ($out === null) {
            continue;
        }
        $accepted++;

        $problem = null;
        if (! OutputGuard::safe($out, 0)) {
            $problem = 'output guard refused it';
        } elseif (substr_count($out, 'html.dark {') !== 1 || preg_match('/^(?!html\.dark \{|\}|  --(?:ts|p)-[a-z0-9-]+: |\/\* Theme Selector skin)/m', $out) !== 0) {
            $problem = 'output has something other than one html.dark block of custom properties';
        } else {
            foreach ($forbidden as $f) {
                if (stripos($out, $f) !== false) {
                    $problem = "output contains '$f'";
                    break;
                }
            }
        }
        if ($problem !== null) {
            $violations[] = $problem . ' | input: ' . substr(json_encode($css), 0, 300);
        }
    }
    T::ok("no accepted mutation violated an invariant ($accepted of $rounds were accepted)", $violations === [], implode("\n        ", array_slice($violations, 0, 3)));
    T::ok('the fuzzer both accepts and rejects (it is exercising both paths)', $accepted > 0 && $accepted < $rounds, "accepted $accepted of $rounds");

    T::group("fuzz: zip parsing ($rounds mutations)");
    $valid = ZipBuilder::build([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8],
        ['name' => 'skin.css', 'data' => good_css(), 'method' => 8],
        ['name' => 'graph.conf', 'data' => "graph_colours.greens=[\"FFFFFF\"]\n"],
        ['name' => 'fonts/a.woff2', 'data' => fake_font(), 'method' => 8],
    ]);
    $allowed = '#^(skin\.json|skin\.css|graph\.conf|fonts/[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(woff2|woff))\z#D';
    $crashed = [];
    $bad = [];
    $ok = 0;
    for ($i = 0; $i < $rounds; $i++) {
        $z = $valid;
        for ($k = mt_rand(1, 6); $k > 0; $k--) {
            $p = mt_rand(0, strlen($z) - 1);
            switch (mt_rand(0, 3)) {
                case 0: $z[$p] = chr(mt_rand(0, 255)); break;
                case 1: $z = substr($z, 0, $p) . substr($z, $p + mt_rand(1, 20)); break;
                case 2: $z = substr($z, 0, mt_rand(0, strlen($z))); break;
                default: $z = substr($z, 0, $p) . chr(mt_rand(0, 255)) . substr($z, $p);
            }
            if ($z === '') {
                $z = 'x';
            }
        }
        try {
            $files = ZipBundleReader::parse($z, new Report());
        } catch (Throwable $e) {
            $crashed[] = get_class($e) . ': ' . $e->getMessage();
            continue;
        }
        if ($files === null) {
            continue;
        }
        $ok++;
        $total = 0;
        foreach ($files as $name => $data) {
            $total += strlen($data);
            if (! preg_match($allowed, (string) $name)) {
                $bad[] = 'name escaped the allowlist: ' . json_encode($name);
            }
        }
        if ($total > Xblossia\ThemeSelector\Skin\Limits::UNCOMPRESSED_TOTAL) {
            $bad[] = 'returned more than the total limit';
        }
    }
    T::ok('no mutated archive made the reader throw', $crashed === [], implode(' | ', array_slice(array_unique($crashed), 0, 3)));
    T::ok('no mutated archive produced a name outside the allowlist', $bad === [], implode(' | ', array_slice($bad, 0, 3)));
    T::ok('the fuzzer both accepts and rejects archives', $ok > 0 && $ok < $rounds, "accepted $ok of $rounds");
}
