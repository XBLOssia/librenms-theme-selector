<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\DefaultSkin;
use Xblossia\ThemeSelector\InstallException;
use Xblossia\ThemeSelector\Skin\CompiledSkin;
use Xblossia\ThemeSelector\SkinInstaller;
use Xblossia\ThemeSelector\SkinRegistry;
use Xblossia\ThemeSelector\SkinRepository;

/** An in-memory registry, so the installer can be tested without a database. */
class FakeRegistry extends SkinRegistry
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];
    public bool $failSave = false;
    public bool $failDelete = false;
    public array $installedBy = [];

    public function all(): array
    {
        return $this->rows;
    }

    public function find(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function save(CompiledSkin $skin, ?int $installedBy): void
    {
        if ($this->failSave) {
            throw new RuntimeException('database is down');
        }
        $this->rows[$skin->id()] = ['id' => $skin->id(), 'name' => $skin->manifest['name'], 'description' => '', 'author' => '', 'version' => '1.0.0', 'graph' => $skin->graph];
        $this->installedBy[$skin->id()] = $installedBy;
    }

    public function delete(string $id): void
    {
        if ($this->failDelete) {
            throw new RuntimeException('database is down');
        }
        unset($this->rows[$id]);
    }
}

/** Records what the installer asks of the default-skin service. */
class FakeDefault extends DefaultSkin
{
    public ?string $cur = null;
    /** @var string[] */
    public array $calls = [];

    public function __construct()
    {
    }

    public function current(): ?string
    {
        return $this->cur;
    }

    public function set(?string $id): void
    {
        $this->calls[] = 'set:' . ($id ?? 'null');
        $this->cur = $id;
    }

    public function reapply(): void
    {
        $this->calls[] = 'reapply';
    }
}

function rmrf(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $e) {
        if ($e !== '.' && $e !== '..') {
            rmrf("$path/$e");
        }
    }
    @rmdir($path);
}

function compiled(string $id, string $css = "html.dark {\n  --ts-bg: #000;\n}\n", array $graph = []): CompiledSkin
{
    return new CompiledSkin(
        ['id' => $id, 'name' => ucfirst($id), 'description' => '', 'author' => '', 'version' => '1.0.0', 'license' => '', 'modes' => ['dark']],
        $css,
        $graph,
        hash('sha256', $css),
        0,
    );
}

/** @return array{0: SkinInstaller, 1: FakeRegistry, 2: FakeDefault, 3: string, 4: string} */
function installer_fixture(): array
{
    $root = sys_get_temp_dir() . '/ts-inst-' . bin2hex(random_bytes(4));
    $pkg = "$root/package/skins";
    mkdir("$pkg/terran", 0755, true);
    file_put_contents("$pkg/terran/skin.css", 'x');
    file_put_contents("$pkg/terran/skin.json", '{}');
    $pub = "$root/public";
    mkdir("$pub/skins/terran", 0755, true);
    file_put_contents("$pub/skins/terran/skin.css", 'bundled');

    $registry = new FakeRegistry();
    $default = new FakeDefault();
    $repo = new SkinRepository($pub, $registry, $pkg);

    return [new SkinInstaller($pub, $repo, $registry, $default), $registry, $default, $pub, $root];
}

function leftover_count(string $pub): int
{
    return count(array_filter(scandir("$pub/skins") ?: [], fn ($e) => str_starts_with($e, '.stage-') || str_starts_with($e, '.old-')));
}

