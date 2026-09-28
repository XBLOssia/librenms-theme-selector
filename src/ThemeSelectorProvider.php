<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Xblossia\ThemeSelector\Hooks\Menu;

class ThemeSelectorProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'ThemeSelector';

    public function register(): void
    {
        $this->app->singleton(SkinRepository::class, fn () => new SkinRepository(
            dirname(__DIR__) . '/skins',
            public_path(SkinRepository::PUBLIC_DIR),
        ));
    }

    public function boot(PluginManagerInterface $pluginManager): void
    {
        // Publish at least one hook on every request, enabled or not:
        // PluginManager::cleanupPlugins() deletes the plugin's row (and its
        // settings) if it registered none.
        $pluginManager->publishHook(self::PLUGIN_NAME, MenuEntryHook::class, Menu::class);

        if (! $pluginManager->pluginEnabled(self::PLUGIN_NAME)) {
            return;
        }

        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/web.php');
        $this->loadViewsFrom(dirname(__DIR__) . '/resources/views', self::PLUGIN_NAME);

        // Not a plugin hook: core has no <head> hook. A second composer on the
        // main layout pushes into its @stack('styles'), which renders after
        // webui.custom_css. See docs/PLUGIN.md.
        View::composer('layouts.librenmsv1', SkinInjector::class);
    }
}
