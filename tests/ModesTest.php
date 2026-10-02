<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Features;
use Xblossia\ThemeSelector\Modes;
use Xblossia\ThemeSelector\PreviewGraph;
use Xblossia\ThemeSelector\Settings;
use Xblossia\ThemeSelector\SkinResolver;
use Xblossia\ThemeSelector\SkinPublisher;
use Xblossia\ThemeSelector\SkinRepository;
use Xblossia\ThemeSelector\Skin\GraphConf;
use Xblossia\ThemeSelector\Skin\Manifest;
use Xblossia\ThemeSelector\Skin\Mode;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;
use Xblossia\ThemeSelector\Skin\TokenFile;

/** A block of html:not(.dark) declarations: a skin written for light mode. */
function light(string ...$decls): string
{
    return "html:not(.dark) {\n" . implode("\n", $decls) . "\n}\n";
}

/*
 * Light and dark: a skin is written for one mode, can be put in either slot (its mirror), and the base has a light twin.
 */
function test_modes(): void
{
    T::group('modes: the helpers');
    T::ok('two modes', Modes::ALL === ['dark', 'light'] && Modes::valid('dark') && Modes::valid('light'));
    foreach (['', 'Dark', 'DARK', 'sepia', null, 1, ['dark'], 'dark '] as $bad) {
        T::ok('not a mode: ' . json_encode($bad), ! Modes::valid($bad));
    }
    T::ok('the other mode', Modes::other('dark') === 'light' && Modes::other('light') === 'dark');
    T::ok('the selectors', Modes::selector('dark') === 'html.dark' && Modes::selector('light') === 'html:not(.dark)');

    T::group('modes: where a mode\'s choice and default are kept');
    T::ok('the dark choice keeps the name every earlier version used', SkinResolver::pref('dark') === 'theme_selector.skin' && SkinResolver::PREF === 'theme_selector.skin');
    T::ok('the light choice has its own', SkinResolver::pref('light') === 'theme_selector.skin_light' && SkinResolver::PREF_LIGHT === 'theme_selector.skin_light');
    T::ok('the default for dark keeps its name, light has its own', Settings::defaultName('dark') === 'default_skin' && Settings::defaultName('light') === 'default_skin_light');

    T::group('modes: the mirror of a stylesheet');
    $d = "/* c */\nhtml.dark {\n  --ts-bg: #000;\n}\n";
    $l = "/* c */\nhtml:not(.dark) {\n  --ts-bg: #000;\n}\n";
    T::ok('a dark stylesheet is for dark and mirrors to light', Modes::nativeOf($d) === 'dark' && Modes::mirror($d) === $l);
    T::ok('a light stylesheet is for light and mirrors to dark', Modes::nativeOf($l) === 'light' && Modes::mirror($l) === $d);
    T::ok('mirroring twice gives the original', Modes::mirror((string) Modes::mirror($d)) === $d);
    T::ok('a stylesheet with both is for neither: no mirror', Modes::nativeOf($d . $l) === null && Modes::mirror($d . $l) === null);
    T::ok('a stylesheet with neither has none', Modes::nativeOf("body {\n}\n") === null && Modes::mirror("body {\n}\n") === null);
    T::ok('every root block is swapped', substr_count((string) Modes::mirror($d . $d), 'html:not(.dark) {') === 2);
    T::ok('only a root block at the start of a line is swapped', Modes::mirror("html.dark {\n  --p-a: x;\n}\n.a html.dark {\n}\n") === "html:not(.dark) {\n  --p-a: x;\n}\n.a html.dark {\n}\n");

    T::group('modes: the light twin of the base stylesheet');
    $base = (string) file_get_contents(__DIR__ . '/../base/base.css');
    $twin = Modes::lightBase($base);
    // Blocks fenced `ts:dark-only` (the dark map) are the one thing the twin leaves out.
    $kept = [];
    $fenced = [];
    $inside = false;
    $balanced = true;
    foreach (explode("\n", $base) as $line) {
        if (str_starts_with($line, '/* ts:dark-only')) {
            $balanced = $balanced && ! $inside;
            $inside = true;
        } elseif (str_starts_with($line, '/* ts:end-dark-only')) {
            $balanced = $balanced && $inside;
            $inside = false;
        } elseif ($inside) {
            $fenced[] = $line;
        } else {
            $kept[] = $line;
        }
    }
    $keptCss = implode("\n", $kept);
    T::ok('the fences in base.css are balanced and hold only the dark attribution bar', $balanced && ! $inside && $fenced !== [] && count(array_filter($fenced, fn ($l) => $l !== '' && ! str_starts_with($l, ' ') && ! str_starts_with($l, '}') && ! str_contains($l, 'leaflet'))) === 0);
    T::ok('the black attribution bar is in the dark base and not in the twin; the tile filter is in both (a token)', str_contains($base, 'leaflet-control-attribution') && ! str_contains($twin, 'leaflet-control-attribution') && str_contains($twin, 'leaflet-tile') && ! str_contains($twin, 'ts:dark-only'));
    T::ok('the twin has no html.dark left', ! str_contains($twin, 'html.dark'));
    T::ok('every html.dark outside the fences became html:not(.dark)', substr_count($twin, 'html:not(.dark)') === substr_count($keptCss, 'html.dark'), substr_count($twin, 'html:not(.dark)') . ' vs ' . substr_count($keptCss, 'html.dark'));
    T::ok('the ornament gate looks for the light slot\'s own mark', substr_count($twin, 'link[data-ts-orn-light]') === substr_count($keptCss, 'link[data-ts-orn]') && ! str_contains($twin, 'link[data-ts-orn]'));
    T::ok('the dark base is not touched by making the twin', str_contains($base, 'html.dark') && ! str_contains($base, 'html:not(.dark)') && ! str_contains($base, 'data-ts-orn-light'));
    T::ok('the twin changes nothing but those substitutions and the fenced blocks', str_replace(['html:not(.dark)', 'link[data-ts-orn-light]'], ['html.dark', 'link[data-ts-orn]'], $twin) === $keptCss);
    T::group('modes: a skin written for light');
    $out = css_compile(light('--ts-bg: #fff;', '--ts-text: #222;'), [], Mode::Upload, $r, 'light');
    T::ok('a light skin is accepted', $out !== null, implode(' | ', $r->errors()));
    T::ok('its output has the light wrapper and no dark one', $out !== null && str_contains($out, "\nhtml:not(.dark) {\n") && ! str_contains($out, 'html.dark'));
    T::ok('and passes the output guard as a light stylesheet', $out !== null && OutputGuard::safe($out, 0, 0, 'light'));
    T::ok('but not as a dark one', $out !== null && ! OutputGuard::safe($out, 0, 0, 'dark'));
    $dark = css_compile(dark('--ts-bg: #000;'), [], Mode::Upload, $r);
    T::ok('a dark skin passes as dark and not as light', $dark !== null && OutputGuard::safe($dark, 0) && ! OutputGuard::safe($dark, 0, 0, 'light'));
    T::ok('the guard refuses a mode that is not one, for either wrapper', $dark !== null && $out !== null && ! OutputGuard::safe($dark, 0, 0, 'sepia') && ! OutputGuard::safe($out, 0, 0, 'sepia') && ! OutputGuard::safe($out, 0, 0, ''));
    T::ok('and a stylesheet with both wrappers in it', ! OutputGuard::safe("html.dark {\n  --ts-bg: #000;\n}\nhtml:not(.dark) {\n  --ts-bg: #fff;\n}\n", 0) && ! OutputGuard::safe("html.dark {\n  --ts-bg: #000;\n}\nhtml:not(.dark) {\n  --ts-bg: #fff;\n}\n", 0, 0, 'light'));
    css_compile(light('--ts-bg: #fff;'), [], Mode::Upload, $r, 'dark');
    T::ok('a light block in a skin that says it is dark is refused, and says why', ! $r->ok() && str_contains(implode(' | ', $r->errors()), 'for light mode'), implode(' | ', $r->errors()));
    css_compile(dark('--ts-bg: #000;'), [], Mode::Upload, $r, 'light');
    T::ok('a dark block in a skin that says it is light is refused, and says why', ! $r->ok() && str_contains(implode(' | ', $r->errors()), 'for dark mode'), implode(' | ', $r->errors()));
    foreach ([
        'a list' => "html:not(.dark), body {\n  --ts-bg: #fff;\n}\n",
        'a descendant' => "html:not(.dark) body {\n  --ts-bg: #fff;\n}\n",
        'a different class' => "html:not(.light) {\n  --ts-bg: #fff;\n}\n",
        'spaces inside' => "html:not( .dark ) {\n  --ts-bg: #fff;\n}\n",
        'a bare :not' => ":not(.dark) {\n  --ts-bg: #fff;\n}\n",
        'html alone' => "html {\n  --ts-bg: #fff;\n}\n",
        'a pseudo-class added' => "html:not(.dark):hover {\n  --ts-bg: #fff;\n}\n",
        'the other wrapper twice' => "html:not(.dark) {\n  --ts-bg: #fff;\n}\nhtml.dark {\n  --ts-bg: #000;\n}\n",
    ] as $what => $css) {
        $o = css_compile($css, [], Mode::Upload, $r, 'light');
        T::ok("a light skin with $what is refused", $o === null && ! $r->ok(), (string) $o);
    }
    css_compile("html:not(.dark) {\n  --ts-bg: #fff;\n}\nhtml:not(.dark) {\n  --ts-text: #000;\n}\n", [], Mode::Upload, $r, 'light');
    T::ok('two light blocks are fine, as two dark blocks are', $r->ok(), implode(' | ', $r->errors()));

    T::group('modes: a whole bundle');
    $files = ['skin.json' => good_manifest(['mode' => 'light', 'family' => 'Test Family']), 'skin.css' => light('--ts-bg: #fff;', '--ts-text: #222;')];
    $compiler = new SkinCompiler(catalog());
    $rep = new Report();
    $skin = $compiler->compileFiles($files, $rep);
    T::ok('a light bundle compiles', $skin !== null, implode(' | ', $rep->errors()));
    T::ok('its manifest records the mode and family', $skin !== null && $skin->manifest['mode'] === 'light' && $skin->manifest['family'] === 'Test Family');
    T::ok('its stylesheet is for light and its mirror for dark', $skin !== null && Modes::nativeOf($skin->css) === 'light' && Modes::nativeOf($skin->mirror) === 'dark');
    T::ok('the mirror is the stylesheet with one selector swapped', $skin !== null && $skin->mirror === Modes::mirror($skin->css));
    T::ok('and it passes the guard as a dark stylesheet', $skin !== null && OutputGuard::safe($skin->mirror, 0, 0, 'dark'));
    $rep = new Report();
    $dskin = $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => good_css()], $rep);
    T::ok('a bundle with no mode is dark, as before', $dskin !== null && $dskin->manifest['mode'] === 'dark' && Modes::nativeOf($dskin->css) === 'dark' && Modes::nativeOf($dskin->mirror) === 'light', implode(' | ', $rep->errors()));
    T::ok('a different stylesheet gives a different hash, and so does the mode', $skin !== null && $dskin !== null && $skin->sha256 !== $dskin->sha256);
    $rep = new Report();
    T::ok('a manifest that says light over a dark stylesheet is refused', $compiler->compileFiles(['skin.json' => good_manifest(['mode' => 'light']), 'skin.css' => good_css()], $rep) === null && ! $rep->ok());
    $rep = new Report();
    T::ok('and one that says dark over a light stylesheet', $compiler->compileFiles(['skin.json' => good_manifest(['mode' => 'dark']), 'skin.css' => light('--ts-bg: #fff;')], $rep) === null && ! $rep->ok());
    $rep = new Report();
    $legacy = $compiler->compileFiles(['skin.json' => good_manifest(['modes' => ['light']]), 'skin.css' => light('--ts-bg: #fff;')], $rep);
    T::ok('the older "modes": ["light"] spelling still means light', $legacy !== null && $legacy->manifest['mode'] === 'light', implode(' | ', $rep->errors()));

    $face = fn (string $range) => "@font-face {\n  font-family: \"Two Faces\";\n  src: url(\"fonts/one.woff2\") format(\"woff2\");\n  font-weight: 700;\n  unicode-range: $range;\n}\n";
    $rep = new Report();
    $two = $compiler->compileFiles(['skin.json' => good_manifest(['mode' => 'light']), 'skin.css' => $face('U+0000-002F, U+003A-10FFFF') . $face('U+0030-0039') . light('--ts-bg: #fff;', '--ts-font-display: "Two Faces", serif;'), 'fonts/one.woff2' => fake_font('wOF2', 500)], $rep);
    T::ok('one font file may serve two faces (split by unicode-range), and the skin still gets its mirror', $two !== null && substr_count($two->css, '@font-face') === 2 && substr_count($two->mirror, '@font-face') === 2 && $two->fontCount === 1 && Modes::nativeOf($two->mirror) === 'dark', implode(' | ', $rep->errors()));
    T::group('modes: skin.json');
    $r = new Report();
    $m = Manifest::parse(good_manifest(['mode' => 'light']), $r);
    T::ok('mode light', $m !== null && $m['mode'] === 'light' && $m['modes'] === ['light'], implode(' | ', $r->errors()));
    $m = Manifest::parse(good_manifest(['mode' => 'dark', 'modes' => ['dark']]), $r = new Report());
    T::ok('mode and modes that agree', $m !== null && $m['mode'] === 'dark', implode(' | ', $r->errors()));
    $m = Manifest::parse(good_manifest(['family' => 'Clock Tower']), $r = new Report());
    T::ok('a family', $m !== null && $m['family'] === 'Clock Tower', implode(' | ', $r->errors()));
    $m = Manifest::parse(good_manifest(), $r = new Report());
    T::ok('no family means none', $m !== null && $m['family'] === '');

    T::group('modes: graph.conf for light graphs');
    $rep = new Report();
    $g = GraphConf::parse("rrdgraph_def_text=-c BACK#FFFFFF -c GRID#DDDDDD\nrrdgraph_def_text_color=222222\nrrdgraph_def_text_dark=-c BACK#000000\nrrdgraph_def_text_color_dark=EEEEEE\ngraph_colours.port_in=[\"00FF00\",\"00DD00\",\"00BB00\"]\n", $rep);
    T::ok('the light chrome and its text colour are accepted beside the dark', $g !== null && isset($g['rrdgraph_def_text'], $g['rrdgraph_def_text_color'], $g['rrdgraph_def_text_dark']), implode(' | ', $rep->errors()));
    foreach (["rrdgraph_def_text=-c BACK#FFF\n", "rrdgraph_def_text=-c EVIL#FFFFFF\n", "rrdgraph_def_text_color=red\n", "rrdgraph_def_text=-c BACK#FFFFFF; rm -rf /\n", "rrdgraph_def_text_light=-c BACK#FFFFFF\n"] as $bad) {
        $rep = new Report();
        T::ok('refused: ' . trim($bad), GraphConf::parse($bad, $rep) === null && ! $rep->ok());
    }
    T::ok('the stored form keeps the light keys too', isset(GraphConf::fromStored(['rrdgraph_def_text' => '-c BACK#FFFFFF', 'rrdgraph_def_text_color' => '222222'])['rrdgraph_def_text_color']));

    T::group('modes: a skin colours the graphs of the mode it is put in, whichever it was written for');
    $darkSkin = ['rrdgraph_def_text_dark' => '-c BACK#111111', 'rrdgraph_def_text_color_dark' => 'EEEEEE', 'graph_colours.greens' => ['111111', '222222', '333333']];
    $lightSkin = ['rrdgraph_def_text' => '-c BACK#FFFFFF', 'rrdgraph_def_text_color' => '222222', 'graph_colours.greens' => ['AAAAAA', 'BBBBBB', 'CCCCCC']];
    T::ok('a dark skin in the dark slot: its own dark chrome', (function () use ($darkSkin) { $o = GraphConf::forMode($darkSkin, 'dark'); ksort($o); $d = $darkSkin; ksort($d); return $o === $d; })());
    $lightFromDark = GraphConf::forMode($darkSkin, 'light');
    T::ok('a dark skin in the light slot: its dark chrome carried over to light graphs, and its ramps', (function () use ($lightFromDark) { ksort($lightFromDark); return $lightFromDark === ['graph_colours.greens' => ['111111', '222222', '333333'], 'rrdgraph_def_text' => '-c BACK#111111', 'rrdgraph_def_text_color' => 'EEEEEE']; })(), json_encode($lightFromDark));
    $darkFromLight = GraphConf::forMode($lightSkin, 'dark');
    T::ok('a light skin in the dark slot: its light chrome carried over to dark graphs', (function () use ($darkFromLight) { ksort($darkFromLight); return $darkFromLight === ['graph_colours.greens' => ['AAAAAA', 'BBBBBB', 'CCCCCC'], 'rrdgraph_def_text_color_dark' => '222222', 'rrdgraph_def_text_dark' => '-c BACK#FFFFFF']; })(), json_encode($darkFromLight));
    T::ok('a light skin in the light slot: as it is', (function () use ($lightSkin) { $o = GraphConf::forMode($lightSkin, 'light'); ksort($o); $d = $lightSkin; ksort($d); return $o === $d; })());
    $both = $darkSkin + ['rrdgraph_def_text' => '-c BACK#FFFFFF', 'rrdgraph_def_text_color' => '000000'];
    T::ok('a skin that gives both modes\' chrome uses each for its own mode', GraphConf::forMode($both, 'light')['rrdgraph_def_text'] === '-c BACK#FFFFFF' && GraphConf::forMode($both, 'dark')['rrdgraph_def_text_dark'] === '-c BACK#111111' && ! isset(GraphConf::forMode($both, 'light')['rrdgraph_def_text_dark']) && ! isset(GraphConf::forMode($both, 'dark')['rrdgraph_def_text']));
    T::ok('a skin with no chrome at all gives only its ramps', GraphConf::forMode(['graph_colours.greens' => ['111111', '222222', '333333']], 'light') === ['graph_colours.greens' => ['111111', '222222', '333333']]);
    T::ok('an empty palette gives nothing', GraphConf::forMode([], 'dark') === [] && GraphConf::forMode([], 'light') === []);
    T::group('modes: the sample graph in light');
    $svg = PreviewGraph::svg(['rrdgraph_def_text' => '-c BACK#FAF3E0 -c GRID#D8C9A8 -c MGRID#B89B5E', 'rrdgraph_def_text_color' => '3B2A1A', 'graph_colours.port_in' => ['C8D8B0', '7A9A4A', '4F6B2A']], 'light');
    T::ok('the light keys are used for a light graph', str_contains($svg, '#FAF3E0') && str_contains($svg, '#D8C9A8') && str_contains($svg, '#3B2A1A') && str_contains($svg, '#7A9A4A'));
    T::ok('the dark keys are ignored in a light graph', ! str_contains(PreviewGraph::svg(['rrdgraph_def_text_dark' => '-c BACK#123456'], 'light'), '#123456'));
    T::ok('the light keys are ignored in a dark graph', ! str_contains(PreviewGraph::svg(['rrdgraph_def_text' => '-c BACK#123456'], 'dark'), '#123456'));
    T::ok('with no palette a light graph is light and a dark one dark', str_contains(PreviewGraph::svg([], 'light'), '#F4F4F4') && str_contains(PreviewGraph::svg([], 'dark'), '#1C1C1C'));
    T::ok('a mode that is not one draws dark', PreviewGraph::svg([], 'sepia') === PreviewGraph::svg([], 'dark'));

    T::group('modes: which stylesheets a slot loads');
    [, $reg, , $pub, $root] = installer_fixture();
    $pkg = "$root/package/skins";
    file_put_contents("$pub/base.css", 'b');
    file_put_contents("$pub/base-light.css", 'bl');
    foreach (['terran' => 'dark', 'daylight' => 'light'] as $id => $mode) {
        @mkdir("$pub/skins/$id", 0755, true);
        file_put_contents("$pub/skins/$id/skin.css", 'x');
        file_put_contents("$pub/skins/$id/skin.mirror.css", 'y');
        @mkdir("$pkg/$id", 0755, true);
        file_put_contents("$pkg/$id/skin.css", 'x');
        $json = json_encode(['id' => $id, 'name' => ucfirst($id), 'mode' => $mode, 'family' => $id === 'daylight' ? 'Tower' : '']);
        file_put_contents("$pkg/$id/skin.json", $json);
        file_put_contents("$pub/skins/$id/skin.json", $json); // bundled skins' metadata is read from the published copy
    }
    $reg->rows['mine'] = ['id' => 'mine', 'name' => 'Mine', 'description' => '', 'author' => '', 'version' => '1.0.0', 'mode' => 'light', 'family' => 'Mine Family', 'graph' => [], 'created_at' => '2026-10-03 09:00:00', 'updated_at' => '2026-10-03 09:00:00'];
    @mkdir("$pub/skins/mine", 0755, true);
    file_put_contents("$pub/skins/mine/skin.css", 'x');
    file_put_contents("$pub/skins/mine/skin.mirror.css", 'y');
    $repo = new SkinRepository($pub, $reg, $pkg);
    $all = $repo->all();
    T::ok('a bundled skin reports its mode and family', $all['terran']['mode'] === 'dark' && $all['daylight']['mode'] === 'light' && $all['daylight']['family'] === 'Tower' && $all['terran']['family'] === '');
    T::ok('an uploaded skin reports its mode and family', $all['mine']['mode'] === 'light' && $all['mine']['family'] === 'Mine Family');
    $files = fn (array $urls): array => array_map(fn ($u) => preg_replace('/\?v=\d+$/', '', $u), $urls);
    T::ok('a dark skin in the dark slot: the base and its own stylesheet', $files($repo->stylesheetUrls('terran', 'dark')) === ['css/custom/theme-selector/base.css', 'css/custom/theme-selector/skins/terran/skin.css']);
    T::ok('a dark skin in the light slot: the light base and its mirror', $files($repo->stylesheetUrls('terran', 'light')) === ['css/custom/theme-selector/base-light.css', 'css/custom/theme-selector/skins/terran/skin.mirror.css']);
    T::ok('a light skin in the light slot: the light base and its own stylesheet', $files($repo->stylesheetUrls('daylight', 'light')) === ['css/custom/theme-selector/base-light.css', 'css/custom/theme-selector/skins/daylight/skin.css']);
    T::ok('a light skin in the dark slot: the dark base and its mirror', $files($repo->stylesheetUrls('daylight', 'dark')) === ['css/custom/theme-selector/base.css', 'css/custom/theme-selector/skins/daylight/skin.mirror.css']);
    T::ok('an uploaded light skin likewise', $files($repo->stylesheetUrls('mine', 'dark')) === ['css/custom/theme-selector/base.css', 'css/custom/theme-selector/skins/mine/skin.mirror.css']);
    T::ok('the default slot is dark', $repo->stylesheetUrls('terran') === $repo->stylesheetUrls('terran', 'dark'));
    T::ok('a slot that is not one has nothing', $repo->stylesheetUrls('terran', 'sepia') === [] && $repo->stylesheetUrls('terran', '') === []);
    T::ok('an id that is not a skin has nothing', $repo->stylesheetUrls('nope', 'dark') === [] && $repo->stylesheetUrls('../terran', 'dark') === []);
    unlink("$pub/skins/terran/skin.mirror.css");
    T::ok('a skin with no mirror applies only in its own mode', $repo->stylesheetUrls('terran', 'light') === [] && count($repo->stylesheetUrls('terran', 'dark')) === 2);
    unlink("$pub/base-light.css");
    T::ok('with no light base nothing loads in the light slot', $repo->stylesheetUrls('daylight', 'light') === []);

    T::group('modes: publishing the twin and the mirrors');
    $root2 = sys_get_temp_dir() . '/ts-modes-' . bin2hex(random_bytes(4));
    mkdir("$root2/package/base", 0755, true);
    file_put_contents("$root2/package/base/base.css", "html.dark {\n  --ts-bg: #000;\n}\nhtml.dark:has(link[data-ts-orn]) .panel {\n}\n");
    foreach (['nightly' => "html.dark {\n  --ts-bg: #000;\n}\n", 'sunny' => "html:not(.dark) {\n  --ts-bg: #fff;\n}\n", 'odd' => "body {\n}\n"] as $id => $css) {
        mkdir("$root2/package/skins/$id", 0755, true);
        file_put_contents("$root2/package/skins/$id/skin.css", $css);
        file_put_contents("$root2/package/skins/$id/skin.json", json_encode(['id' => $id, 'name' => $id]));
    }
    file_put_contents("$root2/package/base/light.css", "html:not(.dark) {\n  --tw-color-gray-100: var(--ts-surface-raised);\n}\n");
    $out = "$root2/public";
    (new SkinPublisher("$root2/package", $out, new FakeRegistry()))->syncNow();
    T::ok('the base is published, and its light twin beside it, with the light-only mapping on the end', is_file("$out/base.css") && is_file("$out/base-light.css")
        && file_get_contents("$out/base-light.css") === Modes::lightBase((string) file_get_contents("$out/base.css")) . "\n" . file_get_contents("$root2/package/base/light.css"));
    T::ok('light.css is not published as a file of its own', ! file_exists("$out/light.css"));
    T::ok('the dark base does not get the light-only mapping', ! str_contains((string) file_get_contents("$out/base.css"), '--tw-color-gray-100'));
    T::ok('a dark skin gets a light mirror', file_get_contents("$out/skins/nightly/skin.mirror.css") === "html:not(.dark) {\n  --ts-bg: #000;\n}\n");
    T::ok('a light skin gets a dark mirror', file_get_contents("$out/skins/sunny/skin.mirror.css") === "html.dark {\n  --ts-bg: #fff;\n}\n");
    T::ok('a stylesheet that is for neither gets none', ! file_exists("$out/skins/odd/skin.mirror.css") && is_file("$out/skins/odd/skin.css"));
    T::ok('the skins themselves are published unchanged', file_get_contents("$out/skins/nightly/skin.css") === "html.dark {\n  --ts-bg: #000;\n}\n");
    T::ok('an unchanged package is not published again', (function () use ($root2, $out) {
        $pub = new SkinPublisher("$root2/package", $out, new FakeRegistry());

        return $pub->syncIfNeeded() === false;
    })());
    rmrf($root2);

    T::group('modes: the bundled skins');
    foreach (glob(__DIR__ . '/../skins/*/skin.css') ?: [] as $file) {
        $id = basename(dirname($file));
        $css = (string) file_get_contents($file);
        $manifest = json_decode((string) file_get_contents(dirname($file) . '/skin.json'), true) ?: [];
        $declared = $manifest['mode'] ?? ($manifest['modes'][0] ?? 'dark');
        T::ok("$id: its stylesheet is written for one mode, the one skin.json declares", Modes::nativeOf($css) === $declared, (string) Modes::nativeOf($css) . ' vs ' . $declared);
        T::ok("$id: it mirrors, and back again", Modes::mirror($css) !== null && Modes::mirror((string) Modes::mirror($css)) === $css);
    }
}

