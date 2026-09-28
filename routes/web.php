<?php

use Illuminate\Support\Facades\Route;
use Xblossia\ThemeSelector\Http\Controllers\PickerController;

// Every logged-in user picks their own skin.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('plugin/theme-selector', [PickerController::class, 'index'])->name('theme-selector.index');
    Route::post('plugin/theme-selector', [PickerController::class, 'store'])->name('theme-selector.store');
});

// Instance-wide settings: LibreNMS's own admin role (docs/PLUGIN.md, Decisions).
Route::middleware(['web', 'auth', 'can:admin'])->group(function (): void {
    Route::post('plugin/theme-selector/default', [PickerController::class, 'setDefault'])->name('theme-selector.default');
});
