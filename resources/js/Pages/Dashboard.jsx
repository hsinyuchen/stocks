import { Deferred, Link, router } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';
import { Bell, Bot, Gauge, LineChart, Loader2, Newspaper, RefreshCw, Star, Waypoints } from 'lucide-react';
import AppShell from '../Layouts/AppShell';
import { useI18n } from '../i18n';

// 大盤層級警報無個股標的，卡片標題用固定字樣。映射值為 i18n key，於 render 處 t()。
const MARKET_ALERT_TITLE = {
    market_futures_flip: 'dashboard.marketAlertFuturesFlip',
    market_bearish_flip: 'dashboard.marketAlertBearishFlip',
};

// Sparkline 建立獨立的 Lightweight Charts 實例，按需載入讓首屏 bundle 保持精簡。
const Sparkline = lazy(() => import('../Components/charts/Sparkline'));

// 以下 label 映射改存 i18n key，於 render 處以 t() 取當前語言字串。
const stanceLabels = {
    bullish: 'dashboard.stanceBullish',
    bearish: 'dashboard.stanceBearish',
    neutral: 'dashboard.stanceNeutral',
    watch: 'dashboard.stanceWatch',
    insufficient_data: 'dashboard.stanceInsufficientData',
};

const sentimentLabels = {
    bullish: 'dashboard.stanceBullish',
    bearish: 'dashboard.stanceBearish',
    neutral: 'dashboard.stanceNeutral',
};

const chipStanceLabels = {
    accumulating: 'dashboard.chipAccumulating',
    distributing: 'dashboard.chipDistributing',
    neutral: 'dashboard.chipNeutral',
};

const alignmentLabels = {
    confirm: 'dashboard.alignConfirm',
    diverge: 'dashboard.alignDiverge',
};

/** forward 沿用 sectors 宣告方向、reverse 整組翻轉、unknown 一律降為中性。值為 i18n key。 */
const polarityLabels = {
    forward: 'dashboard.polarityForward',
    reverse: 'dashboard.polarityReverse',
    unknown: 'dashboard.polarityUnknown',
};

const directionLabels = {
    positive: 'dashboard.directionPositive',
    negative: 'dashboard.directionNegative',
    neutral: 'dashboard.directionNeutral',
};

function polarityClass(polarity) {
    if (polarity === 'unknown') {
        return 'neutral';
    }

    return polarity === 'forward' ? 'accumulating' : 'distributing';
}

function directionClass(direction) {
    if (direction === 'positive') {
        return 'is-up';
    }

    return direction === 'negative' ? 'is-down' : '';
}

const analysisTypeLabels = {
    stock: 'dashboard.analysisTypeStock',
    news: 'dashboard.analysisTypeNews',
    daily: 'dashboard.analysisTypeDaily',
};

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString('zh-TW', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    const num = Number(value);

    return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
}

function changeClass(value) {
    const num = Number(value);

    if (Number.isNaN(num) || num === 0) {
        return '';
    }

    return num > 0 ? 'is-up' : 'is-down';
}

// 金額（元）→ 億元字串，帶正負號。買超為正、賣超為負。單位字樣走 i18n，故需傳入 t。
function formatOku(value, t) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const oku = Number(value) / 1e8;
    const num = `${oku > 0 ? '+' : ''}${oku.toLocaleString('zh-TW', { maximumFractionDigits: 1 })}`;

    return t('dashboard.okuUnit', { value: num });
}

// 口數，帶正負號（淨多為正、淨空為負）。單位字樣走 i18n，故需傳入 t。
function formatLots(value, t) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const num = Number(value);
    const signed = `${num > 0 ? '+' : ''}${num.toLocaleString('zh-TW')}`;

    return t('dashboard.lotsUnit', { value: signed });
}

/**
 * 大盤風向：全市場三大法人現貨買賣超 ＋ 期貨/選擇權籌碼。
 * 判斷「外資站買方還是賣方」「期貨留倉多空」的市場級訊號。僅台股盤後有資料。
 */
