import { router, useForm, usePage } from '@inertiajs/react';
import { Bot, LineChart, Newspaper, Search, Sparkles } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

const stanceLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
    watch: '觀察',
    insufficient_data: '資料不足',
};

function formatNumber(value, digits = 2) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '-';
    }

    return Number(value).toLocaleString('zh-TW', {
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
                <span>股票代號</span>
                <input
                    maxLength="32"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="AAPL 或 2330.TW"
                    type="search"
                    value={form.data.symbol}
                />
                <FieldError message={errors.symbol} />
            </label>
            <button className="button-primary" type="submit">
                <Search aria-hidden="true" size={18} />
                <span>搜尋</span>
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
                <span>模型名稱</span>
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
                <span>產生分析</span>
            </button>
        </form>
    );
}

function QuotePanel({ quote, instrument }) {
    if (!quote || !instrument) {
        return (
            <section className="stock-panel empty-state">
                <strong>尚未選擇股票</strong>
                <span>搜尋股票代號後，會載入報價、近期價格與相關新聞。</span>
            </section>
        );
    }

    const changeClass = quote.change >= 0 ? 'stock-change stock-change--up' : 'stock-change stock-change--down';

    return (
        <section className="stock-panel stock-quote">
            <div>
                <p className="section-kicker">即時報價</p>
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
                    <p className="section-kicker">近期價格</p>
                    <h2>20 日收盤價走勢</h2>
                </div>
                <LineChart aria-hidden="true" size={22} />
            </div>
            <div className="price-bars" aria-label="近期收盤價">
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
                            <th>日期</th>
                            <th>開盤</th>
                            <th>最高</th>
                            <th>最低</th>
                            <th>收盤</th>
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
                    <p className="section-kicker">相關新聞</p>
                    <h2>資料供應器新聞標題</h2>
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
                <strong>尚無分析紀錄</strong>
                <span>執行分析後，會為這檔股票保存一份參考摘要。</span>
            </section>
        );
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">參考分析</p>
                    <h2>最新保存摘要</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>
            <div className="analysis-list">
                {analyses.map((analysis) => {
                    const stance = analysis.rule_signal?.stance ?? 'watch';

                    return (
                        <article className="analysis-item" key={analysis.id}>
                            <div className="analysis-item__head">
                                <span className={`status-pill status-pill--${stance}`}>
                                    {stanceLabels[stance] ?? stance}
                                </span>
                                <small>{analysis.provider_type} · {analysis.model}</small>
                            </div>
                            <p>{analysis.llm_output?.content ?? '尚未保存 LLM 參考文字。'}</p>
                            {analysis.rule_signal?.reasons?.length ? (
                                <ul>
                                    {analysis.rule_signal.reasons.map((reason) => (
                                        <li key={reason}>{reason}</li>
                                    ))}
                                </ul>
                            ) : null}
                        </article>
                    );
                })}
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
        <AppShell title="個股搜尋">
            <div className="stock-search-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">個股搜尋</p>
                        <h2>搜尋、檢視並保存參考分析</h2>
                        <p>資料供應器內容僅作研究脈絡。AI 輸出屬參考分析，不保證為投資建議。</p>
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