<?php

use Illuminate\Support\Facades\Route;
use ModpackSwitcher\Http\Controllers\InstallController;

Route::get('/providers', [InstallController::class, 'providers']);
Route::post('/install', [InstallController::class, 'install']);

Route::get('/curseforge/search', [\ModpackSwitcher\Http\Controllers\CurseForgeController::class, 'search']);

Route::get('/curseforge/projects/{project}/versions', [\ModpackSwitcher\Http\Controllers\CurseForgeController::class, 'versions']);

Route::get('/ftb/search', [\ModpackSwitcher\Http\Controllers\FtbController::class, 'search']);

Route::get('/ftb/projects/{project}/versions', [\ModpackSwitcher\Http\Controllers\FtbController::class, 'versions']);
