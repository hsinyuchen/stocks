<?php

use App\Http\Controllers\DashboardController;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/login', fn () => response('Login placeholder', 200))->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/news', fn () => Inertia::render('Placeholder', [
        'title' => '即時新聞',
        'status' => 'News workspace will be implemented in the news task.',
    ]))->name('news.index');
    Route::get('/watchlists', fn () => Inertia::render('Placeholder', [
        'title' => '觀察清單',
        'status' => 'Watchlist management will be implemented in the watchlist task.',
    ]))->name('watchlists.index');
    Route::get('/stocks/search', fn () => Inertia::render('Placeholder', [
        'title' => '個股搜尋',
        'status' => 'Stock search and analysis actions will be implemented in the stock search task.',
    ]))->name('stocks.search');
    Route::get('/analyses', fn () => Inertia::render('Placeholder', [
        'title' => 'AI 分析紀錄',
        'status' => 'Analysis history will be implemented after stock analysis actions are wired to the UI.',
    ]))->name('analyses.index');
    Route::get('/settings', fn () => Inertia::render('Placeholder', [
        'title' => '設定',
        'status' => 'LLM provider settings will be implemented in the settings task.',
    ]))->name('settings.index');
});