/*
 * base/light.css is the plugin's own stylesheet, appended to the light twin. What it may do is pinned:
 * custom properties in one root block, and exactly two small rules. Nothing in it can place, hide,
 * resize, add content to or cover anything.
 */
function test_light_css(): void
{
    T::group('light.css: what the light-only stylesheet may contain');
    $raw = (string) file_get_contents(__DIR__ . '/../base/light.css');
    $css = trim((string) preg_replace('~/\*.*?\*/~s', '', $raw));
    T::ok('it has content', strlen($css) > 500);
    foreach (['@', 'url(', '!important', 'content', 'position', 'display', 'z-index', 'pointer-events', 'clip', 'transform', 'animation', 'width', 'height', 'margin', 'padding', 'html.dark', 'import', 'expression', '<', '>', 'javascript'] as $bad) {
        T::ok("it has no $bad", ! str_contains(strtolower($css), $bad));
    }
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $selectors = array_map(fn ($r) => trim(preg_replace('/\s+/', ' ', $r[1])), $rules);
    T::ok('three rules, with exactly these selectors', $selectors === ['html:not(.dark)', 'html:not(.dark) .tw\:bg-white', 'html:not(.dark) .pagemenu-selected a, html:not(.dark) .pagemenu-selected a:hover'], json_encode($selectors));
    $own = [];
    $bad = [];
    foreach (array_filter(array_map('trim', explode(';', $rules[0][2]))) as $d) {
        [$name, $value] = array_map('trim', explode(':', $d, 2)) + ['', ''];
        if (preg_match('/^--(tw-color-[a-z]+-\d+|ts-light-n\d+|ts-map-tile-filter)\z/', $name) !== 1) {
            $bad[] = $name;
        }
        if (str_starts_with($name, '--ts-light-')) {
            $own[$name] = true;
        }
    }
    T::ok('the root block sets only Tailwind colour variables, its own helpers and the map filter', $bad === [], implode(', ', $bad));
    T::ok('the light twin\'s map is unfiltered by default, so a skin chooses its own map', str_contains($rules[0][2], '--ts-map-tile-filter: none;'));
    T::ok('and has many (the scales it maps)', substr_count($rules[0][2], '--tw-color-') > 60);
    $cat = catalog();
    $bad = [];
    foreach ($rules as $r) {
        preg_match_all('/var\((--[a-z0-9-]+)\)/', $r[2], $m);
        foreach ($m[1] as $v) {
            if (! isset($own[$v]) && ! ($cat->has($v) && ! $cat->isStructural($v))) {
                $bad[] = $v;
            }
        }
    }
    T::ok('everything it reads is a settable role (or its own helper)', $bad === [], implode(', ', $bad));
    T::ok('the two rules set one property each: background-color and color, to roles', trim($rules[1][2]) === 'background-color: var(--ts-surface);' && trim($rules[2][2]) === 'color: var(--ts-text-bright);');
    T::ok('white is left alone (text on coloured buttons)', ! str_contains($rules[0][2], '--tw-color-white') && ! str_contains($rules[0][2], '--tw-color-black'));
}

