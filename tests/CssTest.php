<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\Mode;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\TokenCatalog;
use Xblossia\ThemeSelector\Skin\TokenFile;

function catalog(): TokenCatalog
{
    static $c = null;

    return $c ??= TokenCatalog::fromFile(__DIR__ . '/../resources/token-catalog.json');
}

/**
 * @param  array<string, string>  $fonts
 */
function css_compile(string $css, array $fonts = [], Mode $mode = Mode::Upload, ?Report &$report = null): ?string
{
    $report = new Report();

    return (new TokenFile(catalog(), $mode))->compile($css, $fonts, $report);
}

/** A block of html.dark declarations. */
function dark(string ...$decls): string
{
    return "html.dark {\n" . implode("\n", $decls) . "\n}\n";
}

function css_bad(string $what, string $css, ?string $mentions = null, array $fonts = [], Mode $mode = Mode::Upload): void
{
    $out = css_compile($css, $fonts, $mode, $r);
    T::ok($what . ' is rejected', $out === null && ! $r->ok(), $out === null ? 'no errors reported' : "was ACCEPTED, output: " . substr($out, 0, 200));
    if ($mentions !== null && $out === null) {
        $msgs = implode(' | ', $r->errors());
        T::ok($what . " explains why ('$mentions')", stripos($msgs, $mentions) !== false, "errors: $msgs");
    }
}

function css_good(string $what, string $css, array $fonts = [], Mode $mode = Mode::Upload): ?string
{
    $out = css_compile($css, $fonts, $mode, $r);
    T::ok($what . ' is accepted', $out !== null, implode(' | ', $r->errors()));

    return $out;
}