function MarketBreadth({ breadth }) {
    const { t } = useI18n();

    if (!breadth) {
        return null;
    }

    const inst = breadth.institutional ?? { available: false };
    const fut = breadth.futures ?? { available: false, enabled: false };

    // 兩區塊都拿不到就不顯示整個面板（例如非台股時段、或免費層額度冷卻中）。
    if (!inst.available && !(fut.enabled && fut.available)) {
        return null;
    }

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Gauge aria-hidden="true" size={16} /> {t('dashboard.marketBreadthKicker')}
                    </p>
                    <h2>{t('dashboard.marketBreadthTitle')}</h2>
                </div>
                {inst.date || fut.date ? (
                    <small className="dashboard-coverage">{t('dashboard.dataDate', { date: inst.date ?? fut.date })}</small>
                ) : null}
            </div>

            <div className="metric-strip">
                {inst.available ? (
                    <>
                        <article className="metric-card">
                            <span>{t('dashboard.instForeignSpot')}</span>
                            <strong className={changeClass(inst.foreign_net)}>{formatOku(inst.foreign_net, t)}</strong>
                            <small>{t('dashboard.instNetSubtitle')}</small>
                        </article>
                        <article className="metric-card">
                            <span>{t('dashboard.instTrustSpot')}</span>
                            <strong className={changeClass(inst.trust_net)}>{formatOku(inst.trust_net, t)}</strong>
                            <small>{t('dashboard.netBuySell')}</small>
                        </article>
                        <article className="metric-card">
                            <span>{t('dashboard.instDealerSpot')}</span>
                            <strong className={changeClass(inst.dealer_net)}>{formatOku(inst.dealer_net, t)}</strong>
                            <small>{t('dashboard.netBuySell')}</small>
                        </article>
                    </>
                ) : null}

                {fut.enabled && fut.available ? (
                    <>
                        <article className="metric-card">
                            <span>{t('dashboard.futForeign')}</span>
                            <strong className={changeClass(fut.foreign_net_oi)}>{formatLots(fut.foreign_net_oi, t)}</strong>
                            <small>{t('dashboard.netOpenInterest')}</small>
                        </article>
                        <article className="metric-card">
                            <span>{t('dashboard.taifexOpenInterest')}</span>
                            <strong>{fut.futures_open_interest !== null ? t('dashboard.lotsUnit', { value: Number(fut.futures_open_interest).toLocaleString('zh-TW') }) : '—'}</strong>
                            <small>{t('dashboard.nearMonth')}{fut.futures_close !== null ? t('dashboard.closePrefix', { value: Number(fut.futures_close).toLocaleString('zh-TW') }) : ''}</small>
                        </article>
                        <article className="metric-card">
                            <span>{t('dashboard.optionsPutCall')}</span>
                            <strong>{fut.put_call_ratio !== null && fut.put_call_ratio !== undefined ? Number(fut.put_call_ratio).toFixed(2) : '—'}</strong>
                            <small>{t('dashboard.pcHedgeHint')}</small>
                        </article>
                    </>
                ) : null}
            </div>
            <p className="dashboard-coverage-note">
                {t('dashboard.breadthNote')}
            </p>
        </section>
    );
}

/**
 * 美債利率環境面板。
 *
 * 兩個窗口並列而不擇一：短窗口是戰術判定，長窗口是戰略背景，兩者分歧本身就是
 * 資訊（例如長期上行、短期停滯 = 可能是回檔而非轉向）。任一維中性時後端給的
 * quadrant 為 null，此處就只顯示 level 與 shape，不自行拼湊象限。
 */
