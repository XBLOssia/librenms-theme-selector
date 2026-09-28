<?php

namespace Xblossia\ThemeSelector;

use App\Models\UserPref;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * View composer on layouts.librenmsv1: pushes the current user's skin into
 * the layout's @stack('styles').
 *
 * This runs on every page render and is not a plugin hook, so PluginManager
 * does not catch its errors. Anything thrown here would break the page, so
 * every failure falls back to stock styling.
 */
class SkinInjector
{
    public const PREF = 'theme_selector.skin';

    public function __construct(private readonly SkinRepository $skins)
    {
    }

    public function compose(View $view): void
    {
        try {
            $skin = $this->resolveSkin();
            $urls = $skin === null ? [] : $this->skins->stylesheetUrls($skin);

            $html = '<meta name="theme-selector" content="' . e($skin ?? 'none') . '">';
            foreach ($urls as $url) {
                // Webroot-relative, like webui.custom_css; resolves via the layout's <base>.
                $html .= "\n    <link href=\"" . e($url) . '" rel="stylesheet" data-theme-selector>';
            }

            $view->getFactory()->startPush('styles', $html . "\n");
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: skin injection skipped: ' . $e->getMessage());
        }
    }

    private function resolveSkin(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $skin = UserPref::getPref($user, self::PREF);

        return is_string($skin) && $this->skins->exists($skin) ? $skin : null;
    }
}
