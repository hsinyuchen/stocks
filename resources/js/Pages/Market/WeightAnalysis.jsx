import { Link, useForm } from '@inertiajs/react';
import { Bot, Layers, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';
import { useI18n } from '../../i18n';

const stanceKeys = {
    bullish: 'weight.stance.bullish',
    bearish: 'weight.stance.bearish',
    neutral: 'weight.stance.neutral',
    insufficient_data: 'weight.stance.insufficientData',
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
    const { t } = useI18n();

    return (
        <p className="news-settings-prompt">
            {t('weight.settingsPromptBefore')}
            {' '}
            <Link href="/settings">{t('weight.settingsLink')}</Link>
            {' '}
            {t('weight.settingsPromptAfter')}
        </p>
    );
}

function ModelPicker({ providers, value, onChange }) {
    const { t } = useI18n();

    if (!providers || providers.length === 0) {
        return null;
    }

    return (
        <label className="form-field">
            <span>{t('weight.modelLabel')}</span>
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
    const { t } = useI18n();

    return (
        <article className="analysis-item analysis-item--pending">
            <div className="analysis-item__head">
                <span className="status-pill status-pill--pending">{t('weight.statusAnalyzing')}</span>
                <small>{model} · {formatDateTime(createdAt)}</small>
            </div>
            <p className="analysis-item__pending-note">
                {t('weight.pendingNote')}
            </p>
        </article>
    );
}

/**
 * 籃子聚合：加權漲跌、多空分數、外資聚合。這是「權值籃子當大盤代理」的核心讀數。
 */
function AggregatePanel({ aggregate }) {
    const { t } = useI18n();

    if (!aggregate) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('weight.aggregateKicker')}</p>
                    <h2>{t('weight.aggregateTitle')}</h2>
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
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>{t('weight.weightedChange')}</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.weighted_change_percent) }}>
                        {formatPercent(aggregate.weighted_change_percent)}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>
                        {t('weight.coverage', { covered: aggregate.covered, total: aggregate.total })}
                    </div>
                </div>

                <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>{t('weight.breadthScore')}</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.breadth_score) }}>
                        {aggregate.breadth_score > 0 ? '+' : ''}{aggregate.breadth_score}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>
                        {t('weight.breadthBreakdown', { bullish: aggregate.bullish, bearish: aggregate.bearish, neutral: aggregate.neutral })}
                    </div>
                </div>

                <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                    <div style={{ color: 'var(--text-muted, #8a8a8a)', fontSize: '0.8rem' }}>{t('weight.foreignNet')}</div>
                    <div style={{ fontSize: '1.3rem', fontWeight: 700, color: changeColor(aggregate.foreign_net_sum) }}>
                        {aggregate.foreign_net_sum !== null && aggregate.foreign_net_sum !== undefined
                            ? t('weight.sharesUnit', { value: formatNumber(aggregate.foreign_net_sum) })
                            : '—'}
                    </div>
                    <div style={{ fontSize: '0.75rem', color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.recentDaysSum')}</div>
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
    const { t } = useI18n();

    if (!benchmarks || benchmarks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('weight.benchmarkKicker')}</p>
                    <h2>{t('weight.benchmarkTitle')}</h2>
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
                            <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>{t('common.unavailable')}</div>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

// 期貨口數：淨多紅、淨空綠，破折號代表無資料。
function OiValue({ value }) {
    const { t } = useI18n();

    if (value === null || value === undefined) {
        return <span style={{ color: 'var(--text-muted, #8a8a8a)' }}>—</span>;
    }

    const num = Number(value);
    const sign = num > 0 ? '+' : '';

    return (
        <span style={{ color: changeColor(num), fontWeight: 600 }}>
            {t('weight.contractsUnit', { value: `${sign}${num.toLocaleString('zh-TW')}` })}
        </span>
    );
}

function FuturesPanel({ futures }) {
    const { t } = useI18n();

    if (!futures || futures.enabled === false) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('weight.futuresKicker')}</p>
                    <h2>{t('weight.futuresTitle')}</h2>
                </div>
            </div>

            {!futures.available ? (
                <p className="field-hint">{t('weight.futuresUnavailable')}</p>
            ) : (
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
                        gap: '0.75rem',
                    }}
                >
                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('weight.futuresNearMonth')}</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.closeLabel')}</dt>
                            <dd style={{ margin: 0 }}>{formatNumber(futures.futures_close)}</dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.openInterest')}</dt>
                            <dd style={{ margin: 0 }}>{futures.futures_open_interest !== null ? t('weight.contractsUnit', { value: Number(futures.futures_open_interest).toLocaleString('zh-TW') }) : '—'}</dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('weight.netOiTitle')}</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.foreign')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.foreign_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.trust')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.trust_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.dealer')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.dealer_net_oi} /></dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('weight.optionPutCall')}</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>P/C ratio</dt>
                            <dd style={{ margin: 0, fontWeight: 600 }}>{formatNumber(futures.put_call_ratio)}</dd>
                        </dl>
                    </div>
                </div>
            )}
            <p className="field-hint" style={{ marginTop: '0.6rem' }}>
                {t('weight.futuresHint')}
            </p>
        </section>
    );
}

