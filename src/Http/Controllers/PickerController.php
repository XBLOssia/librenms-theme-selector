<?php

namespace Xblossia\ThemeSelector\Http\Controllers;

use App\Models\UserPref;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;
use Xblossia\ThemeSelector\GraphPalette;
use Xblossia\ThemeSelector\Settings;
use Xblossia\ThemeSelector\SkinRepository;
use Xblossia\ThemeSelector\SkinResolver;
use Xblossia\ThemeSelector\ThemeSelectorProvider;

class PickerController extends Controller
{
    public function index(Request $request, SkinRepository $skins, SkinResolver $resolver): View
    {
        return view(ThemeSelectorProvider::PLUGIN_NAME . '::picker', [
            'skins' => $skins->all(),
            'choice' => $resolver->choice($request->user()),
            'default' => $resolver->default(),
            'defaultName' => $skins->name($resolver->default()),
        ]);
    }

    /**
     * The user's own choice: '' (follow the default), 'none', or a skin id.
     */
    public function store(Request $request, SkinRepository $skins): RedirectResponse
    {
        $skin = (string) $request->input('skin', '');

        if ($skin === '') {
            UserPref::forgetPref($request->user(), SkinResolver::PREF);
        } elseif ($skin === SkinResolver::NONE || $skins->exists($skin)) {
            UserPref::setPref($request->user(), SkinResolver::PREF, $skin);
        } else {
            return back()->withErrors(['skin' => 'Unknown skin.']);
        }

        return redirect()->route('theme-selector.index')->with('status', 'Your skin is saved.');
    }

    /**
     * Admin: the instance default ('' for none), which users who haven't
     * chosen get, the login page gets, and whose graph palette applies to
     * everyone.
     */
    public function setDefault(Request $request, SkinRepository $skins, Settings $settings, GraphPalette $graphs): RedirectResponse
    {
        $skin = (string) $request->input('default', '');
        if ($skin !== '' && ! $skins->exists($skin)) {
            return back()->withErrors(['default' => 'Unknown skin.']);
        }

        try {
            if ($skin === '') {
                $settings->forget(Settings::DEFAULT_SKIN);
            } else {
                $settings->set(Settings::DEFAULT_SKIN, $skin);
            }
            $graphs->apply($skin === '' ? null : $skin);
        } catch (Throwable $e) {
            Log::error('ThemeSelector: saving the default skin failed: ' . $e->getMessage());

            return back()->withErrors(['default' => 'Saving the default failed; see the LibreNMS log.']);
        }

        $name = $skins->name($skin);

        return redirect()->route('theme-selector.index')->with('status', $name
            ? "Default skin is now $name, and graphs use its palette."
            : 'No default skin; graphs are back to their previous colours.');
    }
}