/*
 * The Clock Tower family: a light skin and a dark skin from one template (scripts/make-clock-tower.py).
 * Each must be something an upload could be (no structural tokens, no bundled-only features in the
 * stylesheet), and the two must be one design: the same tokens, in two moods.
 */
function test_clock_tower(): void
{
    T::group('clock tower: two skins, one family, each valid as an upload');
    $compiler = new SkinCompiler(catalog());
    $skins = [];
    foreach (['daylight' => 'light', 'lantern' => 'dark'] as $name => $mode) {
        $dir = __DIR__ . "/../skins/clock-tower-$name";
        $files = [];
        foreach (['skin.json', 'skin.css', 'graph.conf'] as $f) {
            $files[$f] = (string) file_get_contents("$dir/$f");
        }
        foreach (glob("$dir/textures/*.png") ?: [] as $png) {
            $files['textures/' . basename($png)] = (string) file_get_contents($png);
        }
        foreach (glob("$dir/fonts/*.woff2") ?: [] as $font) {
            $files['fonts/' . basename($font)] = (string) file_get_contents($font); // the OFL notices stay bundled-only
        }
        $rep = new Report();
        $skin = $compiler->compileFiles($files, $rep); // as an upload: features.json is the one thing it can't carry
        T::ok("clock-tower-$name compiles as an upload", $skin !== null, implode(' | ', $rep->errors()));
        if ($skin === null) {
            continue;
        }
        $skins[$name] = [$skin, $files];
        T::ok("clock-tower-$name is written for $mode mode and is in the Clock Tower family", $skin->manifest['mode'] === $mode && $skin->manifest['family'] === 'Clock Tower' && $skin->manifest['id'] === "clock-tower-$name");
        T::ok("clock-tower-$name has a texture and a graph palette", count($skin->textures) === 1 && $skin->textures[0]['name'] === 'gears' && $skin->graph !== []);
        T::ok("clock-tower-$name asks for the ornament layer (features.json)", Features::parse((string) file_get_contents("$dir/features.json")) === ['ornaments' => true, 'effects' => []]);
        $chrome = $mode === 'light' ? ['rrdgraph_def_text', 'rrdgraph_def_text_color'] : ['rrdgraph_def_text_dark', 'rrdgraph_def_text_color_dark'];
        $other = $mode === 'light' ? ['rrdgraph_def_text_dark', 'rrdgraph_def_text_color_dark'] : ['rrdgraph_def_text', 'rrdgraph_def_text_color'];
        T::ok("clock-tower-$name's graph palette has its own mode's chrome, the ramps and the port series, and not the other mode's chrome",
            count(array_diff($chrome, array_keys($skin->graph))) === 0 && isset($skin->graph['graph_colours.port_in'], $skin->graph['graph_colours.port_out'], $skin->graph['graph_colours.greens']) && count(array_intersect($other, array_keys($skin->graph))) === 0);
        T::ok("clock-tower-$name ships its three font files, each with an OFL notice beside them", $skin->fontCount === 3 && count(glob("$dir/fonts/*.woff2") ?: []) === 3 && count(glob("$dir/fonts/OFL-*.txt") ?: []) === 2);
        T::ok("clock-tower-$name's stylesheet names the fonts it ships and no others", (function () use ($files, $dir) {
            preg_match_all('#url\("fonts/([^"]+)"\)#', $files['skin.css'], $m);
            $m[1] = array_values(array_unique($m[1])); // one file may serve two faces (the display face is split by unicode-range)
            sort($m[1]);
            $have = array_map('basename', glob("$dir/fonts/*.woff2") ?: []);
            sort($have);

            return $m[1] === $have;
        })());
        T::ok("clock-tower-$name has a FONTS.md", is_file("$dir/FONTS.md"));
    }
    if (count($skins) === 2) {
        $names = fn (string $css): array => (function () use ($css) {
            preg_match_all('/^  (--ts-[a-z0-9-]+):/m', $css, $m);
            sort($m[1]);

            return $m[1];
        })();
        $a = $names($skins['daylight'][1]['skin.css']);
        $b = $names($skins['lantern'][1]['skin.css']);
        // One design in two moods: the same tokens, except that only the lantern breathes (daylight is still).
        T::ok('the two skins set the same tokens, and only the lantern breathes', array_diff($a, $b) === [] && array_values(array_diff($b, $a)) === ['--ts-frame-breathe', '--ts-heading-marker-breathe'], implode(', ', array_merge(array_diff($a, $b), array_diff($b, $a))));
        T::ok('they differ in their colours', $skins['daylight'][0]->css !== $skins['lantern'][0]->css && $skins['daylight'][1]['skin.css'] !== $skins['lantern'][1]['skin.css']);
        T::ok('and the daylight one is light, the lantern one dark, by their grounds', (function () use ($skins) {
            $lum = function (string $css): float {
                preg_match('/--p-bg: #([0-9a-f]{6})/', $css, $m);
                [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));

                return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
            };

            return $lum($skins['daylight'][1]['skin.css']) > 0.7 && $lum($skins['lantern'][1]['skin.css']) < 0.15;
        })());
    }
}

