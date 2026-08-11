import { Link, useForm } from '@inertiajs/react';
import { Bot, Layers, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';

const stanceLabels = {
    bullish: '偏多',
    bearish: '偏空',
    neutral: '中性',
    insufficient_data: '資料不足',
};

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString('zh-TW', { dateStyle: 'medium', timeStyle: 'short' });
}

function formatNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 2 });
}

// 台股慣例：漲紅、跌綠。
function changeColor(value) {
    if (value === null || value === undefined || Number(value) === 0) {
        return 'var(--text-muted, #8a8a8a)';
    }

    return Number(value) > 0 ? '#d64545' : '#2f9e6b';
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    const num = Number(value);

    return `${num > 0 ? '+' : ''}${num.toFixed(2)}%`;
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
            新增一個模型，才能產生權值股大盤分析。
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

function FailureNote({ failure }) {
    return (
        <div className="analysis-failure">
            <strong>{failure.message}</strong>
            <span>{failure.hint}</span>
        </div>
    );
}

function PendingAnalysis({ createdAt, model }) {
    return (
        <article className="analysis-item analysis-item--pending">
            <div className="analysis-item__head">
                <span className="status-pill status-pill--pending">分析中</span>
                <small>{model} · {formatDateTime(createdAt)}</small>
            </div>
            <p className="analysis-item__pending-note">
                已排入佇列，正在彙整權值股行情與籌碼，完成後會自動顯示。
            </p>
        </article>
    );
}

/**
 * 籃子聚合：加權漲跌、多空分數、外資聚合。這是「權值籃子當大盤代理」的核心讀數。
 */
function AggregatePanel({ aggregate }) {
    if (!aggregate) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">籃子聚合</p>
                    <h2>權值股大盤讀數</h2>
                </div>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
                    gap: '0.75rem',
                }}
            >
                <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>籃子加權漲跌</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.weighted_change_percent) }}>
                        {formatPercent(aggregate.weighted_change_percent)}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>
                        涵蓋 {aggregate.covered} / {aggregate.total} 檔
                    </div>
                </div>

                <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>多空分數</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.breadth_score) }}>
                        {aggregate.breadth_score > 0 ? '+' : ''}{aggregate.breadth_score}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>
                        偏多 {aggregate.bullish}／偏空 {aggregate.bearish}／中性 {aggregate.neutral}
                    </div>
                </div>

                <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>外資對權值股買賣超</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.foreign_net_sum) }}>
                        {aggregate.foreign_net_sum !== null && aggregate.foreign_net_sum !== undefined
                            ? `${formatNumber(aggregate.foreign_net_sum)} 張`
                            : '—'}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>近數日合計</div>
                </div>
            </div>
        </section>
    );
}

/**
 * 對照大盤標的：加權指數、0050、0056。看籃子加權漲跌與指數是否背離，以及
 * 0050（權值）vs 0056（高股息）的風格輪動。
 */
