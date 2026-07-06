import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { Bot, LineChart, Newspaper, RotateCcw, Sparkles } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';
import Markdown from '../../Components/Markdown';
import StockChart from '../../Components/charts/StockChart';
import CompareBox from '../../Components/charts/CompareBox';
import TimeframeSwitcher from '../../Components/charts/TimeframeSwitcher';

const stanceLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
    watch: '觀察',
    insufficient_data: '資料不足',
};

// 副圖開關：預設開 KD/MACD，RSI/OBV 預設關（spec 決策）。選擇存 localStorage。
const PANE_OPTIONS = [
    { key: 'kd', label: 'KD' },
    { key: 'macd', label: 'MACD' },
    { key: 'rsi', label: 'RSI' },
    { key: 'obv', label: 'OBV' },
];
const DEFAULT_PANES = ['kd', 'macd'];
const PANES_STORAGE_KEY = 'chart-panes';

function loadPanes() {
    if (typeof window === 'undefined') {
        return DEFAULT_PANES;
    }
    try {
        const raw = window.localStorage.getItem(PANES_STORAGE_KEY);
        if (!raw) {
            return DEFAULT_PANES;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return DEFAULT_PANES;
        }

        return parsed.filter((key) => PANE_OPTIONS.some((option) => option.key === key));
    } catch {
        return DEFAULT_PANES;
    }
}

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
    const onSelect = (result) => {
        router.get(
            '/stocks/search',
            { symbol: result.symbol, name: result.name },
            { preserveScroll: true },
        );
    };

    return (
        <div className="stock-search-form">
            <StockSearchBox onSelect={onSelect} />
            {initialSymbol ? (
                <p className="field-hint">目前股票：{initialSymbol}</p>
            ) : null}
        </div>
    );
}