/**
 * 逐檔權值股資料層：近似權重、報價、規則訊號、技術指標與籌碼摘要。
 */
function StockGrid({ stocks }) {
    const { t } = useI18n();

    if (!stocks || stocks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('weight.stockGridKicker')}</p>
                    <h2>{t('weight.stockGridTitle', { count: stocks.length })}</h2>
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
                    const tech = stock.technical ?? {};

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
                                    {stanceKeys[stock.stance] ? t(stanceKeys[stock.stance]) : stock.stance}
                                </span>
                            </div>
                            <div style={{ fontSize: '0.8rem', color: 'var(--text-muted, #8a8a8a)', margin: '0.25rem 0' }}>
                                {stock.name}
                                {stock.weight ? <span>{t('weight.weightApprox', { value: formatNumber(stock.weight) })}</span> : null}
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
                                        <dd style={{ margin: 0 }}>{formatNumber(tech.k)} / {formatNumber(tech.d)}</dd>
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.macdHist')}</dt>
                                        <dd style={{ margin: 0 }}>{formatNumber(tech.macd_histogram)}</dd>
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>MA5/20/60</dt>
                                        <dd style={{ margin: 0 }}>{formatNumber(tech.ma5)} / {formatNumber(tech.ma20)} / {formatNumber(tech.ma60)}</dd>
                                        {tech.rsi !== null && tech.rsi !== undefined ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>RSI</dt>
                                                <dd style={{ margin: 0 }}>{formatNumber(tech.rsi)}</dd>
                                            </>
                                        ) : null}
                                        {stock.chip ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('weight.foreignDays', { days: stock.chip.days })}</dt>
                                                <dd style={{ margin: 0, color: changeColor(stock.chip.foreign_net_sum) }}>
                                                    {t('weight.sharesUnit', { value: formatNumber(stock.chip.foreign_net_sum) })}
                                                </dd>
                                            </>
                                        ) : null}
                                    </dl>
                                </>
                            ) : (
                                <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>
                                    {t('weight.insufficientHistory')}
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
    const { t } = useI18n();

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
                        {failed ? t('weight.aiIncomplete') : t('weight.reportBadge')}
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
                    {t('weight.dataLayerNote', {
                        weightsAsOf: payload.weights_as_of || t('weight.notAnnotated'),
                        dataAsOf: formatDateTime(analysis.data_as_of),
                    })}
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
    const { t } = useI18n();

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
                    <p className="section-kicker">{t('weight.triggerKicker')}</p>
                    <h2>{t('weight.triggerTitle')}</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>

            <p className="field-hint">
                {t('weight.triggerHint', {
                    count,
                    weightsAsOf: basketSummary?.weights_as_of || t('weight.notAnnotated'),
                })}
            </p>

            {providers.length === 0 ? (
                <SettingsPrompt />
            ) : (
                <form className="analysis-action" onSubmit={submit}>
                    <ModelPicker onChange={onProviderChange} providers={providers} value={providerId} />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>{t('weight.generateButton')}</span>
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
    const { t } = useI18n();
    const providers = llmProviders ?? [];
    const fallback = defaultProvider(providers);

    const [selectedProviderId, setSelectedProviderId] = useState(fallback ? String(fallback.id) : '');

    const hasPending = analysis?.status === 'pending';
    const stalled = useAnalysisPolling(hasPending, ['analysis']);

    return (
        <AppShell title={t('nav.weightAnalysis')}>
            <section className="news-panel">
                <header className="news-header">
                    <p className="section-kicker">
                        <Layers aria-hidden="true" size={16} /> {t('weight.headerKicker')}
                    </p>
                </header>

                {stalled ? (
                    <p className="queue-stalled-hint">
                        {t('weight.stalledLead')}
                        <code>composer dev</code>
                        {t('weight.stalledMid')}
                        <code>php artisan queue:work</code>
                        {t('weight.stalledTail')}
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
                    <p className="news-empty">{t('weight.emptyReport')}</p>
                )}
            </section>
        </AppShell>
    );
}
