<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\PreviewChoice;
use Xblossia\ThemeSelector\PreviewGraph;
use Xblossia\ThemeSelector\SkinRepository;

/*
 * The picker's preview: which skin a choice previews, the sample graph drawn from a skin's own
 * palette, and the install dates the skin list shows.
 */
function test_preview(): void
{
    T::group('preview: which skin a choice shows');
    $installed = fn (string $id): bool => in_array($id, ['zerg', 'terran', 'mine'], true);
    T::ok('a skin id previews as itself', PreviewChoice::target('zerg', null, $installed) === 'zerg');
    T::ok('stock previews as stock', PreviewChoice::target('none', 'zerg', $installed) === 'none');
    T::ok('"follow the default" previews the default skin', PreviewChoice::target('', 'terran', $installed) === 'terran');
    T::ok('"follow the default" with none set previews stock', PreviewChoice::target('', null, $installed) === 'none');
    T::ok('"follow the default" with a default that is gone previews stock', PreviewChoice::target('', 'removed', $installed) === 'none');
    foreach (['removed', 'ZERG', 'zerg ', "zerg\n", '../zerg', 'a/b', '<script>', 'default', 'null'] as $bad) {
        T::ok('an id that is not installed previews nothing: ' . json_encode($bad), PreviewChoice::target($bad, 'zerg', $installed) === null);
    }
    $asked = [];
    PreviewChoice::target('none', 'zerg', function (string $id) use (&$asked): bool {
        $asked[] = $id;

        return true;
    });
    T::ok('stock needs no lookup', $asked === []);

    T::group('preview: the sample graph');
    $palette = [
        'rrdgraph_def_text_dark' => '-c BACK#010A03 -c SHADEA#EEEEEE00 -c GRID#0A2E14 -c MGRID#0F5A26 -c FRAME#0F5A26 -c ARROW#00FF41',
        'rrdgraph_def_text_color_dark' => '9DFFB5',
        'graph_colours.port_in' => ['B8FFCB', '00FF41', '00DD41'],
        'graph_colours.port_out' => ['FFE9A8', 'FFD23F', 'FFAA3F'],
    ];
    $svg = PreviewGraph::svg($palette);
    T::ok('it is one svg element', str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>') && substr_count($svg, '<svg') === 1);
    foreach (['#010A03', '#0A2E14', '#0F5A26', '#9DFFB5', '#00FF41', '#00DD41', '#FFD23F', '#FFAA3F'] as $colour) {
        T::ok("it uses the skin's $colour", str_contains($svg, $colour));
    }
    T::ok('it uses nothing else for ground and grid', ! str_contains($svg, '#1C1C1C') && ! str_contains($svg, '#3A3A3A'));
    foreach (['<script', 'href', 'xlink', 'url(', 'http:', 'javascript', 'onload', '<image', '<foreignObject', '<style', 'data:'] as $needle) {
        T::ok("it has no $needle", ! str_contains(strtolower(preg_replace('~xmlns="http://www.w3.org/2000/svg"~', '', $svg)), $needle));
    }
    T::ok('it is deterministic', PreviewGraph::svg($palette) === $svg);
    T::ok('with no palette it falls back to the stock colours', str_contains(PreviewGraph::svg([]), '#1C1C1C') && str_contains(PreviewGraph::svg([]), '#4CAF50'));

    $evil = [
        'rrdgraph_def_text_dark' => '-c BACK#"/><script>alert(1)</script> -c GRID#zzzzzz -c MGRID#0F5A26',
        'rrdgraph_def_text_color_dark' => '"><script>',
        'graph_colours.port_in' => ['"><script>', '00FF41', '00DD41'],
        'graph_colours.port_out' => ['FFE9A8', 'FFD23F'],
    ];
    $out = PreviewGraph::svg($evil);
    T::ok('colours that are not hex never reach the markup', ! str_contains($out, 'script') && ! str_contains($out, 'alert') && ! str_contains($out, 'zzzzzz'));
    T::ok('and fall back as a whole: a ramp with one bad colour, or too short, is the stock one', str_contains($out, '#4CAF50') && str_contains($out, '#42A5F5'));
    T::ok('a valid colour next to a bad one is still used', str_contains($out, '#0F5A26'));
    T::ok('a colour with a trailing newline is not accepted', ! str_contains(PreviewGraph::svg(['graph_colours.port_in' => ["B8FFCB\n", '00FF41', '00DD41']]), '#B8FFCB'));
    T::ok('a colour with something stuck on the end is not accepted', str_contains(PreviewGraph::svg(['rrdgraph_def_text_dark' => '-c BACK#010A03;']), '#1C1C1C') && ! str_contains(PreviewGraph::svg(['rrdgraph_def_text_dark' => '-c BACK#010A03;']), '#010A03'));
    T::ok('a ramp colour of six characters that are not hex digits is not drawn', ! str_contains(PreviewGraph::svg(['graph_colours.port_in' => ['B8FFCB', 'ZZZZZZ', '00DD41']]), 'ZZZZZZ'));
    T::ok('an 8-digit colour (with alpha) is drawn as its 6 digits', str_contains(PreviewGraph::svg(['rrdgraph_def_text_dark' => '-c BACK#11223344']), '#112233'));
    T::ok('non-string palette values are ignored', str_contains(PreviewGraph::svg(['rrdgraph_def_text_dark' => ['x'], 'rrdgraph_def_text_color_dark' => 5, 'graph_colours.port_in' => 'x']), '#1C1C1C'));

    T::group('the skin list: install dates');
    [, $reg, , $pub, $root] = installer_fixture();
    $pkg = "$root/package/skins";
    mkdir("$pub/skins/mine", 0755, true);
    file_put_contents("$pub/skins/mine/skin.css", 'x');
    mkdir("$pub/skins/odd", 0755, true);
    file_put_contents("$pub/skins/odd/skin.css", 'x');
    $row = ['name' => 'Mine', 'description' => '', 'author' => '', 'version' => '1.0.0', 'graph' => []];
    $reg->rows['mine'] = ['id' => 'mine', 'created_at' => '2026-09-30 08:15:00', 'updated_at' => '2026-10-02 17:20:09'] + $row;
    $reg->rows['odd'] = ['id' => 'odd', 'created_at' => 'yesterday', 'updated_at' => null] + $row;
    $all = (new SkinRepository($pub, $reg, $pkg))->all();
    T::ok('an uploaded skin has the day it was installed', ($all['mine']['installed_at'] ?? null) === '2026-09-30 08:15:00');
    T::ok('and the day it was last replaced', ($all['mine']['updated_at'] ?? null) === '2026-10-02 17:20:09');
    T::ok('a timestamp that is not one is nothing, not an error', array_key_exists('odd', $all) && $all['odd']['installed_at'] === null && $all['odd']['updated_at'] === null);
    T::ok('a bundled skin has no install date', array_key_exists('terran', $all) && $all['terran']['installed_at'] === null && $all['terran']['updated_at'] === null);
}
