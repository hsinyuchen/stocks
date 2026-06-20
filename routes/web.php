<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LlmProviderSettingController;
use App\Http\Controllers\StockSearchController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.store');
Route::post('/register', [AuthController::class, 'register'])->name('register.store');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/news', fn () => Inertia::render('Placeholder', [
        'title' => '即時新聞',
        'status' => 'News workspace will be implemented in the news task.',
    ]))->name('news.index');
    Route::get('/watchlists', [WatchlistController::class, 'index'])->name('watchlists.index');
    Route::post('/watchlists', [WatchlistController::class, 'store'])->name('watchlists.store');
    Route::patch('/watchlists/{watchlist}', [WatchlistController::class, 'update'])->name('watchlists.update');
    Route::delete('/watchlists/{watchlist}', [WatchlistController::class, 'destroy'])->name('watchlists.destroy');
    Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'addItem'])->name('watchlists.items.store');
    Route::delete('/watchlists/{watchlist}/items/{watchlistItem}', [WatchlistController::class, 'removeItem'])->name('watchlists.items.destroy');
    Route::get('/stocks/search', [StockSearchController::class, 'index'])->name('stocks.search');
    Route::post('/stocks/{instrument}/analyses', [StockSearchController::class, 'analyze'])->name('stocks.analyses.store');
    Route::get('/analyses', fn () => Inertia::render('Placeholder', [
        'title' => 'AI 分析紀錄',
        'status' => 'Analysis history will be implemented after stock analysis actions are wired to the UI.',
    ]))->name('analyses.index');
    Route::get('/settings', [LlmProviderSettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [LlmProviderSettingController::class, 'store'])->name('settings.store');
    Route::patch('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'destroy'])->name('settings.destroy');
    Route::patch('/settings/{llmProviderSetting}/default', [LlmProviderSettingController::class, 'makeDefault'])->name('settings.default');
});
