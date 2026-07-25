<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\EditorController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EditorController::class, 'index'])->name('editor');

// Fetching arbitrary URLs is the expensive, abusable endpoint — throttle it
// so an open instance cannot be used as a free crawler.
Route::post('/proxy', [EditorController::class, 'proxy'])
    ->middleware('throttle:20,1')
    ->name('proxy');

Route::prefix('ai')->middleware('throttle:30,1')->group(function () {
    Route::post('/edit', [AiController::class, 'edit'])->name('ai.edit');
    Route::post('/variants', [AiController::class, 'variants'])->name('ai.variants');
    Route::post('/restructure', [AiController::class, 'restructure'])->name('ai.restructure');
    Route::post('/critique', [AiController::class, 'critique'])->name('ai.critique');
});