function test_installer(): void
{
    T::group('installer: a fresh install');
    [$inst, $reg, $def, $pub, $root] = installer_fixture();

    $replaced = $inst->install(compiled('mine', "html.dark {\n  --ts-bg: #111;\n}\n"), 7);
    T::ok('reports it was not a replacement', $replaced === false);
    T::ok('writes exactly one file, skin.css', scandir("$pub/skins/mine") === ['.', '..', 'skin.css']);
    T::ok('with the generated content', file_get_contents("$pub/skins/mine/skin.css") === "html.dark {\n  --ts-bg: #111;\n}\n");
    T::ok('file mode 644, directory 755', substr(sprintf('%o', fileperms("$pub/skins/mine/skin.css")), -3) === '644' && substr(sprintf('%o', fileperms("$pub/skins/mine")), -3) === '755');
    T::ok('records the installing user', ($reg->installedBy['mine'] ?? null) === 7);
    T::ok('the registry now has it', isset($reg->rows['mine']));
    T::ok('no staging directory is left', leftover_count($pub) === 0);
    T::ok('the lock file exists (installs are serialised)', is_file("$pub/.install.lock"));
    T::ok('the default service was not touched', $def->calls === []);
    T::ok('the bundled skin is untouched', file_get_contents("$pub/skins/terran/skin.css") === 'bundled');

    T::group('installer: replacing');
    $replaced = $inst->install(compiled('mine', "html.dark {\n  --ts-bg: #222;\n}\n"), 8);
    T::ok('reports a replacement', $replaced === true);
    T::ok('the new content is in place', file_get_contents("$pub/skins/mine/skin.css") === "html.dark {\n  --ts-bg: #222;\n}\n");
    T::ok('the old directory is gone', leftover_count($pub) === 0);
    T::ok('the new installing user is recorded', $reg->installedBy['mine'] === 8);
    $def->cur = 'mine';
    $inst->install(compiled('mine', "html.dark {\n  --ts-bg: #333;\n}\n"), 8);
    T::ok('replacing the default skin re-applies its palette', $def->calls === ['reapply']);
    $def->cur = 'other';
    $def->calls = [];
    $inst->install(compiled('mine', "html.dark {\n  --ts-bg: #444;\n}\n"), 8);
    T::ok('replacing a skin that is not the default does not', $def->calls === []);

    T::group('installer: ids it will not take');
    foreach (['terran' => 'bundled skin', 'none' => 'not allowed', '../x' => 'not allowed', 'a/b' => 'not allowed', '' => 'not allowed', 'UP' => 'not allowed', '.hidden' => 'not allowed', 'a b' => 'not allowed', "a\n" => 'not allowed'] as $id => $why) {
        $before = scandir("$pub/skins");
        $threw = null;
        try {
            $inst->install(compiled((string) $id), 1);
        } catch (InstallException $e) {
            $threw = $e->getMessage();
        }
        T::ok("id " . json_encode($id) . ' is refused', $threw !== null && stripos($threw, $why) !== false, (string) $threw);
        T::ok("and writes nothing for " . json_encode($id), scandir("$pub/skins") === $before && ! file_exists("$pub/x") && ! file_exists("$root/x"));
    }
    T::ok('the bundled skin still has its own content', file_get_contents("$pub/skins/terran/skin.css") === 'bundled');

    T::group('installer: something else is in the way');
    file_put_contents("$pub/skins/afile", 'not a directory');
    $threw = false;
    try {
        $inst->install(compiled('afile'), 1);
    } catch (InstallException) {
        $threw = true;
    }
    T::ok('a regular file at the target is refused', $threw && file_get_contents("$pub/skins/afile") === 'not a directory');
    mkdir("$root/elsewhere");
    file_put_contents("$root/elsewhere/secret", 'keep');
    symlink("$root/elsewhere", "$pub/skins/alink");
    $threw = false;
    try {
        $inst->install(compiled('alink'), 1);
    } catch (InstallException) {
        $threw = true;
    }
    T::ok('a symlink at the target is refused', $threw);
    T::ok('and nothing was written through it', scandir("$root/elsewhere") === ['.', '..', 'secret']);
    T::ok('no staging directory is left behind either', leftover_count($pub) === 0);
    unlink("$pub/skins/alink");

    T::group('installer: the database fails');
    $reg->failSave = true;
    $threw = null;
    try {
        $inst->install(compiled('fresh'), 1);
    } catch (InstallException $e) {
        $threw = $e->getMessage();
    }
    T::ok('a fresh install is reported as failed', $threw !== null && stripos($threw, 'database') !== false);
    T::ok('and its directory is rolled back', ! file_exists("$pub/skins/fresh"));
    T::ok('with no leftovers', leftover_count($pub) === 0);
    T::ok('and no row', ! isset($reg->rows['fresh']));
    $before = file_get_contents("$pub/skins/mine/skin.css");
    $threw = null;
    try {
        $inst->install(compiled('mine', "html.dark {\n  --ts-bg: #999;\n}\n"), 1);
    } catch (InstallException $e) {
        $threw = $e->getMessage();
    }
    T::ok('a failed replacement is reported', $threw !== null);
    T::ok('and the previous skin is restored exactly', file_get_contents("$pub/skins/mine/skin.css") === $before);
    T::ok('with no leftovers', leftover_count($pub) === 0);
    $reg->failSave = false;

    T::group('installer: stale working directories');
    mkdir("$pub/skins/.stage-stale", 0755);
    file_put_contents("$pub/skins/.stage-stale/skin.css", 'x');
    touch("$pub/skins/.stage-stale", time() - 7200);
    mkdir("$pub/skins/.old-stale", 0755);
    touch("$pub/skins/.old-stale", time() - 7200);
    mkdir("$pub/skins/.stage-recent", 0755);
    $inst->install(compiled('sweeper'), 1);
    T::ok('old staging and trash directories are swept', ! file_exists("$pub/skins/.stage-stale") && ! file_exists("$pub/skins/.old-stale"));
    T::ok('a recent one (another install in progress) is left alone', is_dir("$pub/skins/.stage-recent"));
    rmdir("$pub/skins/.stage-recent");

    T::group('installer: removal');
    foreach (['terran' => 'Bundled', 'never-installed' => 'no uploaded skin', '../x' => 'not valid', '' => 'not valid', 'A' => 'not valid'] as $id => $why) {
        $threw = null;
        try {
            $inst->remove((string) $id);
        } catch (InstallException $e) {
            $threw = $e->getMessage();
        }
        T::ok("removing " . json_encode($id) . ' is refused', $threw !== null && stripos($threw, $why) !== false, (string) $threw);
    }
    T::ok('the bundled skin survived those attempts', file_get_contents("$pub/skins/terran/skin.css") === 'bundled');

    $def->cur = 'mine';
    $def->calls = [];
    $inst->remove('mine');
    T::ok('removing a skin deletes its directory', ! file_exists("$pub/skins/mine"));
    T::ok('and its row', ! isset($reg->rows['mine']));
    T::ok('and clears the default first when it was the default', $def->calls === ['set:null'] && $def->cur === null);
    T::ok('and leaves nothing behind', leftover_count($pub) === 0);
    $def->calls = [];
    $inst->remove('sweeper');
    T::ok('removing a non-default skin does not touch the default', $def->calls === []);

    $inst->install(compiled('keeper'), 1);
    $reg->failDelete = true;
    $threw = null;
    try {
        $inst->remove('keeper');
    } catch (InstallException $e) {
        $threw = $e->getMessage();
    }
    T::ok('a database failure while removing is reported', $threw !== null);
    T::ok('and the skin is put back exactly', file_get_contents("$pub/skins/keeper/skin.css") === "html.dark {\n  --ts-bg: #000;\n}\n" && isset($reg->rows['keeper']));
    T::ok('with no leftovers', leftover_count($pub) === 0);
    $reg->failDelete = false;
    $inst->remove('keeper');

    T::group('installer: removal never follows a link out of the skins directory');
    // a skin whose directory has been swapped for a symlink to somewhere precious
    $reg->rows['linky'] = ['id' => 'linky', 'name' => 'Linky', 'description' => '', 'author' => '', 'version' => '1.0.0', 'graph' => []];
    symlink("$root/elsewhere", "$pub/skins/linky");
    $inst->remove('linky');
    T::ok('the link itself is removed', ! file_exists("$pub/skins/linky") && ! is_link("$pub/skins/linky"));
    T::ok('its target is untouched', is_file("$root/elsewhere/secret") && file_get_contents("$root/elsewhere/secret") === 'keep');
    T::ok('the row is gone', ! isset($reg->rows['linky']));

    // a real skin directory containing links: files and directories elsewhere must survive
    $inst->install(compiled('nested'), 1);
    mkdir("$root/other-dir/inner", 0755, true);
    file_put_contents("$root/other-dir/inner/precious", 'keep');
    file_put_contents("$root/other-file", 'keep');
    symlink("$root/other-dir", "$pub/skins/nested/dirlink");
    symlink("$root/other-file", "$pub/skins/nested/filelink");
    // Links may only ever point inside this test's own temp directory. This
    // suite also runs against deliberately broken copies of the installer
    // (tests/mutate.sh), where a link that leaves the sandbox, "/" above all,
    // would be followed and deleted through. That is exactly how a run once
    // wiped a bind-mounted repository. A sacrificial directory proves the same
    // property with a bounded blast radius.
    mkdir("$root/sacrificial/deep", 0755, true);
    file_put_contents("$root/sacrificial/deep/keep", 'keep');
    symlink("$root/sacrificial", "$pub/skins/nested/rootlink");
    $inst->remove('nested');
    T::ok('a skin containing symlinks is removed', ! file_exists("$pub/skins/nested"));
    T::ok('a directory it linked to is untouched', is_file("$root/other-dir/inner/precious"));
    T::ok('a file it linked to is untouched', is_file("$root/other-file"));
    T::ok('and so is a link to a directory tree', is_file("$root/sacrificial/deep/keep"));
    T::ok('and so is everything else', is_file("$pub/skins/terran/skin.css") && is_dir($root));

    rmrf($root);
}
