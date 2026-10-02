<?php

namespace Xblossia\ThemeSelector\Http\Controllers;

use App\Models\UserPref;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;
use Xblossia\ThemeSelector\DefaultSkin;
use Xblossia\ThemeSelector\InstallException;
use Xblossia\ThemeSelector\Modes;
use Xblossia\ThemeSelector\PreviewChoice;
use Xblossia\ThemeSelector\PreviewGraph;
use Xblossia\ThemeSelector\Skin\GraphConf;
use Xblossia\ThemeSelector\Skin\Limits;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;
use Xblossia\ThemeSelector\SkinInjector;
use Xblossia\ThemeSelector\SkinInstaller;
use Xblossia\ThemeSelector\SkinRepository;
use Xblossia\ThemeSelector\SkinResolver;
use Xblossia\ThemeSelector\ThemeSelectorProvider;

class PickerController extends Controller
{
    public function index(Request $request, SkinRepository $skins, SkinResolver $resolver): View
    {
        // One dropdown and preview for each mode. ?light=<choice> and ?dark=<choice> open the page
        // with that choice already selected (the "Preview" link on a row of the skin list); a
        // choice that is not one of the choices is ignored.
        $modes = [];
        foreach (Modes::ALL as $mode) {
            $default = $resolver->default($mode);
            $exists = fn (string $id): bool => $skins->exists($id);
            $asked = $request->query($mode);
            $asked = is_string($asked) && PreviewChoice::target($asked, $default, $exists) !== null ? $asked : null;
            $choice = $resolver->choice($request->user(), $mode);
            $selected = $asked ?? ($choice ?? '');
            $modes[$mode] = [
                'choice' => $choice,
                'default' => $default,
                'defaultName' => $skins->name($default),
                'selected' => $selected,
                'target' => PreviewChoice::target($selected, $default, $exists) ?? PreviewChoice::STOCK,
            ];
        }

        return view(ThemeSelectorProvider::PLUGIN_NAME . '::picker', [
            'skins' => $skins->all(),
            'modes' => $modes,
            'uploadsAvailable' => function_exists('inflate_init'),
            'uploadLimit' => intdiv(Limits::ARCHIVE_BYTES, 1024 * 1024),
            'phpLimit' => ini_get('upload_max_filesize'),
        ]);
    }

    /**
     * The sample page the picker shows in its preview frame, with one skin on it. Any signed-in
     * user may ask for any installed skin (they can pick any of them anyway); it shows invented
     * content only. The skin is named to SkinInjector by a request attribute set here, after the
     * id has been checked, so nothing else can make a page show a skin it wasn't asked to.
     */
    public function preview(Request $request, string $id, SkinRepository $skins): View
    {
        abort_unless($id === PreviewChoice::STOCK || $skins->exists($id), 404);
        // ?mode=light shows the page in light mode; anything else is dark.
        $mode = $request->query('mode') === Modes::LIGHT ? Modes::LIGHT : Modes::DARK;
        $request->attributes->set(SkinInjector::PREVIEW, ['skin' => $id, 'mode' => $mode]);
        $stock = $id === PreviewChoice::STOCK;

        return view(ThemeSelectorProvider::PLUGIN_NAME . '::preview', [
            'name' => $stock ? 'Stock LibreNMS' : $skins->name($id),
            'mode' => $mode,
            // The graph colours the skin gives to this mode's graphs (a skin written for the other mode
            // lends its own chrome, as for real graphs: GraphConf::forMode).
            'graph' => PreviewGraph::svg($stock ? [] : GraphConf::forMode($skins->graphPalette($id), $mode), $mode),
        ]);
    }

    /**
     * The user's own choices, one for each mode: '' (follow the default), 'none', or a skin id.
     * `skin` is the dark-mode choice (the name the form has always used) and `skin_light` the
     * light-mode one; a choice that is not sent is left as it is. Every choice is checked before
     * any is saved, so a bad one changes nothing.
     */
    public function store(Request $request, SkinRepository $skins): RedirectResponse
    {
        $choices = [];
        foreach ([Modes::DARK => 'skin', Modes::LIGHT => 'skin_light'] as $mode => $field) {
            if (! $request->has($field)) {
                continue;
            }
            // LibreNMS's ConvertEmptyStringsToNull turns an empty choice ("follow the default") into null.
            $skin = $request->input($field) ?? '';
            if (! is_string($skin) || ($skin !== '' && $skin !== SkinResolver::NONE && ! $skins->exists($skin))) {
                return back()->withErrors([$field => 'Unknown skin.']);
            }
            $choices[$mode] = $skin;
        }

        foreach ($choices as $mode => $skin) {
            if ($skin === '') {
                UserPref::forgetPref($request->user(), SkinResolver::pref($mode));
            } else {
                UserPref::setPref($request->user(), SkinResolver::pref($mode), $skin);
            }
        }

        return redirect()->route('theme-selector.index')->with('status', 'Your skins are saved.');
    }

