<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Throwable;
use Xblossia\ThemeSelector\Console\PublishCommand;
use Xblossia\ThemeSelector\Hooks\Menu;

class ThemeSelectorProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'ThemeSelector';

    public function register(): void
    {
        $root = dirname(__DIR__);
        $this->app->singleton(Settings::class);
        $this->app->singleton(SkinRepository::class, fn () => new SkinRepository(public_path(SkinRepository::PUBLIC_DIR)));
        $this->app->singleton(SkinPublisher::class, fn () => new SkinPublisher($root, public_path(SkinRepository::PUBLIC_DIR)));
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Publish at least one hook on every request, enabled or not:
        // PluginManager::cleanupPlugins() deletes the plugin's row (and its
        // settings) if it registered none.
        $pluginManager->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, Menu::class);

        // Migrations register regardless, so `lnms migrate` (run by daily.sh)
        // creates the table before the plugin is first enabled.
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');

        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PublishCommand::class]);
        } else {
            $this->publishSkins();
        }

        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/web.php');
        $this->loadViewsFrom(dirname(__DIR__) . '/resources/views', self::PLUGIN_NAME);

        // Not a plugin hook: core has no <head> hook. A second composer on the
        // main layout pushes into its @stack('styles'), which renders after
        // webui.custom_css. See docs/PLUGIN.md.
        View::composer('layouts.librenmsv1', SkinInjector::class);
    }

    /**
     * Republish the bundled skins after a package update. Runs on web
     * requests, as the webserver user that serves the files; a failure (most
     * likely permissions) is logged and the page carries on with whatever is
     * already published.
     */
    private function publishSkins(): void
    {
        try {
            $this->app->make(SkinPublisher::class)->syncIfNeeded();
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: publishing skins failed: ' . $e->getMessage());
        }
    }
}