function test_css(): void
{
    T::group('css: what is accepted');
    $out = css_good('the minimal example (20 core roles)', good_css());
    T::ok('its output contains only the html.dark block', $out !== null && substr_count($out, '{') === 1 && str_contains($out, 'html.dark {'));
    T::ok('its output passes the independent output guard', $out !== null && OutputGuard::safe($out, 0));
    css_bad('an empty html.dark block', dark(), 'sets no');
    css_good('a palette alongside a token', dark('--p-a: #123456;', '--p-b: rgba(1, 2, 3, .5);', '--ts-bg: var(--p-a);'));
    css_bad('a palette with no tokens', dark('--p-a: #123456;'), 'sets no');
    css_good('comments between declarations', "/* hello */\nhtml.dark { /* c */ --ts-bg: #000; /* d */ }\n/* end */");
    css_good('a palette used through var()', dark('--p-bg: #101010;', '--ts-bg: var(--p-bg);'));
    css_good('gradients, shadows and filters', dark(
        '--ts-navbar-bg-image: linear-gradient(180deg, #2b343b 0%, #1e252a 100%);',
        '--ts-panel-shadow: inset 0 1px 0 rgba(255, 255, 255, .06), 0 3px 10px rgba(0, 0, 0, .4);',
        '--ts-btn-hover-filter: brightness(1.2) saturate(1.1);',
        '--ts-map-tile-filter: invert(1) hue-rotate(180deg) brightness(0.72) contrast(1.02) saturate(0.32) sepia(0.18);',
    ));
    css_good('a font family list (a font token may hold strings)', dark('--ts-font-display: "Some Font", "Other", system-ui, sans-serif;'));
    css_good('color-mix', dark('--ts-surface-raised: color-mix(in srgb, #202020 60%, #ffffff);'));
    css_good('CRLF line endings', str_replace("\n", "\r\n", good_css()));
    css_good('no trailing semicolon on the last declaration', "html.dark { --ts-bg: #000 }");
    css_good('several html.dark blocks', dark('--ts-bg: #000;') . dark('--ts-text: #fff;'));

    T::group('css: only html.dark and @font-face may appear');
    foreach ([
        'body selector' => 'body { --ts-bg: red; }',
        'html.dark, body list' => 'html.dark, body { --ts-bg: red; }',
        'html.dark body descendant' => 'html.dark body { --ts-bg: red; }',
        'html selector' => 'html { --ts-bg: red; }',
        ':root selector' => ':root { --ts-bg: red; }',
        'universal selector' => '* { --ts-bg: red; }',
        'attribute selector' => 'html.dark[data-x] { --ts-bg: red; }',
        'html.dark:hover' => 'html.dark:hover { --ts-bg: red; }',
        'html.light' => 'html.light { --ts-bg: red; }',
        'a class' => '.evil { --ts-bg: red; }',
        'an id' => '#evil { --ts-bg: red; }',
        'html.dark ::before' => 'html.dark::before { --ts-bg: red; }',
    ] as $what => $css) {
        css_bad($what, $css, 'selector');
    }
    foreach (['@import', '@charset', '@namespace', '@media', '@keyframes', '@supports', '@page', '@layer', '@property', '@container'] as $at) {
        css_bad("the $at at-rule", "$at x { --ts-bg: red; }", 'not allowed');
        css_bad("the $at at-rule after a good block", good_css() . "\n$at x;\n", 'not allowed');
    }
    css_bad('@import url()', "@import url(http://evil.example/x.css);\n" . good_css(), 'not allowed');
    css_bad('@import string', "@import 'http://evil.example/x.css';\n" . good_css(), 'not allowed');

    T::group('css: only custom properties, only ours');
    css_bad('a normal property', dark('color: red;'), 'not allowed');
    css_bad('background', dark('background: url(x);'), 'not allowed');
    css_bad('display', dark('display: none;'), 'not allowed');
    css_bad('a vendor property', dark('-moz-binding: url(x);'), 'not allowed');
    css_bad('behavior', dark('behavior: url(x.htc);'), 'not allowed');
    css_bad('an unknown --ts token', dark('--ts-made-up: red;'), 'not a Theme Selector token');
    css_bad('a core tailwind variable', dark('--tw-color-dark-gray-500: red;'), 'not allowed');
    css_bad('a foreign custom property', dark('--evil: red;'), 'not allowed');
    css_bad('an upper-case token name', dark('--TS-BG: red;'), 'not allowed');
    css_bad('a bare --ts- prefix', dark('--ts-: red;'), 'not a Theme Selector token');
    css_bad('a private name with a bad shape', dark('--p-Bad_Name: red;'), 'not allowed');
    css_bad('a duplicate declaration', dark('--ts-bg: red;', '--ts-bg: blue;'), 'more than once');
    css_bad('a duplicate across blocks', dark('--ts-bg: red;') . dark('--ts-bg: blue;'), 'more than once');
    css_good('a space before the colon', dark('--ts-bg : #000;'));

    T::group('css: structural tokens are refused for uploads');
    foreach ([
        '--ts-panel-before-content: "Your session has expired. Sign in again.";',
        '--ts-panel-heading-after-content: "x";',
        '--ts-panel-before-position: fixed;',
        '--ts-panel-before-width: 100vw;',
        '--ts-panel-before-height: 100px;',
        '--ts-panel-before-z-index: 999999;',
        '--ts-panel-before-pointer-events: auto;',
        '--ts-panel-before-top: 0;',
        '--ts-navbar-after-height: 9999px;',
        '--ts-navbar-after-animation: spin 1s infinite;',
        '--ts-btn-clip-path: circle(1px);',
        '--ts-dropdown-submenu-margin-top: 500px;',
    ] as $decl) {
        css_bad("structural token: $decl", dark($decl), 'structural');
    }
    // and none of them can be reached indirectly
    css_bad('a var() to a structural token', dark('--ts-bg: var(--ts-panel-before-width);'), 'not defined or not allowed');
    css_bad('a var() to a structural token via the palette', dark('--p-x: var(--ts-navbar-after-height);'), 'not defined or not allowed');

    T::group('css: url(), imports and every other way to load something');
    foreach ([
        'url(http://evil.example/x.png)', 'url("http://evil.example/x.png")', "url('//evil.example/x')", 'url(data:image/png;base64,AAAA)',
        'url(javascript:alert(1))', 'URL(x)', 'Url(x)', 'uRl(x)', 'url (x)', 'linear-gradient(url(x), red)', 'image-set("x" 1x)',
        '-webkit-image-set(url(x) 1x)', 'element(#x)', 'paint(evil)', 'src(x)', 'attr(href)', 'env(x)', 'expression(alert(1))',
        'cross-fade(url(a), url(b), 50%)', 'image(url(x))', 'local(Arial)', 'format("woff2")',
    ] as $v) {
        css_bad("image token holding $v", dark("--ts-navbar-bg-image: $v;"), null);
        css_bad("colour token holding $v", dark("--ts-bg: $v;"), null);
        css_bad("palette entry holding $v", dark("--p-x: $v;"), null);
    }

    T::group('css: escaping and breaking out of a value');
    foreach ([
        'backslash escape of url' => dark('--p-x: u\72l(http://evil);'),
        'backslash escape in ident' => dark('--p-x: \75rl(x);'),
        'escaped quote' => dark('--ts-font-display: "a\"b";'),
        'unicode escape' => dark('--p-x: \0075rl(x);'),
        'value closes the block' => "html.dark { --ts-bg: red; } body { display: none }",
        'value contains }' => "html.dark { --ts-bg: red } body { display:none } { }",
        'value contains {' => dark('--ts-bg: red { color: blue };'),
        'value contains ;' => dark('--ts-bg: red; color: blue;'),
        'value contains a second declaration' => dark('--ts-bg: red; --ts-text: blue; display: none;'),
        'value contains an at-rule' => dark('--ts-bg: red @import "x";'),
        'important' => dark('--ts-bg: red !important;'),
        'a comment in a value' => dark('--ts-bg: red/*x*/;'),
        'an opened comment in a value' => dark('--ts-bg: red /* ;'),
        'a closed comment trick' => dark('--ts-bg: red /* */ blue;'),
        'angle brackets' => dark('--ts-bg: <script>;'),
        'closing style tag' => dark('--ts-bg: </style><script>alert(1)</script>;'),
        'colon in a value' => dark('--ts-bg: progid:DXImageTransform.Microsoft.gradient(x);'),
        'pipe' => dark('--ts-bg: red | blue;'),
        'backtick' => dark('--ts-bg: `x`;'),
        'dollar' => dark('--ts-bg: $x;'),
        'equals' => dark('--ts-bg: a=b;'),
        'square brackets' => dark('--ts-bg: [x];'),
        'question mark' => dark('--ts-bg: x?;'),
        'ampersand' => dark('--ts-bg: a&b;'),
        'tilde' => dark('--ts-bg: ~x;'),
        'caret' => dark('--ts-bg: ^x;'),
        'exclamation' => dark('--ts-bg: red !ie;'),
    ] as $what => $css) {
        css_bad($what, $css);
    }

    T::group('css: characters and structure');
    css_bad('a non-ASCII character', dark('--ts-text: #fff; /* caf' . "\xc3\xa9" . ' */'), 'non-ASCII');
    css_bad('a byte order mark', "\xEF\xBB\xBF" . good_css(), 'non-ASCII');
    css_bad('a NUL byte', "html.dark { --ts-bg: red;\0 }", 'control');
    css_bad('a form feed', "html.dark { --ts-bg: red;\x0c }", 'control');
    css_bad('an escape character', "html.dark { --ts-bg: red;\x1b }", 'control');
    css_bad('an unterminated block', 'html.dark { --ts-bg: red;', 'ends inside');
    css_bad('an unterminated value', 'html.dark { --ts-bg: red', 'not terminated');
    css_bad('an unterminated comment', "html.dark { --ts-bg: red; } /* oops", 'unterminated');
    css_bad('an unterminated string', 'html.dark { --ts-font-display: "abc; }', null);
    css_bad('a string with a line break', "html.dark { --ts-font-display: \"abc\ndef\"; }", 'line break');
    css_bad('a stray closing brace', good_css() . "\n}\n", 'expected');
    css_bad('a nested block', 'html.dark { html.dark { --ts-bg: red; } }', null);
    css_bad('a missing colon', 'html.dark { --ts-bg red; }', 'expected');
    css_bad('a declaration with no value', dark('--ts-bg: ;'), 'empty');
    css_bad('an empty file', '', 'sets no');
    css_bad('a value of only spaces', dark('--ts-bg:     ;'), 'empty');
    css_bad('an unbalanced (', dark('--ts-bg: rgba(0,0,0;'), null);
    css_bad('an unbalanced )', dark('--ts-bg: red);'), null);

    T::group('css: values are bounded');
    css_bad('a shadow with a huge blur', dark('--ts-panel-shadow: 0 0 5000px red;'), 'out of range');
    css_bad('a shadow just over the shadow cap', dark('--ts-panel-shadow: 0 0 101px red;'), 'out of range');
    css_good('a shadow at the cap', dark('--ts-panel-shadow: 0 0 100px red;'));
    css_bad('a huge border width', dark('--ts-panel-border: 500px solid red;'), 'out of range');
    css_bad('a huge padding', dark('--ts-badge-padding: 900px;'), 'out of range');
    css_bad('a huge radius', dark('--ts-radius-sm: 9999px;'), 'out of range');
    css_bad('a huge px in a colour token', dark('--ts-bg: linear-gradient(red 0, blue 999999px);'), 'out of range');
    css_bad('an enormous number', dark('--ts-bg: 99999999999999999999;'), 'out of range');
    css_bad('a number with an exponent', dark('--ts-bg: 1e3px;'), 'unknown unit');
    foreach (['5vw', '5vh', '5vmin', '5ch', '5ex', '5cm', '5mm', '5in', '5pt', '5pc', '5fr', '5dpi', '5x', '5em3'] as $u) {
        css_bad("the unit in $u", dark("--ts-panel-shadow: 0 0 $u red;"), 'unit');
    }
    css_bad('a duration over five seconds', dark('--ts-btn-transition: filter 60s linear;'), 'out of range');
    css_bad('a negative duration', dark('--ts-btn-transition: filter -1s linear;'), 'out of range');
    css_bad('an invalid hex colour', dark('--ts-bg: #12345;'), 'invalid');
    css_bad('a hex colour with junk', dark('--ts-bg: #12345z;'), 'invalid');
    css_bad('a 5-digit hex', dark('--ts-bg: #12345;'), 'invalid');
    css_bad('a 9-digit hex', dark('--ts-bg: #123456789;'), 'invalid');
    css_bad('an over-long value', dark('--p-x: ' . str_repeat('#fff ', 300) . ';'), 'too long');
    css_bad('too many parts in a value', dark('--p-x: ' . implode(' ', array_fill(0, 250, '1px')) . ';'), null);
    css_bad('a value nested too deeply', dark('--p-x: rgb(rgb(rgb(rgb(rgb(rgb(rgb(1))))))) ;'), 'nested');
    css_bad('a long identifier', dark('--p-x: ' . str_repeat('a', 60) . ';'), 'too long');
    css_bad('a string in a colour token', dark('--ts-bg: "red";'), 'quoted string');
    css_bad('a string with punctuation', dark('--ts-font-display: "a;b";'), 'not allowed');
    css_bad('a string with markup', dark('--ts-font-display: "<b>";'), 'not allowed');
    css_bad('a bare custom property name', dark('--ts-bg: --evil;'), 'outside var');

    T::group('css: filters and functions');
    foreach (['blur(100px)', 'drop-shadow(0 0 10px red)', 'opacity(0)', 'url(#f)', 'contrast(50)', 'brightness(40)', 'brightness(-1)', 'sepia(2)', 'brightness()', 'brightness(1 2)', 'brightness(var(--ts-bg))', 'hue-rotate(50px)', 'invert(1px)'] as $f) {
        css_bad("filter $f", dark("--ts-btn-hover-filter: $f;"), null);
    }
    css_good('filter with percentages', dark('--ts-btn-hover-filter: brightness(120%) invert(50%);'));
    css_good('hue-rotate in degrees', dark('--ts-btn-hover-filter: hue-rotate(180deg);'));
    foreach (['calc(1px + 2px)', 'min(1px, 2px)', 'max(1px, 2px)', 'clamp(1px, 2px, 3px)', 'polygon(0 0, 1px 1px)', 'circle(5px)', 'translate(5px)', 'scale(9)', 'rotate(9deg)', 'matrix(1,0,0,1,0,0)', 'counter(x)', 'symbols(x)', 'anchor(x)', 'random(1, 2)', 'sin(1)'] as $fn) {
        css_bad("function $fn", dark("--p-x: $fn;"), 'not allowed');
    }
    css_bad('arithmetic without calc', dark('--p-x: 1px + 2px;'), 'arithmetic');
    css_bad('a multiplication', dark('--p-x: 2 * 3;'), 'arithmetic');

    T::group('css: var()');
    css_bad('var with a fallback', dark('--ts-bg: var(--ts-text, url(x));'), 'var()');
    css_bad('var with a plain fallback', dark('--ts-bg: var(--ts-text, red);'), 'var()');
    css_bad('an empty var', dark('--ts-bg: var();'), 'var()');
    css_bad('var of a non-custom name', dark('--ts-bg: var(color);'), 'var()');
    css_bad('var of a foreign custom property', dark('--ts-bg: var(--evil);'), 'var()');
    css_bad('var of a tailwind variable', dark('--ts-bg: var(--tw-color-dark-gray-500);'), 'var()');
    css_bad('var with two names', dark('--ts-bg: var(--ts-text --ts-border);'), 'var()');
    css_bad('a var to an unknown token', dark('--ts-bg: var(--ts-nope);'), 'not defined or not allowed');
    css_bad('a var to an undefined palette entry', dark('--ts-bg: var(--p-missing);'), 'not defined or not allowed');
    css_bad('a palette cycle', dark('--p-a: var(--p-b);', '--p-b: var(--p-a);'), 'itself');
    css_bad('a palette self-reference', dark('--p-a: var(--p-a);'), 'itself');
    css_bad('a three-step palette cycle', dark('--p-a: var(--p-b);', '--p-b: var(--p-c);', '--p-c: var(--p-a);'), 'itself');
    css_good('a palette chain', dark('--p-a: #111;', '--p-b: var(--p-a);', '--ts-bg: var(--p-b);'));

    T::group('css: a palette entry can not get round a token\'s cap');
    css_bad('a 100px+ entry used in a shadow', dark('--p-big: 0 0 400px red;', '--ts-panel-shadow: var(--p-big);'), 'larger than this token allows');
    css_bad('the same through a chain', dark('--p-big: 400px;', '--p-b: var(--p-big);', '--ts-panel-shadow: 0 0 var(--p-b) red;'), 'larger than this token allows');
    css_good('the same entry used in a token with a big cap', dark('--p-big: linear-gradient(red 0, blue 400px);', '--ts-navbar-bg-image: var(--p-big);'));

    T::group('css: limits');
    css_bad('a file over the size limit', "/* " . str_repeat('x', 100000) . " */\n" . good_css(), 'larger than');
    $many = ''; for ($i = 0; $i < 300; $i++) { $many .= "  --p-e$i: #000;\n"; }
    css_bad('too many palette entries', "html.dark {\n$many}\n", 'palette entries');
    $blocks = str_repeat("@font-face { font-family: \"A\"; src: url(\"fonts/a.woff2\") format(\"woff2\"); }\n", 13);
    css_bad('too many @font-face blocks', $blocks, 'font-face', ['fonts/a.woff2' => fake_font()]);
}