/*
 * Stock dark markup hard-codes a few light greys that are unreadable on a skin whose surfaces are light (a
 * light skin in the dark slot). The base answers them with the skin's own roles, in both modes.
 */
function test_stock_greys(): void
{
    T::group('stock greys: answered with roles, in the dark base and its twin');
    $base = (string) file_get_contents(__DIR__ . '/../base/base.css');
    $twin = Modes::lightBase($base);
    foreach (['html.dark' => $base, 'html:not(.dark)' => $twin] as $wrap => $css) {
        T::ok("$wrap: a sortable table header's text is the skin's dim text, not tw_dark.css's fixed grey", str_contains($css, "$wrap .bootgrid-table th > .column-header-anchor {\n  color: var(--ts-text-dim);\n}"));
        T::ok("$wrap: Tailwind's quiet greys (gray-400, gray-500) are the skin's muted text", str_contains($css, '--tw-color-gray-400: var(--ts-text-mute);') && str_contains($css, '--tw-color-gray-500: var(--ts-text-mute);'));
    }
    T::ok('the tile filter is a token read by the map in both modes, and the twin defaults it to none', str_contains($twin, "html:not(.dark) .leaflet-tile {\n  filter: var(--ts-map-tile-filter);") && str_contains((string) file_get_contents(__DIR__ . '/../base/light.css'), '--ts-map-tile-filter: none;'));
}
