<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TdsynexProductController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategorySyncController;
use App\Http\Controllers\Api\CronController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportFilterController;
use App\Http\Controllers\Api\IntegrationSettingsController;
use App\Http\Controllers\Api\MarginRuleController;
use App\Http\Controllers\Api\SyncLogController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Routes publiques ───────────────────────────────────────────────────────

// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});

// Connexion : émet un token Sanctum. Seule route "métier" accessible sans token.
Route::post('/login', [AuthController::class, 'login']);

// ─── Routes protégées (token Sanctum requis) ────────────────────────────────
// Sans token valide, tout l'API renvoie 401 → le front redirige vers /login.

Route::middleware('auth:sanctum')->group(function () {
    // Session courante
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Gestion des utilisateurs (CRUD, page Paramètres)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    // Dashboard
    Route::get('/dashboard/catalogue-evolution', [DashboardController::class, 'catalogueEvolution']);

    // Filtres d'import (réglages globaux + overrides par catégorie)
    Route::get('/import-filters', [ImportFilterController::class, 'index']);
    Route::put('/import-filters', [ImportFilterController::class, 'update']);
    Route::get('/import-filters/category-overrides', [ImportFilterController::class, 'categoryOverrides']);
    Route::put('/import-filters/category-overrides/{code}', [ImportFilterController::class, 'updateCategoryOverride']);
    Route::delete('/import-filters/category-overrides/{code}', [ImportFilterController::class, 'deleteCategoryOverride']);

    // Produits TD SYNNEX
    Route::get('/products', [TdsynexProductController::class, 'index']);
    Route::post('/tdsynnex-products/sync', [TdsynexProductController::class, 'sync']);
    Route::get('/tdsynnex-products', [TdsynexProductController::class, 'index']);

    // Marques
    Route::prefix('brands')->group(function () {
        // Actions spécifiques AVANT les routes paramétrées /{brand}
        Route::post('/sync', [BrandController::class, 'sync']);
        Route::get('/tdsynnex-manufacturers', [BrandController::class, 'tdsynnexManufacturers']);
        Route::get('/tdsynnex-test', [BrandController::class, 'tdsynnexTest']);

        Route::get('/', [BrandController::class, 'index']);
        Route::post('/', [BrandController::class, 'store']);
        Route::get('/{brand}', [BrandController::class, 'show']);
        Route::put('/{brand}', [BrandController::class, 'update']);
        Route::post('/{brand}/toggle-blacklist', [BrandController::class, 'toggleBlacklist']);
    });

    // Catégories & mapping
    Route::prefix('categories')->group(function () {
        Route::get('/tds', [CategoryController::class, 'tdsCategories']);
        Route::get('/tds/counts', [CategoryController::class, 'tdsCategoryCounts']);
        Route::post('/mapping', [CategoryController::class, 'saveMapping']);
        Route::get('/{code}/import-status', [CategoryController::class, 'tdsImportStatus']);
        Route::post('/{code}/push', [CategorySyncController::class, 'push']);
    });

    // Règles des marges (MVP : règle globale)
    Route::prefix('margin-rules')->group(function () {
        Route::get('/global', [MarginRuleController::class, 'showGlobal']);
        Route::put('/global', [MarginRuleController::class, 'updateGlobal']);
        // Catégories avec une marge personnalisée (règles spécifiques)
        Route::get('/specific', [MarginRuleController::class, 'specificRules']);
    });

    // Historique des synchronisations
    Route::get('/sync-logs', [SyncLogController::class, 'index']);

    // Configuration cron / sync planifiée
    Route::prefix('cron')->group(function () {
        Route::get('/', [CronController::class, 'index']);
        Route::put('/', [CronController::class, 'update']);
        Route::post('/{job}/run', [CronController::class, 'run']);
    });

    // Réglages d'intégration (TD SYNNEX / PrestaShop)
    Route::get('/integration-settings', [IntegrationSettingsController::class, 'index']);
    Route::put('/integration-settings/{section}', [IntegrationSettingsController::class, 'update']);
    Route::post('/integration-settings/{section}/test', [IntegrationSettingsController::class, 'test']);
    Route::get('/integration-settings/prestashop/categories', [IntegrationSettingsController::class, 'prestashopCategories']);

    // Utilisateur Sanctum (compat)
    Route::get('/user', fn (Request $request) => $request->user());
});
