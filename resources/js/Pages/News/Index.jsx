import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Bot, ChevronDown, GitBranch, Newspaper, Settings, SlidersHorizontal, Sparkles, Video } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';

// 需與 config/news.php 的 domains 鍵一致；缺對應時前端會直接顯示英文鍵值。
const domainLabels = {
    tech: '科技',
    defense: '國防',
    geopolitics: '地緣政治',
    energy: '能源',
    finance: '金融',
    currency: '匯率',
    supply_chain: '供應鏈',
    market: '市場',
    other: '其他',
};

const sentimentLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
};

const kindLabels = {
    article: '文章',
    video: '影片',
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

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

function defaultProvider(providers) {
    if (!providers || providers.length === 0) {
        return null;
    }

    return providers.find((provider) => provider.is_default) ?? providers[0];
}

function SettingsPrompt() {
    return (
        <p className="news-settings-prompt">
            尚未設定 AI 模型。請先到
            {' '}
            <Link href="/settings">系統設定</Link>
            {' '}
            新增一個模型，才能使用 AI 分析。
        </p>
    );
}

function ModelPicker({ providers, value, onChange }) {
    if (!providers || providers.length === 0) {
        return null;
    }

    return (
        <label className="form-field">
            <span>使用的模型</span>
            <select onChange={(event) => onChange(event.target.value)} value={value ?? ''}>
                {providers.map((provider) => (
                    <option key={provider.id} value={String(provider.id)}>
                        {provider.display_name}（{provider.provider_type} · {provider.model}）
                    </option>
                ))}
            </select>
        </label>
    );
}

function FilterBar({ filters, facets }) {
    const [form, setForm] = useState({
        market: filters.market ?? '',
        domain: filters.domain ?? '',
        kind: filters.kind ?? '',
        source: filters.source ?? '',
        symbol: filters.symbol ?? '',
        q: filters.q ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
        include_irrelevant: filters.include_irrelevant ? '1' : '',
    });

    // 預設收合：篩選列有九個欄位，展開時會把新聞流推到折線以下。
    // 已套用篩選時預設展開，否則使用者看不出結果為何被縮減。
    const hasActiveFilter = Object.entries(filters).some(
        ([key, value]) => key !== 'include_irrelevant' && value !== null && value !== '',
    );
    const [open, setOpen] = useState(hasActiveFilter);

    const update = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        const query = Object.fromEntries(
            Object.entries(form).filter(([, value]) => value !== '' && value !== null),
        );
        router.get('/news', query, { preserveScroll: true });
    };

    const reset = () => router.get('/news', {}, { preserveScroll: true });

    const activeCount = Object.entries(filters).filter(
        ([key, value]) => key !== 'include_irrelevant' && value !== null && value !== '',
    ).length;

    return (
        <div className="news-filter">
            <div className="news-filter__head">
                <button
                    aria-expanded={open}
                    className="news-filter__toggle"
                    onClick={() => setOpen((v) => !v)}
                    type="button"
                >
                    <SlidersHorizontal aria-hidden="true" size={15} />
                    篩選{activeCount > 0 ? `（已套用 ${activeCount} 項）` : ''}
                    <ChevronDown aria-hidden="true" className={open ? 'is-open' : undefined} size={15} />
                </button>
                {activeCount > 0 ? (
                    <button className="news-filter__reset" onClick={reset} type="button">清除</button>
                ) : null}
            </div>

            {open ? (
        <form className="news-filter-bar" onSubmit={submit}>
            <label className="form-field">
                <span>市場</span>
                <select onChange={(event) => update('market', event.target.value)} value={form.market}>
                    <option value="">全部</option>
                    {facets.markets.map((market) => (
                        <option key={market} value={market}>{market}</option>
                    ))}
                </select>
            </label>
            <label className="form-field">
                <span>領域</span>
                <select onChange={(event) => update('domain', event.target.value)} value={form.domain}>
                    <option value="">全部</option>
                    {facets.domains.map((domain) => (
                        <option key={domain} value={domain}>{domainLabels[domain] ?? domain}</option>
                    ))}
                </select>
            </label>
            <label className="form-field">
                <span>類型</span>
                <select onChange={(event) => update('kind', event.target.value)} value={form.kind}>
                    <option value="">全部</option>
                    <option value="article">文章</option>
                    <option value="video">影片</option>
                </select>
            </label>
            <label className="form-field">
                <span>來源</span>
                <select onChange={(event) => update('source', event.target.value)} value={form.source}>
                    <option value="">全部</option>
                    {(facets.sources ?? []).map((source) => (
                        <option key={source} value={source}>{source}</option>
                    ))}
                </select>
            </label>
            <label className="form-field">
                <span>股票代號</span>
                <input
                    maxLength="32"
                    onChange={(event) => update('symbol', event.target.value.toUpperCase())}
                    placeholder="NVDA 或 2330.TW"
                    type="search"
                    value={form.symbol}
                />
            </label>
            <label className="form-field">
                <span>關鍵字</span>
                <input
                    maxLength="120"
                    onChange={(event) => update('q', event.target.value)}
                    placeholder="標題或摘要"
                    type="search"
                    value={form.q}
                />
            </label>
            <label className="form-field">
                <span>起</span>
                <input onChange={(event) => update('from', event.target.value)} type="date" value={form.from} />
            </label>
            <label className="form-field">
                <span>迄</span>
                <input onChange={(event) => update('to', event.target.value)} type="date" value={form.to} />
            </label>
            {/* 分類器把明顯與投資無關的新聞標為雜訊並預設隱藏。這個開關是檢查
                誤判的出口——被誤標的新聞若沒有辦法叫出來，就等於永遠消失。 */}
            <label className="form-field form-field--checkbox">
                <input
                    checked={form.include_irrelevant === '1'}
                    onChange={(event) => update('include_irrelevant', event.target.checked ? '1' : '')}
                    type="checkbox"
                />
                <span>顯示雜訊新聞</span>
            </label>
            <button className="button-primary" type="submit">篩選</button>
        </form>
            ) : null}
        </div>
    );
}

