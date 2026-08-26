import { Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { Bot, LineChart, Newspaper, RotateCcw, Sparkles, Trash2 } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';
import Markdown from '../../Components/Markdown';
import StockChatPanel from '../../Components/StockChatPanel';
import { FailureNote, QueueStalledHint, formatDateTime } from '../../Components/AnalysisFeedback';
import StockChart from '../../Components/charts/StockChart';
import CompareBox from '../../Components/charts/CompareBox';
import TimeframeSwitcher from '../../Components/charts/TimeframeSwitcher';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';
import { useI18n } from '../../i18n';

// 值為 i18n key，於 render 端以 t() 翻譯；缺 key 時回退顯示原始 stance 值。
const stanceLabels = {
    bullish: 'stocks.stance.bullish',
    bearish: 'stocks.stance.bearish',
    neutral: 'stocks.stance.neutral',
    watch: 'stocks.stance.watch',
    insufficient_data: 'stocks.stance.insufficientData',
};

const chipStanceLabels = {
    accumulating: 'stocks.chipStance.accumulating',
    distributing: 'stocks.chipStance.distributing',
    neutral: 'stocks.chipStance.neutral',
};

// 借用既有 status-pill 的多空配色，不另外定義一套語意色。
const chipStanceTone = {
    accumulating: 'bullish',
    distributing: 'bearish',
    neutral: 'neutral',
};

const alignmentLabels = {
    confirm: 'stocks.alignment.confirm',
    diverge: 'stocks.alignment.diverge',
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
    const { t } = useI18n();

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
                <p className="field-hint">{t('stocks.currentStock', { symbol: initialSymbol })}</p>
            ) : null}
        </div>
    );
}

function AnalyzeForm({ instrument, llmProviders }) {
    const { t } = useI18n();
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
                    {t('stocks.noProviderAnalysisBefore')}
                    {' '}
                    <Link href="/settings">{t('stocks.settingsLink')}</Link>
                    {' '}
                    {t('stocks.noProviderAnalysisAfter')}
                </p>
            ) : (
                <>
                    <label className="form-field">
                        <span>{t('stocks.aiModel')}</span>
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
                        <span>{t('stocks.modelNameOverride')}</span>
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
                <span>{t('stocks.generateAnalysis')}</span>
            </button>
        </form>
    );
}

function QuotePanel({ quote, instrument }) {
    const { t } = useI18n();

    if (!quote || !instrument) {
        return (
            <section className="stock-panel empty-state">
                <strong>{t('stocks.noStockSelected')}</strong>
                <span>{t('stocks.noStockSelectedHint')}</span>
            </section>
        );
    }

    const changeClass = quote.change >= 0 ? 'stock-change stock-change--up' : 'stock-change stock-change--down';

    return (
        <section className="stock-panel stock-quote">
            <div>
                <p className="section-kicker">{t('stocks.realtimeQuote')}</p>
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
    const { t } = useI18n();
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
                    <p className="section-kicker">{t('stocks.chartKicker')}</p>
                    <h2>{t('stocks.chartTitle')}</h2>
                </div>
                <LineChart aria-hidden="true" size={22} />
            </div>

            <div className="chart-toolbar">
                <TimeframeSwitcher loading={loading} onChange={setTf} value={tf} />
                {isCompare ? null : (
                    <div className="chart-panes" role="group" aria-label={t('stocks.subChartIndicators')}>
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
                    <span className="chart-empty">{t('stocks.chartLoadFailed')}</span>
                    <button className="button-secondary" onClick={() => fetchChart(tf)} type="button">
                        <RotateCcw aria-hidden="true" size={16} />
                        <span>{t('common.retry')}</span>
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
                    <span className="chart-empty">{t('stocks.chartLoading')}</span>
                </div>
            )}
        </section>
    );
}

function NewsList({ news }) {
    const { t } = useI18n();

    if (news.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.relatedNews')}</p>
                    <h2>{t('stocks.providerNewsTitles')}</h2>
                </div>
                <Newspaper aria-hidden="true" size={22} />
            </div>
            <div className="news-list">
                {news.map((item) => (
                    <article className="news-item" key={item.url || `${item.source}-${item.title}`}>
                        {/* 與即時新聞一致：有原文連結時標題可點擊外開；舊資料或
                            fake provider 沒有 url，退回純文字，不渲染空連結。 */}
                        <strong>
                            {item.url ? (
                                <a href={item.url} rel="noopener noreferrer" target="_blank">{item.title}</a>
                            ) : (
                                item.title
                            )}
                        </strong>
                        <p>{item.summary}</p>
                        <span>{item.source} · {item.published_at}</span>
                    </article>
                ))}
            </div>
        </section>
    );
}

/**
 * 刪除單筆分析。
 *
 * 刻意不用 window.confirm：那是阻塞式對話框，且和整體介面風格斷裂。改成就地
 * 二次確認，誤點一次不會直接刪掉東西。
 */
function DeleteAnalysisButton({ analysisId }) {
    const { t } = useI18n();
    const [armed, setArmed] = useState(false);
    const form = useForm();

    if (!armed) {
        return (
            <button
                aria-label={t('stocks.deleteAnalysis')}
                className="analysis-item__delete"
                onClick={() => setArmed(true)}
                title={t('stocks.deleteAnalysis')}
                type="button"
            >
                <Trash2 aria-hidden="true" size={15} />
            </button>
        );
    }

    return (
        <span className="analysis-item__confirm">
            <button
                className="analysis-item__confirm-yes"
                disabled={form.processing}
                onClick={() => form.delete(`/stocks/analyses/${analysisId}`, { preserveScroll: true })}
                type="button"
            >
                {t('stocks.confirmDelete')}
            </button>
            <button onClick={() => setArmed(false)} type="button">{t('common.cancel')}</button>
        </span>
    );
}

function PendingAnalysisItem({ analysis }) {
    const { t } = useI18n();

    return (
        <article className="analysis-item analysis-item--pending">
            <div className="analysis-item__head">
                <span className="status-pill status-pill--pending">{t('stocks.statusAnalyzing')}</span>
                <small className="analysis-item__time">
                    {formatDateTime(analysis.created_at)}
                    <span>{analysis.model}</span>
                </small>
                {/* 排隊中也能刪：job 找不到紀錄就直接結束，等於取消這次分析。 */}
                <DeleteAnalysisButton analysisId={analysis.id} />
            </div>
            <p className="analysis-item__pending-note">
                {t('stocks.pendingNote')}
            </p>
        </article>
    );
}