function BenchmarkGrid({ benchmarks }) {
    if (!benchmarks || benchmarks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">對照大盤標的</p>
                    <h2>指數與代表性 ETF</h2>
                </div>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))',
                    gap: '0.75rem',
                }}
            >
                {benchmarks.map((item) => (
                    <div
                        key={item.symbol}
                        style={{
                            border: '1px solid var(--border, rgba(128,128,128,0.25))',
                            borderRadius: '0.75rem',
                            padding: '0.75rem',
                        }}
                    >
                        <div style={{ fontWeight: 600 }}>{item.label}</div>
                        <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>{item.symbol}</div>
                        {item.available ? (
                            <>
                                <div style={{ fontSize: '1.15rem', fontWeight: 700 }}>{formatNumber(item.price)}</div>
                                <div style={{ color: changeColor(item.change_percent), fontWeight: 600 }}>
                                    {formatPercent(item.change_percent)}
                                </div>
                            </>
                        ) : (
                            <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>無法取得</div>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

// 期貨口數：淨多紅、淨空綠，破折號代表無資料。
function OiValue({ value }) {
    if (value === null || value === undefined) {
        return <span style={{ color: 'var(--text-muted, #8a8a8a)' }}>—</span>;
    }

    const num = Number(value);
    const sign = num > 0 ? '+' : '';

    return (
        <span style={{ color: changeColor(num), fontWeight: 600 }}>
            {sign}{num.toLocaleString('zh-TW')} 口
        </span>
    );
}

function FuturesPanel({ futures }) {
    if (!futures || futures.enabled === false) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">台股期貨籌碼</p>
                    <h2>法人期貨/選擇權留倉</h2>
                </div>
            </div>

            {!futures.available ? (
                <p className="field-hint">本次無法取得期貨籌碼（免費資料源或抓取失敗）。</p>
            ) : (
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
                        gap: '0.75rem',
                    }}
                >
                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>台指期近月</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>收盤</dt>
                            <dd style={{ margin: 0 }}>{formatNumber(futures.futures_close)}</dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>未平倉</dt>
                            <dd style={{ margin: 0 }}>{futures.futures_open_interest !== null ? `${Number(futures.futures_open_interest).toLocaleString('zh-TW')} 口` : '—'}</dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>三大法人期貨淨未平倉</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>外資</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.foreign_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>投信</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.trust_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>自營商</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.dealer_net_oi} /></dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>選擇權 Put/Call</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>P/C ratio</dt>
                            <dd style={{ margin: 0, fontWeight: 600 }}>{formatNumber(futures.put_call_ratio)}</dd>
                        </dl>
                    </div>
                </div>
            )}
            <p className="field-hint" style={{ marginTop: '0.6rem' }}>
                期貨淨未平倉為正＝法人淨多、為負＝淨空；P/C &gt; 1 偏空避險。
            </p>
        </section>
    );
}

/**
 * 逐檔權值股資料層：近似權重、報價、規則訊號、技術指標與籌碼摘要。
 */
function StockGrid({ stocks }) {
    if (!stocks || stocks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">權值股拆解</p>
                    <h2>逐檔技術與籌碼（{stocks.length} 檔）</h2>
                </div>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))',
                    gap: '0.75rem',
                }}
            >
                {stocks.map((stock) => {
                    const t = stock.technical ?? {};

                    return (
                        <div
                            key={stock.symbol}
                            style={{
                                border: '1px solid var(--border, rgba(128,128,128,0.25))',
                                borderRadius: '0.75rem',
                                padding: '0.85rem',
                            }}
                        >
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                                <a className="news-symbol-chip" href={`/stocks/search?symbol=${encodeURIComponent(stock.symbol)}`}>
                                    {stock.symbol}
                                </a>
                                <span className={`status-pill status-pill--${stock.stance === 'bullish' ? 'bullish' : stock.stance === 'bearish' ? 'bearish' : 'neutral'}`}>
                                    {stanceLabels[stock.stance] ?? stock.stance}
                                </span>
                            </div>
                            <div style={{ fontSize: '0.8rem', color: 'var(--text-muted, #8a8a8a)', margin: '0.25rem 0' }}>
                                {stock.name}
                                {stock.weight ? <span>（權重約 {formatNumber(stock.weight)}%）</span> : null}
                            </div>

                            {stock.available ? (
                                <>
                                    <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'baseline' }}>
                                        <span style={{ fontSize: '1.1rem', fontWeight: 700 }}>{formatNumber(stock.price)}</span>
                                        <span style={{ color: changeColor(stock.change_percent), fontWeight: 600 }}>
                                            {formatPercent(stock.change_percent)}
                                        </span>
                                    </div>
                                    <dl style={{ fontSize: '0.8rem', margin: '0.5rem 0 0', display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.15rem 0.5rem' }}>
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>KD</dt>
                                        <dd style={{ margin: 0 }}>{formatNumber(t.k)} / {formatNumber(t.d)}</dd>
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>MACD柱</dt>
                                        <dd style={{ margin: 0 }}>{formatNumber(t.macd_histogram)}</dd>
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>MA5/20/60</dt>
                                        <dd style={{ margin: 0 }}>{formatNumber(t.ma5)} / {formatNumber(t.ma20)} / {formatNumber(t.ma60)}</dd>
                                        {t.rsi !== null && t.rsi !== undefined ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>RSI</dt>
                                                <dd style={{ margin: 0 }}>{formatNumber(t.rsi)}</dd>
                                            </>
                                        ) : null}
                                        {stock.chip ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>外資{stock.chip.days}日</dt>
                                                <dd style={{ margin: 0, color: changeColor(stock.chip.foreign_net_sum) }}>
                                                    {formatNumber(stock.chip.foreign_net_sum)} 張
                                                </dd>
                                            </>
                                        ) : null}
                                    </dl>
                                </>
                            ) : (
                                <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>
                                    價格歷史不足，無法計算技術指標。
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function AnalysisReport({ analysis }) {
    if (!analysis) {
        return null;
    }

    if (analysis.status === 'pending') {
        return <PendingAnalysis createdAt={analysis.created_at} model={analysis.model} />;
    }

    const payload = analysis.payload ?? { weights_as_of: '', benchmarks: [], futures: null, stocks: [], aggregate: null };
    const points = analysis.points ?? [];
    const symbols = analysis.related_symbols ?? [];
    const failed = analysis.status === 'failed';

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <article className="analysis-item">
                <div className="analysis-item__head">
                    <span className={`status-pill status-pill--${failed ? 'failed' : 'neutral'}`}>
                        {failed ? 'AI 未完成' : '權值股大盤分析'}
                    </span>
                    <small>
                        {analysis.provider_type} · {analysis.model} · {formatDateTime(analysis.created_at)}
                    </small>
                </div>

                {failed && analysis.failure ? (
                    <FailureNote failure={analysis.failure} />
                ) : (
                    <Markdown>{analysis.summary}</Markdown>
                )}

                {points.length > 0 ? (
                    <ul>
                        {points.map((point, index) => (
                            <li key={index}>{point}</li>
                        ))}
                    </ul>
                ) : null}

                {symbols.length > 0 ? (
                    <div className="news-symbols">
                        {symbols.map((symbol) => (
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

                <p className="field-hint" style={{ marginTop: '0.75rem' }}>
                    以下數據為資料層，即使 AI 分析失敗仍保留。權重為靜態近似值（更新日 {payload.weights_as_of || '未標注'}），
                    僅供加權方向參考，非 0050 實際成分。資料時間：{formatDateTime(analysis.data_as_of)}。本報告僅供研究參考，非投資建議。
                </p>
            </article>

            <AggregatePanel aggregate={payload.aggregate} />
            <BenchmarkGrid benchmarks={payload.benchmarks} />
            <FuturesPanel futures={payload.futures} />
            <StockGrid stocks={payload.stocks} />
        </div>
    );
}

function TriggerPanel({ providers, providerId, onProviderChange, basketSummary }) {
    const form = useForm({
        llm_provider_setting_id: providerId ?? '',
        model: '',
    });

    useEffect(() => {
        form.setData('llm_provider_setting_id', providerId ?? '');
    }, [providerId]);

    const submit = (event) => {
        event.preventDefault();
        form.post('/market/weight-analysis', { preserveScroll: true });
    };

    const count = basketSummary?.count ?? 0;

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">權值股大盤分析</p>
                    <h2>用台灣50前段權值股籃子研判大盤方向</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>

            <p className="field-hint">
                以台灣50前 {count} 大權值股為籃子（權重更新日 {basketSummary?.weights_as_of || '未標注'}），
                聚合逐檔行情與三大法人籌碼，作為大盤先行代理。分析為即時觸發，需要佇列處理程序在執行。
            </p>

            {providers.length === 0 ? (
                <SettingsPrompt />
            ) : (
                <form className="analysis-action" onSubmit={submit}>
                    <ModelPicker onChange={onProviderChange} providers={providers} value={providerId} />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>產生大盤分析</span>
                    </button>
                </form>
            )}
        </section>
    );
}

export default function MarketWeightAnalysis({
    analysis = null,
    llmProviders = [],
    basketSummary = { count: 0, limit: 15, weights_as_of: '', symbols: [] },
}) {
    const providers = llmProviders ?? [];
    const fallback = defaultProvider(providers);

    const [selectedProviderId, setSelectedProviderId] = useState(fallback ? String(fallback.id) : '');

    const hasPending = analysis?.status === 'pending';
    const stalled = useAnalysisPolling(hasPending, ['analysis']);

    return (
        <AppShell title="權值股大盤">
            <section className="news-panel">
                <header className="news-header">
                    <p className="section-kicker">
                        <Layers aria-hidden="true" size={16} /> 權值股籃子大盤分析
                    </p>
                </header>

                {stalled ? (
                    <p className="queue-stalled-hint">
                        分析排隊超過 10 分鐘仍未完成，已停止等待。請確認佇列處理程序有在執行
                        （<code>composer dev</code> 會一併啟動，或另開終端機執行 <code>php artisan queue:work</code>），
                        重新整理後即可看到結果。
                    </p>
                ) : null}

                <TriggerPanel
                    basketSummary={basketSummary}
                    onProviderChange={setSelectedProviderId}
                    providerId={selectedProviderId}
                    providers={providers}
                />

                {analysis ? (
                    <AnalysisReport analysis={analysis} />
                ) : (
                    <p className="news-empty">尚未產生任何權值股大盤分析。設定模型後按上方按鈕即可開始。</p>
                )}
            </section>
        </AppShell>
    );
}
