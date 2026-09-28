<?php

use Illuminate\Support\Facades\Route;
use Xblossia\ThemeSelector\Http\Controllers\PickerController;

// Every logged-in user picks their own skin. Admin-only routes (upload,
// delete, instance default) will sit in a separate group under `can:admin`.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('plugin/theme-selector', [PickerController::class, 'index'])->name('theme-selector.index');
    Route::post('plugin/theme-selector', [PickerController::class, 'store'])->name('theme-selector.store');
});