function test_fonts_in_css(): void
{
    T::group('css: @font-face');
    $font = ['fonts/a.woff2' => fake_font()];
    $face = fn (string $body) => "@font-face {\n$body\n}\n" . dark('--ts-bg: #000;');

    $out = css_good('a valid font face', $face('font-family: "My Font"; src: url("fonts/a.woff2") format("woff2"); font-weight: 400; font-style: normal; font-display: swap;'), $font);
    T::ok('the upload output embeds the font as a data: URL', $out !== null && str_contains($out, 'url("data:font/woff2;base64,'));
    T::ok('the upload output has no file reference to fonts/', $out !== null && ! str_contains($out, 'fonts/'));
    T::ok('the upload output passes the guard', $out !== null && OutputGuard::safe($out, 1));
    $out = css_good('a bundled font face keeps its file reference', $face('font-family: "My Font"; src: url("fonts/a.woff2") format("woff2");'), $font, Mode::Bundled);
    T::ok('bundled output references the file', $out !== null && str_contains($out, 'url("fonts/a.woff2")'));

    css_good('a weight range', $face('font-family: "My Font"; src: url("fonts/a.woff2") format("woff2"); font-weight: 600 700;'), $font);
    css_good('a unicode range', $face('font-family: "My Font"; src: url("fonts/a.woff2") format("woff2"); unicode-range: U+0000-00FF, U+0131;'), $font);

    foreach ([
        'a remote url' => 'src: url("http://evil.example/f.woff2") format("woff2");',
        'a protocol-relative url' => 'src: url("//evil.example/f.woff2") format("woff2");',
        'a path traversal' => 'src: url("fonts/../skin.css") format("woff2");',
        'a deep path' => 'src: url("fonts/a/b.woff2") format("woff2");',
        'an absolute path' => 'src: url("/etc/passwd") format("woff2");',
        'a data url' => 'src: url("data:font/woff2;base64,AAAA") format("woff2");',
        'an unquoted url' => 'src: url(fonts/a.woff2) format("woff2");',
        'local()' => 'src: local("Arial");',
        'two sources' => 'src: url("fonts/a.woff2") format("woff2"), url("fonts/a.woff") format("woff");',
        'a format that does not match' => 'src: url("fonts/a.woff2") format("woff");',
        'a missing format' => 'src: url("fonts/a.woff2");',
        'a php file' => 'src: url("fonts/a.php") format("woff2");',
        'a file that is not in the bundle' => 'src: url("fonts/nope.woff2") format("woff2");',
    ] as $what => $src) {
        css_bad($what, $face("font-family: \"My Font\"; $src"), null, $font);
    }
    foreach ([
        'an unquoted family' => 'font-family: MyFont;',
        'a family with punctuation' => 'font-family: "My;Font";',
        'a family with markup' => 'font-family: "<b>";',
        'an empty family' => 'font-family: "";',
        'a long family' => 'font-family: "' . str_repeat('a', 50) . '";',
        'a family list' => 'font-family: "A", "B";',
    ] as $what => $fam) {
        css_bad($what, $face("$fam src: url(\"fonts/a.woff2\") format(\"woff2\");"), null, $font);
    }
    foreach ([
        'font-weight: 99999;', 'font-weight: bolder;', 'font-style: oblique 999deg;', 'font-display: url(x);',
        'unicode-range: U+0000-00FF; }', 'unicode-range: x;', 'size-adjust: 10000%;', 'ascent-override: 1;', 'font-feature-settings: "x";',
        'behavior: url(x);', 'content: "x";', 'src: url("fonts/a.woff2") format("woff2");',
    ] as $extra) {
        css_bad("font-face with $extra", $face("font-family: \"My Font\"; src: url(\"fonts/a.woff2\") format(\"woff2\"); $extra"), null, $font);
    }
    css_bad('a font face missing src', $face('font-family: "My Font";'), 'needs', $font);
    css_bad('a font face missing family', $face('src: url("fonts/a.woff2") format("woff2");'), 'needs', $font);
    css_bad('a font in the bundle no face uses', dark('--ts-bg: #000;'), 'not used', $font);
    css_bad('a font face with a nested rule', "@font-face { font-family: \"A\"; @font-face { } }", null, $font);

    T::group('css: the output guard (independent of the parser)');
    $data = base64_encode(fake_font());
    $fontFace = "@font-face {\n  font-family: \"A\";\n  src: url(\"data:font/woff2;base64,$data\") format(\"woff2\");\n}\n";
    T::ok('a clean generated stylesheet passes', OutputGuard::safe("html.dark {\n  --ts-bg: #000;\n}\n", 0));
    T::ok('one with a data: font passes', OutputGuard::safe($fontFace . "html.dark {\n}\n", 1));
    foreach ([
        'an @import' => ["@import url(x);\nhtml.dark {\n}\n", 0],
        'a stray url(' => ["html.dark {\n  --ts-bg: url(x);\n}\n", 0],
        'an http url in a font face' => [str_replace("data:font/woff2;base64,$data", 'http://evil/x', $fontFace) . "html.dark {\n}\n", 1],
        'a data url that is not a font' => [str_replace('data:font/woff2', 'data:text/html', $fontFace) . "html.dark {\n}\n", 1],
        'a script tag' => ["html.dark {\n  --ts-bg: <script>;\n}\n", 0],
        'a backslash' => ["html.dark {\n  --ts-bg: \\75rl(x);\n}\n", 0],
        'an extra block' => ["html.dark {\n}\nbody {\n}\n", 0],
        'a non-ASCII character' => ["html.dark {\n  --ts-bg: caf\xc3\xa9;\n}\n", 0],
        'a javascript: url' => ["html.dark {\n  --ts-bg: javascript:x;\n}\n", 0],
        'an unclosed block' => ["html.dark {\n  --ts-bg: red;\n", 0],
        'a NUL' => ["html.dark {\0}\n", 0],
        'expression(' => ["html.dark {\n  --ts-bg: expression(x);\n}\n", 0],
        'a font face when none is expected' => [$fontFace . "html.dark {\n}\n", 0],
        'a font count that is too high' => [$fontFace . "html.dark {\n}\n", 2],
        'empty output' => ['', 0],
    ] as $what => [$css, $fonts]) {
        T::ok("the guard refuses $what", ! OutputGuard::safe($css, $fonts));
    }
}


