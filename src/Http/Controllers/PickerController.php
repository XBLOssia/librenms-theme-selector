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
use Xblossia\ThemeSelector\PreviewChoice;
use Xblossia\ThemeSelector\PreviewGraph;
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
        // ?preview=<choice> opens the page with that choice already previewed: the link for
        // "Preview" on a row of the skin list, and what the form does without JavaScript.
        $previewChoice = $request->query('preview');
        $previewChoice = is_string($previewChoice) ? $previewChoice : null;
        $previewTarget = $previewChoice === null ? null
            : PreviewChoice::target($previewChoice, $resolver->default(), fn (string $id): bool => $skins->exists($id));

        return view(ThemeSelectorProvider::PLUGIN_NAME . '::picker', [
            'skins' => $skins->all(),
            'choice' => $resolver->choice($request->user()),
            'default' => $resolver->default(),
            'defaultName' => $skins->name($resolver->default()),
            'previewChoice' => $previewTarget === null ? null : $previewChoice,
            'previewTarget' => $previewTarget,
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
        $request->attributes->set(SkinInjector::PREVIEW, $id);
        $stock = $id === PreviewChoice::STOCK;

        return view(ThemeSelectorProvider::PLUGIN_NAME . '::preview', [
            'name' => $stock ? 'Stock LibreNMS' : $skins->name($id),
            'graph' => PreviewGraph::svg($stock ? [] : $skins->graphPalette($id)),
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
    public function setDefault(Request $request, SkinRepository $skins, DefaultSkin $default): RedirectResponse
    {
        $skin = (string) $request->input('default', '');
        if ($skin !== '' && ! $skins->exists($skin)) {
            return back()->withErrors(['default' => 'Unknown skin.']);
        }

        try {
            $default->set($skin === '' ? null : $skin);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['default' => 'Unknown skin.']);
        } catch (Throwable $e) {
            Log::error('ThemeSelector: saving the default skin failed: ' . $e->getMessage());

            return back()->withErrors(['default' => 'Saving the default failed; see the LibreNMS log.']);
        }

        $name = $skins->name($skin === '' ? null : $skin);

        return redirect()->route('theme-selector.index')->with('status', $name
            ? "Default skin is now $name, and graphs use its palette."
            : 'No default skin; graphs are back to their previous colours.');
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
