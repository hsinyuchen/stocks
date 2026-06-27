import { Link, router } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';
import { Bot, LineChart, Newspaper, RefreshCw, Star } from 'lucide-react';
import AppShell from '../Layouts/AppShell';

// Sparkline pulls in recharts — load it on demand so the bundle stays light.
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
                            </Link>
                        );
                    })}
                </div>
            )}
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
    recentAnalyses = [],
    disclaimer = '',
    generatedAt = null,
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

                <MarketSnapshot items={marketSnapshot} />
                <WatchlistMovers items={watchlistMovers} />
                <LatestNews items={latestNews} />
                <RecentAnalyses items={recentAnalyses} />

                {disclaimer ? <p className="dashboard-disclaimer">{disclaimer}</p> : null}
            </div>
        </AppShell>
    );
}