function RatesPanel({ rates }) {
    const { t } = useI18n();

    if (!rates) {
        return null;
    }

    const levelLabel = (value) => ({
        bear: t('dashboard.ratesLevelBear'),
        bull: t('dashboard.ratesLevelBull'),
    }[value] ?? t('dashboard.ratesLevelNeutral'));

    const shapeLabel = (value) => ({
        steepening: t('dashboard.ratesShapeSteepening'),
        flattening: t('dashboard.ratesShapeFlattening'),
    }[value] ?? t('dashboard.ratesShapeNeutral'));

    const quadrantLabel = (value) => ({
        bear_steepening: t('dashboard.ratesQuadrantBearSteepening'),
        bear_flattening: t('dashboard.ratesQuadrantBearFlattening'),
        bull_steepening: t('dashboard.ratesQuadrantBullSteepening'),
        bull_flattening: t('dashboard.ratesQuadrantBullFlattening'),
    }[value] ?? null);

    const windows = Object.entries(rates.windows ?? {});

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Gauge aria-hidden="true" size={16} /> {t('dashboard.ratesKicker')}
                    </p>
                    <h2>{t('dashboard.ratesTitle')}</h2>
                </div>
                {rates.as_of ? (
                    <small className="dashboard-coverage">{t('dashboard.dataDate', { date: rates.as_of })}</small>
                ) : null}
            </div>

            {!rates.available ? (
                <p className="dashboard-coverage-note">{t('dashboard.ratesUnavailable')}</p>
            ) : (
                <>
                    <div className="metric-strip">
                        <article className="metric-card">
                            <span>{rates.long_label}</span>
                            <strong>{Number(rates.long_yield).toFixed(3)}</strong>
                            <small>%</small>
                        </article>
                        <article className="metric-card">
                            <span>{rates.short_label}</span>
                            <strong>{Number(rates.short_yield).toFixed(3)}</strong>
                            <small>%</small>
                        </article>
                        <article className="metric-card">
                            <span>{t('dashboard.ratesSpread')}</span>
                            <strong className={changeClass(rates.spread_bp)}>{Number(rates.spread_bp).toFixed(1)} bp</strong>
                            <small>
                                {rates.inverted
                                    ? t('dashboard.ratesInverted')
                                    : rates.recently_uninverted
                                        ? t('dashboard.ratesRecentlyUninverted')
                                        : ''}
                            </small>
                        </article>
                        {windows.map(([key, w]) => (
                            <article className="metric-card" key={key}>
                                <span>{t('dashboard.ratesWindowLabel', { days: w.days })}</span>
                                <strong>{quadrantLabel(w.quadrant) ?? levelLabel(w.level)}</strong>
                                <small>{quadrantLabel(w.quadrant) ? '' : shapeLabel(w.shape)}</small>
                            </article>
                        ))}
                    </div>
                    <p className="dashboard-coverage-note">{t('dashboard.ratesNote')}</p>
                </>
            )}
        </section>
    );
}

function MarketSnapshot({ items }) {
    const { t } = useI18n();

    return (
        <section className="metric-strip" aria-label={t('dashboard.marketSnapshotAria')}>
            {items.length === 0 ? (
                <p className="dashboard-empty">{t('dashboard.marketSnapshotEmpty')}</p>
            ) : (
                items.map((index) => (
                    <article className="metric-card" key={index.symbol}>
                        <span>{index.name}</span>
                        <strong>{index.price?.toLocaleString?.('zh-TW') ?? index.price}</strong>
                        <small className={changeClass(index.change_percent)}>
                            {formatPercent(index.change_percent)} · {index.symbol}
                        </small>
                        {index.spark?.length >= 2 ? (
                            <div className="metric-card__spark">
                                <Suspense fallback={<span className="spark-fallback" />}>
                                    <Sparkline data={index.spark} />
                                </Suspense>
                            </div>
                        ) : null}
                    </article>
                ))
            )}
        </section>
    );
}

