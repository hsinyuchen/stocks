<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AnalysesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LlmProviderSettingController;
use App\Http\Controllers\NewsAnalysisController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\StockSearchController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/register', [AuthController::class, 'register'])->name('register.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/reset-password/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'store'])->name('password.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/news', [NewsController::class, 'index'])->name('news.index');
    Route::post('/news/daily-summary', [NewsAnalysisController::class, 'dailySummary'])->name('news.daily-summary');
    Route::post('/news/{newsItem}/analyses', [NewsAnalysisController::class, 'store'])->name('news.analyses.store');
    Route::get('/watchlists', [WatchlistController::class, 'index'])->name('watchlists.index');
    Route::post('/watchlists', [WatchlistController::class, 'store'])->name('watchlists.store');
    Route::patch('/watchlists/{watchlist}', [WatchlistController::class, 'update'])->name('watchlists.update');
    Route::delete('/watchlists/{watchlist}', [WatchlistController::class, 'destroy'])->name('watchlists.destroy');
    Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'addItem'])->name('watchlists.items.store');
    Route::delete('/watchlists/{watchlist}/items/{watchlistItem}', [WatchlistController::class, 'removeItem'])->name('watchlists.items.destroy');
    Route::get('/stocks/search', [StockSearchController::class, 'index'])->name('stocks.search');
    Route::get('/stocks/lookup', [StockSearchController::class, 'lookup'])->name('stocks.lookup');
    Route::post('/stocks/{instrument}/analyses', [StockSearchController::class, 'analyze'])->name('stocks.analyses.store');
    Route::get('/analyses', [AnalysesController::class, 'index'])->name('analyses.index');
    Route::get('/settings', [LlmProviderSettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [LlmProviderSettingController::class, 'store'])->name('settings.store');
    Route::patch('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'destroy'])->name('settings.destroy');
    Route::patch('/settings/{llmProviderSetting}/default', [LlmProviderSettingController::class, 'makeDefault'])->name('settings.default');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}/disable', [UserManagementController::class, 'disable'])->name('users.disable');
    Route::patch('/users/{user}/enable', [UserManagementController::class, 'enable'])->name('users.enable');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'toggleRole'])->name('users.role');
    Route::post('/users/{user}/reset-link', [UserManagementController::class, 'sendResetLink'])->name('users.reset-link');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
});
