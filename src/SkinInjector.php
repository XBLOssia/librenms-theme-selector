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
    /** The request attribute the preview controller sets to show one skin on its sample page. */
    public const PREVIEW = 'theme-selector.preview';

    public function __construct(
        private readonly SkinResolver $resolver,
        private readonly SkinRepository $skins,
        private readonly Effects $effects,
    ) {
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

            // The picker's preview page names the skin to show, as a request attribute that only
            // the preview controller sets (never read from the URL). It shows that skin, or stock
            // for 'none', whatever the visitor has chosen.
            $previewing = request()->attributes->get(self::PREVIEW);
            if (is_string($previewing)) {
                $skin = $previewing !== PreviewChoice::STOCK && $this->skins->exists($previewing) ? $previewing : null;
            } else {
                // The login page has no user: it gets the instance default.
                $skin = $this->resolver->forUser(Auth::user());
            }
            $urls = $skin === null ? [] : $this->skins->stylesheetUrls($skin);

            $html = '<meta name="theme-selector" content="' . e($skin ?? 'none') . '">';
            // Skins installed by upload, and bundled skins that ask for it (features.json), get
            // the fixed ornament layer in base.css (docs/ORNAMENTS.md): that rule is keyed on
            // this attribute.
            $mark = $skin !== null && $this->skins->usesOrnaments($skin) ? ' data-ts-orn' : '';
            foreach ($urls as $url) {
                // Webroot-relative, like webui.custom_css; resolves via the layout's <base>.
                $html .= "\n    <link href=\"" . e($url) . '" rel="stylesheet" data-theme-selector' . $mark . '>';
            }

            $view->getFactory()->startPush('styles', $html . "\n");

            // A bundled skin's page effects (Effects) go at the end of the body, after the page.
            if ($skin !== null && ! is_string($previewing)) {
                $effects = $this->effects->html(
                    $this->skins->features($skin)['effects'],
                    request()->is('device/*'),
                    fn (int $n): int => random_int(1, $n),
                );
                if ($effects !== '') {
                    $view->getFactory()->startPush('scripts', $effects);
                }
            }
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: skin injection skipped: ' . $e->getMessage());
        }
    }
}
