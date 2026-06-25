import { router } from '@inertiajs/react';
import { Newspaper } from 'lucide-react';
import { useState } from 'react';
import AppShell from '../../Layouts/AppShell';

const domainLabels = {
    tech: '科技',
    defense: '國防',
    finance: '金融',
    other: '其他',
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

function FilterBar({ filters, facets }) {
    const [form, setForm] = useState({
        market: filters.market ?? '',
        domain: filters.domain ?? '',
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

function NewsCard({ item }) {
    return (
        <article className="news-card">
            <div className="news-card-meta">
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

export default function NewsIndex({
    items = { data: [], links: [] },
    filters = {},
    facets = { markets: [], domains: [], sources: [] },
    lastUpdatedAt = null,
    nextUpdateTimes = [],
}) {
    const data = items.data ?? [];

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

                <FilterBar facets={facets} filters={filters} />

                {data.length === 0 ? (
                    <p className="news-empty">目前沒有符合條件的新聞。</p>
                ) : (
                    <div className="news-list">
                        {data.map((item) => (
                            <NewsCard item={item} key={item.id} />
                        ))}
                    </div>
                )}

                <Pagination links={items.links} />
            </section>
        </AppShell>
    );
}
