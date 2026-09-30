<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\FontFile;
use Xblossia\ThemeSelector\Skin\GraphConf;
use Xblossia\ThemeSelector\Skin\Limits;
use Xblossia\ThemeSelector\Skin\Manifest;
use Xblossia\ThemeSelector\Skin\Mode;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;

function test_fonts(): void
{
    T::group('fonts');
    $check = function (string $name, string $bytes, ?string $mentions = null, bool $ok = false): void {
        $r = new Report();
        $res = FontFile::check($name, $bytes, $r);
        if ($ok) {
            T::ok("$name accepted", $res && $r->ok(), implode(' | ', $r->errors()));

            return;
        }
        T::ok("$name rejected", ! $res && ! $r->ok(), 'was accepted');
        if ($mentions !== null) {
            T::ok("$name explains ('$mentions')", stripos(implode(' ', $r->errors()), $mentions) !== false, implode(' | ', $r->errors()));
        }
    };

    $check('a.woff2', fake_font('wOF2'), null, true);
    $check('a.woff', fake_font('wOFF'), null, true);
    $check('a.woff2', fake_font('wOFF'), 'signature');
    $check('a.woff', fake_font('wOF2'), 'signature');
    $check('a.woff2', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' . str_repeat(' ', 100), 'signature');
    $check('a.woff2', '<html><body>x</body></html>' . str_repeat(' ', 100), 'signature');
    $check('a.woff2', '<?php system($_GET[0]); ?>' . str_repeat(' ', 100), 'signature');
    $check('a.woff2', 'GIF89a' . str_repeat("\0", 100), 'signature');
    $check('a.woff2', "\x00\x01\x00\x00" . str_repeat("\0", 100), 'signature');  // a raw TrueType file
    $check('a.woff2', '', 'truncated');
    $check('a.woff2', 'wOF2', 'truncated');
    $check('a.woff2', fake_font('wOF2') . '<?php echo 1; ?>', 'different size');
    $check('a.woff2', fake_font('wOF2') . "\0", 'different size');
    $check('a.woff2', substr(fake_font('wOF2', 300), 0, 250), 'different size');
    $check('a.woff2', fake_font('wOF2', Limits::FONT_BYTES + 1), 'too large');

    foreach (['<?php', '<?PHP', '<?=', '<? ', "<?\n", "<?\t", '<script', '<SCRIPT'] as $marker) {
        $body = fake_font('wOF2', 300, "\x01");
        $body = substr($body, 0, 100) . $marker . substr($body, 100 + strlen($marker));
        $check('a.woff2', $body, 'PHP or script', false);
    }
    // A bare `<?` followed by a non-space turns up by chance in compressed data
    // and must not cause a false rejection.
    $body = fake_font('wOF2', 300, "\x01");
    $check('a.woff2', substr($body, 0, 100) . '<?x' . substr($body, 103), null, true);
    // Likewise `<%` (an ASP tag, which nothing in this stack executes): two bytes
    // are enough to turn up by chance in a real font, and did in Montserrat Bold.
    $check('a.woff2', substr($body, 0, 100) . '<%' . substr($body, 102), null, true);

    // header sanity
    $badReserved = fake_font('wOF2');
    $badReserved[14] = "\x01";
    $check('a.woff2', $badReserved, 'invalid header');
    $noTables = fake_font('wOF2');
    $noTables[12] = "\0"; $noTables[13] = "\0";
    $check('a.woff2', $noTables, 'invalid header');

    T::group('fonts: the ones shipped in this package are real and pass');
    foreach (glob(__DIR__ . '/../skins/*/fonts/*.woff2') ?: [] as $file) {
        $check('fonts/' . basename($file), (string) file_get_contents($file), null, true);
    }
    T::ok('there are bundled fonts to test', count(glob(__DIR__ . '/../skins/*/fonts/*.woff2')) >= 9);
}

function test_graph(): void
{
    T::group('graph.conf');
    $good = "# comment\n\nrrdgraph_def_text_dark=-c BACK#1c2226 -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID#292929 -c MGRID#2f343e -c FRAME#5e5e5e -c ARROW#5e5e5e\nrrdgraph_def_text_color_dark=c6ced4\ngraph_colours.greens=[\"9CF2B4\",\"6FE08E\"]\ngraph_colours.port_in=[\"9CF2B4\"]\n";
    $r = new Report();
    $p = GraphConf::parse($good, $r);
    T::ok('a well-formed file parses', $p !== null && count($p) === 4, implode(' | ', $r->errors()));
    T::ok('ramps come back as arrays', $p !== null && $p['graph_colours.greens'] === ['9CF2B4', '6FE08E']);

    foreach (glob(__DIR__ . '/../skins/*/graph.conf') ?: [] as $file) {
        $r = new Report();
        T::ok('the shipped ' . basename(dirname($file)) . '/graph.conf parses', GraphConf::parse((string) file_get_contents($file), $r) !== null, implode(' | ', $r->errors()));
    }

    $bad = [
        'an unknown key' => "evil_setting=1\n",
        'a config key that is not a graph colour' => "auth_mechanism=none\n",
        'a path-like key' => "graph_colours.../x=[\"FFFFFF\"]\n",
        'a key with a newline trick' => "graph_colours.a=[\"FFFFFF\"]\nauth_mechanism=none\n",
        'command injection in the chrome value' => "rrdgraph_def_text_dark=-c BACK#000000 --imgformat SVG\n",
        'shell metacharacters' => "rrdgraph_def_text_dark=-c BACK#000000; rm -rf /\n",
        'a command substitution' => "rrdgraph_def_text_dark=-c BACK#\$(id)\n",
        'a backtick' => "rrdgraph_def_text_dark=-c BACK#`id`\n",
        'an unknown colour tag' => "rrdgraph_def_text_dark=-c EVIL#000000\n",
        'a repeated colour tag' => "rrdgraph_def_text_dark=-c BACK#000000 -c BACK#111111\n",
        'a bad hex length' => "rrdgraph_def_text_dark=-c BACK#00000\n",
        'a non-colour option' => "rrdgraph_def_text_dark=-x BACK#000000\n",
        'an empty chrome value' => "rrdgraph_def_text_dark=\n",
        'a font colour with a hash' => "rrdgraph_def_text_color_dark=#c6ced4\n",
        'a font colour of the wrong length' => "rrdgraph_def_text_color_dark=abc\n",
        'a ramp that is not JSON' => "graph_colours.a=FFFFFF\n",
        'a ramp of non-hex' => "graph_colours.a=[\"GGGGGG\"]\n",
        'a ramp with markup' => "graph_colours.a=[\"<script>\"]\n",
        'a ramp of numbers' => "graph_colours.a=[1,2,3]\n",
        'a nested ramp' => "graph_colours.a=[[\"FFFFFF\"]]\n",
        'an object ramp' => "graph_colours.a={\"x\":\"FFFFFF\"}\n",
        'an empty ramp' => "graph_colours.a=[]\n",
        'a ramp that is too long' => 'graph_colours.a=' . json_encode(array_fill(0, 41, 'FFFFFF')) . "\n",
        'a key set twice' => "graph_colours.a=[\"FFFFFF\"]\ngraph_colours.a=[\"000000\"]\n",
        'a line with no equals sign' => "graph_colours.a\n",
        'a non-ASCII byte' => "graph_colours.a=[\"FFFFFF\"] \xc3\xa9\n",
        'a NUL byte' => "graph_colours.a=[\"FFFFFF\"]\0\n",
        'an over-long file' => str_repeat('# x' . "\n", 4000),
        'an upper-case ramp name' => "graph_colours.Greens=[\"FFFFFF\"]\n",
        'a ramp name with a digit' => "graph_colours.a1=[\"FFFFFF\"]\n",
    ];
    foreach ($bad as $what => $text) {
        $r = new Report();
        $res = GraphConf::parse($text, $r);
        T::ok("$what is rejected", $res === null && ! $r->ok(), 'was accepted: ' . json_encode($res));
    }

    T::group('graph.conf: re-validation of a stored palette (defence in depth)');
    $stored = [
        'rrdgraph_def_text_dark' => '-c BACK#000000; drop table',
        'rrdgraph_def_text_color_dark' => 'zzzzzz',
        'graph_colours.greens' => ['FFFFFF', '<b>'],
        'auth_mechanism' => 'none',
        'graph_colours.ok' => ['FFFFFF'],
    ];
    $clean = GraphConf::fromStored($stored);
    T::ok('only the valid stored entry survives', array_keys($clean) === ['graph_colours.ok'], json_encode($clean));
    T::ok('a non-array stored palette becomes empty', GraphConf::fromStored('junk') === []);
}

function test_manifest(): void
{
    T::group('skin.json');
    $r = new Report();
    $m = Manifest::parse(good_manifest(), $r);
    T::ok('a good manifest parses', $m !== null && $m['id'] === 'testskin' && $m['modes'] === ['dark'], implode(' | ', $r->errors()));
    $r = new Report();
    $m = Manifest::parse(json_encode(['id' => 'x', 'name' => 'X']), $r);
    T::ok('optional fields default', $m !== null && $m['version'] === '1.0.0' && $m['description'] === '', implode(' | ', $r->errors()));

    $cases = [
        'not JSON' => 'not json',
        'a JSON list' => '[1,2]',
        'a JSON string' => '"hi"',
        'an empty object' => '{}',
        'deep nesting' => '{"a":{"b":{"c":{"d":{"e":1}}}}}',
        'a path-traversal id' => good_manifest(['id' => '../evil']),
        'an id with a slash' => good_manifest(['id' => 'a/b']),
        'an id with a dot' => good_manifest(['id' => 'a.b']),
        'an upper-case id' => good_manifest(['id' => 'Skin']),
        'an id starting with a hyphen' => good_manifest(['id' => '-skin']),
        'an empty id' => good_manifest(['id' => '']),
        'an over-long id' => good_manifest(['id' => str_repeat('a', 64)]),
        'a non-string id' => good_manifest(['id' => 5]),
        'a unicode id' => good_manifest(['id' => "sk\u{0131}n"]),
        'an id with a newline' => good_manifest(['id' => "abc\n"]),
        'an id with a NUL' => good_manifest(['id' => "abc\0"]),
        'the reserved id none' => good_manifest(['id' => 'none']),
        'the reserved id default' => good_manifest(['id' => 'default']),
        'the reserved id base' => good_manifest(['id' => 'base']),
        'a name with a script tag' => good_manifest(['name' => '<script>alert(1)</script>']),
        'a name with markup' => good_manifest(['name' => 'My <b>Skin</b>']),
        'a name with a quote' => good_manifest(['name' => 'My "Skin"']),
        'a name with a backtick' => good_manifest(['name' => 'My `Skin`']),
        'a name with a newline' => good_manifest(['name' => "My\nSkin"]),
        'an over-long name' => good_manifest(['name' => str_repeat('a', 61)]),
        'an empty name' => good_manifest(['name' => '']),
        'a name starting with a space' => good_manifest(['name' => ' Skin']),
        'a non-string name' => good_manifest(['name' => ['x']]),
        'a description with markup' => good_manifest(['description' => '<img src=x onerror=alert(1)>']),
        'an over-long description' => good_manifest(['description' => str_repeat('a', 201)]),
        'an author with markup' => good_manifest(['author' => '<a href=x>']),
        'a bad version' => good_manifest(['version' => '1.0']),
        'a version with text' => good_manifest(['version' => '1.0.0-beta']),
        'a non-string version' => good_manifest(['version' => 1.0]),
        'the light mode' => good_manifest(['modes' => ['light']]),
        'both modes' => good_manifest(['modes' => ['dark', 'light']]),
        'an unknown mode' => good_manifest(['modes' => ['evil']]),
        'a string mode' => good_manifest(['modes' => 'dark']),
        'an unknown field' => good_manifest(['homepage' => 'http://evil.example']),
        'a __proto__ field' => '{"id":"x","name":"X","__proto__":{"a":1}}',
        'a non-ASCII byte' => "{\"id\":\"x\",\"name\":\"caf\xc3\xa9\"}",
        'an over-large file' => json_encode(['id' => 'x', 'name' => 'X', 'description' => str_repeat('a', 5000)]),
    ];
    foreach ($cases as $what => $json) {
        $r = new Report();
        $res = Manifest::parse($json, $r);
        T::ok("$what is rejected", $res === null && ! $r->ok(), 'was accepted: ' . json_encode($res));
    }
    T::group('skin.json: a partial failure does not mask a later stage');
    $r = new Report();
    Manifest::parse('{"id":"../x","name":"X"}', $r);
    Manifest::parse(good_manifest(), $r);
    T::ok('the second (valid) call does not clear the first call\'s errors', ! $r->ok());
}

function test_catalog(): void
{
    T::group('token catalog');
    $c = catalog();
    T::ok('has tokens', count($c->names()) > 250);

    // Every token in base.css's defaults block is in the catalog, and the
    // reverse: the catalog has no token base.css lacks.
    $css = (string) file_get_contents(__DIR__ . '/../base/base.css');
    $start = strpos($css, 'html.dark {');
    $end = strpos($css, "\n}", $start);
    preg_match_all('/^\s*(--ts-[a-z0-9-]+):/m', substr($css, $start, $end - $start), $m);
    $inBase = array_unique($m[1]);
    sort($inBase);
    $names = $c->names();
    sort($names);
    T::ok('catalog and base.css defaults list the same tokens', $inBase === $names,
        'only in base: ' . implode(',', array_diff($inBase, $names)) . ' / only in catalog: ' . implode(',', array_diff($names, $inBase)));

    $core = ['bg', 'surface', 'surface-raised', 'surface-hover', 'border', 'text', 'text-dim', 'text-mute', 'text-bright', 'accent', 'link', 'highlight', 'success', 'warning', 'danger', 'info', 'danger-text', 'font-display', 'font-mono', 'radius-sm'];
    foreach ($core as $n) {
        T::ok("core role --ts-$n is settable by an upload", $c->has("--ts-$n") && ! $c->isStructural("--ts-$n"));
    }
    foreach (['panel-before-content', 'panel-heading-after-content', 'panel-before-position', 'panel-before-z-index', 'panel-before-pointer-events', 'navbar-after-height', 'btn-clip-path', 'navbar-after-animation'] as $n) {
        T::ok("--ts-$n is structural", $c->isStructural("--ts-$n"));
    }
    T::ok('an unknown token counts as structural (deny by default)', $c->isStructural('--ts-nonexistent') === true);
    T::ok('an unknown token has a zero cap', $c->maxPx('--ts-nonexistent') === 0);
    T::ok('shadow tokens are capped at 100px', $c->maxPx('--ts-panel-shadow') === 100);
    T::ok('there are structural tokens to protect', count($c->structural()) >= 30);
}

function skin_files(string $id): array
{
    $dir = __DIR__ . '/../skins/' . $id;
    $files = ['skin.json' => file_get_contents("$dir/skin.json"), 'skin.css' => file_get_contents("$dir/skin.css")];
    if (is_file("$dir/graph.conf")) {
        $files['graph.conf'] = file_get_contents("$dir/graph.conf");
    }
    foreach (glob("$dir/fonts/*.woff2") ?: [] as $f) {
        $files['fonts/' . basename($f)] = file_get_contents($f);
    }
    foreach (glob("$dir/textures/*.png") ?: [] as $f) {
        $files['textures/' . basename($f)] = file_get_contents($f);
    }

    return $files;
}

function test_bundled(): void
{
    T::group('the bundled skins pass through the same pipeline');
    $compiler = new SkinCompiler(catalog());
    foreach (['terran', 'protoss', 'zerg'] as $id) {
        $r = new Report();
        $c = $compiler->compileFiles(skin_files($id), $r, Mode::Bundled);
        T::ok("$id compiles in bundled mode", $c !== null, implode(' | ', array_slice($r->errors(), 0, 6)));
        if ($c !== null) {
            T::ok("$id keeps its file references to fonts", str_contains($c->css, 'url("fonts/') && ! str_contains($c->css, 'data:font'));
            T::ok("$id has a graph palette", count($c->graph) > 5);
        }

        // As an upload, the same skin must be refused, and only for the
        // structural tokens it uses (decorations, clip-path, animation).
        $r = new Report();
        $u = $compiler->compileFiles(skin_files($id), $r, Mode::Upload);
        T::ok("$id is refused as an upload", $u === null);
        $only = true;
        foreach ($r->errors() as $e) {
            $only = $only && (stripos($e, 'structural') !== false);
        }
        T::ok("$id is refused only for structural tokens", $only, implode(' | ', array_slice($r->errors(), 0, 4)));
    }

    T::group('the example skin passes as an upload, end to end');
    $r = new Report();
    $c = $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => good_css()], $r);
    T::ok('compiles', $c !== null, implode(' | ', $r->errors()));
    if ($c !== null) {
        T::ok('output passes the guard', OutputGuard::safe($c->css, 0));
        T::ok('metadata is canonical', $c->manifest['id'] === 'testskin' && $c->fontCount === 0);
        T::ok('the fingerprint is a sha256', preg_match('/^[0-9a-f]{64}\z/', $c->sha256) === 1);
        $r2 = new Report();
        $again = $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => $c->css], $r2);
        T::ok('compiling the output again gives the same output (idempotent)', $again !== null && $again->css === $c->css, implode(' | ', $r2->errors()));
        T::ok('the same input gives the same fingerprint', $again !== null && $again->sha256 === $c->sha256);
    }

    T::group('the compiler collects problems from every stage');
    $r = new Report();
    $res = $compiler->compileFiles([
        'skin.json' => good_manifest(['id' => '../x']),
        'skin.css' => 'html.dark { --evil: 1; }',
        'graph.conf' => "evil=1\n",
        'fonts/a.woff2' => 'not a font',
    ], $r);
    T::ok('all four bad parts are refused', $res === null);
    $msgs = implode(' | ', $r->errors());
    T::ok('and all four are mentioned', str_contains($msgs, 'skin.json') && str_contains($msgs, '--evil') && str_contains($msgs, 'graph.conf') && str_contains($msgs, 'font'), $msgs);
    $r = new Report();
    T::ok('a missing skin.css is refused', $compiler->compileFiles(['skin.json' => good_manifest()], $r) === null && stripos(implode(' ', $r->errors()), 'skin.css is missing') !== false);
    $r = new Report();
    T::ok('a missing skin.json is refused', $compiler->compileFiles(['skin.css' => good_css()], $r) === null && stripos(implode(' ', $r->errors()), 'skin.json is missing') !== false);

    T::group('a whole bundle, from zip bytes');
    $zip = ZipBuilder::build([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8],
        ['name' => 'skin.css', 'data' => good_css(), 'method' => 8],
        ['name' => 'graph.conf', 'data' => "graph_colours.greens=[\"FFFFFF\"]\n", 'method' => 8],
    ]);
    $path = ZipBuilder::write($zip);
    $r = new Report();
    $c = $compiler->compileZip($path, $r);
    T::ok('a real zip compiles', $c !== null, implode(' | ', $r->errors()));
    T::ok('and its graph palette comes through', $c !== null && $c->graph === ['graph_colours.greens' => ['FFFFFF']]);
    @unlink($path);
    $r = new Report();
    $bad = ZipBuilder::write(ZipBuilder::build([['name' => '../skin.css', 'data' => good_css()], ['name' => 'skin.json', 'data' => good_manifest()]]));
    T::ok('a zip with a traversal name is refused before anything is compiled', $compiler->compileZip($bad, $r) === null && stripos(implode(' ', $r->errors()), 'not allowed') !== false);
    @unlink($bad);
}