/**
 * 今日總經摘要。
 *
 * 這裡的模型選擇是整頁共用的——下方每則新聞的分析也用它。原本每張新聞卡片
 * 各自帶一個選擇器，一頁 20 則就有 20 個下拉選單，而使用者幾乎不會為個別
 * 新聞換模型。
 */
function DailySummaryPanel({ providers, summary, providerId, onProviderChange }) {
    const form = useForm({
        llm_provider_setting_id: providerId ?? '',
        model: '',
    });

    useEffect(() => {
        form.setData('llm_provider_setting_id', providerId ?? '');
    }, [providerId]);

    const submit = (event) => {
        event.preventDefault();
        form.post('/news/daily-summary', { preserveScroll: true });
    };

    const points = summary?.points ?? [];

    return (
        <section className="stock-panel news-daily-summary">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">今日總經摘要</p>
                    <h2>用我的模型整理今日重點</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>

            {providers.length === 0 ? (
                <SettingsPrompt />
            ) : (
                <form className="analysis-action" onSubmit={submit}>
                    <ModelPicker
                        onChange={onProviderChange}
                        providers={providers}
                        value={providerId}
                    />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>產生今日摘要</span>
                    </button>
                </form>
            )}

            {summary?.status === 'pending' ? (
                <PendingAnalysis createdAt={summary.created_at} model={summary.model} />
            ) : null}

            {summary && summary.status !== 'pending' ? (
                <article className="analysis-item news-daily-summary__result">
                    <div className="analysis-item__head">
                        <span className={`status-pill status-pill--${summary.status === 'failed' ? 'failed' : 'neutral'}`}>
                            {summary.status === 'failed' ? 'AI 未完成' : '今日總經'}
                        </span>
                        <small>{summary.provider_type} · {summary.model} · {formatDateTime(summary.created_at)}</small>
                    </div>
                    {summary.status === 'failed' && summary.failure ? (
                        <FailureNote failure={summary.failure} />
                    ) : (
                        <Markdown>{summary.summary}</Markdown>
                    )}
                    {points.length > 0 ? (
                        <ul>
                            {points.map((point, index) => (
                                <li key={index}>{point}</li>
                            ))}
                        </ul>
                    ) : null}
                    {(summary.related_symbols ?? []).length > 0 ? (
                        <div className="news-symbols">
                            {summary.related_symbols.map((symbol) => (
                                <a
                                    className="news-symbol-chip"
                                    href={`/stocks/search?symbol=${encodeURIComponent(symbol)}`}
                                    key={symbol}
                                >
                                    {symbol}
                                </a>
                            ))}
                        </div>
                    ) : null}
                </article>
            ) : null}
        </section>
    );
}