function AnalysisHistory({ analyses, stalled = false }) {
    const { t } = useI18n();

    if (analyses.length === 0) {
        return (
            <section className="stock-panel empty-state">
                <strong>{t('stocks.noAnalysisRecords')}</strong>
                <span>{t('stocks.noAnalysisRecordsHint')}</span>
            </section>
        );
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.referenceAnalysis')}</p>
                    <h2>{t('stocks.savedCount', { count: analyses.length })}</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>
            {stalled ? <QueueStalledHint /> : null}
            <div className="analysis-list">
                {analyses.map((analysis, index) => {
                    if (analysis.status === 'pending') {
                        return <PendingAnalysisItem analysis={analysis} key={analysis.id} />;
                    }

                    const stance = analysis.rule_signal?.stance ?? 'watch';
                    // chip / alignment 只在有籌碼資料時存在（台股且抓取成功）。
                    const chip = analysis.rule_signal?.chip ?? null;
                    const alignment = analysis.rule_signal?.alignment ?? null;

                    return (
                        <article className="analysis-item" key={analysis.id}>
                            <div className="analysis-item__head">
                                <span className={`status-pill status-pill--${stance}`}>
                                    {t('stocks.techLabel')} {stanceLabels[stance] ? t(stanceLabels[stance]) : stance}
                                </span>
                                {chip ? (
                                    <span className={`status-pill status-pill--${chipStanceTone[chip.stance] ?? 'neutral'}`}>
                                        {t('stocks.chipLabel')} {chipStanceLabels[chip.stance] ? t(chipStanceLabels[chip.stance]) : chip.stance}
                                    </span>
                                ) : null}
                                {alignment && alignment !== 'none' ? (
                                    <span className={`chip-alignment chip-alignment--${alignment}`}>
                                        {alignmentLabels[alignment] ? t(alignmentLabels[alignment]) : alignment}
                                    </span>
                                ) : null}
                                {/* 技術與籌碼是本地計算，AI 失敗時仍然有效；標出來讓
                                    使用者知道缺的是哪一段，而不是整筆分析都不可信。 */}
                                {analysis.status === 'failed' ? (
                                    <span className="status-pill status-pill--failed">{t('stocks.aiIncomplete')}</span>
                                ) : null}
                                {/* 時間必須顯示：同一檔股票的多筆分析，provider 與
                                    model 往往相同，沒有時間就完全無法區分，五筆疊在
                                    一起看起來像同一筆。created_at 本來就在 payload 裡
                                    卻沒被用。 */}
                                <small className="analysis-item__time">
                                    {index === 0 ? <strong>{t('stocks.latest')}</strong> : null}
                                    {formatDateTime(analysis.created_at)}
                                    <span>{analysis.provider_type} · {analysis.model}</span>
                                </small>
                                <DeleteAnalysisButton analysisId={analysis.id} />
                            </div>
                            {analysis.status === 'failed' && analysis.llm_output?.metadata?.failure ? (
                                <FailureNote failure={analysis.llm_output.metadata.failure} />
                            ) : (
                                <Markdown>{analysis.llm_output?.content ?? t('stocks.noLlmReferenceText')}</Markdown>
                            )}
                            {analysis.rule_signal?.reasons?.length ? (
                                <ul>
                                    {analysis.rule_signal.reasons.map((reason) => (
                                        <li key={reason}>{reason}</li>
                                    ))}
                                </ul>
                            ) : null}
                            {chip?.reasons?.length ? (
                                <ul className="analysis-item__chip-reasons">
                                    {chip.reasons.map((reason) => (
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

function fmtNum(value, digits = 2) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString('zh-TW', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function fmtPct(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    const num = Number(value);

    return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
}

function fmtRevenue(value, suffix) {
    if (value === null || value === undefined) {
        return '—';
    }

    // 億元
    return `${(Number(value) / 1e8).toLocaleString('zh-TW', { maximumFractionDigits: 1 })} ${suffix}`;
}

function changeClass(value) {
    if (value === null || value === undefined || Number(value) === 0) {
        return '';
    }

    return Number(value) > 0 ? 'is-up' : 'is-down';
}

function FundamentalsPanel({ fundamentals }) {
    const { t } = useI18n();

    if (!fundamentals) {
        return null;
    }

    const f = fundamentals;
    const allNull = ['per', 'pbr', 'dividend_yield', 'eps', 'roe', 'revenue', 'revenue_yoy']
        .every((key) => f[key] === null || f[key] === undefined);

    return (
        <section className="stock-panel fundamentals-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.fundamentals')}</p>
                    <h2>{t('stocks.financialsValuation')}</h2>
                </div>
                {f.data_as_of ? <span className="field-hint">{t('stocks.valuationDataDate', { date: f.data_as_of })}</span> : null}
            </div>

            {allNull ? (
                <p className="dashboard-empty">{t('stocks.fundamentalsEmpty')}</p>
            ) : (
                <div className="fundamentals-grid">
                    <div className="fundamentals-cell">
                        <span>{t('stocks.per')}</span>
                        <strong>{fmtNum(f.per)}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>{t('stocks.pbr')}</span>
                        <strong>{fmtNum(f.pbr)}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>{t('stocks.dividendYield')}</span>
                        <strong>{f.dividend_yield === null ? '—' : `${fmtNum(f.dividend_yield)}%`}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>EPS{f.eps_quarter ? `（${f.eps_quarter}）` : ''}</span>
                        <strong>{fmtNum(f.eps)}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>ROE</span>
                        <strong>{f.roe === null ? '—' : `${fmtNum(f.roe)}%`}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>{t('stocks.monthlyRevenue')}{f.revenue_month ? `（${f.revenue_month.slice(0, 7)}）` : ''}</span>
                        <strong>{fmtRevenue(f.revenue, t('stocks.revenueUnit'))}</strong>
                    </div>
                    <div className="fundamentals-cell">
                        <span>{t('stocks.revenueYoy')}</span>
                        <strong className={changeClass(f.revenue_yoy)}>{fmtPct(f.revenue_yoy)}</strong>
                    </div>
                </div>
            )}

            <ValuationPercentiles percentiles={f.percentiles} />

            <p className="fundamentals-disclaimer">
                {t('stocks.fundamentalsDisclaimer')}
            </p>
        </section>
    );
}

const PERCENTILE_LABELS = { per: 'stocks.per', pbr: 'stocks.pbr' };

/**
 * 估值在自身歷史中的位置。分位低不等於便宜（可能是基本面轉壞），
 * 因此只呈現區間與分位，不下多空結論。
 */
function ValuationPercentiles({ percentiles }) {
    const { t } = useI18n();
    const entries = Object.entries(percentiles ?? {});

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="valuation-percentiles">
            <p className="section-kicker">{t('stocks.historicalValuationPercentile')}</p>
            {entries.map(([metric, p]) => (
                <div className="valuation-row" key={metric}>
                    <span className="valuation-label">{PERCENTILE_LABELS[metric] ? t(PERCENTILE_LABELS[metric]) : metric}</span>
                    <div className="valuation-track">
                        <div className="valuation-marker" style={{ left: `${p.percentile}%` }} />
                    </div>
                    <span className="valuation-figure">
                        {p.value}{t('stocks.percentileSuffix', { percentile: p.percentile })}
                    </span>
                    <span className="valuation-range">
                        {t('stocks.valuationRange', {
                            min: p.min,
                            median: p.median,
                            max: p.max,
                            samples: p.samples,
                        })}
                    </span>
                </div>
            ))}
        </div>
    );
}

/** 股 → 張（台股慣例，1 張 = 1000 股）。資料層一律存股，只在顯示時換算。 */
function fmtLots(shares) {
    if (shares === null || shares === undefined) {
        return '—';
    }

    const lots = Math.round(Number(shares) / 1000);

    return `${lots > 0 ? '+' : ''}${lots.toLocaleString('en-US')}`;
}

function ChipPanel({ chipFlows }) {
    const { t } = useI18n();

    if (!chipFlows || chipFlows.length === 0) {
        return null;
    }

    const window = chipFlows.slice(-5);
    const sum = (key) => window.reduce((total, row) => total + Number(row[key] ?? 0), 0);
    const foreign = sum('foreign_net');

    // 外資連續同向天數：與後端 SignalEngine::foreignStreak 同規則，
    // 淨額 0 視為中斷。
    let streak = 0;
    const lastForeign = Number(chipFlows[chipFlows.length - 1].foreign_net ?? 0);
    if (lastForeign !== 0) {
        for (let i = chipFlows.length - 1; i >= 0; i -= 1) {
            const net = Number(chipFlows[i].foreign_net ?? 0);
            if (net === 0 || net > 0 !== lastForeign > 0) {
                break;
            }
            streak += 1;
        }
    }

    const recent = [...chipFlows].slice(-10).reverse();
    const lotsUnit = t('stocks.lotsUnit');

    return (
        <section className="stock-panel chip-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.chipFlows')}</p>
                    <h2>{t('stocks.institutionalNet')}</h2>
                </div>
                <span className="field-hint">{t('stocks.dataDate', { date: chipFlows[chipFlows.length - 1].date })}</span>
            </div>

            <div className="fundamentals-grid">
                <div className="fundamentals-cell">
                    <span>{t('stocks.foreignRecentDays', { count: window.length })}</span>
                    <strong className={changeClass(foreign)}>{fmtLots(foreign)} {lotsUnit}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.trustRecentDays', { count: window.length })}</span>
                    <strong className={changeClass(sum('trust_net'))}>{fmtLots(sum('trust_net'))} {lotsUnit}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.dealerRecentDays', { count: window.length })}</span>
                    <strong className={changeClass(sum('dealer_net'))}>{fmtLots(sum('dealer_net'))} {lotsUnit}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{lastForeign > 0 ? t('stocks.foreignStreakBuy') : t('stocks.foreignStreakSell')}</span>
                    <strong>{streak === 0 ? '—' : t('stocks.streakDays', { count: streak })}</strong>
                </div>
            </div>

            <div className="chip-table-scroll">
                <table className="chip-table">
                    <thead>
                        <tr>
                            <th scope="col">{t('stocks.thDate')}</th>
                            <th scope="col">{t('stocks.thForeign')}</th>
                            <th scope="col">{t('stocks.thTrust')}</th>
                            <th scope="col">{t('stocks.thDealer')}</th>
                            <th scope="col">{t('stocks.thTotal')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {recent.map((row) => (
                            <tr key={row.date}>
                                <td>{row.date}</td>
                                <td className={changeClass(row.foreign_net)}>{fmtLots(row.foreign_net)}</td>
                                <td className={changeClass(row.trust_net)}>{fmtLots(row.trust_net)}</td>
                                <td className={changeClass(row.dealer_net)}>{fmtLots(row.dealer_net)}</td>
                                <td className={changeClass(row.total_net)}>{fmtLots(row.total_net)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="fundamentals-disclaimer">
                {t('stocks.chipDisclaimer')}
            </p>
        </section>
    );
}

/**
 * 融資融券面板。
 *
 * 與籌碼分開呈現，因為主體不同：籌碼是法人資金流向，融資是散戶槓桿。
 * 絕對餘額不可跨股比較（融資限額依股本而異），所以把使用率放在最顯眼的位置。
 */
function MarginPanel({ marginFlows }) {
    const { t } = useI18n();

    if (!marginFlows || marginFlows.length === 0) {
        return null;
    }

    const latest = marginFlows[marginFlows.length - 1];
    const window = marginFlows.slice(-5);
    const first = window[0];

    // 變化率用頭尾兩點相除，與後端 SignalEngine 同規則——累加每日增減在資料
    // 缺日時會低估。
    const changePercent = first.margin_balance > 0
        ? (latest.margin_balance / first.margin_balance - 1) * 100
        : null;

    const recent = [...marginFlows].slice(-10).reverse();
    const lotsUnit = t('stocks.lotsUnit');

    return (
        <section className="stock-panel chip-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.creditTrading')}</p>
                    <h2>{t('stocks.marginShort')}</h2>
                </div>
                <span className="field-hint">{t('stocks.dataDate', { date: latest.date })}</span>
            </div>

            <div className="fundamentals-grid">
                <div className="fundamentals-cell">
                    <span>{t('stocks.marginBalance')}</span>
                    <strong>{fmtLots(latest.margin_balance)} {lotsUnit}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.recentDaysChange', { count: window.length })}</span>
                    <strong className={changeClass(changePercent)}>
                        {changePercent === null ? '—' : `${changePercent >= 0 ? '+' : ''}${changePercent.toFixed(2)}%`}
                    </strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.marginUsage')}</span>
                    <strong>{latest.usage_percent === null ? '—' : `${latest.usage_percent}%`}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.shortRatio')}</span>
                    <strong>{latest.short_ratio === null ? '—' : `${latest.short_ratio}%`}</strong>
                </div>
            </div>

            <div className="chip-table-scroll">
                <table className="chip-table">
                    <thead>
                        <tr>
                            <th scope="col">{t('stocks.thDate')}</th>
                            <th scope="col">{t('stocks.thMarginBalance')}</th>
                            <th scope="col">{t('stocks.thMarginChange')}</th>
                            <th scope="col">{t('stocks.thShortBalance')}</th>
                            <th scope="col">{t('stocks.thShortChange')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {recent.map((row) => (
                            <tr key={row.date}>
                                <td>{row.date}</td>
                                <td>{fmtLots(row.margin_balance)}</td>
                                <td className={changeClass(row.margin_change)}>{fmtLots(row.margin_change)}</td>
                                <td>{fmtLots(row.short_balance)}</td>
                                <td className={changeClass(row.short_change)}>{fmtLots(row.short_change)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="fundamentals-disclaimer">
                {t('stocks.marginDisclaimer')}
            </p>
        </section>
    );
}

/**
 * 券商分點主力面板。
 *
 * 券商分點反映「哪一家券商在買/賣」，是散戶追主力的核心籌碼。資料源為 FinMind 贊助
 * 等級（Sponsor）付費 dataset：免費 token 抓不到，此時顯示提示引導升級，其餘功能不受
 * 影響。非台股不顯示。
 */
function BrokerBranchPanel({ brokerBranch, market }) {
    const { t } = useI18n();

    if (market !== 'TW') {
        return null;
    }

    if (!brokerBranch || !brokerBranch.available) {
        return (
            <section className="stock-panel chip-panel">
                <div className="panel-heading">
                    <div>
                        <p className="section-kicker">{t('stocks.chipFlows')}</p>
                        <h2>{t('stocks.brokerBranch')}</h2>
                    </div>
                </div>
                <p className="fundamentals-disclaimer">
                    {t('stocks.brokerUnavailableBefore')}
                    {' '}
                    <a href="/settings">{t('stocks.settingsLink')}</a>
                    {' '}
                    {t('stocks.brokerUnavailableAfter')}
                </p>
            </section>
        );
    }

    const {
        top_buyers: topBuyers = [],
        top_sellers: topSellers = [],
        concentration = {},
        as_of: asOf,
        window_days: windowDays,
    } = brokerBranch;

    const lotsUnit = t('stocks.lotsUnit');
    const pct = (value) => (value === null || value === undefined ? '—' : `${(Number(value) * 100).toFixed(1)}%`);

    const renderList = (rows) => (
        rows.length === 0 ? (
            <p className="field-hint">{t('stocks.none')}</p>
        ) : (
            <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '0.35rem' }}>
                {rows.map((row) => (
                    <li key={row.broker_id} style={{ display: 'flex', justifyContent: 'space-between', gap: '0.5rem', alignItems: 'baseline' }}>
                        <span>{row.broker}</span>
                        <span style={{ display: 'flex', gap: '0.5rem', alignItems: 'baseline' }}>
                            <strong className={changeClass(row.net_shares)}>{fmtLots(row.net_shares)} {lotsUnit}</strong>
                            <small style={{ color: 'var(--text-muted, #8a8a8a)' }}>{row.streak_days > 0 ? t('stocks.streakDaysShort', { count: row.streak_days }) : ''}</small>
                        </span>
                    </li>
                ))}
            </ul>
        )
    );

    return (
        <section className="stock-panel chip-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.chipFlows')}</p>
                    <h2>{t('stocks.brokerBranchTitleDays', { count: windowDays })}</h2>
                </div>
                <span className="field-hint">{t('stocks.dataDate', { date: asOf })}</span>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '1rem' }}>
                <div>
                    <p className="section-kicker" style={{ marginBottom: '0.4rem' }}>{t('stocks.topBuyers')}</p>
                    {renderList(topBuyers)}
                </div>
                <div>
                    <p className="section-kicker" style={{ marginBottom: '0.4rem' }}>{t('stocks.topSellers')}</p>
                    {renderList(topSellers)}
                </div>
            </div>

            <div className="fundamentals-grid" style={{ marginTop: '0.75rem' }}>
                <div className="fundamentals-cell">
                    <span>{t('stocks.buyConcentration')}</span>
                    <strong>{pct(concentration.buy_topn_ratio)}</strong>
                </div>
                <div className="fundamentals-cell">
                    <span>{t('stocks.sellConcentration')}</span>
                    <strong>{pct(concentration.sell_topn_ratio)}</strong>
                </div>
            </div>

            <p className="fundamentals-disclaimer">
                {t('stocks.brokerDisclaimer')}
            </p>
        </section>
    );
}

/*
 * 社交套利：分類、細分原因與各腿判定**全部由後端算好**（SocialArbitrageAssessor →
 * SocialArbitrageClassifier → SocialArbitrageVerdicts），前端只做查表與格式化。
 *
 * 三張對照表刻意放在模組層而不是元件內：判定文案的 i18n 鍵只准由後端傳來的
 * verdict 索引，一旦寫進元件就等於前端自己選了一個結論，於是「畫面顯示的」與
 * 「prompt 給 LLM 的」會出現兩套說法。門檻同理——傳下來只為了顯示，不參與比較。
 */
const SOCIAL_STAGE_LABELS = {
    early: 'stocks.social.stage.early',
    partly_priced: 'stocks.social.stage.partlyPriced',
    fully_priced: 'stocks.social.stage.fullyPriced',
    false_signal: 'stocks.social.stage.falseSignal',
    insufficient: 'stocks.social.stage.insufficient',
};

const SOCIAL_REASON_LABELS = {
    not_enough_samples: 'stocks.social.reason.notEnoughSamples',
    heat_not_rising: 'stocks.social.reason.heatNotRising',
    price_unavailable: 'stocks.social.reason.priceUnavailable',
    price_in_grey_zone: 'stocks.social.reason.priceInGreyZone',
    price_fell: 'stocks.social.reason.priceFell',
    no_bucket_matched: 'stocks.social.reason.noBucketMatched',
};

const SOCIAL_VERDICT_LABELS = {
    heat_up: 'stocks.social.verdict.heatUp',
    heat_flat: 'stocks.social.verdict.heatFlat',
    heat_unevaluable: 'stocks.social.verdict.heatUnevaluable',
    high_water_yes: 'stocks.social.verdict.highWaterYes',
    high_water_no: 'stocks.social.verdict.highWaterNo',
    price_surged: 'stocks.social.verdict.priceSurged',
    price_risen: 'stocks.social.verdict.priceRisen',
    price_flat: 'stocks.social.verdict.priceFlat',
    price_fell: 'stocks.social.verdict.priceFell',
    price_grey_zone: 'stocks.social.verdict.priceGreyZone',
    price_unevaluable: 'stocks.social.verdict.priceUnevaluable',
    foreign_heavy: 'stocks.social.verdict.foreignHeavy',
    foreign_buying: 'stocks.social.verdict.foreignBuying',
    foreign_below: 'stocks.social.verdict.foreignBelow',
    foreign_unevaluable: 'stocks.social.verdict.foreignUnevaluable',
    revenue_verified: 'stocks.social.verdict.revenueVerified',
    revenue_unverified: 'stocks.social.verdict.revenueUnverified',
    revenue_unevaluable: 'stocks.social.verdict.revenueUnevaluable',
    margin_declining: 'stocks.social.verdict.marginDeclining',
    margin_stable: 'stocks.social.verdict.marginStable',
    margin_unevaluable: 'stocks.social.verdict.marginUnevaluable',
};

const MOMENTUM_UNAVAILABLE_LABELS = {
    not_taiwan: 'stocks.social.momentumUnavailableNotTaiwan',
    industry_unknown: 'stocks.social.momentumUnavailableIndustryUnknown',
};

/** 比率轉帶正負號的百分比。null 一律回破折號——「沒有這種資料」不是「0%」。 */
function socialPercent(ratio) {
    if (ratio === null || ratio === undefined) {
        return '—';
    }

    const value = Number(ratio) * 100;

    return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
}

/** 本身已經是百分點的值（毛利率 QoQ、持平帶下界）。 */
function socialPoints(points) {
    if (points === null || points === undefined) {
        return '—';
    }

    const value = Number(points);

    return `${value >= 0 ? '+' : ''}${value.toFixed(1)}pp`;
}

/** 兩個比率相減得到的百分點（產業超額與其門檻）。 */
function socialPointsFromRatio(ratio) {
    if (ratio === null || ratio === undefined) {
        return '—';
    }

    return socialPoints(Number(ratio) * 100);
}

/**
 * 單一條腿：判定 + 原始值 + 門檻。
 *
 * 「無法評估」與「否定」走**完全不同的分支**：不可評估那支不印任何原始值與門檻，
 * 並多帶一個明示的標記。兩者長得一樣等於把「本平台沒有這種資料」講成「查過了，
 * 不成立」——美股沒有三大法人籌碼，那是常態不是例外。
 *
 * 原始值與門檻並列是刻意的：本功能的門檻全都沒做過預測力回測，只給「法人買：是」
 * 是把一條武斷的線包裝成事實，使用者無從判斷結論離門檻有多遠。
 */
function SocialLeg({ name, leg, value, thresholds }) {
    const { t } = useI18n();

    if (!leg.evaluable) {
        return (
            <div className="social-leg social-leg--unevaluable">
                <span className="social-leg__name">{name}</span>
                <span className="social-leg__badge">{t('stocks.social.unevaluableBadge')}</span>
                <span className="social-leg__unevaluable">{t(SOCIAL_VERDICT_LABELS[leg.verdict])}</span>
            </div>
        );
    }

    return (
        <div className="social-leg social-leg--evaluable">
            <span className="social-leg__name">{name}</span>
            {value ? <span className="social-leg__value">{value}</span> : null}
            {thresholds ? <span className="social-leg__thresholds">（{thresholds}）</span> : null}
            <span className="social-leg__verdict">{t(SOCIAL_VERDICT_LABELS[leg.verdict])}</span>
        </div>
    );
}

/**
 * 新聞熱度腿。
 *
 * 則數與判定分開：則數是事實（永遠顯示），判定在新期則數未達樣本下限時是
 * 「不予判定」而不是「未升溫」——1→2 則會被算成 +100%，那是算不準。
 * 變化率為 null（前期 0 則，除以 0 無定義）時整段略過那半句。
 */
function SocialHeatRow({ heat }) {
    const { t } = useI18n();

    return (
        <div className="social-leg social-leg--evaluable">
            <span className="social-leg__name">{t('stocks.social.heatLegName')}</span>
            <span className="social-leg__value">
                {t('stocks.social.heatCounts', { recent: heat.recent_count, prior: heat.prior_count })}
                {heat.change_ratio === null ? '' : `，${t('stocks.social.heatChange', { value: socialPercent(heat.change_ratio) })}`}
            </span>
            <span className="social-leg__thresholds">
                （{t('stocks.social.heatThresholds', {
                    floor: heat.min_recent_mentions,
                    rise: socialPercent(heat.rise_ratio),
                })}）
            </span>
            <span className="social-leg__verdict">{t(SOCIAL_VERDICT_LABELS[heat.verdict])}</span>
        </div>
    );
}

/**
 * 熱度高檔那一行。門檻算不出來（歷史太短、分佈全空、百分位落在 0 則）時後端送
 * null，整行不輸出——印一個 0.0 則的門檻會讓剛被報導的標的立刻看起來像高檔。
 */
function SocialHighWaterRow({ highWater, recentCount }) {
    const { t } = useI18n();

    if (!highWater) {
        return null;
    }

    return (
        <div className="social-leg social-leg--evaluable">
            <span className="social-leg__name">{t('stocks.social.highWaterName')}</span>
            <span className="social-leg__value">
                {t('stocks.social.highWaterDetail', {
                    threshold: Number(highWater.threshold).toFixed(1),
                    recent: recentCount,
                })}
            </span>
            <span className="social-leg__verdict">{t(SOCIAL_VERDICT_LABELS[highWater.verdict])}</span>
        </div>
    );
}

/**
 * 社交套利面板。
 *
 * 「本分類只涵蓋新聞熱度」是**固定文案**：不可摺疊、不可縮成 tooltip。SOP 2.3 列的
 * YouTube／X／Reddit／Threads／PTT／Dcard／電商通路本平台一個都沒有接入，使用者
 * 看到「社交套利」四個字時預設會以為涵蓋了社群，這句話是唯一的更正機會。
 */
function SocialArbitragePanel({ arbitrage }) {
    const { t } = useI18n();

    if (!arbitrage) {
        return null;
    }

    const { heat, legs } = arbitrage;
    const stageKey = SOCIAL_STAGE_LABELS[arbitrage.stage];
    const reasonKey = SOCIAL_REASON_LABELS[arbitrage.insufficient_reason];

    return (
        <section className="stock-panel social-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.social.kicker')}</p>
                    <h2>{t('stocks.social.title')}</h2>
                </div>
                <span className="field-hint">{t('stocks.social.windowNote', { days: arbitrage.window_days })}</span>
            </div>

            <p className="social-stage">
                <span className="social-stage__label">{t('stocks.social.stageHeading')}</span>
                <strong>{stageKey === undefined ? arbitrage.stage : t(stageKey)}</strong>
            </p>

            {reasonKey === undefined ? null : (
                <p className="social-reason">
                    <span className="social-reason__label">{t('stocks.social.insufficientReason')}</span>
                    {t(reasonKey)}
                </p>
            )}

            <p className="social-coverage">{t('stocks.social.coverageNote')}</p>
            <p className="social-coverage">{t('stocks.social.noBacktestNote')}</p>

            <div className="social-legs">
                <SocialHeatRow heat={heat} />
                <SocialHighWaterRow highWater={heat.high_water} recentCount={heat.recent_count} />
                <SocialLeg
                    name={t('stocks.social.priceLegName')}
                    leg={legs.price}
                    value={t('stocks.social.priceValue', { value: socialPercent(legs.price.value) })}
                    thresholds={t('stocks.social.priceThresholds', {
                        risen: socialPercent(legs.price.thresholds.risen),
                        surged: socialPercent(legs.price.thresholds.surged),
                        flat: socialPercent(legs.price.thresholds.flat),
                        fell: socialPercent(legs.price.thresholds.fell),
                    })}
                />
                <SocialLeg
                    name={t('stocks.social.foreignLegName')}
                    leg={legs.foreign}
                    value={t('stocks.social.foreignValue', { value: socialPercent(legs.foreign.value) })}
                    thresholds={t('stocks.social.foreignThresholds', {
                        buy: socialPercent(legs.foreign.thresholds.buy),
                        heavy: socialPercent(legs.foreign.thresholds.heavy),
                    })}
                />
                {/* 營收腿的原始輸入本身就是布林（訂單庫存框架的 C1），沒有數值可印。 */}
                <SocialLeg name={t('stocks.social.revenueLegName')} leg={legs.revenue} value="" thresholds="" />
                <SocialLeg
                    name={t('stocks.social.marginLegName')}
                    leg={legs.margin}
                    value={t('stocks.social.marginValue', { value: socialPoints(legs.margin.value) })}
                    thresholds={t('stocks.social.marginThresholds', {
                        band: socialPoints(legs.margin.thresholds.stable_band),
                    })}
                />
            </div>

            <p className="fundamentals-disclaimer">{t('stocks.social.disclaimer')}</p>
        </section>
    );
}

/**
 * 產業動能面板。三種狀態必須分得開，後端已經把它們編碼在兩個欄位上：
 *
 * 1. 不適用（`applicable = false`）——這個市場沒有這個功能，原因由 `reason` 指名。
 * 2. 樣本不足（`applicable = true`、`median` 為 null）——有功能但還沒累積夠，樣本數
 *    照實寫。`fundamentals.order_inventory` 是新欄位，上線初期這會是常態，寫成
 *    「無資料」或「不適用」會被讀成這檔不支援。
 * 3. 正常。
 *
 * 樣本數在中位數分支之前無條件輸出：不寫會讓使用者以為系統看過整個產業。
 */
function IndustryMomentumPanel({ momentum }) {
    const { t } = useI18n();

    if (!momentum) {
        return null;
    }

    if (!momentum.applicable) {
        const reasonKey = MOMENTUM_UNAVAILABLE_LABELS[momentum.reason];

        return (
            <section className="stock-panel momentum-panel">
                <div className="panel-heading">
                    <div>
                        <p className="section-kicker">{t('stocks.social.momentumKicker')}</p>
                        <h2>{t('stocks.social.momentumNotApplicableHeading')}</h2>
                    </div>
                </div>
                <p className="momentum-unavailable">{reasonKey === undefined ? '' : t(reasonKey)}</p>
            </section>
        );
    }

    return (
        <section className="stock-panel momentum-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('stocks.social.momentumKicker')}</p>
                    <h2>{t('stocks.social.momentumTitle')}</h2>
                </div>
                {momentum.industry ? (
                    <span className="field-hint">{t('stocks.social.momentumIndustry')}：{momentum.industry}</span>
                ) : null}
            </div>

            <p className="momentum-samples">{t('stocks.social.momentumSamples', { count: momentum.samples })}</p>

            {momentum.median === null ? (
                <p className="momentum-insufficient">
                    {t('stocks.social.momentumInsufficientSamples', {
                        count: momentum.samples,
                        min: momentum.min_samples,
                    })}
                </p>
            ) : (
                <div className="fundamentals-grid">
                    <div className="fundamentals-cell">
                        <span>{t('stocks.social.momentumMedian')}</span>
                        <strong className={changeClass(momentum.median)}>{socialPercent(momentum.median)}</strong>
                        <span>{t('stocks.social.momentumMedianThreshold', {
                            threshold: socialPercent(momentum.thresholds.industry_accelerating),
                        })}</span>
                    </div>
                    {/*
                      * 算不出來時整格略過，不印破折號——與 prompt 端的
                      * SocialArbitrageGuide::momentumBlock() 同一個做法：印「無」會被
                      * 讀成「查過而且沒有」，印 0 更糟（0 是合法的 YoY，也是「與產業
                      * 同步」這個實質宣稱）。同業中位數算得出來、標的自己算不出來
                      * （快取過舊或產業別不符）是實際會發生的組合。
                      */}
                    {momentum.own === null ? null : (
                        <div className="fundamentals-cell">
                            <span>{t('stocks.social.momentumOwn')}</span>
                            <strong className={changeClass(momentum.own)}>{socialPercent(momentum.own)}</strong>
                        </div>
                    )}
                    {momentum.excess === null ? null : (
                        <div className="fundamentals-cell">
                            <span>{t('stocks.social.momentumExcess')}</span>
                            <strong className={changeClass(momentum.excess)}>{socialPointsFromRatio(momentum.excess)}</strong>
                            <span>{t('stocks.social.momentumExcessThreshold', {
                                threshold: socialPointsFromRatio(momentum.thresholds.outperformance),
                            })}</span>
                        </div>
                    )}
                </div>
            )}

            <p className="fundamentals-disclaimer">
                {t('stocks.social.momentumRetrospective')}
                {' '}
                {t('stocks.social.momentumNoBacktest')}
            </p>
        </section>
    );
}

/* ---------------------------------------------------------------
   短線／中長線體質判讀（階段 5b）
   --------------------------------------------------------------- */

// 立場與塊名一律走對照表：機器鍵（bullish、return_on_equity）不得直接印給使用者。
const HEALTH_TECHNICAL_STANCE_LABELS = {
    bullish: 'health.stanceBullish',
    bearish: 'health.stanceBearish',
    watch: 'health.stanceWatch',
    neutral: 'health.stanceNeutral',
};

const HEALTH_CHIP_STANCE_LABELS = {
    accumulating: 'health.chipAccumulating',
    distributing: 'health.chipDistributing',
    neutral: 'health.chipNeutral',
};

const HEALTH_BLOCK_LABELS = {
    valuation: 'health.blockValuation',
    return_on_equity: 'health.blockReturnOnEquity',
    growth: 'health.blockGrowth',
    quality: 'health.blockQuality',
};

/** 缺值印破折號，**不印 0**：0 在 RSI 的量尺上是極度超賣，語意完全相反。 */
function healthNumber(value, digits) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toFixed(digits);
}

/**
 * 一項的資料日。**null 也要明講「無」**，不是整段省略——省略會讓讀者以為這一項
 * 與前一項是同一天算的，而實測價格、籌碼、財報分別停在三個不同的日期
 * （2026-08-25／08-17／08-05）。
 */
function HealthAsOf({ date }) {
    const { t } = useI18n();

    return (
        <span className="health-as-of">
            {t('health.asOfLabel', { date: date ? date : t('health.asOfUnknown') })}
        </span>
    );
}

/**
 * 立場或判定的理由。
 *
 * **已知的雙語缺口：標籤是英文，理由維持中文。** 這些字串由後端的 SignalEngine
 * 與 LongTermHealthReader 直接以繁中產生（兩者都不吃 locale，也沒有機器鍵對照
 * 表），到呈現層已經是定稿，沒有可翻譯的空間。**不能因此在英文路徑把它們丟掉**
 * ——理由正是使用者判斷可信度的依據，只給判定不給理由等於要求使用者無條件相信
 * 一組未經回測的門檻。沿用 OrderInventoryGuide 對 fixedCaveats／industryNote 的
 * 既有裁決：丟掉資訊比語言混雜更糟。正解是讓兩個 reader 改輸出機器鍵、由呈現層
 * 依 locale 渲染，那要動階段 5b Task 3、4 已定稿的純計算類別。
 */
function HealthReasons({ reasons }) {
    if (!reasons || !reasons.length) {
        return null;
    }

    return (
        <ul className="health-reasons">
            {reasons.map((reason) => (
                <li key={reason}>{reason}</li>
            ))}
        </ul>
    );
}

/**
 * 判定徽章。**四個分支，「中性」與「不可評估」絕不共用。**
 *
 * 「中性」是看過了、沒有偏向；「不可評估」是不知道——對使用者是兩種不同的行動。
 * 讓 null 沿著分支落到中性那一支，系統就會對一件根本沒算過的事宣稱「沒有偏向」，
 * 而使用者沒有任何線索能察覺。
 */
function HealthVerdictBadge({ verdict }) {
    const { t } = useI18n();

    if (verdict === 'positive') {
        return (
            <span className="health-verdict health-verdict--positive">{t('health.verdictPositive')}</span>
        );
    }

    if (verdict === 'negative') {
        return (
            <span className="health-verdict health-verdict--negative">{t('health.verdictNegative')}</span>
        );
    }

    if (verdict === 'neutral') {
        return (
            <span className="health-verdict health-verdict--neutral">{t('health.verdictNeutral')}</span>
        );
    }

    return (
        <span className="health-verdict health-verdict--unavailable">{t('health.verdictUnavailable')}</span>
    );
}

/**
 * 不可評估的成因。**五態，五個分支，不得合併任何兩個。**
 *
 * 五種對使用者是五種不同的行動：`not_in_universe` 與 `not_applicable` 永遠不會
 * 有；`not_yet` 等分析或掃描再跑幾次就有；`stale` 要等上游更新；`indeterminate`
 * 是資料到齊了但這一項本身算不出來。把任兩種講成同一句，使用者要嘛一直等一個
 * 不會來的東西，要嘛放棄一個再跑一次就有的東西。
 *
 * `indeterminate` 放在最後兼作兜底：「資料到齊但這一項算不出來」是任何未預期值
 * 的誠實上位描述，落到別的分支才會說錯話（與 Topics 的 RevenueBadge 同一手法）。
 */
function HealthUnavailableNote({ reason }) {
    const { t } = useI18n();

    if (reason === 'not_in_universe') {
        return (
            <p className="health-unavailable health-unavailable--not-in-universe">{t('health.reasonNotInUniverse')}</p>
        );
    }

    if (reason === 'not_applicable') {
        return (
            <p className="health-unavailable health-unavailable--not-applicable">{t('health.reasonNotApplicable')}</p>
        );
    }

    if (reason === 'stale') {
        return (
            <p className="health-unavailable health-unavailable--stale">{t('health.reasonStale')}</p>
        );
    }

    if (reason === 'not_yet') {
        return (
            <p className="health-unavailable health-unavailable--not-yet">{t('health.reasonNotYet')}</p>
        );
    }

    return (
        <p className="health-unavailable health-unavailable--indeterminate">{t('health.reasonIndeterminate')}</p>
    );
}

/**
 * 短線的一個立場：名稱、立場、資料日、理由。
 *
 * 立場為 null 時走與四塊完全相同的「不可評估」徽章：ShortTermRead 已把
 * SignalEngine 的 'insufficient_data' 轉成 null，呈現層只需處理一種缺席。
 */
function HealthStanceRow({ name, stance, labels, asOf, reasons }) {
    const { t } = useI18n();
    const labelKey = labels[stance];

    return (
        <div className="health-stance">
            <span className="health-stance__name">{name}</span>
            {labelKey === undefined ? (
                <HealthVerdictBadge verdict={null} />
            ) : (
                <span className="health-stance__value">{t(labelKey)}</span>
            )}
            <HealthAsOf date={asOf} />
            <HealthReasons reasons={reasons} />
        </div>
    );
}

/**
 * 中長線的一塊：名稱、判定、**自己的**資料日，以及理由或不可評估成因。
 *
 * 不可評估的塊照樣列出：刪掉它們，使用者只會看到一份比較短的清單，
 * 而不知道少了什麼、為什麼少。
 */
function HealthBlockRow({ block }) {
    const { t } = useI18n();
    const labelKey = HEALTH_BLOCK_LABELS[block.block];

    return (
        <div className="health-block">
            <div className="health-block__head">
                <span className="health-block__name">{labelKey === undefined ? block.block : t(labelKey)}</span>
                <HealthVerdictBadge verdict={block.verdict} />
                <HealthAsOf date={block.as_of} />
            </div>
            {block.unavailable_reason ? (
                <HealthUnavailableNote reason={block.unavailable_reason} />
            ) : (
                <HealthReasons reasons={block.reasons} />
            )}
        </div>
    );
}

/**
 * 六則必要說明。**無條件顯示、不可摺疊、不接受任何 prop。**
 *
 * 沿用階段 4／5a 的標準：藏進 details 或縮成 tooltip 等於沒說。不收 prop 是刻意
 * 的——收了就有辦法做成「只在有判讀時顯示」，而這些說明最需要被看到的時刻，正是
 * 判讀最薄弱、最容易被過度解讀的時候。
 */
function HealthNotes() {
    const { t } = useI18n();

    return (
        <div className="health-notes">
            <p className="health-notes__item">{t('health.noteNoBacktest')}</p>
            <p className="health-notes__item">{t('health.noteTechnicalCollinear')}</p>
            <p className="health-notes__item">{t('health.noteDivergenceNotNetted')}</p>
            <p className="health-notes__item">{t('health.noteDatesDiffer')}</p>
            <p className="health-notes__item">{t('health.noteUnavailableIsNotNegative')}</p>
            <p className="health-notes__item">{t('health.notePricesUnadjusted')}</p>
        </div>
    );
}

/**
 * 體質判讀面板：短線兩維立場 + 中長線四塊。**沒有總分、沒有排名。**
 *
 * 技術與籌碼**並列**、背離時明確標示：兩者背離不互相抵銷，壓成一個數字會讓
 * 「技術偏多但法人在賣」與「兩邊都沒訊號」變成同一格。
 *
 * 本元件一個門檻都不比。判定全部由後端的兩個 reader 決定，送進 prompt 的是同一
 * 份輸出（HealthGuide）——前端自己重判會出現「畫面顯示的」與「LLM 讀到的」不一
 * 致，同一份資料兩套結論，而使用者無從得知哪一個算數。
 */
function HealthPanel({ health }) {
    const { t } = useI18n();

    if (!health) {
        return null;
    }

    const { short, long, snapshot } = health;

    return (
        <section className="stock-panel health-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('health.kicker')}</p>
                    <h2>{t('health.title')}</h2>
                </div>
                <span className="field-hint">{t('health.barsLabel', { bars: snapshot.bars })}</span>
            </div>

            <h3 className="health-subheading">{t('health.shortTermHeading')}</h3>

            <div className="health-stances">
                <HealthStanceRow
                    asOf={short.price_as_of}
                    labels={HEALTH_TECHNICAL_STANCE_LABELS}
                    name={t('health.technicalStanceLabel')}
                    reasons={short.technical_reasons}
                    stance={short.technical_stance}
                />
                <HealthStanceRow
                    asOf={short.chip_as_of}
                    labels={HEALTH_CHIP_STANCE_LABELS}
                    name={t('health.chipStanceLabel')}
                    reasons={short.chip_reasons}
                    stance={short.chip_stance}
                />
            </div>

            {/* 背離是三態：alignment 為 null（SignalEngine 的 none）是「無法判定」，
                走與四塊相同的不可評估徽章。印成「否」等於對一檔連一列籌碼都沒有的
                標的宣稱技術與籌碼一致——同一頁對 rule_signal.alignment 的既有處理
                本來就是三態，這裡不能倒退成兩態。 */}
            <p className="health-divergence">
                <span className="health-divergence__label">{t('health.divergenceLabel')}</span>
                {short.alignment ? (
                    <strong>{short.alignment === 'diverge' ? t('health.divergingYes') : t('health.divergingNo')}</strong>
                ) : (
                    <HealthVerdictBadge verdict={null} />
                )}
            </p>

            {/* RSI 與量能就地標明未參與判定：它們與 KD／MACD／均線同為價格動能的
                衍生量、彼此高度共線，列在同一個區塊裡而不加註會被讀成第四、第五項
                佐證。 */}
            <p className="health-context">
                <span className="health-context__label">{t('health.contextLabel')}</span>
                {t('health.contextValues', {
                    rsi: healthNumber(short.rsi, 1),
                    volume: healthNumber(short.volume_ratio, 2),
                })}
                {' '}
                {t('health.contextNote')}
            </p>

            <h3 className="health-subheading">{t('health.longTermHeading')}</h3>

            <div className="health-blocks">
                {long.blocks.map((block) => (
                    <HealthBlockRow block={block} key={block.block} />
                ))}
            </div>

            <p className="health-provenance">{t('health.formulaVersion', { version: long.formula_version })}</p>

            {/* 個股頁走 cachedFor()（零上游），所以這句幾乎一定會出現。它是換來
                「同步 web 請求不會被 max_execution_time 砍掉」的代價，必須說出口。 */}
            {snapshot.cached_only ? <p className="health-cached-only">{t('health.cachedOnlyNote')}</p> : null}

            <HealthNotes />

            <p className="fundamentals-disclaimer">{t('health.disclaimer')}</p>
        </section>
    );
}

export default function StockSearch({
    symbol = null,
    instrument = null,
    quote = null,
    news = [],
    analyses = [],
    chatTurns = [],
    llmProviders = [],
    fundamentals = null,
    chipFlows = [],
    marginFlows = [],
    brokerBranch = null,
    socialArbitrage = null,
    industryMomentum = null,
    health = null,
}) {
    const { t } = useI18n();

    // 分析與問答都在佇列執行，頁面回來時多半還是 pending，靠輪詢把結果補上。
    // 兩者共用同一個計時器：分開會讓同一頁每輪送出兩個 request，而 Inertia 的
    // 部分重載本來就支援一次帶多個 prop。
    const hasPending = analyses.some((analysis) => analysis.status === 'pending')
        || chatTurns.some((turn) => turn.status === 'pending');
    const stalled = useAnalysisPolling(hasPending, ['analyses', 'chatTurns']);

    return (
        <AppShell title={t('stocks.pageTitle')}>
            <div className="stock-search-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">{t('stocks.pageKicker')}</p>
                        <h2>{t('stocks.pageHeadline')}</h2>
                        <p>{t('stocks.pageIntro')}</p>
                    </div>
                    <SearchForm initialSymbol={symbol} />
                </section>

                <div className="stock-workspace">
                    <div className="stock-workspace__main">
                        <QuotePanel instrument={instrument} quote={quote} />
                        {instrument ? <ChartSection instrument={instrument} /> : null}
                        <HealthPanel health={health} />
                        <FundamentalsPanel fundamentals={fundamentals} />
                        <ChipPanel chipFlows={chipFlows} />
                        <BrokerBranchPanel brokerBranch={brokerBranch} market={instrument?.market} />
                        <MarginPanel marginFlows={marginFlows} />
                        <SocialArbitragePanel arbitrage={socialArbitrage} />
                        <IndustryMomentumPanel momentum={industryMomentum} />
                        <NewsList news={news} />
                    </div>
                    <aside className="stock-workspace__side">
                        <AnalyzeForm instrument={instrument} llmProviders={llmProviders} />
                        <AnalysisHistory analyses={analyses} stalled={stalled} />
                        <StockChatPanel
                            instrument={instrument}
                            llmProviders={llmProviders}
                            stalled={stalled}
                            turns={chatTurns}
                        />
                    </aside>
                </div>
            </div>
        </AppShell>
    );
}