function AnalyzeForm({ instrument, llmProviders }) {
    const providers = llmProviders ?? [];
    const defaultProvider = providers.find((provider) => provider.is_default) ?? providers[0] ?? null;
    const form = useForm({
        llm_provider_setting_id: defaultProvider ? defaultProvider.id : '',
        model: defaultProvider ? defaultProvider.model : '',
    });

    if (!instrument) {
        return null;
    }

    const onProviderChange = (event) => {
        const id = event.target.value;
        form.setData('llm_provider_setting_id', id);
        const selected = providers.find((provider) => String(provider.id) === String(id));
        form.setData('model', selected ? selected.model : form.data.model);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/stocks/${instrument.id}/analyses`, { preserveScroll: true });
    };

    return (
        <form className="analysis-action" onSubmit={submit}>
            {providers.length === 0 ? (
                <p className="field-hint">
                    尚未設定 AI 模型，本次分析僅包含技術指標與規則訊號。可至
                    {' '}
                    <Link href="/settings">系統設定</Link>
                    {' '}
                    新增 OpenAI、Gemini、Anthropic 或本地 Ollama 模型。
                </p>
            ) : (
                <>
                    <label className="form-field">
                        <span>AI 模型</span>
                        <select value={form.data.llm_provider_setting_id} onChange={onProviderChange}>
                            {providers.map((provider) => (
                                <option key={provider.id} value={provider.id}>
                                    {provider.display_name}（{provider.provider_type} · {provider.model}）
                                </option>
                            ))}
                        </select>
                        <FieldError message={form.errors.llm_provider_setting_id} />
                    </label>
                    <label className="form-field">
                        <span>模型名稱（可覆寫）</span>
                        <input
                            maxLength="120"
                            onChange={(event) => form.setData('model', event.target.value)}
                            placeholder="llama3.1"
                            type="text"
                            value={form.data.model}
                        />
                        <FieldError message={form.errors.model} />
                    </label>
                </>
            )}
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

/**
 * 圖表區塊：掛載與 timeframe 變更時非同步打 chart endpoint（首載已不帶 prices/indicators）。
 * 副圖開關持久化於 localStorage；error 顯示重試按鈕不白屏。
 *
 * 比較模式：compareSymbols 非空時 StockChart 派生為 compare 模式。
 * 各比較 symbol 走 by-symbol endpoint（可能無 instrument，含指數），
 * 與主 tf 一致；fetch 失敗只標記該 symbol，不影響主圖與其他比較線。
 */
function ChartSection({ instrument }) {
    const [tf, setTf] = useState('daily');
    const [chartData, setChartData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const [panes, setPanes] = useState(() => loadPanes());
    const [compareSymbols, setCompareSymbols] = useState([]);
    const [compareSeries, setCompareSeries] = useState([]);
    const [compareErrors, setCompareErrors] = useState({});

    const fetchChart = useCallback((timeframe, signal) => {
        setLoading(true);
        setError(false);

        return axios
            .get(`/stocks/${instrument.id}/chart`, { params: { tf: timeframe }, signal })
            .then((response) => {
                setChartData(response.data);
                setLoading(false);
            })
            .catch((err) => {
                if (axios.isCancel?.(err) || err?.name === 'CanceledError') {
                    return;
                }
                setError(true);
                setLoading(false);
            });
    }, [instrument.id]);

    useEffect(() => {
        const controller = new AbortController();
        fetchChart(tf, controller.signal);

        return () => controller.abort();
    }, [fetchChart, tf]);

    // 比較 series 依 compareSymbols × tf 取回。symbol/tf 變更時整批重抓，
    // 各 symbol 獨立成敗：成功者進 compareSeries，失敗者進 compareErrors。
    useEffect(() => {
        if (compareSymbols.length === 0) {
            setCompareSeries([]);
            setCompareErrors({});

            return undefined;
        }

        const controller = new AbortController();

        Promise.all(compareSymbols.map((symbol) => axios
            .get('/stocks/chart', { params: { symbol, tf }, signal: controller.signal })
            .then((response) => ({ symbol, candles: response.data.candles ?? [], ok: true }))
            .catch((err) => {
                if (axios.isCancel?.(err) || err?.name === 'CanceledError') {
                    return { symbol, cancelled: true };
                }

                return { symbol, ok: false };
            }))).then((results) => {
            if (results.some((result) => result.cancelled)) {
                return;
            }

            setCompareSeries(results
                .filter((result) => result.ok)
                .map(({ symbol, candles }) => ({ symbol, candles })));
            setCompareErrors(Object.fromEntries(results
                .filter((result) => !result.ok)
                .map((result) => [result.symbol, true])));
        });

        return () => controller.abort();
    }, [compareSymbols, tf]);

    const togglePane = (key) => {
        setPanes((current) => {
            const next = current.includes(key)
                ? current.filter((item) => item !== key)
                : [...current, key];

            if (typeof window !== 'undefined') {
                window.localStorage.setItem(PANES_STORAGE_KEY, JSON.stringify(next));
            }

            return next;
        });
    };

    const addCompareSymbol = (symbol) => {
        // 不與主 symbol 重複、去重、上限 4（CompareBox 亦有把關，此為第二道）。
        if (symbol === instrument.symbol.toUpperCase() || compareSymbols.includes(symbol) || compareSymbols.length >= 4) {
            return;
        }
        setCompareSymbols((current) => [...current, symbol]);
    };

    const removeCompareSymbol = (symbol) => {
        setCompareSymbols((current) => current.filter((item) => item !== symbol));
    };

    const isCompare = compareSymbols.length > 0;

    return (
        <section className="stock-panel chart-section">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">技術線圖</p>
                    <h2>K 線與技術指標</h2>
                </div>
                <LineChart aria-hidden="true" size={22} />
            </div>

            <div className="chart-toolbar">
                <TimeframeSwitcher loading={loading} onChange={setTf} value={tf} />
                {isCompare ? null : (
                    <div className="chart-panes" role="group" aria-label="副圖指標">
                        {PANE_OPTIONS.map((option) => (
                            <label className="chart-pane-toggle" key={option.key}>
                                <input
                                    checked={panes.includes(option.key)}
                                    onChange={() => togglePane(option.key)}
                                    type="checkbox"
                                />
                                <span>{option.label}</span>
                            </label>
                        ))}
                    </div>
                )}
            </div>

            <CompareBox
                errors={compareErrors}
                onAdd={addCompareSymbol}
                onRemove={removeCompareSymbol}
                symbols={compareSymbols}
            />

            {error ? (
                <div className="chart-container chart-container--empty">
                    <span className="chart-empty">圖表資料載入失敗。</span>
                    <button className="button-secondary" onClick={() => fetchChart(tf)} type="button">
                        <RotateCcw aria-hidden="true" size={16} />
                        <span>重試</span>
                    </button>
                </div>
            ) : chartData ? (
                <StockChart
                    chartData={chartData}
                    compareSeries={compareSeries}
                    compareSymbols={compareSymbols}
                    tf={tf}
                    visiblePanes={panes}
                />
            ) : (
                <div className="chart-container chart-container--empty">
                    <span className="chart-empty">載入圖表資料中…</span>
                </div>
            )}
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
                            <Markdown>{analysis.llm_output?.content ?? '尚未保存 LLM 參考文字。'}</Markdown>
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
    news = [],
    analyses = [],
    llmProviders = [],
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
                        {instrument ? <ChartSection instrument={instrument} /> : null}
                        <NewsList news={news} />
                    </div>
                    <aside className="stock-workspace__side">
                        <AnalyzeForm instrument={instrument} llmProviders={llmProviders} />
                        <AnalysisHistory analyses={analyses} />
                    </aside>
                </div>
            </div>
        </AppShell>
    );
}