function AnalysisResult({ analysis }) {
    if (!analysis) {
        return null;
    }

    if (analysis.status === 'pending') {
        return <PendingAnalysis createdAt={analysis.created_at} model={analysis.model} />;
    }

    // 失敗時 sentiment / summary 是 service 的降級佔位值，不能當成模型判斷呈現。
    if (analysis.status === 'failed') {
        return (
            <article className="analysis-item news-analysis-result">
                <div className="analysis-item__head">
                    <span className="status-pill status-pill--failed">AI 未完成</span>
                    <small>{analysis.model} · {formatDateTime(analysis.created_at)}</small>
                </div>
                {analysis.failure ? (
                    <FailureNote failure={analysis.failure} />
                ) : (
                    <p className="analysis-item__pending-note">分析未成功，可重新送出。</p>
                )}
            </article>
        );
    }

    const sentiment = analysis.sentiment ?? 'neutral';
    const label = sentimentLabels[sentiment] ?? sentimentLabels.neutral;

    return (
        <article className="analysis-item news-analysis-result">
            <div className="analysis-item__head">
                <span className={`status-pill status-pill--${sentiment}`}>{label}</span>
                {analysis.impact_score ? (
                    <span className="news-impact">影響 {analysis.impact_score}/5</span>
                ) : null}
                <small>{analysis.model} · {formatDateTime(analysis.created_at)}</small>
            </div>
            <Markdown>{analysis.summary}</Markdown>
            {analysis.reasoning ? <Markdown className="news-analysis-reasoning">{analysis.reasoning}</Markdown> : null}
        </article>
    );
}

/**
 * AI 失敗的原因與下一步，分類由後端 LlmFailureReason 提供。
 */
function FailureNote({ failure }) {
    return (
        <div className="analysis-failure">
            <strong>{failure.message}</strong>
            <span>{failure.hint}</span>
        </div>
    );
}

/**
 * 等待佇列完成的骨架。新聞分析實測約 47 秒，每日總結更久，所以要明確說明
 * 「還在跑」而不是留白讓使用者以為按鈕沒反應。
 */
function PendingAnalysis({ createdAt, model }) {
    return (
        <article className="analysis-item analysis-item--pending news-analysis-result">
            <div className="analysis-item__head">
                <span className="status-pill status-pill--pending">分析中</span>
                <small>{model} · {formatDateTime(createdAt)}</small>
            </div>
            <p className="analysis-item__pending-note">已排入佇列，完成後會自動顯示。</p>
        </article>
    );
}

/**
 * 單則新聞的分析按鈕。
 *
 * 刻意不放模型選擇器：一頁 20 則新聞就會有 20 個下拉選單，而使用者幾乎不會
 * 為個別新聞換模型。模型統一由頁面上方「今日總經摘要」的選擇器控制，這裡只
 * 沿用該選擇並在按鈕上標示目前用哪一個，避免使用者不知道會用什麼模型送出。
 */
function ItemAnalyzeForm({ item, providerId, providerName }) {
    const form = useForm({
        llm_provider_setting_id: providerId ?? '',
        model: '',
    });

    // 上方換模型後，本表單要跟著換——useForm 的初始值只在掛載時取一次。
    useEffect(() => {
        form.setData('llm_provider_setting_id', providerId ?? '');
    }, [providerId]);

    const submit = (event) => {
        event.preventDefault();
        form.post(`/news/${item.id}/analyses`, { preserveScroll: true });
    };

    return (
        <form className="analysis-action news-analyze-form" onSubmit={submit}>
            <button className="button-secondary" disabled={form.processing || !providerId} type="submit">
                <Sparkles aria-hidden="true" size={18} />
                <span>{form.processing ? '分析中…' : '分析這則新聞'}</span>
            </button>
            {providerName ? <small className="field-hint">使用 {providerName}</small> : null}
            <FieldError message={form.errors.llm_provider_setting_id} />
        </form>
    );
}

