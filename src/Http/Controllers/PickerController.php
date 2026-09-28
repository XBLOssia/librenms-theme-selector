<?php

namespace Xblossia\ThemeSelector\Http\Controllers;

use App\Models\UserPref;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Xblossia\ThemeSelector\SkinInjector;
use Xblossia\ThemeSelector\SkinRepository;
use Xblossia\ThemeSelector\ThemeSelectorProvider;

class PickerController extends Controller
{
    public function index(Request $request, SkinRepository $skins): View
    {
        return view(ThemeSelectorProvider::PLUGIN_NAME . '::picker', [
            'skins' => $skins->all(),
            'current' => UserPref::getPref($request->user(), SkinInjector::PREF),
        ]);
    }

    public function store(Request $request, SkinRepository $skins): RedirectResponse
    {
        $skin = (string) $request->input('skin', '');

        if ($skin === '') {
            UserPref::forgetPref($request->user(), SkinInjector::PREF);
        } elseif ($skins->exists($skin)) {
            UserPref::setPref($request->user(), SkinInjector::PREF, $skin);
        } else {
            return back()->withErrors(['skin' => 'Unknown skin.']);
        }

        return redirect()->route('theme-selector.index');
    }
}
