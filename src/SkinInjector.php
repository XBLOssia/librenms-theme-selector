<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * View composer on layouts.librenmsv1: pushes the current user's skins into
 * the layout's @stack('styles'), one for each mode.
 *
 * LibreNMS decides light or dark in the browser, so the server can't know which a page will be.
 * It sends both: the dark slot's skin written for `html.dark` and the light slot's for
 * `html:not(.dark)`, and whichever matches is the one that applies (Modes).
 *
 * This runs on every page render and is not a plugin hook, so PluginManager
 * does not catch its errors. Anything thrown here would break the page, so
 * every failure falls back to stock styling.
 */
class SkinInjector
{
    /**
     * The request attribute the preview controller sets, as ['skin' => id or 'none', 'mode' => slot],
     * to show one skin in one mode on its sample page.
     */
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

            // The picker's preview page names the skin to show and the mode to show it in, as a
            // request attribute that only the preview controller sets (never read from the URL).
            // It shows that skin in that mode, or stock for 'none', whatever the visitor has chosen.
            $previewing = request()->attributes->get(self::PREVIEW);
            $slots = [];
            foreach (Modes::ALL as $mode) {
                if (is_array($previewing)) {
                    $id = (string) ($previewing['skin'] ?? PreviewChoice::STOCK);
                    $slots[$mode] = ($previewing['mode'] ?? null) === $mode && $id !== PreviewChoice::STOCK && $this->skins->exists($id) ? $id : null;
                } else {
                    // The login page has no user: it gets the instance defaults.
                    $slots[$mode] = $this->resolver->forUser(Auth::user(), $mode);
                }
            }

            // The dark slot's meta tag keeps its original name (pages and tests read it).
            $html = '<meta name="theme-selector" content="' . e($slots[Modes::DARK] ?? 'none') . '">'
                . "\n    " . '<meta name="theme-selector-light" content="' . e($slots[Modes::LIGHT] ?? 'none') . '">';
            foreach ($slots as $mode => $skin) {
                if ($skin === null) {
                    continue;
                }
                // Skins installed by upload, and bundled skins that ask for it (features.json), get
                // the fixed ornament layer in base.css (docs/ORNAMENTS.md): that rule is keyed on
                // this attribute, which is named for the slot so one slot's ornaments don't switch
                // on the other's.
                $mark = $this->skins->usesOrnaments($skin) ? ($mode === Modes::LIGHT ? ' data-ts-orn-light' : ' data-ts-orn') : '';
                foreach ($this->skins->stylesheetUrls($skin, $mode) as $url) {
                    // Webroot-relative, like webui.custom_css; resolves via the layout's <base>.
                    $html .= "\n    <link href=\"" . e($url) . '" rel="stylesheet" data-theme-selector' . $mark . '>';
                }
            }

            $view->getFactory()->startPush('styles', $html . "\n");

            // A bundled skin's page effects (Effects) go at the end of the body, after the page,
            // each in a wrapper that hides it when the page is in the other mode.
            if (! is_array($previewing)) {
                $effects = '';
                foreach ($slots as $mode => $skin) {
                    if ($skin === null) {
                        continue;
                    }
                    $fx = $this->effects->html(
                        $this->skins->features($skin)['effects'],
                        request()->is('device/*'),
                        fn (int $n): int => random_int(1, $n),
                    );
                    if ($fx !== '') {
                        $effects .= '<div class="ts-fx-' . $mode . '">' . $fx . '</div>';
                    }
                }
                if ($effects !== '') {
                    $view->getFactory()->startPush('scripts', '<style>html:not(.dark) .ts-fx-dark,html.dark .ts-fx-light{display:none}</style>' . $effects);
                }
            }
        } catch (Throwable $e) {
            Log::warning('ThemeSelector: skin injection skipped: ' . $e->getMessage());
        }
    }
}