function NewsCard({ item, providers, providerId, providerName }) {
    return (
        <article className="news-card">
            <div className="news-card-meta">
                {item.kind === 'video' ? (
                    <span className="news-chip news-chip--video">
                        <Video aria-hidden="true" size={14} /> {kindLabels.video}
                    </span>
                ) : null}
                {/* 顯示全部命中的領域，不只主領域。跨領域新聞（例如對半導體加徵
                    關稅＝科技＋地緣政治＋供應鏈）往往影響最大，只顯示第一個會
                    把這個資訊藏起來。舊資料沒有 domains 欄位時退回單一 domain。 */}
                {(item.domains?.length ? item.domains : [item.domain]).map((domain) => (
                    <span className="news-chip" key={domain}>{domainLabels[domain] ?? domain}</span>
                ))}
                {item.market ? <span className="news-chip">{item.market}</span> : null}
                <span className="news-source">{item.source}</span>
                <span className="news-time">{formatDateTime(item.published_at)}</span>
            </div>
            <h3 className="news-title">
                {item.url ? (
                    <a href={item.url} rel="noopener noreferrer" target="_blank">{item.title}</a>
                ) : (
                    item.title
                )}
            </h3>
            {item.summary ? <p className="news-summary">{item.summary}</p> : null}
            {(item.related_symbols ?? []).length > 0 ? (
                <div className="news-symbols">
                    {item.related_symbols.map((symbol) => (
                        <a
                            className="news-symbol-chip"
                            href={`/stocks/search?symbol=${encodeURIComponent(symbol)}`}
                            key={symbol}
                        >
                            {symbol}
                        </a>
                    ))}
                </div>
            ) : null}

            <TransmissionChains chains={item.transmission} />

            <div className="news-card-ai">
                {providers.length === 0 ? (
                    <p className="news-settings-prompt">
                        想要 AI 解讀？請先到
                        {' '}
                        <Link href="/settings">系統設定</Link>
                        {' '}
                        新增模型。
                    </p>
                ) : (
                    <ItemAnalyzeForm item={item} providerId={providerId} providerName={providerName} />
                )}
                <AnalysisResult analysis={item.latest_analysis} />
            </div>
        </article>
    );
}

function Pagination({ links }) {
    if (!links || links.length <= 3) {
        return null;
    }

    return (
        <nav className="news-pagination">
            {links.map((link, index) => (
                <button
                    className={`news-page-link${link.active ? ' is-active' : ''}`}
                    disabled={!link.url}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                    key={index}
                    onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true })}
                    type="button"
                />
            ))}
        </nav>
    );
}

/**
 * 事件 → 產業 → 個股的傳導鏈。
 *
 * 與 related_symbols 分開呈現：後者是「新聞提到這檔股票」，這裡是「這個事件
 * 可能影響這檔股票」。合併會讓人誤以為新聞直接談到了該公司。預設收合，因為
 * 它是延伸推論而非新聞事實。
 */
