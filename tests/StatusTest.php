<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\SkinPublisher;
use Xblossia\ThemeSelector\Status;

/*
 * The update path: what `theme-selector:status` says about the plugin, what the publisher records
 * about the version it published (so the log can say when an update landed), and the update script.
 */
function test_status(): void
{
    T::group('status: what daily.sh will do with the plugin');
    $p = Status::PACKAGE;
    [$level, $text] = Status::constraint(null);
    T::ok('no composer.plugins.json: the next daily.sh removes the plugin', $level === Status::FAIL && str_contains($text, 'removes this one') && str_contains($text, "plugin:add $p dev-main"));
    [$level] = Status::constraint(['require' => ['someone/else' => 'dev-main']]);
    T::ok('listed for another package only: same failure', $level === Status::FAIL);
    foreach ([['require' => [$p => '']], ['require' => [$p => 5]], ['require' => [$p => null]], ['require' => 'x'], ['other' => 1], []] as $bad) {
        T::ok('a missing or malformed entry fails: ' . json_encode($bad), Status::constraint($bad)[0] === Status::FAIL);
    }
    [$level, $text] = Status::constraint(['require' => [$p => 'dev-main']]);
    T::ok('a branch follows the newest commit', $level === Status::OK && str_contains($text, 'dev-main') && str_contains($text, 'newest commit'));
    T::ok('a -dev constraint counts as a branch', str_contains(Status::constraint(['require' => [$p => '1.x-dev']])[1], 'newest commit'));
    foreach (['1.2.3', 'v1.2', '2'] as $pin) {
        T::ok("$pin is a pin", str_contains(Status::constraint(['require' => [$p => $pin]])[1], 'pinned to ' . $pin));
    }
    foreach (['^1.0', '~1.2', '>=1.0 <2.0', '1.*'] as $range) {
        T::ok("$range follows releases", str_contains(Status::constraint(['require' => [$p => $range]])[1], 'releases matching ' . $range));
    }

    T::group('status: pending migrations');
    $files = ['2026_09_28_000001_a', '2026_10_03_000001_c', '2026_09_29_000001_b'];
    T::ok('nothing pending when all ran', Status::pendingMigrations($files, array_merge($files, ['core_one'])) === []);
    T::ok('the ones not run, in order', Status::pendingMigrations($files, ['2026_09_28_000001_a']) === ['2026_09_29_000001_b', '2026_10_03_000001_c']);
    T::ok('all pending on an empty table', count(Status::pendingMigrations($files, [])) === 3);

    T::group('status: describing a version');
    T::ok('a branch and its commit', Status::describeVersion('dev-main', '668fa5ffbc0105038ef775d14dfda8025fe60a62') === 'dev-main@668fa5f');
    T::ok('a release and its commit', Status::describeVersion('1.2.0', '668fa5ffbc0105038ef775d14dfda8025fe60a62') === '1.2.0 (668fa5f)');
    T::ok('a version with no commit', Status::describeVersion('1.2.0', null) === '1.2.0');
    T::ok('a commit with no version', Status::describeVersion(null, '668fa5ffbc0105038ef775d14dfda8025fe60a62') === '668fa5f');
    T::ok('nothing known', Status::describeVersion(null, null) === 'unknown');
    T::ok('a reference that is not a commit is not shown', Status::describeVersion('dev-main', "x\ny; rm -rf /") === 'dev-main');

    T::group('status: reading what Composer installed');
    $tmp = sys_get_temp_dir() . '/ts-installed-' . bin2hex(random_bytes(4)) . '.php';
    $write = fn (array $versions) => file_put_contents($tmp, '<?php return ' . var_export(['versions' => $versions], true) . ';');
    $write([$p => ['pretty_version' => 'dev-main', 'reference' => '668fa5ffbc0105038ef775d14dfda8025fe60a62']]);
    T::ok('a branch install', Status::installedVersion($p, $tmp) === 'dev-main@668fa5f');
    $write([$p => ['pretty_version' => '1.2.0', 'reference' => '668fa5ffbc0105038ef775d14dfda8025fe60a62']]);
    T::ok('a release install, read afresh when the file changes (not cached for the process)', Status::installedVersion($p, $tmp) === '1.2.0 (668fa5f)');
    $write(['other/package' => ['pretty_version' => '1.0.0']]);
    T::ok('a package that is not in the file', Status::installedVersion($p, $tmp) === null);
    file_put_contents($tmp, 'not php at all');
    T::ok('a file that is not what Composer writes', (function () use ($p, $tmp) {
        try {
            return Status::installedVersion($p, $tmp) === null;
        } catch (Throwable) {
            return true;
        }
    })());
    unlink($tmp);
    T::ok('a file that is not there', Status::installedVersion($p, $tmp) === null);

    T::group('status: the publisher records which version it published');
    $root = sys_get_temp_dir() . '/ts-status-' . bin2hex(random_bytes(4));
    mkdir("$root/package/base", 0755, true);
    file_put_contents("$root/package/base/base.css", "html.dark {\n}\n");
    mkdir("$root/package/skins/nightly", 0755, true);
    file_put_contents("$root/package/skins/nightly/skin.css", "html.dark {\n  --ts-bg: #000;\n}\n");
    file_put_contents("$root/package/skins/nightly/skin.json", json_encode(['id' => 'nightly', 'name' => 'Nightly']));
    $out = "$root/public";
    $old = new SkinPublisher("$root/package", $out, new FakeRegistry(), 'dev-main@aaaaaaa');
    T::ok('nothing recorded before the first publish', $old->publishedVersion() === null && $old->publishedAt() === null && ! $old->isCurrent());
    T::ok('the first publish writes', $old->syncIfNeeded() === true);
    T::ok('and records the version', $old->publishedVersion() === 'dev-main@aaaaaaa' && $old->version() === 'dev-main@aaaaaaa');
    T::ok('and when, as a UTC timestamp', preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) $old->publishedAt()) === 1);
    T::ok('it is current', $old->isCurrent());
    T::ok('an unchanged package is not published again', $old->syncIfNeeded() === false);
    $new = new SkinPublisher("$root/package", $out, new FakeRegistry(), 'dev-main@bbbbbbb');
    T::ok('a new package version is not current, though no file changed', ! $new->isCurrent() && $new->publishedVersion() === 'dev-main@aaaaaaa');
    T::ok('and is published on the next load', $new->syncIfNeeded() === true && $new->publishedVersion() === 'dev-main@bbbbbbb' && $new->isCurrent());
    $none = new SkinPublisher("$root/package", $out, new FakeRegistry());
    T::ok('a publisher that is not told a version has none', $none->version() === null);
    file_put_contents("$out/.bundled.json", '{"fingerprint": "x", "version": ["not", "a", "string"], "published_at": 5}');
    T::ok('a damaged marker reads as nothing', $none->publishedVersion() === null && $none->publishedAt() === null && ! $none->isCurrent());

    // A marker is a file on disk: what it lists as dropped from the package is only ever a skin directory.
    mkdir("$out/skins/gone", 0755, true);
    file_put_contents("$out/skins/gone/skin.css", 'x');
    file_put_contents("$out/keep.txt", 'keep');
    file_put_contents("$out/skins/keep.txt", 'keep');
    file_put_contents("$out/.bundled.json", json_encode(['fingerprint' => 'stale', 'skins' => ['..', '../..', '.', 'a/b', '', 'gone', 'nightly']]));
    (new SkinPublisher("$root/package", $out, new FakeRegistry(), 'v'))->syncNow();
    T::ok('a skin dropped from the package is removed', ! file_exists("$out/skins/gone"));
    T::ok('a marker entry that is a path, not a skin name, removes nothing', is_file("$out/keep.txt") && is_file("$out/skins/keep.txt") && is_file("$out/base.css") && is_dir("$out/skins/nightly"));

    // An uploaded skin whose id is all digits is an integer array key; the guard against a
    // bundled skin with the same id must still see it.
    $digits = new FakeRegistry();
    $digits->rows['2026'] = ['id' => '2026', 'name' => 'Digits', 'description' => '', 'author' => '', 'version' => '1.0.0', 'graph' => []];
    mkdir("$root/package/skins/2026", 0755, true);
    file_put_contents("$root/package/skins/2026/skin.css", "html.dark {\n  --ts-bg: #fff;\n}\n");
    file_put_contents("$root/package/skins/2026/skin.json", json_encode(['id' => '2026', 'name' => 'Bundled 2026']));
    mkdir("$out/skins/2026", 0755, true);
    file_put_contents("$out/skins/2026/skin.css", "html.dark {\n  --ts-bg: #123;\n}\n");
    (new SkinPublisher("$root/package", $out, $digits, 'w'))->syncNow();
    T::ok('a bundled skin does not overwrite an uploaded one whose id is all digits', file_get_contents("$out/skins/2026/skin.css") === "html.dark {\n  --ts-bg: #123;\n}\n");
    rmrf($root);

    T::group('status: where Composer looks for the source');
    $homes = Status::composerHomes(['COMPOSER_HOME' => '/data/composer', 'HOME' => '/home/librenms', 'XDG_CONFIG_HOME' => ''], '/opt/librenms');
    T::ok('COMPOSER_HOME first, then LibreNMS\'s fallback, then Composer\'s two layouts', $homes === ['/data/composer', '/opt/librenms/.composer', '/home/librenms/.composer', '/home/librenms/.config/composer'], json_encode($homes));
    T::ok('XDG_CONFIG_HOME is honoured, and a trailing slash does not double', Status::composerHomes(['HOME' => '/h/', 'XDG_CONFIG_HOME' => '/x/'], '/opt/l/')[2] === '/x/composer');
    T::ok('with nothing set there is still the install directory', Status::composerHomes([], '/opt/librenms') === ['/opt/librenms/.composer']);

    $gh = 'https://github.com/XBLOssia/librenms-theme-selector';
    [$level, $text, $url] = Status::repository([['repositories' => ['theme-selector' => ['type' => 'vcs', 'url' => $gh, 'no-api' => true]]]]);
    T::ok('git mode is fine', $level === Status::OK && $url === $gh && str_contains($text, 'no GitHub API calls'));
    [$level, $text] = Status::repository([['repositories' => ['theme-selector' => ['type' => 'vcs', 'url' => $gh]]]]);
    T::ok('the GitHub API form warns, with the fix', $level === Status::WARN && str_contains($text, 'rate limited') && str_contains($text, '"no-api":true') && str_contains($text, $gh));
    [$level] = Status::repository([['repositories' => ['theme-selector' => ['type' => 'vcs', 'url' => $gh, 'no-api' => 'true']]]]);
    T::ok('only a real true counts as git mode', $level === Status::WARN);
    [$level] = Status::repository([['repositories' => [['type' => 'vcs', 'url' => $gh . '.git', 'no-api' => true]]]]);
    T::ok('an entry is found by its url when its key is something else', $level === Status::OK);
    [$level, $text] = Status::repository([['repositories' => ['theme-selector' => ['type' => 'vcs', 'url' => 'https://git.example.net/me/theme-selector.git']]]]);
    T::ok('another host is fine', $level === Status::OK && ! str_contains($text, 'rate limited'));
    [$level] = Status::repository([['repositories' => ['theme-selector' => ['type' => 'path', 'url' => '/plugin']]]]);
    T::ok('a path repository is a development install', $level === Status::OK);
    [$level, , $url] = Status::repository([['repositories' => [['name' => 'theme-selector', 'type' => 'vcs', 'url' => '/data/ts.git']]]]);
    T::ok('Composer 2 writes a list with the name inside each entry, and an entry is found by it', $level === Status::OK && $url === '/data/ts.git');
    [$level, $text] = Status::repository([['repositories' => ['someone-else' => ['type' => 'vcs', 'url' => 'https://github.com/a/b']]]], ['/h/.composer']);
    T::ok('no entry for the plugin warns, naming where it looked', $level === Status::WARN && str_contains($text, '/h/.composer'));
    foreach ([[], [['repositories' => 'x']], [['repositories' => [null, 5, 'x']]], [['other' => 1]]] as $odd) {
        T::ok('a config with no usable repositories warns, never fails: ' . json_encode($odd), Status::repository($odd)[0] === Status::WARN);
    }

    $dir = 'https---github.com-XBLOssia-librenms-theme-selector';
    T::ok('a cached copy of the repository', Status::cache($gh, ['https---other', $dir])[0] === Status::OK);
    T::ok('the cache is found by the url\'s own slug too', Status::cache('https://git.example.net/me/x.git', ['https---git.example.net-me-x.git'])[0] === Status::OK);
    [$level, $text] = Status::cache($gh, ['https---other']);
    T::ok('no cached copy warns, saying what that risks', $level === Status::WARN && str_contains($text, 'remove the plugin'));
    T::ok('no cache directory at all warns', Status::cache($gh, [])[0] === Status::WARN);
    T::ok('a local source needs no cache', Status::cache('/data/ts.git', [])[0] === Status::OK && Status::cache('./x', [])[0] === Status::OK);
    T::ok('an unknown url with an empty cache warns', Status::cache(null, [])[0] === Status::WARN);

    T::group('the update script');
    $script = (string) file_get_contents(__DIR__ . '/../scripts/update.sh');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $script)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
    T::ok('it ends on the call to main and an exit, so the shell never reads past a file Composer has replaced', array_slice($lines, -2) === ['main "$@"', 'exit $?']);
    T::ok('it refuses to run as root', str_contains($script, 'id -u') && str_contains($script, 'not root'));
    $code = (string) preg_replace('/^\s*#.*$/m', '', $script);
    preg_match_all('/^\s*(?:if ! )?step (?:\.\/lnms|php artisan) (\S+(?: --force)?)/m', $code, $steps);
    T::ok('it runs the steps an update can need, in order', $steps[1] === ['plugin:add', 'migrate --force', 'route:cache', 'theme-selector:publish'], json_encode($steps[1]));
    T::ok('and ends by checking the result', str_contains($code, "./lnms theme-selector:status\n}"));
    T::ok('it only rebuilds a route cache that exists', str_contains($code, 'bootstrap/cache/routes-*.php'));
    T::ok('it runs neither sudo nor rm as a command', ! preg_match('/^\s*(sudo|rm)\s/m', $code));

    T::group('the safety-net script (ensure-installed.sh)');
    $ensure = (string) file_get_contents(__DIR__ . '/../scripts/ensure-installed.sh');
    $elines = array_values(array_filter(array_map('trim', explode("\n", $ensure)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
    $ecode = (string) preg_replace('/^\s*#.*$/m', '', $ensure);
    T::ok('it ends on the call to main and an exit, so replacing the file while it runs is harmless', array_slice($elines, -2) === ['main "$@"', 'exit $?']);
    T::ok('it refuses to run as root', str_contains($ecode, 'id -u') && str_contains($ecode, 'not root'));
    T::ok('it does nothing when the plugin is installed', (bool) preg_match('~if \[ -f "vendor/\$PACKAGE/composer.json" \]; then\s+return 0~', $ecode));
    T::ok('it does nothing for a plugin that is not listed in composer.plugins.json (removed on purpose)', (bool) preg_match('~if \[ -z "\$constraint" \]; then\s+return 0~', $ecode) && str_contains($ecode, 'composer_get_plugins'));
    T::ok('it keeps what the plugin follows (the recorded constraint)', str_contains($ecode, 'plugin:add "$PACKAGE" "$constraint"'));
    T::ok('it stands aside while daily.sh or Composer is running', str_contains($ecode, "pgrep -f 'daily\\.sh'") && str_contains($ecode, 'php .*composer'));
    T::ok('and checks that before it changes anything', strpos($ecode, 'pgrep') < strpos($ecode, 'plugin:add'));
    T::ok('a failed plugin:add exits 1 and changes nothing further', (bool) preg_match('~plugin:add[^\n]*\n\s+log [^\n]*\n\s+return 1~', $ecode));
    T::ok('it restores with the same follow-up steps as the updater', str_contains($ecode, 'migrate --force') && str_contains($ecode, 'route:cache') && str_contains($ecode, 'theme-selector:publish'));
    T::ok('it logs only where LibreNMS rotates logs', str_contains($ecode, 'logs/theme-selector-ensure.log'));
    T::ok('it runs neither sudo nor rm as a command', ! preg_match('/^\s*(sudo|rm)\s/m', $ecode));
}
