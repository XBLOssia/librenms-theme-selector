<?php

namespace Xblossia\ThemeSelector;

/**
 * The pure parts of `lnms theme-selector:status`: what the update machinery has done, said in
 * words. LibreNMS's daily.sh updates plugins from composer.plugins.json; these decide whether
 * what is on this host will survive and follow that (the command gathers the facts and prints).
 */
final class Status
{
    public const PACKAGE = 'xblossia/librenms-theme-selector';

    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * What daily.sh will do with this plugin, from composer.plugins.json (decoded, or null if the
     * file is missing or unreadable).
     *
     * @param  array<mixed>|null  $plugins
     * @return array{0: string, 1: string} level and text
     */
    public static function constraint(?array $plugins, string $package = self::PACKAGE): array
    {
        $require = is_array($plugins['require'] ?? null) ? $plugins['require'] : [];
        $constraint = $require[$package] ?? null;
        if (! is_string($constraint) || $constraint === '') {
            return [self::FAIL, "$package is not in composer.plugins.json. daily.sh reinstalls only the plugins listed there, so its next run removes this one. Fix: ./lnms plugin:add $package dev-main"];
        }
        if (str_starts_with($constraint, 'dev-') || str_ends_with($constraint, '-dev')) {
            return [self::OK, "update source: $constraint (daily.sh moves it to the newest commit on every run)"];
        }
        if (preg_match('/^v?\d+(\.\d+){0,3}$/', $constraint) === 1) {
            return [self::OK, "update source: pinned to $constraint (daily.sh keeps it there; ./lnms plugin:add $package <version> moves it)"];
        }

        return [self::OK, "update source: releases matching $constraint (daily.sh moves it to the newest one on every run)"];
    }

    /**
     * Where Composer may keep its global config for the user running this: COMPOSER_HOME, LibreNMS's
     * fallback when HOME is not writable (composer_wrapper.php), and Composer's own two layouts.
     *
     * @param  array<string, string>  $env  COMPOSER_HOME, HOME, XDG_CONFIG_HOME
     * @return string[]
     */
    public static function composerHomes(array $env, string $installDir): array
    {
        $homes = [];
        if (($env['COMPOSER_HOME'] ?? '') !== '') {
            $homes[] = $env['COMPOSER_HOME'];
        }
        $homes[] = rtrim($installDir, '/') . '/.composer';
        $home = $env['HOME'] ?? '';
        if ($home !== '') {
            $homes[] = rtrim($home, '/') . '/.composer';
            $xdg = ($env['XDG_CONFIG_HOME'] ?? '') !== '' ? $env['XDG_CONFIG_HOME'] : rtrim($home, '/') . '/.config';
            $homes[] = rtrim($xdg, '/') . '/composer';
        }

        return array_values(array_unique($homes));
    }

    /**
     * Where the repository entry the plugin is installed from stands, and whether it will make the
     * install and the nightly update fail on GitHub's API limit.
     *
     * @param  array<int, array<mixed>>  $configs  the decoded config.json of each Composer home that had one
     * @param  string[]  $checked  the homes that were looked in (named when nothing is found)
     * @return array{0: string, 1: string, 2: string|null} level, text, and the entry's url if one was found
     */
    public static function repository(array $configs, array $checked = []): array
    {
        $entry = null;
        foreach ($configs as $config) {
            $repos = $config['repositories'] ?? null;
            foreach (is_array($repos) ? $repos : [] as $key => $repo) {
                if (is_array($repo) && (str_contains((string) ($repo['url'] ?? ''), 'librenms-theme-selector') || $key === 'theme-selector' || ($repo['name'] ?? null) === 'theme-selector')) {
                    $entry = $repo;
                    break 2;
                }
            }
        }
        if ($entry === null) {
            return [self::WARN, 'update source: no Composer repository entry for the plugin in the global config (looked in ' . ($checked === [] ? 'nowhere' : implode(', ', $checked)) . '). daily.sh needs one to find it; if this user is not the one daily.sh runs as, ignore this', null];
        }
        $url = is_string($entry['url'] ?? null) ? $entry['url'] : null;
        if (($entry['type'] ?? '') === 'path') {
            return [self::OK, 'repository: a local path (a development install)', $url];
        }
        if (($entry['no-api'] ?? false) === true) {
            return [self::OK, 'repository: ' . ($url ?? 'set') . ', fetched with git (no GitHub API calls)', $url];
        }
        if (is_string($url) && preg_match('~^(https?://|git@)github\.com[/:]~i', $url) === 1) {
            return [self::WARN, "repository: $url is fetched through GitHub's API, which is rate limited for anonymous callers: plugin:add and the nightly update can then fail with \"Could not authenticate against github.com\". Fix: php scripts/composer_wrapper.php config --global repositories.theme-selector '{\"type\":\"vcs\",\"url\":\"$url\",\"no-api\":true}'", $url];
        }

        return [self::OK, 'repository: ' . ($url ?? 'set'), $url];
    }