/**
 * ValueValidator on its own, without TokenFile or OutputGuard behind it, so a
 * weakness in this layer shows up even though the layers after it would still
 * have refused the same input.
 */
function test_values(): void
{
    T::group('values: ValueValidator on its own');
    $val = function (string $v, Mode $mode = Mode::Upload, int $px = 800, bool $strings = false): ?array {
        return (new Xblossia\ThemeSelector\Skin\ValueValidator($mode))->validate($v, 'test', $px, $strings, new Report());
    };
    $accept = function (string $v, Mode $m = Mode::Upload, int $px = 800, bool $s = false) use ($val): void {
        T::ok("accepts $v", $val($v, $m, $px, $s) !== null);
    };
    $refuse = function (string $v, Mode $m = Mode::Upload, int $px = 800, bool $s = false, string $note = '') use ($val): void {
        T::ok("refuses $v" . ($note ? " ($note)" : ''), $val($v, $m, $px, $s) === null);
    };

    foreach (['red', '#fff', '#1c2226', '#1c222680', 'rgba(0, 0, 0, .5)', 'rgb(0 0 0 / 50%)', 'transparent', 'ease-in-out',
             'linear-gradient(180deg, #000 0%, #fff 100%)', 'radial-gradient(ellipse 120% 60% at 50% 0%, red 0%, transparent 70%)',
             'inset 0 1px 0 0 rgba(255, 255, 255, .06)', 'brightness(1.2) saturate(1.1)', 'var(--ts-bg)', 'var(--p-x)',
             '1px solid var(--ts-border)', '.08em', '-.01em', '0', '400', 'color-mix(in srgb, #202020 60%, #fff)',
             'filter .12s linear', '2px 0', '-45deg', 'uppercase', 'repeating-linear-gradient(-45deg, transparent 0 62px, #fff 62px 63px)'] as $v) {
        $accept($v);
    }
    $accept('"Some Font", "Other", system-ui, sans-serif', Mode::Upload, 800, true);

    // Anything that can load, run or escape must be refused on its own merits.
    foreach (['url(x)', 'URL(x)', 'Url( x )', 'url("x")', "url('x')", 'url(#f)', 'image-set(x 1x)', 'element(#a)', 'paint(a)', 'src(x)', 'attr(x)', 'env(x)',
             'expression(x)', 'image(x)', 'cross-fade(a, b)', 'local(x)', 'format(x)', 'linear-gradient(url(x), red)', 'var(--x, url(y))'] as $v) {
        $refuse($v);
        $refuse($v, Mode::Bundled, 800, true, 'bundled too');
    }
    foreach (['red;', 'red}', '{red', 'red !important', '<x>', 'a:b', 'a=b', 'a|b', '\\75rl(x)', '@import', 'a[b]', 'a$b', 'a`b', 'a&b', 'a~b', 'a^b', "a\0b", 'a?b'] as $v) {
        $refuse($v, Mode::Upload, 800, false, 'characters');
        $refuse($v, Mode::Bundled, 800, true, 'characters, bundled');
    }

    T::group('values: bounds');
    foreach (['linear-gradient(red 0%, blue 99999%)', 'linear-gradient(red -5000%, blue 100%)', '20000%', '-2000%'] as $v) {
        $refuse($v, Mode::Upload, 800, false, 'percent out of range');
    }
    $accept('linear-gradient(red 0%, blue 300%)');
    $refuse('hue-rotate(99999deg)');
    $refuse('1000000');
    $refuse('41em');
    $refuse('-41rem');
    $refuse('6s');
    $refuse('5001ms');
    $accept('5s');
    $refuse('801px', Mode::Upload, 800);
    $accept('800px', Mode::Upload, 800);
    $refuse('101px', Mode::Upload, 100);
    $accept('100px', Mode::Upload, 100);
    $refuse('-101px', Mode::Upload, 100, false, 'negative lengths are bounded too');

    T::group('values: strings');
    $refuse('"a"', Mode::Upload, 800, false, 'strings not allowed here');
    $accept('"a"', Mode::Upload, 800, true);
    $refuse('"a;b"', Mode::Upload, 800, true);
    $refuse('"a<b"', Mode::Upload, 800, true);
    $refuse('"a\\b"', Mode::Upload, 800, true);
    $refuse('"unterminated', Mode::Upload, 800, true);

    T::group('values: trusted-only constructs stay trusted-only');
    foreach (['calc(100% - 8px)', 'polygon(8px 0, 100% 0, 100% calc(100% - 8px), 0 100%)', '(1px)', '1px + 2px', '2 * 3'] as $v) {
        $refuse($v, Mode::Upload, 800, false, 'upload');
        $accept($v, Mode::Bundled);
    }
    // The comment ban must hold on its own: in trusted mode `/` and `*` are
    // legal operators, so nothing else would stop a comment.
    foreach (['red/*x*/', 'red /* x */ blue', 'red /*', 'red */'] as $v) {
        $refuse($v, Mode::Bundled, 800, true, 'comment, bundled');
        $refuse($v, Mode::Upload, 800, false, 'comment, upload');
    }
    $accept('""', Mode::Bundled, 800, true);
}
