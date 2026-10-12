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
    $html = $effects->html(['white-rabbit'], $roll);
    T::ok('on a lucky roll it is added, on any page', str_contains($html, 'class="ts-white-rabbit"'));
    T::ok('the roll is one in ten', $asked === [10] && Effects::RABBIT_ONE_IN === 10);
    $shown = 0;
    for ($n = 1; $n <= 10; $n++) {
        $shown += $effects->html(['white-rabbit'], fn (int $max): int => $n) !== '' ? 1 : 0;
    }
    T::ok('of the ten possible rolls exactly one shows it', $shown === 1, (string) $shown);
    T::ok('not for a skin that did not ask', $effects->html([], fn (int $n): int => 1) === '');
    T::ok('an unknown effect adds nothing', $effects->html(['evil'], fn (int $n): int => 1) === '');
    T::ok('the roll is not made unless it matters', (function () use ($effects) {
        $calls = 0;
        $r = function (int $n) use (&$calls): int {
            $calls++;

            return 1;
        };
        $effects->html([], $r);
        $effects->html(['evil'], $r);

        return $calls === 0;
    })());
    T::ok('a missing file adds nothing and does not fail', (new Effects($dir . '/missing'))->html(['white-rabbit'], fn (int $n): int => 1) === '');

    $text = (string) file_get_contents("$dir/white-rabbit.html");
    T::ok('the markup is small', strlen($text) > 1000 && strlen($text) < 8192, (string) strlen($text));
    foreach (['<script', 'javascript:', 'http:', 'https:', '@import', 'url(', 'onload', 'onerror', 'onclick', '<a ', '<img', '<iframe', '<link', '<form', 'href', 'xlink', 'data:', ' id=', ' name=', 'tabindex', 'infinite'] as $needle) {
        T::ok("the markup has no $needle", ! str_contains(strtolower($text), $needle));
    }
    T::ok('no script: its one input is a hidden checkbox inside the label that is the click target', substr_count($text, '<input') === 1
        && str_contains($text, '<label class="ts-white-rabbit" aria-hidden="true"><input type="checkbox">') && str_contains($text, '.ts-white-rabbit input{display:none}'));
    T::ok('it can be clicked, but not focused, tabbed to or read out', str_contains($text, 'cursor:pointer') && str_contains($text, 'aria-hidden="true"') && str_contains($text, 'input{display:none}'));
    T::ok('it plays once, for three and a half seconds, and stays gone', str_contains($text, 'animation:ts-white-rabbit 3.5s ease-in-out 1 forwards'));
    T::ok('the rabbit stays in the corner and below modals; the overlay is over everything', str_contains($text, 'position:fixed;right:20px;bottom:20px') && str_contains($text, 'z-index:1000') && str_contains($text, 'z-index:100000'));
    T::ok('it is hidden under reduced motion', str_contains($text, '@media (prefers-reduced-motion:reduce){.ts-wr-sprite,.ts-wr-over{display:none!important}}'));
    T::ok('it fades in and out and finishes at no opacity, then hidden', (bool) preg_match('/@keyframes ts-white-rabbit\{0%\{opacity:0;[^}]*\}7%\{opacity:1;[^}]*\}78%\{opacity:1;[^}]*\}99%\{[^}]*\}100%\{opacity:0;visibility:hidden\}\}/', $text));
    T::ok('a click shows the overlay and hides the rabbit', str_contains($text, '.ts-white-rabbit input:checked ~ .ts-wr-over{display:block;animation:ts-wr-wake 4.8s linear 1 forwards}') && str_contains($text, 'input:checked ~ .ts-wr-sprite{display:none}'));
    T::ok('the overlay is black and white monospace, over the whole screen', str_contains($text, 'top:0;right:0;bottom:0;left:0') && str_contains($text, 'background:#000;color:#fff') && str_contains($text, 'monospace'));
    T::ok('it says one line and has a cursor after it, and nothing else', (bool) preg_match('~<span class="ts-wr-over"><b>Wake up, Neo\.\.\.</b><i>_</i></span></label>~', $text));
    T::ok('the line is typed (in two beats), then the cursor blinks three times, then the overlay ends', str_contains($text, 'animation:ts-wr-type 2s linear 1 forwards')
        && str_contains($text, 'animation:ts-wr-blink .9s steps(1,end) 2s 3') && 2 + 0.9 * 3 <= 4.8
        && (bool) preg_match('/@keyframes ts-wr-wake\{0%,99%\{opacity:1;visibility:visible\}100%\{opacity:0;visibility:hidden\}\}/', $text));

    T::group('effects: the eye of providence');
    $asked = [];
    $roll = function (int $n) use (&$asked): int {
        $asked[] = $n;

        return 1;
    };
    $html = $effects->html(['eye-of-providence'], $roll);
    T::ok('on a lucky roll it is added, on any page', str_contains($html, 'class="ts-eye"'));
    T::ok('the roll is one in twelve', $asked === [12] && Effects::EYE_ONE_IN === 12);
    $shown = 0;
    for ($n = 1; $n <= 12; $n++) {
        $shown += $effects->html(['eye-of-providence'], fn (int $max): int => $n) !== '' ? 1 : 0;
    }
    T::ok('of the twelve possible rolls exactly one shows it', $shown === 1, (string) $shown);
    T::ok('not for a skin that did not ask, and not for one that asked only for the rabbit', $effects->html([], fn (int $n): int => 1) === '' && ! str_contains($effects->html(['white-rabbit'], fn (int $n): int => 1), 'ts-eye'));
    T::ok('a skin that asks for the eye does not get the rabbit', ! str_contains($effects->html(['eye-of-providence'], fn (int $n): int => 1), 'ts-white-rabbit'));
    T::ok('a missing file adds nothing and does not fail', (new Effects($dir . '/missing'))->html(['eye-of-providence'], fn (int $n): int => 1) === '');

    $text = (string) file_get_contents("$dir/eye-of-providence.html");
    T::ok('the markup is small', strlen($text) > 1000 && strlen($text) < 12288, (string) strlen($text));
    foreach (['<script', 'javascript:', 'http:', 'https:', '@import', 'url(', 'onload', 'onerror', 'onclick', '<a ', '<img', '<iframe', '<link', '<form', 'href', 'xlink', 'data:', ' id=', ' name=', 'tabindex', 'infinite'] as $needle) {
        T::ok("the markup has no $needle", ! str_contains(strtolower($text), $needle));
    }
    T::ok('no script: its one input is a hidden checkbox inside the label that is the click target', substr_count($text, '<input') === 1
        && str_contains($text, '<label class="ts-eye" aria-hidden="true"><input type="checkbox">') && str_contains($text, '.ts-eye input{display:none}'));
    T::ok('it can be clicked, but not focused, tabbed to or read out', str_contains($text, 'cursor:pointer') && str_contains($text, 'aria-hidden="true"') && str_contains($text, 'input{display:none}'));
    T::ok('it plays once, for five seconds, and stays gone', str_contains($text, 'animation:ts-eye 5s ease-in-out 1 forwards'));
    T::ok('the eye stays in the corner and below modals; the rain is over everything and cannot be clicked', str_contains($text, 'position:fixed;left:20px;bottom:20px') && str_contains($text, 'z-index:1000')
        && (bool) preg_match('/\.ts-rain\{display:none;position:fixed;top:0;right:0;bottom:0;left:0;z-index:100000;overflow:hidden;pointer-events:none\}/', $text));
    T::ok('it is hidden under reduced motion', str_contains($text, '@media (prefers-reduced-motion:reduce){.ts-eye-sprite,.ts-rain{display:none!important}}'));
    T::ok('the eye fades in and out and finishes at no opacity, then hidden', (bool) preg_match('/@keyframes ts-eye\{0%\{opacity:0;[^}]*\}10%\{opacity:1;[^}]*\}78%\{opacity:1;[^}]*\}99%\{[^}]*\}100%\{opacity:0;visibility:hidden\}\}/', $text));
    T::ok('a click shows the rain and hides the eye', str_contains($text, '.ts-eye input:checked ~ .ts-rain{display:block;animation:ts-rain 12s linear 1 forwards}') && str_contains($text, 'input:checked ~ .ts-eye-sprite{display:none}'));
    T::ok('the rain is at full strength for ten seconds, then fades out over the rest, then is hidden', (bool) preg_match('/@keyframes ts-rain\{0%,83%\{opacity:1;visibility:visible\}99%\{[^}]*\}100%\{opacity:0;visibility:hidden\}\}/', $text));
    preg_match_all('/<i style="--l:([\d.]+)%;--h:(\d+)px;--d:([\d.]+)s;--o:(-?[\d.]+)s;--a:([\d.]+);--w:(\d)px"><\/i>/', $text, $drops, PREG_SET_ORDER);
    T::ok('eighty-four drops, spread across the whole width', count($drops) === 84 && substr_count($text, '<i style=') === 84 && min(array_column($drops, 1)) < 2 && max(array_column($drops, 1)) > 97);
    T::ok('each drop falls for one to one and a half seconds, ten times at most, from a start that is not in the future', (function () use ($drops, $text) {
        foreach ($drops as $d) {
            if ($d[3] < 1.0 || $d[3] > 1.5 || $d[4] > 0 || $d[2] < 60 || $d[2] > 160 || $d[6] < 2 || $d[6] > 3) {
                return false;
            }
        }

        return str_contains($text, 'animation:ts-fall var(--d) linear var(--o) 10}');
    })());
    T::ok('its colours are the skin\'s own palette variables, each with a fallback, and nothing it could not have', preg_match_all('/var\((--[\w-]+)(,[^)]*)?\)/', $text, $vars, PREG_SET_ORDER) > 0
        && array_diff(array_unique(array_column($vars, 1)), ['--p-glow', '--p-gold-hi', '--p-violet', '--p-violet-hi', '--l', '--h', '--d', '--o', '--a', '--w']) === []
        && ! array_filter($vars, fn ($v) => str_starts_with($v[1], '--p-') && (! isset($v[2]) || $v[2] === '')));
}
