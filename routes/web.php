<?php

use Illuminate\Support\Facades\Route;
use Xblossia\ThemeSelector\Http\Controllers\PickerController;

// Every logged-in user picks their own skin.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('plugin/theme-selector', [PickerController::class, 'index'])->name('theme-selector.index');
    Route::post('plugin/theme-selector', [PickerController::class, 'store'])->name('theme-selector.store');
});

// Instance-wide settings and skin management: LibreNMS's own admin role
// (docs/PLUGIN.md, Decisions). All of these are POST, so they carry the web
// group's CSRF check, and uploads are rate limited. Skin ids in URLs are
// constrained to the same slug the manifest validator enforces.
Route::middleware(['web', 'auth', 'can:admin'])->group(function (): void {
    Route::post('plugin/theme-selector/default', [PickerController::class, 'setDefault'])->name('theme-selector.default');
    Route::post('plugin/theme-selector/skins', [PickerController::class, 'upload'])
        ->middleware('throttle:12,1')
        ->name('theme-selector.upload');
    Route::post('plugin/theme-selector/skins/{id}/delete', [PickerController::class, 'delete'])
        ->where('id', '[a-z0-9][a-z0-9-]{0,62}')
        ->middleware('throttle:30,1')
        ->name('theme-selector.delete');
});
