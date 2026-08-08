import { Link, useForm } from '@inertiajs/react';
import { Bot, Moon, Sparkles } from 'lucide-react';
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

// 台股慣例：漲紅、跌綠。背景含美股指數，但本報告以台股視角撰寫，統一沿用台股配色。
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
            新增一個模型，才能產生晚間快報。
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
                已排入佇列，正在彙整國際市場與自選股資料，完成後會自動顯示。
            </p>
        </article>
    );
}

/**
 * 國際市場風險溫度：依 group 分區，每格顯示報價與漲跌幅（漲紅跌綠）。
 * 抓取失敗的指標標「無法取得」，不臆測數值。
 */
function BackgroundGrid({ background }) {
    if (!background || background.length === 0) {
        return null;
    }

    const groups = [];
    const byKey = new Map();

    background.forEach((item) => {
        const key = item.group || 'other';

        if (!byKey.has(key)) {
            const bucket = { key, label: item.group_label || key, items: [] };
            byKey.set(key, bucket);
            groups.push(bucket);
        }

        byKey.get(key).items.push(item);
    });

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">國際市場訊號</p>
                    <h2>風險溫度</h2>
                </div>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                {groups.map((group) => (
                    <div key={group.key}>
                        <p className="section-kicker" style={{ marginBottom: '0.5rem' }}>{group.label}</p>
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fill, minmax(150px, 1fr))',
                                gap: '0.75rem',
                            }}
                        >
                            {group.items.map((item) => (
                                <div
                                    key={item.symbol}
                                    style={{
                                        border: '1px solid var(--border, rgba(128,128,128,0.25))',
                                        borderRadius: '0.75rem',
                                        padding: '0.75rem',
                                    }}
                                >
                                    <div style={{ fontWeight: 600 }}>{item.label}</div>
                                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>
                                        {item.symbol}
                                    </div>
                                    {item.available ? (
                                        <>
                                            <div style={{ fontSize: '1.15rem', fontWeight: 700 }}>
                                                {formatNumber(item.price)}
                                            </div>
                                            <div style={{ color: changeColor(item.change_percent), fontWeight: 600 }}>
                                                {formatPercent(item.change_percent)}
                                            </div>
                                        </>
                                    ) : (
                                        <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>
                                            無法取得
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

// 期貨口數：淨多紅、淨空綠（沿用台股漲跌配色），破折號代表無資料。
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

/**
 * 台股期貨/選擇權大盤籌碼：台指期未平倉、三大法人期貨淨留倉、選擇權 P/C。
 * 大盤層級訊號，尤其外資期貨淨部位反映法人對隔日大盤方向的押注。
 */
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
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>Put/Call OI</dt>
                            <dd style={{ margin: 0 }}>
                                {futures.option_put_oi !== null ? Number(futures.option_put_oi).toLocaleString('zh-TW') : '—'}
                                {' / '}
                                {futures.option_call_oi !== null ? Number(futures.option_call_oi).toLocaleString('zh-TW') : '—'}
                            </dd>
                        </dl>
                    </div>
                </div>
            )}
            <p className="field-hint" style={{ marginTop: '0.6rem' }}>
                期貨淨未平倉為正＝法人淨多、為負＝淨空；P/C &gt; 1 偏空避險。大額交易人與選擇權 VIX 需 FinMind 贊助等級，未納入。
            </p>
        </section>
    );
}

/**
 * 自選股逐檔資料層：報價、規則訊號、技術指標與籌碼摘要。
 */
function StockGrid({ stocks, omitted }) {
    if (!stocks || stocks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">自選股拆解</p>
                    <h2>逐檔技術與籌碼（{stocks.length} 檔）</h2>
                </div>
            </div>

            {omitted > 0 ? (
                <p className="field-hint">另有 {omitted} 檔因超過上限未納入本次分析。</p>
            ) : null}

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
                                        {stock.margin && stock.margin.usage_percent !== null ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>融資使用率</dt>
                                                <dd style={{ margin: 0 }}>{formatNumber(stock.margin.usage_percent)}%</dd>
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

    const payload = analysis.payload ?? { background: [], futures: null, stocks: [], omitted: 0 };
    const points = analysis.points ?? [];
    const symbols = analysis.related_symbols ?? [];
    const failed = analysis.status === 'failed';

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <article className="analysis-item">
                <div className="analysis-item__head">
                    <span className={`status-pill status-pill--${failed ? 'failed' : 'neutral'}`}>
                        {failed ? 'AI 未完成' : '晚間快報'}
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
                    以下數據為資料層，即使 AI 分析失敗仍保留。資料時間：{formatDateTime(analysis.data_as_of)}。
                    本報告僅供研究參考，非投資建議。
                </p>
            </article>

            <BackgroundGrid background={payload.background} />
            <FuturesPanel futures={payload.futures} />
            <StockGrid stocks={payload.stocks} omitted={payload.omitted ?? 0} />
        </div>
    );
}

function TriggerPanel({ providers, providerId, onProviderChange, watchlistSummary }) {
    const form = useForm({
        llm_provider_setting_id: providerId ?? '',
        model: '',
    });

    useEffect(() => {
        form.setData('llm_provider_setting_id', providerId ?? '');
    }, [providerId]);

    const submit = (event) => {
        event.preventDefault();
        form.post('/watchlists/analysis', { preserveScroll: true });
    };

    const count = watchlistSummary?.count ?? 0;

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">自選股晚間快報</p>
                    <h2>用美股風險溫度校準台股隔日劇本</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>

            <p className="field-hint">
                合併你全部自選清單去重後共 {count} 檔
                {watchlistSummary?.omitted > 0 ? `（超過上限，將分析前 ${watchlistSummary.limit} 檔）` : ''}
                。分析為即時觸發，需要佇列處理程序在執行。
            </p>

            {providers.length === 0 ? (
                <SettingsPrompt />
            ) : count === 0 ? (
                <p className="news-settings-prompt">
                    自選清單是空的。請先到
                    {' '}
                    <Link href="/watchlists">自選清單</Link>
                    {' '}
                    加入股票。
                </p>
            ) : (
                <form className="analysis-action" onSubmit={submit}>
                    <ModelPicker onChange={onProviderChange} providers={providers} value={providerId} />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>產生晚間快報</span>
                    </button>
                </form>
            )}
        </section>
    );
}

export default function WatchlistAnalysis({
    analysis = null,
    llmProviders = [],
    watchlistSummary = { count: 0, limit: 30, symbols: [], omitted: 0 },
}) {
    const providers = llmProviders ?? [];
    const fallback = defaultProvider(providers);

    const [selectedProviderId, setSelectedProviderId] = useState(fallback ? String(fallback.id) : '');

    const hasPending = analysis?.status === 'pending';
    const stalled = useAnalysisPolling(hasPending, ['analysis']);

    return (
        <AppShell title="自選快報">
            <section className="news-panel">
                <header className="news-header">
                    <p className="section-kicker">
                        <Moon aria-hidden="true" size={16} /> 自選股總體分析
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
                    onProviderChange={setSelectedProviderId}
                    providerId={selectedProviderId}
                    providers={providers}
                    watchlistSummary={watchlistSummary}
                />

                {analysis ? (
                    <AnalysisReport analysis={analysis} />
                ) : (
                    <p className="news-empty">尚未產生任何晚間快報。設定模型後按上方按鈕即可開始。</p>
                )}
            </section>
        </AppShell>
    );
}