function TransmissionChains({ chains }) {
    const [open, setOpen] = useState(false);

    if (!chains || chains.length === 0) {
        return null;
    }

    return (
        <div className="news-transmission">
            <button className="news-transmission__toggle" onClick={() => setOpen((v) => !v)} type="button">
                <GitBranch aria-hidden="true" size={13} />
                可能影響的板塊（{chains.map((c) => c.label).join('、')}）{open ? '　收合' : '　展開'}
            </button>

            {open ? (
                <div className="news-transmission__body">
                    {chains.map((chain) => (
                        <div className="news-transmission__chain" key={chain.key}>
                            <ol className="news-transmission__path">
                                {chain.chain.map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                            {chain.sectors.map((sector) => (
                                <div className="news-transmission__sector" key={sector.name}>
                                    <span className={`news-transmission__dir is-${sector.direction}`}>
                                        {sector.direction === 'positive' ? '正向' : sector.direction === 'negative' ? '負向' : '中性'}
                                    </span>
                                    <strong>{sector.name}</strong>
                                    <span className="news-symbols">
                                        {sector.symbols.map((symbol) => (
                                            <a
                                                className="news-symbol-chip"
                                                href={`/stocks/search?symbol=${encodeURIComponent(symbol)}`}
                                                key={symbol}
                                            >
                                                {symbol}
                                            </a>
                                        ))}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ))}
                    <p className="news-transmission__note">
                        依規則推導的可能影響路徑，非新聞事實，也不是投資建議。方向僅描述事件對板塊的影響方向，不保證後續走勢。
                    </p>
                </div>
            ) : null}
        </div>
    );
}

/**
 * 資料來源清單與健康度。
 *
 * 來源失效在後端是靜默的（回 200 但內容凍結，插入即被 prune），使用者只會
 * 發現某個媒體的新聞不見了卻不知原因。這裡把失效攤開，並提供可點的來源連結。
 */
function FeedSourcePanel({ sources }) {
    const [open, setOpen] = useState(false);

    if (!sources || sources.length === 0) {
        return null;
    }

    const broken = sources.filter((s) => s.healthy === false);
    const visible = open ? sources : broken;

    return (
        <section className="feed-sources">
            <div className="feed-sources__head">
                <button className="feed-sources__toggle" onClick={() => setOpen((v) => !v)} type="button">
                    資料來源（{sources.length}）{open ? '　收合' : '　展開'}
                </button>
                {broken.length > 0 ? (
                    <span className="feed-sources__alert">
                        <AlertTriangle aria-hidden="true" size={14} /> {broken.length} 個來源目前沒有新內容
                    </span>
                ) : null}
            </div>

            {visible.length > 0 ? (
                <ul className="feed-sources__list">
                    {visible.map((s) => (
                        <li className={s.healthy === false ? 'is-broken' : undefined} key={s.key}>
                            {s.site ? (
                                <a href={s.site} rel="noopener noreferrer" target="_blank">{s.name}</a>
                            ) : (
                                <span>{s.name}</span>
                            )}
                            <small>{s.market}</small>
                            {s.healthy === false ? (
                                <small className="feed-sources__reason">
                                    連續 {s.stale_runs} 次無新內容
                                    {s.last_fresh_at ? `・最後更新 ${formatDateTime(s.last_fresh_at)}` : ''}
                                </small>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
        </section>
    );
}

export default function NewsIndex({
    items = { data: [], links: [] },
    filters = {},
    facets = { markets: [], domains: [], kinds: [], sources: [] },
    lastUpdatedAt = null,
    nextUpdateTimes = [],
    llmProviders = [],
    latestDailySummary = null,
    feedSources = [],
}) {
    const data = items.data ?? [];
    const providers = llmProviders ?? [];

    // 模型選擇提升到頁面層級：上方摘要面板的選擇同時作為下方每則新聞分析的
    // 預設模型，避免每張卡片各自帶一個選擇器。
    const fallback = defaultProvider(providers);
    const [selectedProviderId, setSelectedProviderId] = useState(fallback ? String(fallback.id) : '');
    const selectedProviderName = providers.find(
        (provider) => String(provider.id) === String(selectedProviderId),
    )?.display_name ?? null;

    // 分析在佇列執行，送出後畫面上會先出現 pending 骨架，靠輪詢補上結果。
    const hasPending = latestDailySummary?.status === 'pending'
        || data.some((item) => item.latest_analysis?.status === 'pending');
    const stalled = useAnalysisPolling(hasPending, ['items', 'latestDailySummary']);

    return (
        <AppShell title="即時新聞">
            <section className="news-panel">
                <header className="news-header">
                    <p className="section-kicker">
                        <Newspaper aria-hidden="true" size={16} /> 財經新聞串流
                    </p>
                    <p className="news-update-line">
                        最後更新：{formatDateTime(lastUpdatedAt)}
                        {nextUpdateTimes.length > 0 ? ` ・ 下次更新：${nextUpdateTimes.join(' / ')} (台北時間)` : ''}
                    </p>
                </header>

                <FeedSourcePanel sources={feedSources} />

                {stalled ? (
                    <p className="queue-stalled-hint">
                        分析排隊超過 10 分鐘仍未完成，已停止等待。請確認佇列處理程序有在執行
                        （<code>composer dev</code> 會一併啟動，或另開終端機執行 <code>php artisan queue:work</code>），
                        重新整理後即可看到結果。
                    </p>
                ) : null}

                <DailySummaryPanel
                    onProviderChange={setSelectedProviderId}
                    providerId={selectedProviderId}
                    providers={providers}
                    summary={latestDailySummary}
                />

                <FilterBar facets={facets} filters={filters} />

                {data.length === 0 ? (
                    <p className="news-empty">目前沒有符合條件的新聞。</p>
                ) : (
                    <div className="news-list">
                        {data.map((item) => (
                            <NewsCard
                                item={item}
                                key={item.id}
                                providerId={selectedProviderId}
                                providerName={selectedProviderName}
                                providers={providers}
                            />
                        ))}
                    </div>
                )}

                <Pagination links={items.links} />
            </section>
        </AppShell>
    );
}
