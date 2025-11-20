<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebsiteEditorController;

// Main editor page
Route::get('/', [WebsiteEditorController::class, 'index'])->name('editor');

// Proxy endpoint to load external websites
Route::post('/proxy', [WebsiteEditorController::class, 'proxy'])->name('proxy');

// AI edit endpoint
Route::post('/edit-section', [WebsiteEditorController::class, 'editSection'])->name('edit-section');