function WatchlistMovers({ items, coverage }) {
    const { t } = useI18n();
    // 顯示數少於自選清單總數時要說明，否則看起來像儀表板漏了標的。差額來自
    // 顯示上限，或某檔完全抓不到行情。
    const missing = Math.max(0, (coverage?.total ?? items.length) - items.length);

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Star aria-hidden="true" size={16} /> {t('dashboard.watchlistKicker')}
                    </p>
                    <h2>{t('dashboard.watchlistTitle')}</h2>
                </div>
                {coverage?.total ? (
                    <small className="dashboard-coverage">
                        {t('dashboard.coverageCount', { shown: items.length, total: coverage.total })}
                    </small>
                ) : null}
            </div>
            {missing > 0 ? (
                <p className="dashboard-coverage-note">
                    {t('dashboard.missingNotePrefix', { count: missing })}
                    {' '}
                    <Link href="/watchlists">{t('dashboard.watchlistLink')}</Link>
                    {t('dashboard.missingNoteSuffix')}
                </p>
            ) : null}
            {items.length === 0 ? (
                <p className="dashboard-empty">
                    {t('dashboard.watchlistEmptyPrefix')}
                    {' '}
                    <Link href="/watchlists">{t('dashboard.watchlistLink')}</Link>
                    {' '}
                    {t('dashboard.watchlistEmptySuffix')}
                </p>
            ) : (
                <div className="signal-list">
                    {items.map((mover) => {
                        const stance = mover.stance ?? 'neutral';

                        return (
                            <Link
                                className="signal-row signal-row--link"
                                href={`/stocks/search?symbol=${encodeURIComponent(mover.symbol)}`}
                                key={mover.symbol}
                                aria-label={t('dashboard.moverAria', { symbol: mover.symbol })}
                            >
                                <div>
                                    <strong>{mover.symbol}</strong>
                                    <span>{mover.name}</span>
                                </div>
                                {mover.spark?.length >= 2 ? (
                                    <div className="signal-row__spark">
                                        <Suspense fallback={<span className="spark-fallback" />}>
                                            <Sparkline data={mover.spark} />
                                        </Suspense>
                                    </div>
                                ) : (
                                    <div className="signal-row__spark" />
                                )}
                                <span className={`status-pill status-pill--${stance}`}>
                                    {t(stanceLabels[stance] ?? stance)}
                                </span>
                                <p>
                                    {mover.price?.toLocaleString?.('zh-TW') ?? mover.price}
                                    {' '}
                                    <span className={changeClass(mover.change_percent)}>
                                        ({formatPercent(mover.change_percent)})
                                    </span>
                                </p>
                                <ChipBadge chip={mover.chip} alignment={mover.alignment} />
                            </Link>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

/**
 * 自選股的籌碼摘要。只有台股有資料，非台股時 chip 為 null 不顯示整列。
 * 張數以 1 張 = 1000 股換算，與個股頁一致。
 */
function ChipBadge({ chip, alignment }) {
    const { t } = useI18n();

    if (!chip || !chip.days) {
        return null;
    }

    const lots = Math.round(chip.foreign_net / 1000);
    const sign = lots > 0 ? '+' : '';

    return (
        <p className="signal-row__chip">
            <span className={`chip-tag chip-tag--${chip.stance}`}>
                {t(chipStanceLabels[chip.stance] ?? chip.stance)}
            </span>
            <span className={changeClass(chip.foreign_net)}>
                {t('dashboard.chipForeignSummary', { days: chip.days, lots: `${sign}${lots.toLocaleString('zh-TW')}` })}
            </span>
            {chip.foreign_streak > 0 ? (
                <span className="chip-streak">{t('dashboard.chipStreak', { days: chip.foreign_streak })}</span>
            ) : null}
            {alignment && alignment !== 'none' ? (
                <span className={`chip-align chip-align--${alignment}`}>
                    {t(alignmentLabels[alignment] ?? alignment)}
                </span>
            ) : null}
        </p>
    );
}

/**
 * 事件 → 產業 → 個股的傳導鏈。
 *
 * 這是觀察起點而非訊號：規則只說明「這類事件通常影響哪些板塊」，
 * 不表示個股必然跟隨，因此不給多空分數，只標方向與命中的自選股。
 */
function TransmissionFocus({ items }) {
    const { t } = useI18n();

    if (items.length === 0) {
        return null;
    }

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Waypoints aria-hidden="true" size={16} /> {t('dashboard.transmissionKicker')}
                    </p>
                    <h2>{t('dashboard.transmissionTitle')}</h2>
                </div>
                <Link className="panel-link" href="/news">{t('dashboard.viewNews')}</Link>
            </div>
            <ul className="transmission-list">
                {items.map((chain) => (
                    <li className="transmission-item" key={chain.key}>
                        <div className="transmission-item__head">
                            <strong>{chain.label}</strong>
                            <span className={`chip-tag chip-tag--${polarityClass(chain.polarity)}`}>
                                {t(polarityLabels[chain.polarity] ?? chain.polarity)}
                            </span>
                            <small>{t('dashboard.recentCount', { count: chain.count })}</small>
                        </div>

                        <p className="transmission-path">{chain.chain.join(' → ')}</p>

                        <div className="transmission-sectors">
                            {chain.sectors.map((sector) => (
                                <span className="transmission-sector" key={sector.name}>
                                    {sector.name}
                                    <em className={directionClass(sector.direction)}>
                                        {t(directionLabels[sector.direction] ?? sector.direction)}
                                    </em>
                                </span>
                            ))}
                        </div>

                        {chain.hits.length > 0 ? (
                            <p className="transmission-hits">
                                <span>{t('dashboard.hitsLabel')}</span>
                                {chain.hits.map((symbol) => (
                                    <Link
                                        href={`/stocks/search?symbol=${encodeURIComponent(symbol)}`}
                                        key={symbol}
                                    >
                                        {symbol}
                                    </Link>
                                ))}
                            </p>
                        ) : null}

                        {chain.latest ? (
                            <a
                                className="transmission-latest"
                                href={chain.latest.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <span className="news-source">{chain.latest.source}</span>
                                {chain.latest.title}
                            </a>
                        ) : null}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function LatestNews({ items }) {
    const { t } = useI18n();

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Newspaper aria-hidden="true" size={16} /> {t('dashboard.latestNewsKicker')}
                    </p>
                    <h2>{t('dashboard.latestNewsTitle')}</h2>
                </div>
                <Link className="panel-link" href="/news">{t('dashboard.viewAll')}</Link>
            </div>
            {items.length === 0 ? (
                <p className="dashboard-empty">{t('dashboard.newsEmpty')}</p>
            ) : (
                <ul className="dashboard-news-list">
                    {items.map((item) => (
                        <li className="dashboard-news-item" key={item.id}>
                            <div className="dashboard-news-item__head">
                                <span className="news-source">{item.source}</span>
                                <span className="news-time">{formatDateTime(item.published_at)}</span>
                            </div>
                            {item.url ? (
                                <a href={item.url} rel="noopener noreferrer" target="_blank">{item.title}</a>
                            ) : (
                                <span>{item.title}</span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RecentAnalyses({ items }) {
    const { t } = useI18n();

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Bot aria-hidden="true" size={16} /> {t('dashboard.aiAnalysisKicker')}
                    </p>
                    <h2>{t('dashboard.recentAnalysesTitle')}</h2>
                </div>
                <Link className="panel-link" href="/analyses">{t('dashboard.viewAll')}</Link>
            </div>
            {items.length === 0 ? (
                <p className="dashboard-empty">{t('dashboard.analysesEmpty')}</p>
            ) : (
                <ul className="dashboard-analysis-list">
                    {items.map((analysis, idx) => {
                        const stance = analysis.stance ?? 'neutral';
                        const stanceLabel = stanceLabels[stance] ?? sentimentLabels[stance] ?? stance;

                        return (
                            <li className="dashboard-analysis-item" key={`${analysis.type}-${idx}`}>
                                <span className="dashboard-analysis-item__type">
                                    {t(analysisTypeLabels[analysis.type] ?? analysis.type)}
                                </span>
                                <strong>{analysis.label ?? '-'}</strong>
                                {analysis.stance ? (
                                    <span className={`status-pill status-pill--${stance}`}>{t(stanceLabel)}</span>
                                ) : null}
                                <small>{analysis.model} · {formatDateTime(analysis.created_at)}</small>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

// deferred 區塊載入中的骨架：外殼已顯示，這裡佔位到行情/新聞/籌碼資料補上。
function DashboardLoading() {
    const { t } = useI18n();

    return (
        <section className="dashboard-loading" role="status" aria-live="polite" aria-busy="true">
            <Loader2 aria-hidden="true" size={28} className="is-spinning" />
            <p>{t('dashboard.loadingMarket')}</p>
        </section>
    );
}

export default function Dashboard({
    marketSnapshot = [],
    marketBreadth = null,
    watchlistMovers = [],
    watchlistCoverage = null,
    latestNews = [],
    transmissionFocus = [],
    recentAnalyses = [],
    disclaimer = '',
    generatedAt = null,
    hasLlmProvider = true,
    triggeredAlerts = [],
}) {
    const { t } = useI18n();
    const [refreshing, setRefreshing] = useState(false);

    const refresh = () =>
        router.get(
            '/dashboard',
            { refresh: 1 },
            {
                preserveScroll: true,
                onStart: () => setRefreshing(true),
                onFinish: () => setRefreshing(false),
            },
        );

    return (
        <AppShell title={t('dashboard.pageTitle')}>
            <div className="dashboard-grid">
                <section className="hero-panel">
                    <div>
                        <p className="section-kicker">
                            <LineChart aria-hidden="true" size={16} /> {t('dashboard.heroKicker')}
                        </p>
                        <h2>{t('dashboard.heroTitle')}</h2>
                        <p>
                            {t('dashboard.heroDescription')}
                        </p>
                    </div>
                    <div className="dashboard-refresh">
                        {generatedAt ? (
                            <span className="dashboard-refresh__time">{t('dashboard.dataTime', { time: formatDateTime(generatedAt) })}</span>
                        ) : null}
                        <button
                            className="button-secondary"
                            onClick={refresh}
                            type="button"
                            disabled={refreshing}
                            aria-busy={refreshing}
                        >
                            <RefreshCw aria-hidden="true" size={16} className={refreshing ? 'is-spinning' : undefined} />
                            <span>{refreshing ? t('dashboard.refreshing') : t('dashboard.refreshLatest')}</span>
                        </button>
                    </div>
                </section>

                {!hasLlmProvider ? (
                    <p className="dashboard-llm-hint" role="note">
                        <Bot aria-hidden="true" size={16} />
                        <span>
                            {t('dashboard.llmHintPrefix')}
                            {' '}
                            <Link href="/settings">{t('dashboard.settingsLink')}</Link>
                            {' '}
                            {t('dashboard.llmHintSuffix')}
                        </span>
                    </p>
                ) : null}

                <Deferred
                    data={[
                        'marketSnapshot',
                        'marketBreadth',
                        'watchlistMovers',
                        'watchlistCoverage',
                        'latestNews',
                        'transmissionFocus',
                        'recentAnalyses',
                        'triggeredAlerts',
                    ]}
                    fallback={<DashboardLoading />}
                >
                    {triggeredAlerts.length > 0 ? (
                        <section className="table-panel">
                            <div className="panel-heading">
                                <div>
                                    <p className="section-kicker">
                                        <Bell aria-hidden="true" size={16} /> {t('dashboard.alertsKicker')}
                                    </p>
                                    <h2>{t('dashboard.alertsTitle')}</h2>
                                </div>
                                <Link className="panel-link" href="/alerts">{t('dashboard.viewAll')}</Link>
                            </div>
                            <ul className="dashboard-news-list">
                                {triggeredAlerts.map((alert) => (
                                    <li className="dashboard-news-item" key={alert.id}>
                                        {alert.scope === 'market' ? (
                                            <Link href="/alerts">
                                                <strong>{t(MARKET_ALERT_TITLE[alert.type] ?? 'dashboard.marketAlertFallback')}</strong>
                                            </Link>
                                        ) : (
                                            <Link href={`/stocks/search?symbol=${encodeURIComponent(alert.symbol)}`}>
                                                <strong>{alert.symbol}</strong> {alert.name}
                                            </Link>
                                        )}
                                        <small>
                                            {alert.scope === 'market'
                                                ? (alert.type === 'market_bearish_flip' ? t('dashboard.marketBearishFlip') : t('dashboard.marketForeignFuturesFlip'))
                                                : alert.type === 'signal'
                                                    ? t('dashboard.signalLabel', { key: alert.signal_key })
                                                    : `${alert.type} ${alert.threshold}`}
                                            {alert.triggered_price !== null ? t('dashboard.triggeredPrice', { price: alert.triggered_price }) : ''}
                                        </small>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}

                    <MarketSnapshot items={marketSnapshot} />
                    <MarketBreadth breadth={marketBreadth} />
                    <RatesPanel rates={marketBreadth?.rates ?? null} />
                    <WatchlistMovers coverage={watchlistCoverage} items={watchlistMovers} />
                    <LatestNews items={latestNews} />
                    <TransmissionFocus items={transmissionFocus} />
                    <RecentAnalyses items={recentAnalyses} />
                </Deferred>

                {disclaimer ? <p className="dashboard-disclaimer">{disclaimer}</p> : null}
            </div>
        </AppShell>
    );
}
