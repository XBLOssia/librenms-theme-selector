<?php

/*
 * Writes the skin bundles dev/test-upload.sh uploads: valid ones, and a set of
 * hostile ones, each built to trip one specific defence.
 *
 *   php dev/make-fixtures.php /tmp/ts-fixtures
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

$out = $argv[1] ?? '/tmp/ts-fixtures';
@mkdir($out, 0777, true);

$css = good_css();
$manifest = fn (array $over = []) => good_manifest(array_merge(['id' => 'slate-teal', 'name' => 'Slate Teal'], $over));
$put = function (string $name, array $entries, array $opts = []) use ($out): void {
    file_put_contents("$out/$name", ZipBuilder::build($entries, $opts));
};
$std = fn (string $id, string $cssText, array $extra = []) => array_merge([
    ['name' => 'skin.json', 'data' => good_manifest(['id' => $id, 'name' => ucwords(str_replace('-', ' ', $id))]), 'method' => 8],
    ['name' => 'skin.css', 'data' => $cssText, 'method' => 8],
], $extra);

// ---- valid ---------------------------------------------------------------------
$put('good-slate.zip', $std('slate-teal', $css));
$put('good-slate-v2.zip', $std('slate-teal', str_replace('#2ec4b6', '#ffb000', $css)));
$put('good-graph.zip', $std('slate-graph', $css, [['name' => 'graph.conf', 'data' => "graph_colours.greens=[\"101010\",\"202020\",\"303030\"]\nrrdgraph_def_text_color_dark=abcdef\n", 'method' => 8]]));
$put('good-linky.zip', $std('linky', $css));
$put('good-font.zip', $std('with-font', "@font-face {\n  font-family: \"Test Face\";\n  src: url(\"fonts/test.woff2\") format(\"woff2\");\n}\n" . $css, [['name' => 'fonts/test.woff2', 'data' => fake_font('wOF2', 500), 'method' => 8]]));

// ---- valid but not allowed -------------------------------------------------------
$put('collide-bundled.zip', $std('terran', $css));
$put('reserved-id.zip', $std('none', $css));

// ---- hostile: the archive --------------------------------------------------------
$put('evil-traversal.zip', array_merge($std('evil', $css), [['name' => '../../../../var/www/evil.php', 'data' => '<?php system($_GET[0]);']]));
$put('evil-php-entry.zip', array_merge($std('evil', $css), [['name' => 'skin.php', 'data' => '<?php phpinfo();']]));
$put('evil-htaccess.zip', array_merge($std('evil', $css), [['name' => 'fonts/.htaccess', 'data' => 'AddType application/x-httpd-php .woff2']]));
$put('evil-symlink.zip', [['name' => 'skin.json', 'data' => $manifest(['id' => 'evil']), 'method' => 8], ['name' => 'skin.css', 'data' => '/etc/passwd', 'madeBy' => (3 << 8) | 20, 'extAttr' => (0120777 << 16)]]);
$put('evil-nested-zip.zip', array_merge($std('evil', $css), [['name' => 'inner.zip', 'data' => ZipBuilder::build([['name' => 'a', 'data' => 'b']])]]));
$bomb = gzdeflate(str_repeat("\0", 40 * 1024 * 1024), 9);
$put('evil-bomb.zip', [['name' => 'skin.json', 'data' => $manifest(['id' => 'evil']), 'method' => 8], ['name' => 'skin.css', 'method' => 8, 'raw' => $bomb, 'data' => str_repeat('a', 100), 'usize' => 100, 'crc' => crc32(str_repeat('a', 100))]]);
file_put_contents("$out/notzip.zip", '<html><script>alert(document.cookie)</script></html>');
file_put_contents("$out/php-as-zip.zip", '<?php system($_GET["c"]); ?>');
file_put_contents("$out/oversize.zip", "PK\x03\x04" . random_bytes(5 * 1024 * 1024));

// ---- licence notices -------------------------------------------------------------
// Markup characters are legal in a notice (it is shown escaped), so this one
// tries to be an XSS payload. It must be stored and shown as text.
$notice = "Copyright (c) 2026 <b>Test</b> & Co <script>alert('licence-xss')</script>\nThis Font Software is licensed under the SIL Open Font License, Version 1.1.\n";
$put('good-license.zip', array_merge($std('with-license', $css), [['name' => 'LICENSE.txt', 'data' => $notice, 'method' => 8]]));
$put('evil-license-control.zip', array_merge($std('evil', $css), [['name' => 'LICENSE.txt', 'data' => "Copyright \x1b[31m2026 \xe2\x80\xae txt.exe", 'method' => 8]]));
$put('evil-license-name.zip', array_merge($std('evil', $css), [['name' => 'license.php', 'data' => '<?php system($_GET[0]);', 'method' => 8]]));

// ---- ornaments -----------------------------------------------------------------
$bar = 'linear-gradient(180deg, transparent 2px, #e50832 2px, #e50832 6px, transparent 6px)';
$extra = "--ts-frame-tl: $bar;\n  --ts-frame-br: $bar;\n  --ts-panel-radius-tl: 0;\n"
    . "  --ts-heading-marker: linear-gradient(90deg, #e50832, #f37c2f);\n  --ts-heading-marker-size: 3px 60%;\n"
    . "  --ts-heading-strip: radial-gradient(circle, #8ea0c2 0, #8ea0c2 1.5px, transparent 1.6px);\n  --ts-heading-strip-size: 34px 6px;\n  --ts-heading-strip-repeat: repeat-x;\n  --ts-heading-strip-opacity: .5;\n"
    . "  --ts-navbar-strip-top: linear-gradient(90deg, transparent, #f37c2f, transparent);\n  --ts-navbar-strip-top-size: 100% 3px;\n"
    . "  --ts-navbar-strip-bottom: linear-gradient(90deg, #e50832 50%, #f37c2f 50%);\n  --ts-navbar-strip-bottom-size: 100% 4px;\n"
    . "  --ts-widget-frame-bottom: linear-gradient(0deg, #f37c2f 2px, transparent 2px);\n  --ts-widget-radius-tl: 0;\n"
    . "  --ts-btn-chamfer: 0px;\n  --ts-btn-chamfer-tl: 8px;\n  --ts-btn-chamfer-br: 8px;\n  --ts-label-chamfer: 5px;\n  --ts-badge-chamfer: 5px;\n"
    . "  --ts-navbar-strip-bottom-breathe: 6s;\n  --ts-heading-marker-breathe: 3s;\n  --ts-breathe-low: .5;\n"
    . "  --ts-heading-marker-glow: rgba(255, 92, 122, .6);\n  --ts-navbar-strip-top-glow: #f37c2f;\n"
    . "  --ts-alert-glow-period: 3s;\n  --ts-alert-glow-low: rgba(255, 92, 122, .5);\n  --ts-alert-glow-high: rgba(255, 92, 122, .95);\n"
    . "  --ts-panel-chamfer: 0px;\n  --ts-panel-chamfer-bl: 12px;\n  --ts-panel-chamfer-rise: 1.732;\n  --ts-panel-cut-fill: #000f26;\n  --ts-panel-cut-stroke: #34497a;\n"
    . "  --ts-widget-chamfer: 0px;\n  --ts-widget-chamfer-bl: 12px;\n  --ts-widget-cut-stroke: #34497a;\n";
$put('good-frames.zip', $std('with-frames', str_replace('--ts-bg:', "$extra  --ts-bg:", $css)));
$put('evil-motion-fast.zip', $std('evil', str_replace('--ts-bg:', "--ts-navbar-strip-top-breathe: 0.2s;\n  --ts-bg:", $css)));
$put('evil-glow-shadow.zip', $std('evil', str_replace('--ts-bg:', "--ts-alert-glow-high: #f00, 0 0 100px 60px #00f;\n  --ts-bg:", $css)));
$put('evil-cut-fill.zip', $std('evil', str_replace('--ts-bg:', "--ts-panel-chamfer-bl: 12px;\n  --ts-panel-cut-fill: #000, 0 0 90px blue;\n  --ts-bg:", $css)));
$put('evil-cut-huge.zip', $std('evil', str_replace('--ts-bg:', "--ts-widget-chamfer: 40px;\n  --ts-bg:", $css)));
$put('evil-chamfer-percent.zip', $std('evil', str_replace('--ts-bg:', "--ts-btn-chamfer: 50%;\n  --ts-bg:", $css)));
$put('evil-frames-url.zip', $std('evil', str_replace('--ts-bg:', "--ts-frame-tl: url(http://evil.example/a.png);
  --ts-bg:", $css)));

// ---- textures ------------------------------------------------------------------
$tileCss = "--tx-tile: url(\"textures/tile.png\");\n  --ts-body-bg-image: var(--tx-tile), linear-gradient(180deg, #101820, #0a1014);\n  --ts-body-bg-size: 64px 64px, auto;\n  ";
$tile = png_build(['w' => 64, 'h' => 64, 'type' => 6]);
$withTile = fn (string $png, string $decl = null) => $std('with-texture', str_replace('--ts-bg:', ($decl ?? $tileCss) . '--ts-bg:', $css), [['name' => 'textures/tile.png', 'data' => $png, 'method' => 8]]);
$put('good-texture.zip', $withTile($tile));
$put('evil-texture-php.zip', $withTile(png_build(['w' => 8, 'h' => 8, 'tail' => '<?php system($_GET[0]); ?>'])));
$put('evil-texture-svg.zip', $withTile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
$put('evil-texture-huge.zip', $withTile(png_build(['w' => 4000, 'h' => 4000, 'raw' => 'x'])));
$put('evil-texture-bomb.zip', $withTile(png_build(['w' => 256, 'h' => 256, 'idat' => gzcompress(str_repeat("\0", 30 * 1024 * 1024), 9)])));
$put('evil-texture-remote.zip', $std('evil', str_replace('--ts-bg:', "--tx-tile: url(\"http://evil.example/beacon.png\");\n  --ts-body-bg-image: var(--tx-tile);\n  --ts-bg:", $css), [['name' => 'textures/tile.png', 'data' => $tile, 'method' => 8]]));
$put('evil-texture-name.zip', $std('evil', $css, [['name' => 'textures/shell.php', 'data' => $tile, 'method' => 8]]));
$put('evil-texture-unused.zip', $std('evil', $css, [['name' => 'textures/tile.png', 'data' => $tile, 'method' => 8]]));

// ---- hostile: the stylesheet -----------------------------------------------------
$put('evil-css-url.zip', $std('evil', "html.dark {\n  --ts-navbar-bg-image: url(http://evil.example/beacon.png);\n  --ts-bg: #000;\n}\n"));
$put('evil-css-import.zip', $std('evil', "@import url(http://evil.example/x.css);\n" . $css));
$put('evil-css-breakout.zip', $std('evil', "html.dark {\n  --ts-bg: red; } body { display: none } html.dark {\n}\n"));
$put('evil-css-selector.zip', $std('evil', "body { background: url(x); }\nhtml.dark { --ts-bg: #000; }\n"));
$put('evil-css-structural.zip', $std('evil', "html.dark {\n  --ts-bg: #000;\n  --ts-panel-before-content: \"Session expired. Sign in again.\";\n  --ts-panel-before-position: fixed;\n  --ts-panel-before-width: 100vw;\n}\n"));
$put('evil-css-escape.zip', $std('evil', "html.dark {\n  --ts-bg: #000;\n  --p-x: u\\72l(http://evil.example);\n}\n"));
$put('evil-css-property.zip', $std('evil', "html.dark {\n  --ts-bg: #000;\n  display: none;\n}\n"));

// ---- hostile: fonts --------------------------------------------------------------
$face = "@font-face {\n  font-family: \"Test Face\";\n  src: url(\"fonts/test.woff2\") format(\"woff2\");\n}\n" . $css;
$put('evil-font-php.zip', $std('evil', $face, [['name' => 'fonts/test.woff2', 'data' => substr(fake_font('wOF2', 500), 0, 200) . '<?php system($_GET[0]); ?>' . substr(fake_font('wOF2', 500), 226), 'method' => 8]]));
$put('evil-font-appended.zip', $std('evil', $face, [['name' => 'fonts/test.woff2', 'data' => fake_font('wOF2', 500) . '<?php echo 1; ?>', 'method' => 8]]));
$put('evil-font-svg.zip', $std('evil', $face, [['name' => 'fonts/test.woff2', 'data' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' . str_repeat(' ', 200), 'method' => 8]]));
$put('evil-font-remote.zip', $std('evil', "@font-face {\n  font-family: \"X\";\n  src: url(\"http://evil.example/f.woff2\") format(\"woff2\");\n}\n" . $css));

// ---- hostile: metadata -----------------------------------------------------------
$put('evil-manifest-xss.zip', [['name' => 'skin.json', 'data' => $manifest(['id' => 'evil', 'name' => '<script>alert(1)</script>']), 'method' => 8], ['name' => 'skin.css', 'data' => $css, 'method' => 8]]);
$put('evil-manifest-id.zip', [['name' => 'skin.json', 'data' => $manifest(['id' => '../../x']), 'method' => 8], ['name' => 'skin.css', 'data' => $css, 'method' => 8]]);
$put('evil-graph.zip', $std('evil', $css, [['name' => 'graph.conf', 'data' => "rrdgraph_def_text_dark=-c BACK#000000 --imgformat SVG\nauth_mechanism=none\n", 'method' => 8]]));

echo count(glob("$out/*")) . " fixtures in $out\n";
