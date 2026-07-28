import { Link, router } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';
import { Bell, Bot, LineChart, Newspaper, RefreshCw, Star, Waypoints } from 'lucide-react';
import AppShell from '../Layouts/AppShell';

// Sparkline 建立獨立的 Lightweight Charts 實例，按需載入讓首屏 bundle 保持精簡。
const Sparkline = lazy(() => import('../Components/charts/Sparkline'));

const stanceLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
    watch: '觀察',
    insufficient_data: '資料不足',
};

const sentimentLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
};

const chipStanceLabels = {
    accumulating: '外資買超',
    distributing: '外資賣超',
    neutral: '籌碼中性',
};

const alignmentLabels = {
    confirm: '價量同向',
    diverge: '價量背離',
};

/** forward 沿用 sectors 宣告方向、reverse 整組翻轉、unknown 一律降為中性。 */
const polarityLabels = {
    forward: '正向觸發',
    reverse: '反向觸發',
    unknown: '方向不明',
};

const directionLabels = {
    positive: '偏正面',
    negative: '偏負面',
    neutral: '中性',
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
    stock: '個股',
    news: '新聞',
    daily: '每日摘要',
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

function MarketSnapshot({ items }) {
    return (
        <section className="metric-strip" aria-label="市場概況">
            {items.length === 0 ? (
                <p className="dashboard-empty">市場指數暫時無法取得。</p>
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

function WatchlistMovers({ items }) {
    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Star aria-hidden="true" size={16} /> 自選訊號
                    </p>
                    <h2>自選清單焦點</h2>
                </div>
            </div>
            {items.length === 0 ? (
                <p className="dashboard-empty">
                    尚未加入自選股。前往
                    {' '}
                    <Link href="/watchlists">自選清單</Link>
                    {' '}
                    新增追蹤標的。
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
                                aria-label={`查看 ${mover.symbol} 個股分析`}
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
                                    {stanceLabels[stance] ?? stance}
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
    if (!chip || !chip.days) {
        return null;
    }

    const lots = Math.round(chip.foreign_net / 1000);
    const sign = lots > 0 ? '+' : '';

    return (
        <p className="signal-row__chip">
            <span className={`chip-tag chip-tag--${chip.stance}`}>
                {chipStanceLabels[chip.stance] ?? chip.stance}
            </span>
            <span className={changeClass(chip.foreign_net)}>
                外資 {chip.days} 日 {sign}{lots.toLocaleString('zh-TW')} 張
            </span>
            {chip.foreign_streak > 0 ? (
                <span className="chip-streak">連 {chip.foreign_streak} 日</span>
            ) : null}
            {alignment && alignment !== 'none' ? (
                <span className={`chip-align chip-align--${alignment}`}>
                    {alignmentLabels[alignment] ?? alignment}
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
    if (items.length === 0) {
        return null;
    }

    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Waypoints aria-hidden="true" size={16} /> 事件傳導
                    </p>
                    <h2>傳導鏈焦點</h2>
                </div>
                <Link className="panel-link" href="/news">查看新聞</Link>
            </div>
            <ul className="transmission-list">
                {items.map((chain) => (
                    <li className="transmission-item" key={chain.key}>
                        <div className="transmission-item__head">
                            <strong>{chain.label}</strong>
                            <span className={`chip-tag chip-tag--${polarityClass(chain.polarity)}`}>
                                {polarityLabels[chain.polarity] ?? chain.polarity}
                            </span>
                            <small>近期 {chain.count} 則</small>
                        </div>

                        <p className="transmission-path">{chain.chain.join(' → ')}</p>

                        <div className="transmission-sectors">
                            {chain.sectors.map((sector) => (
                                <span className="transmission-sector" key={sector.name}>
                                    {sector.name}
                                    <em className={directionClass(sector.direction)}>
                                        {directionLabels[sector.direction] ?? sector.direction}
                                    </em>
                                </span>
                            ))}
                        </div>

                        {chain.hits.length > 0 ? (
                            <p className="transmission-hits">
                                <span>打到自選：</span>
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
    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Newspaper aria-hidden="true" size={16} /> 最新新聞
                    </p>
                    <h2>相關財經新聞</h2>
                </div>
                <Link className="panel-link" href="/news">查看全部</Link>
            </div>
            {items.length === 0 ? (
                <p className="dashboard-empty">目前沒有新聞。</p>
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
    return (
        <section className="table-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">
                        <Bot aria-hidden="true" size={16} /> AI 分析
                    </p>
                    <h2>近期 AI 參考分析</h2>
                </div>
                <Link className="panel-link" href="/analyses">查看全部</Link>
            </div>
            {items.length === 0 ? (
                <p className="dashboard-empty">尚未有 AI 分析紀錄。</p>
            ) : (
                <ul className="dashboard-analysis-list">
                    {items.map((analysis, idx) => {
                        const stance = analysis.stance ?? 'neutral';
                        const stanceLabel = stanceLabels[stance] ?? sentimentLabels[stance] ?? stance;

                        return (
                            <li className="dashboard-analysis-item" key={`${analysis.type}-${idx}`}>
                                <span className="dashboard-analysis-item__type">
                                    {analysisTypeLabels[analysis.type] ?? analysis.type}
                                </span>
                                <strong>{analysis.label ?? '-'}</strong>
                                {analysis.stance ? (
                                    <span className={`status-pill status-pill--${stance}`}>{stanceLabel}</span>
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

export default function Dashboard({
    marketSnapshot = [],
    watchlistMovers = [],
    latestNews = [],
    transmissionFocus = [],
    recentAnalyses = [],
    disclaimer = '',
    generatedAt = null,
    hasLlmProvider = true,
    triggeredAlerts = [],
}) {
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
        <AppShell title="市場儀表板">
            <div className="dashboard-grid">
                <section className="hero-panel">
                    <div>
                        <p className="section-kicker">
                            <LineChart aria-hidden="true" size={16} /> 市場雷達
                        </p>
                        <h2>整合市場、新聞與 AI 參考分析</h2>
                        <p>
                            這裡彙整台灣與美國市場概況、自選清單訊號、相關新聞與 LLM 參考分析，方便快速掌握需要追蹤的投資議題。
                        </p>
                    </div>
                    <div className="dashboard-refresh">
                        {generatedAt ? (
                            <span className="dashboard-refresh__time">資料時間：{formatDateTime(generatedAt)}</span>
                        ) : null}
                        <button
                            className="button-secondary"
                            onClick={refresh}
                            type="button"
                            disabled={refreshing}
                            aria-busy={refreshing}
                        >
                            <RefreshCw aria-hidden="true" size={16} className={refreshing ? 'is-spinning' : undefined} />
                            <span>{refreshing ? '更新中…' : '更新最新資料'}</span>
                        </button>
                    </div>
                </section>

                {!hasLlmProvider ? (
                    <p className="dashboard-llm-hint" role="note">
                        <Bot aria-hidden="true" size={16} />
                        <span>
                            尚未設定 AI 模型，AI 分析功能停用中（行情、技術指標與規則訊號不受影響）。請至
                            {' '}
                            <Link href="/settings">系統設定</Link>
                            {' '}
                            新增模型。
                        </span>
                    </p>
                ) : null}

                {triggeredAlerts.length > 0 ? (
                    <section className="table-panel">
                        <div className="panel-heading">
                            <div>
                                <p className="section-kicker">
                                    <Bell aria-hidden="true" size={16} /> 已觸發警報
                                </p>
                                <h2>價格警報</h2>
                            </div>
                            <Link className="panel-link" href="/alerts">查看全部</Link>
                        </div>
                        <ul className="dashboard-news-list">
                            {triggeredAlerts.map((alert) => (
                                <li className="dashboard-news-item" key={alert.id}>
                                    <Link href={`/stocks/search?symbol=${encodeURIComponent(alert.symbol)}`}>
                                        <strong>{alert.symbol}</strong> {alert.name}
                                    </Link>
                                    <small>
                                        {alert.type === 'signal' ? `訊號 ${alert.signal_key}` : `${alert.type} ${alert.threshold}`}
                                        {alert.triggered_price !== null ? `｜觸發價 ${alert.triggered_price}` : ''}
                                    </small>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <MarketSnapshot items={marketSnapshot} />
                <WatchlistMovers items={watchlistMovers} />
                <LatestNews items={latestNews} />
                <TransmissionFocus items={transmissionFocus} />
                <RecentAnalyses items={recentAnalyses} />

                {disclaimer ? <p className="dashboard-disclaimer">{disclaimer}</p> : null}
            </div>
        </AppShell>
    );
}