    /**
     * Admin: the instance defaults, one for each mode ('' for none), which users who haven't
     * chosen get, the login page gets, and whose graph palettes apply to graphs nobody chose
     * for. `default` is the dark-mode default and `default_light` the light-mode one; one that
     * is not sent is left as it is. Every one is checked before any is saved.
     */
    public function setDefault(Request $request, SkinRepository $skins, DefaultSkin $default): RedirectResponse
    {
        $choices = [];
        foreach ([Modes::DARK => 'default', Modes::LIGHT => 'default_light'] as $mode => $field) {
            if (! $request->has($field)) {
                continue;
            }
            // LibreNMS's ConvertEmptyStringsToNull turns an empty choice ("follow the default") into null.
            $skin = $request->input($field) ?? '';
            if (! is_string($skin) || ($skin !== '' && ! $skins->exists($skin))) {
                return back()->withErrors([$field => 'Unknown skin.']);
            }
            $choices[$mode] = $skin === '' ? null : $skin;
        }

        try {
            foreach ($choices as $mode => $skin) {
                $default->set($skin, $mode);
            }
        } catch (InvalidArgumentException) {
            return back()->withErrors(['default' => 'Unknown skin.']);
        } catch (Throwable $e) {
            Log::error('ThemeSelector: saving the default skin failed: ' . $e->getMessage());

            return back()->withErrors(['default' => 'Saving the default failed; see the LibreNMS log.']);
        }

        $dark = $skins->name($default->current(Modes::DARK));
        $light = $skins->name($default->current(Modes::LIGHT));

        return redirect()->route('theme-selector.index')->with('status',
            'Default skins are now ' . ($light ?? 'stock LibreNMS') . ' in light mode and ' . ($dark ?? 'stock LibreNMS') . ' in dark mode.');
    }

    /**
     * Admin: install a skin from an uploaded zip.
     *
     * The upload is never moved, opened by path or extracted. It is read into
     * memory by the strict reader, validated end to end, and only the
     * regenerated stylesheet is written (SkinInstaller). Nothing the client
     * says about the file (its name, its declared type) is used.
     */
    public function upload(Request $request, SkinCompiler $compiler, SkinInstaller $installer): RedirectResponse
    {
        $user = $request->user();
        $file = $request->file('bundle');

        if ($file === null || ! $file->isValid()) {
            return back()->withErrors(['bundle' => 'No file arrived. PHP accepts uploads up to ' . ini_get('upload_max_filesize') . '.']);
        }
        if (! function_exists('inflate_init')) {
            return back()->withErrors(['bundle' => 'This server\'s PHP has no zlib support, which uploads need.']);
        }

        $path = $file->getRealPath();
        $hash = $path ? (hash_file('sha256', $path) ?: '?') : '?';
        $report = new Report();
        $compiled = $path ? $compiler->compileZip($path, $report) : null;

        if ($compiled === null) {
            Log::warning('ThemeSelector: skin upload rejected', [
                'user' => $user->username ?? $user->user_id,
                'ip' => $request->ip(),
                'sha256' => $hash,
                'problems' => count($report->errors()),
            ]);

            return back()->with('upload_errors', $report->errors());
        }

        try {
            $replaced = $installer->install($compiled, $user->user_id);
        } catch (InstallException $e) {
            return back()->withErrors(['bundle' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('ThemeSelector: installing a skin failed: ' . $e->getMessage());

            return back()->withErrors(['bundle' => 'Installing failed; see the LibreNMS log.']);
        }

        Log::warning('ThemeSelector: skin ' . ($replaced ? 'replaced' : 'installed'), [
            'skin' => $compiled->id(),
            'user' => $user->username ?? $user->user_id,
            'ip' => $request->ip(),
            'sha256' => $compiled->sha256,
            'upload_sha256' => $hash,
        ]);

        return redirect()->route('theme-selector.index')->with('status',
            ($replaced ? 'Replaced ' : 'Installed ') . $compiled->manifest['name'] . '. Pick it under "Your skin" to try it; nobody else sees it until they choose it or you make it the default.');
    }

    /**
     * Admin: remove an uploaded skin. Users who had chosen it fall back to the
     * instance default; if it was the default, the default is cleared and the
     * graph colours restored.
     */
    public function delete(Request $request, string $id, SkinInstaller $installer, SkinRepository $skins): RedirectResponse
    {
        $name = $skins->name($id);
        try {
            $installer->remove($id);
        } catch (InstallException $e) {
            return back()->withErrors(['bundle' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('ThemeSelector: removing a skin failed: ' . $e->getMessage());

            return back()->withErrors(['bundle' => 'Removing failed; see the LibreNMS log.']);
        }

        Log::warning('ThemeSelector: skin removed', [
            'skin' => $id,
            'user' => $request->user()->username ?? $request->user()->user_id,
            'ip' => $request->ip(),
        ]);

        return redirect()->route('theme-selector.index')->with('status', 'Removed ' . ($name ?? $id) . '.');
    }
}
