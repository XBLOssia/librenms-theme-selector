<?php

namespace Xblossia\ThemeSelector;

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
    public function __construct(private readonly SkinResolver $resolver, private readonly SkinRepository $skins)
    {
    }

    public function compose(View $view): void
    {
        try {
            // Escape hatch: a skin that makes a page unusable can't be turned
            // off from inside that page, so ?theme-selector=off shows any page
            // with no skin. It changes nothing stored.
            if (request()->query('theme-selector') === 'off') {
                $view->getFactory()->startPush('styles', '<meta name="theme-selector" content="off">' . "\n");

                return;
            }

            // The login page has no user: it gets the instance default.
            $skin = $this->resolver->forUser(Auth::user());
            $urls = $skin === null ? [] : $this->skins->stylesheetUrls($skin);

            $html = '<meta name="theme-selector" content="' . e($skin ?? 'none') . '">';
            // Skins installed by upload get the fixed ornament layer in base.css
            // (docs/ORNAMENTS.md): that rule is keyed on this attribute.
            $mark = $skin !== null && $this->skins->isUploaded($skin) ? ' data-ts-orn' : '';
            foreach ($urls as $url) {
                // Webroot-relative, like webui.custom_css; resolves via the layout's <base>.
                $html .= "\n    <link href=\"" . e($url) . '" rel="stylesheet" data-theme-selector' . $mark . '>';
            }

            $view->getFactory()->startPush('styles', $html . "\n");
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: skin injection skipped: ' . $e->getMessage());
        }
    }
}
