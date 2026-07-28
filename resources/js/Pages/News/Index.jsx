import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Bot, GitBranch, Newspaper, Settings, Sparkles, Video } from 'lucide-react';
import { useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';

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
    });

    const update = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (event) => {
        event.preventDefault();
        const query = Object.fromEntries(
            Object.entries(form).filter(([, value]) => value !== '' && value !== null),
        );
        router.get('/news', query, { preserveScroll: true });
    };

    return (
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
            <button className="button-primary" type="submit">篩選</button>
        </form>
    );
}

function DailySummaryPanel({ providers, summary }) {
    const fallback = defaultProvider(providers);
    const form = useForm({
        llm_provider_setting_id: fallback ? String(fallback.id) : '',
        model: '',
    });

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
                        onChange={(value) => form.setData('llm_provider_setting_id', value)}
                        providers={providers}
                        value={form.data.llm_provider_setting_id}
                    />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>產生今日摘要</span>
                    </button>
                </form>
            )}

            {summary ? (
                <article className="analysis-item news-daily-summary__result">
                    <div className="analysis-item__head">
                        <span className="status-pill status-pill--neutral">今日總經</span>
                        <small>{summary.provider_type} · {summary.model} · {formatDateTime(summary.created_at)}</small>
                    </div>
                    <Markdown>{summary.summary}</Markdown>
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

function ItemAnalyzeForm({ item, providers }) {
    const fallback = defaultProvider(providers);
    const form = useForm({
        llm_provider_setting_id: fallback ? String(fallback.id) : '',
        model: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/news/${item.id}/analyses`, { preserveScroll: true });
    };

    return (
        <form className="analysis-action news-analyze-form" onSubmit={submit}>
            <ModelPicker
                onChange={(value) => form.setData('llm_provider_setting_id', value)}
                providers={providers}
                value={form.data.llm_provider_setting_id}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                <Sparkles aria-hidden="true" size={18} />
                <span>用我的模型分析</span>
            </button>
            <FieldError message={form.errors.llm_provider_setting_id} />
        </form>
    );
}

function NewsCard({ item, providers }) {
    return (
        <article className="news-card">
            <div className="news-card-meta">
                {item.kind === 'video' ? (
                    <span className="news-chip news-chip--video">
                        <Video aria-hidden="true" size={14} /> {kindLabels.video}
                    </span>
                ) : null}
                <span className="news-chip">{domainLabels[item.domain] ?? item.domain}</span>
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
                    <ItemAnalyzeForm item={item} providers={providers} />
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

                <DailySummaryPanel providers={providers} summary={latestDailySummary} />

                <FilterBar facets={facets} filters={filters} />

                {data.length === 0 ? (
                    <p className="news-empty">目前沒有符合條件的新聞。</p>
                ) : (
                    <div className="news-list">
                        {data.map((item) => (
                            <NewsCard item={item} key={item.id} providers={providers} />
                        ))}
                    </div>
                )}

                <Pagination links={items.links} />
            </section>
        </AppShell>
    );
}
