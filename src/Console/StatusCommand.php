<?php

namespace Xblossia\ThemeSelector\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Throwable;
use Xblossia\ThemeSelector\SkinPublisher;
use Xblossia\ThemeSelector\SkinRepository;
use Xblossia\ThemeSelector\Status;

/**
 * Is the plugin installed, current and wired in the way LibreNMS's nightly update expects? The
 * check to run after an update (or when something looks wrong): it changes nothing. Exits
 * non-zero if anything needs fixing, so it can sit in a monitoring script.
 */
class StatusCommand extends Command
{
    protected $signature = 'theme-selector:status';

    protected $description = 'Check that Theme Selector is installed, up to date and set up to be updated by daily.sh';

    public function handle(SkinPublisher $publisher): int
    {
        $failed = false;
        $say = function (string $level, string $text) use (&$failed): void {
            $failed = $failed || $level === Status::FAIL;
            $this->line(sprintf('%-5s %s', ['ok' => 'ok', 'warn' => 'WARN', 'fail' => 'FAIL'][$level], $text));
        };

        $installed = Status::installedVersion();
        $say(Status::OK, 'installed: ' . ($installed ?? 'not by Composer (a development copy)'));

        $file = base_path('composer.plugins.json');
        $plugins = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        [$level, $text] = Status::constraint(is_array($plugins) ? $plugins : null);
        $say($level, $text);

        // Where daily.sh will find the plugin's source, and whether Composer can still get through a night
        // the source can't be reached. Looked up the way Composer and LibreNMS's wrapper pick their home.
        $env = ['COMPOSER_HOME' => (string) getenv('COMPOSER_HOME'), 'HOME' => (string) getenv('HOME'), 'XDG_CONFIG_HOME' => (string) getenv('XDG_CONFIG_HOME')];
        $homes = Status::composerHomes($env, base_path());
        $configs = [];
        foreach ($homes as $home) {
            $decoded = is_file("$home/config.json") ? json_decode((string) @file_get_contents("$home/config.json"), true) : null;
            if (is_array($decoded)) {
                $configs[] = $decoded;
            }
        }
        [$level, $text, $url] = Status::repository($configs, $homes);
        $say($level, $text);
        $cacheRoots = [];
        if ((string) getenv('COMPOSER_CACHE_DIR') !== '') {
            $cacheRoots[] = (string) getenv('COMPOSER_CACHE_DIR');
        }
        foreach ($homes as $home) {
            $cacheRoots[] = "$home/cache";
        }
        $xdgCache = (string) getenv('XDG_CACHE_HOME');
        if ($xdgCache === '' && (string) getenv('HOME') !== '') {
            $xdgCache = getenv('HOME') . '/.cache';
        }
        if ($xdgCache !== '') {
            $cacheRoots[] = "$xdgCache/composer";
        }
        $names = [];
        foreach ($cacheRoots as $root) {
            foreach (is_dir("$root/vcs") ? (scandir("$root/vcs") ?: []) : [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $names[] = $name;
                }
            }
        }
        [$level, $text] = Status::cache($url, $names);
        $say($level, $text);

        try {
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(dirname(__DIR__, 2) . '/database/migrations'));
            $pending = Status::pendingMigrations($files, $migrator->getRepository()->getRan());
            $say($pending === [] ? Status::OK : Status::FAIL, $pending === []
                ? 'database: all ' . count($files) . ' migrations have run'
                : 'database: ' . count($pending) . ' migration(s) have not run (' . implode(', ', $pending) . '). Run: ./lnms migrate --force');
        } catch (Throwable $e) {
            $say(Status::WARN, 'database: could not check migrations (' . $e->getMessage() . ')');
        }

        $dir = public_path(SkinRepository::PUBLIC_DIR);
        if (! is_dir($dir)) {
            $say(Status::WARN, "published skins: $dir does not exist yet. Run: ./lnms theme-selector:publish, or load any LibreNMS page");
        } elseif (! is_writable($dir)) {
            $say(Status::FAIL, "published skins: $dir is not writable by this user, so an update cannot republish. The web server user must be able to write it");
        } elseif ($publisher->isCurrent() && is_file("$dir/base.css") && is_file("$dir/base-light.css")) {
            $at = $publisher->publishedAt();
            $say(Status::OK, 'published skins: current' . ($at !== null ? " (published $at)" : ''));
        } else {
            $say(Status::WARN, 'published skins: older than the installed package; the next page load republishes them (or run ./lnms theme-selector:publish)');
        }

        if ($this->laravel->routesAreCached() && ! Route::has('theme-selector.index')) {
            $say(Status::FAIL, "routes: LibreNMS's route cache was built without this plugin's page. Run: php artisan route:cache");
        } else {
            $say(Status::OK, 'routes: the picker page is registered');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