    /**
     * Whether Composer holds a cached copy of the repository. With one, a night the source can't be
     * reached is survived (Composer warns and uses what it has); without one, that night's
     * `composer require` fails and daily.sh's `composer install` removes the plugin.
     *
     * @param  string[]  $vcsCacheNames  directory names found under Composer's vcs cache
     * @return array{0: string, 1: string}
     */
    public static function cache(?string $url, array $vcsCacheNames): array
    {
        if ($url !== null && preg_match('~^(/|\./|\.\./)~', $url) === 1) {
            return [self::OK, 'repository cache: not needed for a local source'];
        }
        $slug = $url === null ? null : strtolower((string) preg_replace('/[^a-z0-9.]/i', '-', $url));
        foreach ($vcsCacheNames as $name) {
            $lower = strtolower($name);
            if (str_contains($lower, 'librenms-theme-selector') || ($slug !== null && $lower === $slug)) {
                return [self::OK, 'repository cache: present, so a night GitHub cannot be reached does not remove the plugin'];
            }
        }

        return [self::WARN, 'repository cache: Composer holds no copy of the repository (looked under the vcs cache of this user). Until an update has filled it, a night the source cannot be reached makes daily.sh remove the plugin. Optional safety net: scripts/ensure-installed.sh'];
    }

    /**
     * The plugin's migrations that have not run.
     *
     * @param  string[]  $files  migration names the package ships
     * @param  string[]  $ran  names recorded as run
     * @return string[]
     */
    public static function pendingMigrations(array $files, array $ran): array
    {
        $pending = array_values(array_diff($files, $ran));
        sort($pending);

        return $pending;
    }

    /** `dev-main@668fa5f` for a branch, `1.2.0 (668fa5f)` for a release, whichever parts are known. */
    public static function describeVersion(?string $pretty, ?string $reference): string
    {
        $short = is_string($reference) && preg_match('/^[0-9a-f]{7,40}$/', $reference) === 1 ? substr($reference, 0, 7) : null;
        if ($pretty === null || $pretty === '') {
            return $short ?? 'unknown';
        }
        if ($short === null) {
            return $pretty;
        }

        return str_starts_with($pretty, 'dev-') ? "$pretty@$short" : "$pretty ($short)";
    }

    /**
     * What Composer says is installed here, described; null if the package is not installed by Composer.
     *
     * Read from vendor/composer/installed.php each time rather than from Composer\InstalledVersions,
     * which keeps what it first loaded for the life of the process: a php-fpm worker that was running
     * when an update replaced the package would go on reporting the old version.
     *
     * @param  string|null  $installedFile  where Composer keeps it (tests pass their own)
     */
    public static function installedVersion(string $package = self::PACKAGE, ?string $installedFile = null): ?string
    {
        $file = $installedFile ?? dirname(__DIR__, 3) . '/composer/installed.php';
        $data = null;
        if (is_file($file)) {
            ob_start();
            try {
                $data = (static fn () => include $file)();
            } catch (\Throwable) {
                $data = null;
            } finally {
                ob_end_clean();
            }
        }
        $entry = is_array($data) && is_array($data['versions'] ?? null) ? ($data['versions'][$package] ?? null) : null;
        if (is_array($entry)) {
            $pretty = $entry['pretty_version'] ?? null;
            $reference = $entry['reference'] ?? null;

            return self::describeVersion(is_string($pretty) ? $pretty : null, is_string($reference) ? $reference : null);
        }
        if ($installedFile !== null) {
            return null;
        }
        if (! class_exists(\Composer\InstalledVersions::class) || ! \Composer\InstalledVersions::isInstalled($package)) {
            return null;
        }

        return self::describeVersion(
            \Composer\InstalledVersions::getPrettyVersion($package),
            \Composer\InstalledVersions::getReference($package),
        );
    }
}
