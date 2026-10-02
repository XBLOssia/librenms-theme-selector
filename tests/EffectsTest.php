<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Effects;
use Xblossia\ThemeSelector\Features;
use Xblossia\ThemeSelector\Skin\Manifest;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\SkinRepository;

/*
 * What a bundled skin may ask of the plugin (features.json) and the page effects it can switch on.
 * An uploaded skin can ask for neither: the manifest and the zip allowlist refuse them.
 */
function test_effects(): void
{
    T::group('features.json: what a bundled skin may ask for');
    $none = ['ornaments' => false, 'effects' => []];
    T::ok('no file means nothing', Features::parse(null) === $none);
    foreach (['', 'not json', '[]', '[true]', '"x"', '1', 'null', '{"ornaments": "true"}', '{"ornaments": 1}', '{"ornaments": "yes", "effects": "white-rabbit"}', '{"effects": {"white-rabbit": true}}'] as $bad) {
        T::ok('refuses (reads as nothing): ' . json_encode($bad), Features::parse($bad) === $none, json_encode(Features::parse($bad)));
    }
    T::ok('ornaments true', Features::parse('{"ornaments": true}') === ['ornaments' => true, 'effects' => []]);
    T::ok('a known effect', Features::parse('{"effects": ["white-rabbit"]}') === ['ornaments' => false, 'effects' => ['white-rabbit']]);
    T::ok('unknown effects, non-strings and other keys are ignored', Features::parse('{"ornaments": true, "effects": ["white-rabbit", "evil", 5, null, ["white-rabbit"]], "script": "alert(1)", "css": "x"}') === ['ornaments' => true, 'effects' => ['white-rabbit']]);
    T::ok('a repeated effect is listed once', Features::parse('{"effects": ["white-rabbit", "white-rabbit"]}')['effects'] === ['white-rabbit']);
    T::ok('too much nesting is refused', Features::parse('{"ornaments": true, "x": [[[[[1]]]]]}') === $none);
    T::ok('an oversize file is refused', Features::parse('{"ornaments": true, "x": "' . str_repeat('a', 3000) . '"}') === $none);
    foreach (glob(__DIR__ . '/../skins/*/features.json') ?: [] as $file) {
        $raw = json_decode((string) file_get_contents($file), true);
        T::ok(basename(dirname($file)) . ' features.json has only the keys that are honoured', is_array($raw) && array_diff(array_keys($raw), ['ornaments', 'effects']) === [], (string) file_get_contents($file));
        T::ok(basename(dirname($file)) . ' features.json asks for nothing unknown', array_diff((array) ($raw['effects'] ?? []), Features::EFFECTS) === []);
    }

    T::group('an upload cannot ask for them');
    $good = ['id' => 'x-skin', 'name' => 'X', 'modes' => ['dark']];
    foreach ([['ornaments' => true], ['effects' => ['white-rabbit']], ['features' => ['ornaments' => true]]] as $extra) {
        $r = new Report();
        $m = Manifest::parse(json_encode($good + $extra), $r);
        T::ok('a manifest with ' . implode(',', array_keys($extra)) . ' is refused', $m === null && ! $r->ok(), implode(' | ', $r->errors()));
    }

    T::group('which skins get what: bundled by request, uploads always ornaments, nothing else');
    [, $reg, , $pub, $root] = installer_fixture();
    $pkg = "$root/package/skins";
    foreach (['rainy' => '{"ornaments": true, "effects": ["white-rabbit"]}', 'plain' => null, 'broken' => '{not json'] as $id => $features) {
        mkdir("$pkg/$id", 0755, true);
        file_put_contents("$pkg/$id/skin.css", 'x');
        file_put_contents("$pkg/$id/skin.json", '{}');
        if ($features !== null) {
            file_put_contents("$pkg/$id/features.json", $features);
        }
    }
    file_put_contents("$pkg/terran/features.json", '{"ornaments": true}'); // terran is bundled too
    mkdir("$pkg/half", 0755, true); // a features.json in a directory that is not a complete skin counts for nothing
    file_put_contents("$pkg/half/features.json", '{"ornaments": true, "effects": ["white-rabbit"]}');
    $reg->rows['mine'] = ['id' => 'mine'];
    // an uploaded skin whose id has a features.json planted in the web root or package must not be believed
    @mkdir("$pub/skins/mine", 0755, true);
    file_put_contents("$pub/skins/mine/features.json", '{"effects": ["white-rabbit"]}');
    $repo = new SkinRepository($pub, $reg, $pkg);
    T::ok('a bundled skin that asks gets both', $repo->features('rainy') === ['ornaments' => true, 'effects' => ['white-rabbit']] && $repo->usesOrnaments('rainy'));
    T::ok('a bundled skin with no file gets nothing', $repo->features('plain') === $none && ! $repo->usesOrnaments('plain'));
    T::ok('a bundled skin with a broken file gets nothing', $repo->features('broken') === $none && ! $repo->usesOrnaments('broken'));
    T::ok('ornaments alone do not add effects', $repo->features('terran') === ['ornaments' => true, 'effects' => []] && $repo->usesOrnaments('terran'));
    T::ok('an uploaded skin gets the ornament layer but no effects, whatever is on disk', $repo->usesOrnaments('mine') && $repo->features('mine') === $none);
    T::ok('a directory that is not a complete skin gets nothing', $repo->features('half') === $none && ! $repo->usesOrnaments('half'));
    foreach (['nope', '','../plain', 'plain/../rainy', "rainy\n", 'RAINY'] as $id) {
        T::ok('an unknown or malformed id gets nothing: ' . json_encode($id), $repo->features($id) === $none && ! $repo->usesOrnaments($id));
    }

    T::group('effects: the white rabbit');
    $dir = __DIR__ . '/../resources/effects';
    $effects = new Effects($dir);
    $asked = [];
    $roll = function (int $n) use (&$asked): int {
        $asked[] = $n;

        return 1;
    };
    $html = $effects->html(['white-rabbit'], true, $roll);
    T::ok('on a device page, on a lucky roll, it is added', str_contains($html, 'class="ts-white-rabbit"'));
    T::ok('the roll is one in ten', $asked === [10] && Effects::RABBIT_ONE_IN === 10);
    $shown = 0;
    for ($n = 1; $n <= 10; $n++) {
        $shown += $effects->html(['white-rabbit'], true, fn (int $max): int => $n) !== '' ? 1 : 0;
    }
    T::ok('of the ten possible rolls exactly one shows it', $shown === 1, (string) $shown);
    T::ok('not on a page that is not a device page', $effects->html(['white-rabbit'], false, fn (int $n): int => 1) === '');
    T::ok('not for a skin that did not ask', $effects->html([], true, fn (int $n): int => 1) === '');
    T::ok('an unknown effect adds nothing', $effects->html(['evil'], true, fn (int $n): int => 1) === '');
    T::ok('the roll is not made unless it matters', (function () use ($effects) {
        $calls = 0;
        $r = function (int $n) use (&$calls): int {
            $calls++;

            return 1;
        };
        $effects->html([], true, $r);
        $effects->html(['white-rabbit'], false, $r);

        return $calls === 0;
    })());
    T::ok('a missing file adds nothing and does not fail', (new Effects($dir . '/missing'))->html(['white-rabbit'], true, fn (int $n): int => 1) === '');

    $text = (string) file_get_contents("$dir/white-rabbit.html");
    T::ok('the markup is small', strlen($text) > 500 && strlen($text) < 4096, (string) strlen($text));
    foreach (['<script', 'javascript:', 'http:', 'https:', '@import', 'url(', 'onload', 'onerror', 'onclick', '<a ', '<img', '<iframe', '<link', 'href', 'xlink', 'data:'] as $needle) {
        T::ok("the markup has no $needle", ! str_contains(strtolower($text), $needle));
    }
    T::ok('it cannot be clicked, focused or read out', str_contains($text, 'pointer-events:none') && str_contains($text, 'aria-hidden="true"') && ! str_contains($text, 'tabindex'));
    T::ok('it plays once, for two seconds, and stays gone', str_contains($text, 'animation:ts-white-rabbit 2s ease-in-out 1 forwards') && ! str_contains($text, 'infinite'));
    T::ok('it stays in the corner and below modals', str_contains($text, 'position:fixed;right:20px;bottom:20px') && str_contains($text, 'z-index:1000'));
    T::ok('it is hidden under reduced motion', str_contains($text, '@media (prefers-reduced-motion:reduce){.ts-white-rabbit{display:none}}'));
    T::ok('it fades in and out and finishes at no opacity', (bool) preg_match('/@keyframes ts-white-rabbit\{0%\{opacity:0;[^}]*\}12%\{opacity:1;[^}]*\}62%\{opacity:1;[^}]*\}100%\{opacity:0;[^}]*\}\}/', $text));
}
