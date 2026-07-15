<?php

use App\Http\Controllers\Api\TdsynexProductController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategorySyncController;
use App\Http\Controllers\Api\CronController;
use App\Http\Controllers\Api\IntegrationSettingsController;
use App\Http\Controllers\Api\SyncLogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/products', [TdsynexProductController::class, 'index']);
Route::post('/tdsynnex-products/sync', [TdsynexProductController::class, 'sync']);
Route::get('/tdsynnex-products', [TdsynexProductController::class, 'index']);

// Health check route
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

// Brand routes
Route::prefix('brands')->group(function () {
    // Specific actions FIRST (must precede /{brand} param routes)
    Route::post('/sync', [BrandController::class, 'sync']);
    Route::get('/tdsynnex-manufacturers', [BrandController::class, 'tdsynnexManufacturers']);
    Route::get('/tdsynnex-test', [BrandController::class, 'tdsynnexTest']);

    // Then parameterized routes
    Route::get('/', [BrandController::class, 'index']);
    Route::post('/', [BrandController::class, 'store']);
    Route::get('/{brand}', [BrandController::class, 'show']);
    Route::put('/{brand}', [BrandController::class, 'update']);
    Route::post('/{brand}/toggle-blacklist', [BrandController::class, 'toggleBlacklist']);
});

// Category mapping routes
Route::prefix('categories')->group(function () {
    Route::get('/tds', [CategoryController::class, 'tdsCategories']);
    Route::get('/tds/counts', [CategoryController::class, 'tdsCategoryCounts']);
    Route::post('/mapping', [CategoryController::class, 'saveMapping']);
    // Import progress for a mapped category (drives the "Import en cours" indicator).
    Route::get('/{code}/import-status', [CategoryController::class, 'tdsImportStatus']);
    // Push all local products of a mapped category to PrestaShop.
    Route::post('/{code}/push', [CategorySyncController::class, 'push']);
});

// Synchronisation history (manual + scheduled runs)
Route::get('/sync-logs', [SyncLogController::class, 'index']);

// Cron / scheduled-sync configuration
Route::prefix('cron')->group(function () {
    Route::get('/', [CronController::class, 'index']);
    Route::put('/', [CronController::class, 'update']);
    Route::post('/{job}/run', [CronController::class, 'run']);
});

Route::get('/integration-settings', [IntegrationSettingsController::class, 'index']);
Route::put('/integration-settings/{section}', [IntegrationSettingsController::class, 'update']);
Route::post('/integration-settings/{section}/test', [IntegrationSettingsController::class, 'test']);
Route::get('/integration-settings/prestashop/categories', [IntegrationSettingsController::class, 'prestashopCategories']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
