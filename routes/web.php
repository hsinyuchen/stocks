<?php

use App\Http\Controllers\Admin\InstrumentController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AnalysesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinMindSettingController;
use App\Http\Controllers\LlmProviderSettingController;
use App\Http\Controllers\MarketWeightAnalysisController;
use App\Http\Controllers\NewsAnalysisController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\StockChartController;
use App\Http\Controllers\StockChatController;
use App\Http\Controllers\StockSearchController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\WatchlistAnalysisController;
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
    Route::get('/market/weight-analysis', [MarketWeightAnalysisController::class, 'show'])->name('market.weight-analysis');
    Route::post('/market/weight-analysis', [MarketWeightAnalysisController::class, 'store'])->name('market.weight-analysis.store');
    Route::get('/news', [NewsController::class, 'index'])->name('news.index');
    Route::post('/news/daily-summary', [NewsAnalysisController::class, 'dailySummary'])->name('news.daily-summary');
    Route::post('/news/{newsItem}/analyses', [NewsAnalysisController::class, 'store'])->name('news.analyses.store');
    Route::get('/watchlists', [WatchlistController::class, 'index'])->name('watchlists.index');
    // 具名靜態區段，需排在 /watchlists/{watchlist} 之前（此處無 GET {watchlist}，仍維持清楚順序）。
    Route::get('/watchlists/analysis', [WatchlistAnalysisController::class, 'show'])->name('watchlists.analysis');
    Route::post('/watchlists/analysis', [WatchlistAnalysisController::class, 'store'])->name('watchlists.analysis.store');
    Route::post('/watchlists', [WatchlistController::class, 'store'])->name('watchlists.store');
    Route::patch('/watchlists/{watchlist}', [WatchlistController::class, 'update'])->name('watchlists.update');
    Route::delete('/watchlists/{watchlist}', [WatchlistController::class, 'destroy'])->name('watchlists.destroy');
    Route::post('/watchlists/{watchlist}/items', [WatchlistController::class, 'addItem'])->name('watchlists.items.store');
    Route::delete('/watchlists/{watchlist}/items/{watchlistItem}', [WatchlistController::class, 'removeItem'])->name('watchlists.items.destroy');
    Route::get('/stocks/search', [StockSearchController::class, 'index'])->name('stocks.search');
    Route::get('/stocks/lookup', [StockSearchController::class, 'lookup'])->name('stocks.lookup');
    Route::get('/stocks/chart', [StockChartController::class, 'bySymbol'])->name('stocks.chart.symbol');
    Route::get('/stocks/{instrument}/chart', StockChartController::class)->name('stocks.chart');
    Route::post('/stocks/{instrument}/analyses', [StockSearchController::class, 'analyze'])->name('stocks.analyses.store');
    Route::delete('/stocks/analyses/{stockAnalysis}', [StockSearchController::class, 'destroyAnalysis'])->name('stocks.analyses.destroy');
    // throttle 是濫用後盾而非主要控制：controller 已限制同一檔同時只能有一題
    // 未回答，真人最多約 2 題／分鐘，20 只有在前端失控重送時才會觸發。
    Route::post('/stocks/{instrument}/chat', [StockChatController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('stocks.chat.store');
    Route::delete('/stocks/chat/{stockChatTurn}', [StockChatController::class, 'destroy'])->name('stocks.chat.destroy');
    Route::delete('/stocks/{instrument}/chat', [StockChatController::class, 'clear'])->name('stocks.chat.clear');
    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
    Route::post('/portfolio', [PortfolioController::class, 'store'])->name('portfolio.store');
    Route::patch('/portfolio/{holding}', [PortfolioController::class, 'update'])->name('portfolio.update');
    Route::delete('/portfolio/{holding}', [PortfolioController::class, 'destroy'])->name('portfolio.destroy');
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts', [AlertController::class, 'store'])->name('alerts.store');
    Route::patch('/alerts/{alert}/reactivate', [AlertController::class, 'reactivate'])->name('alerts.reactivate');
    Route::delete('/alerts/{alert}', [AlertController::class, 'destroy'])->name('alerts.destroy');
    Route::get('/screener', [ScreenerController::class, 'index'])->name('screener.index');
    Route::post('/screener/scan', [ScreenerController::class, 'scan'])->name('screener.scan');
    Route::get('/screener/history', [ScreenerController::class, 'history'])->name('screener.history');
    Route::post('/screener/watchlist', [ScreenerController::class, 'addToWatchlist'])->name('screener.watchlist');
    Route::get('/topics', [TopicController::class, 'index'])->name('topics.index');
    Route::get('/analyses', [AnalysesController::class, 'index'])->name('analyses.index');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');
    Route::get('/settings', [LlmProviderSettingController::class, 'index'])->name('settings.index');
    // FinMind 台股資料源 token（每人一組）。靜態路徑須排在下方 /settings/{llmProviderSetting}
    // 動態綁定之前，否則 DELETE /settings/finmind 會被當成 model binding 而 404。
    Route::post('/settings/finmind', [FinMindSettingController::class, 'store'])->name('settings.finmind.store');
    Route::delete('/settings/finmind', [FinMindSettingController::class, 'destroy'])->name('settings.finmind.destroy');
    Route::post('/settings', [LlmProviderSettingController::class, 'store'])->name('settings.store');
    Route::patch('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'update'])->name('settings.update');
    Route::delete('/settings/{llmProviderSetting}', [LlmProviderSettingController::class, 'destroy'])->name('settings.destroy');
    Route::patch('/settings/{llmProviderSetting}/default', [LlmProviderSettingController::class, 'makeDefault'])->name('settings.default');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}/approve', [UserManagementController::class, 'approve'])->name('users.approve');
    Route::delete('/users/{user}/reject', [UserManagementController::class, 'reject'])->name('users.reject');
    Route::patch('/users/{user}/disable', [UserManagementController::class, 'disable'])->name('users.disable');
    Route::patch('/users/{user}/enable', [UserManagementController::class, 'enable'])->name('users.enable');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'toggleRole'])->name('users.role');
    Route::post('/users/{user}/reset-link', [UserManagementController::class, 'sendResetLink'])->name('users.reset-link');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    // 標的清單為全站共用資料（行情、籌碼、基本面快取都掛在其下），限 admin 維護。
    Route::get('/instruments', [InstrumentController::class, 'index'])->name('instruments.index');
    Route::post('/instruments', [InstrumentController::class, 'store'])->name('instruments.store');
    Route::post('/instruments/import', [InstrumentController::class, 'import'])->name('instruments.import');
    Route::patch('/instruments/{instrument}', [InstrumentController::class, 'update'])->name('instruments.update');
    Route::delete('/instruments/{instrument}', [InstrumentController::class, 'destroy'])->name('instruments.destroy');
});
