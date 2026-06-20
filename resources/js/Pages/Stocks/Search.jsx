import { router, useForm, usePage } from '@inertiajs/react';
import { Bot, LineChart, Newspaper, Search, Sparkles } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

function formatNumber(value, digits = 2) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return Number(value).toLocaleString(undefined, {
        maximumFractionDigits: digits,
        minimumFractionDigits: digits,
    });
}

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

function SearchForm({ initialSymbol }) {
    const { errors } = usePage().props;
    const form = useForm({
        symbol: initialSymbol ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        router.get('/stocks/search', { symbol: form.data.symbol }, {
            preserveScroll: true,
        });
    };

    return (
        <form className="stock-search-form" onSubmit={submit}>
            <label className="form-field">
                <span>Symbol</span>
                <input
                    maxLength="32"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="AAPL or 2330.TW"
                    type="search"
                    value={form.data.symbol}
                />
                <FieldError message={errors.symbol} />
            </label>
            <button className="button-primary" type="submit">
                <Search aria-hidden="true" size={18} />
                <span>Search</span>
            </button>
        </form>
    );
}

function AnalyzeForm({ instrument }) {
    const form = useForm({ model: 'reference-model' });

    if (!instrument) {
        return null;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(`/stocks/${instrument.id}/analyses`, {
            preserveScroll: true,
        });
    };

    return (
        <form className="analysis-action" onSubmit={submit}>
            <label className="form-field">
                <span>Model</span>
                <input
                    maxLength="120"
                    onChange={(event) => form.setData('model', event.target.value)}
                    type="text"
                    value={form.data.model}
                />
                <FieldError message={form.errors.model} />
            </label>
            <button className="button-secondary" disabled={form.processing} type="submit">
                <Sparkles aria-hidden="true" size={18} />
                <span>Analyze</span>
            </button>
        </form>
    );
}

function QuotePanel({ quote, instrument }) {
    if (!quote || !instrument) {
        return (
            <section className="stock-panel empty-state">
                <strong>No symbol selected</strong>
                <span>Search a symbol to load provider quote, recent prices, and related news.</span>
            </section>
        );
    }

    const changeClass = quote.change >= 0 ? 'stock-change stock-change--up' : 'stock-change stock-change--down';

    return (
        <section className="stock-panel stock-quote">
            <div>
                <p className="section-kicker">Quote</p>
                <h2>{instrument.symbol}</h2>
                <p>{instrument.name}</p>
            </div>
            <div className="stock-quote__price">
                <strong>{formatNumber(quote.price)}</strong>
                <span className={changeClass}>
                    {formatNumber(quote.change)} ({formatNumber(quote.change_percent)}%)
                </span>
            </div>
            <div className="stock-meta">
                <span>{instrument.market}</span>
                <span>{instrument.asset_type.toUpperCase()}</span>
                <span>{instrument.currency}</span>
                {instrument.exchange ? <span>{instrument.exchange}</span> : null}
            </div>
        </section>
    );
}

function PriceHistory({ prices }) {
    if (prices.length === 0) {
        return null;
    }

    const closes = prices.map((price) => Number(price.close));
    const min = Math.min(...closes);
    const max = Math.max(...closes);
    const range = Math.max(max - min, 1);

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">Recent prices</p>
                    <h2>20-day close history</h2>
                </div>
                <LineChart aria-hidden="true" size={22} />
            </div>
            <div className="price-bars" aria-label="Recent close prices">
                {prices.map((price) => (
                    <span
                        key={price.date}
                        style={{ height: `${28 + ((Number(price.close) - min) / range) * 72}%` }}
                        title={`${price.date}: ${formatNumber(price.close)}`}
                    />
                ))}
            </div>
            <div className="price-table-wrap">
                <table className="stock-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Open</th>
                            <th>High</th>
                            <th>Low</th>
                            <th>Close</th>
                        </tr>
                    </thead>
                    <tbody>
                        {prices.slice(-6).reverse().map((price) => (
                            <tr key={price.date}>
                                <td>{price.date}</td>
                                <td>{formatNumber(price.open)}</td>
                                <td>{formatNumber(price.high)}</td>
                                <td>{formatNumber(price.low)}</td>
                                <td>{formatNumber(price.close)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function NewsList({ news }) {
    if (news.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">Related news</p>
                    <h2>Provider headlines</h2>
                </div>
                <Newspaper aria-hidden="true" size={22} />
            </div>
            <div className="news-list">
                {news.map((item) => (
                    <article className="news-item" key={`${item.source}-${item.title}`}>
                        <strong>{item.title}</strong>
                        <p>{item.summary}</p>
                        <span>{item.source} · {item.published_at}</span>
                    </article>
                ))}
            </div>
        </section>
    );
}

function AnalysisHistory({ analyses }) {
    if (analyses.length === 0) {
        return (
            <section className="stock-panel empty-state">
                <strong>No saved analysis yet</strong>
                <span>Run an analysis to save a reference summary for this symbol.</span>
            </section>
        );
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">Reference analysis</p>
                    <h2>Latest saved summaries</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>
            <div className="analysis-list">
                {analyses.map((analysis) => (
                    <article className="analysis-item" key={analysis.id}>
                        <div className="analysis-item__head">
                            <span className={`status-pill status-pill--${analysis.rule_signal?.stance ?? 'watch'}`}>
                                {analysis.rule_signal?.stance ?? 'watch'}
                            </span>
                            <small>{analysis.provider_type} · {analysis.model}</small>
                        </div>
                        <p>{analysis.llm_output?.content ?? 'No LLM reference text saved.'}</p>
                        {analysis.rule_signal?.reasons?.length ? (
                            <ul>
                                {analysis.rule_signal.reasons.map((reason) => (
                                    <li key={reason}>{reason}</li>
                                ))}
                            </ul>
                        ) : null}
                    </article>
                ))}
            </div>
        </section>
    );
}

export default function StockSearch({
    symbol = null,
    instrument = null,
    quote = null,
    prices = [],
    news = [],
    analyses = [],
}) {
    return (
        <AppShell title="Stock Search">
            <div className="stock-search-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">Stock search</p>
                        <h2>Search, review, and save reference analysis</h2>
                        <p>Provider data is shown for research context. AI output is reference analysis, not guaranteed investment advice.</p>
                    </div>
                    <SearchForm initialSymbol={symbol} />
                </section>

                <div className="stock-workspace">
                    <div className="stock-workspace__main">
                        <QuotePanel instrument={instrument} quote={quote} />
                        <PriceHistory prices={prices} />
                        <NewsList news={news} />
                    </div>
                    <aside className="stock-workspace__side">
                        <AnalyzeForm instrument={instrument} />
                        <AnalysisHistory analyses={analyses} />
                    </aside>
                </div>
            </div>
        </AppShell>
    );
}
