<?php

use Illuminate\Support\Facades\Route;
use ModpackSwitcher\Http\Controllers\StatusController;

// Final URL: GET /api/client/extensions/modpack-switcher/status
Route::get('/status', StatusController::class)->name('status');
