<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Throwable;
use Xblossia\ThemeSelector\Console\PublishCommand;
use Xblossia\ThemeSelector\Console\ValidateCommand;
use Xblossia\ThemeSelector\Graph\PortSeriesSupport;
use Xblossia\ThemeSelector\Graph\RecolouringRrd;
use Xblossia\ThemeSelector\Hooks\Menu;
use Xblossia\ThemeSelector\Http\Middleware\GraphColours;
use Xblossia\ThemeSelector\Skin\SkinCompiler;
use Xblossia\ThemeSelector\Skin\TokenCatalog;

class ThemeSelectorProvider extends ServiceProvider
{
    public const PLUGIN_NAME = 'ThemeSelector';

    public function register(): void
    {
        $root = dirname(__DIR__);
        $this->app->singleton(Settings::class);
        $this->app->singleton(GraphPalette::class);
        $this->app->singleton(SkinResolver::class);
        $this->app->singleton(SkinRegistry::class);
        $this->app->singleton(DefaultSkin::class);
        $this->app->singleton(SkinRepository::class, fn ($app) => new SkinRepository(
            public_path(SkinRepository::PUBLIC_DIR),
            $app->make(SkinRegistry::class),
            $root . '/skins',
        ));
        $this->app->singleton(SkinPublisher::class, fn ($app) => new SkinPublisher(
            $root,
            public_path(SkinRepository::PUBLIC_DIR),
            $app->make(SkinRegistry::class),
        ));
        $this->app->singleton(SkinInstaller::class, fn ($app) => new SkinInstaller(
            public_path(SkinRepository::PUBLIC_DIR),
            $app->make(SkinRepository::class),
            $app->make(SkinRegistry::class),
            $app->make(DefaultSkin::class),
        ));
        $this->app->singleton(TokenCatalog::class, fn () => TokenCatalog::fromFile($root . '/resources/token-catalog.json'));
        $this->app->singleton(SkinCompiler::class, fn ($app) => new SkinCompiler($app->make(TokenCatalog::class)));
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
            $this->commands([PublishCommand::class, ValidateCommand::class]);
        } else {
            $this->publishSkins();
            $this->recolourPortSeries();
        }

        $this->loadRoutesFrom(dirname(__DIR__) . '/routes/web.php');
        $this->loadViewsFrom(dirname(__DIR__) . '/resources/views', self::PLUGIN_NAME);

        // Not a plugin hook: core has no <head> hook. A second composer on the
        // main layout pushes into its @stack('styles'), which renders after
        // webui.custom_css. See docs/PLUGIN.md.
        View::composer('layouts.librenmsv1', SkinInjector::class);

        // Graph images are drawn server-side from config, so they follow the
        // user's skin through a per-request override on the graph route.
        $this->app['router']->pushMiddlewareToGroup('web', GraphColours::class);
    }

    /**
     * Let the port traffic series follow graph_colours.port_in/port_out without editing core.
     *
     * generic_data.inc.php hard-codes those six colours, so RecolouringRrd rewrites exactly those
     * options just before rrdtool runs. Installed on web requests only (they draw the graphs), and
     * only if reflection says core's RRD store still has the shape the subclass assumes: a
     * mismatched override would be an uncatchable fatal, so otherwise we leave graphs as core
     * draws them. See docs/PLUGIN.md.
     */
    private function recolourPortSeries(): void
    {
        try {
            if (! PortSeriesSupport::compatible()) {
                return;
            }
            // Only replace core's own store: if something else already wrapped it, leave that alone.
            $this->app->extend(PortSeriesSupport::STORE, fn ($store) => $store::class === PortSeriesSupport::STORE ? new RecolouringRrd() : $store);
            // A facade caches the instance it first resolved; make it resolve the extended one.
            \Illuminate\Support\Facades\Facade::clearResolvedInstance(PortSeriesSupport::STORE);
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: port graph colours not installed: ' . $e->getMessage());
        }
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
