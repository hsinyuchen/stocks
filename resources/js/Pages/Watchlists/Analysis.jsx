import { Link, useForm } from '@inertiajs/react';
import { Bot, Moon, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Markdown from '../../Components/Markdown';
import useAnalysisPolling from '../../hooks/useAnalysisPolling';
import { useI18n } from '../../i18n';

const stanceKeys = {
    bullish: 'watchlistAnalysis.stance.bullish',
    bearish: 'watchlistAnalysis.stance.bearish',
    neutral: 'watchlistAnalysis.stance.neutral',
    insufficient_data: 'watchlistAnalysis.stance.insufficientData',
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
    const { t } = useI18n();

    return (
        <p className="news-settings-prompt">
            {t('watchlistAnalysis.settingsPromptBefore')}
            {' '}
            <Link href="/settings">{t('watchlistAnalysis.settingsLink')}</Link>
            {' '}
            {t('watchlistAnalysis.settingsPromptAfter')}
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
            <span>{t('watchlistAnalysis.modelLabel')}</span>
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
                <span className="status-pill status-pill--pending">{t('watchlistAnalysis.statusAnalyzing')}</span>
                <small>{model} · {formatDateTime(createdAt)}</small>
            </div>
            <p className="analysis-item__pending-note">
                {t('watchlistAnalysis.pendingNote')}
            </p>
        </article>
    );
}

/**
 * 國際市場風險溫度：依 group 分區，每格顯示報價與漲跌幅（漲紅跌綠）。
 * 抓取失敗的指標標「無法取得」，不臆測數值。
 */
function BackgroundGrid({ background }) {
    const { t } = useI18n();

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
                    <p className="section-kicker">{t('watchlistAnalysis.backgroundKicker')}</p>
                    <h2>{t('watchlistAnalysis.backgroundTitle')}</h2>
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
                                            {t('common.unavailable')}
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
    const { t } = useI18n();

    if (value === null || value === undefined) {
        return <span style={{ color: 'var(--text-muted, #8a8a8a)' }}>—</span>;
    }

    const num = Number(value);
    const sign = num > 0 ? '+' : '';

    return (
        <span style={{ color: changeColor(num), fontWeight: 600 }}>
            {t('watchlistAnalysis.contractsUnit', { value: `${sign}${num.toLocaleString('zh-TW')}` })}
        </span>
    );
}

/**
 * 台股期貨/選擇權大盤籌碼：台指期未平倉、三大法人期貨淨留倉、選擇權 P/C。
 * 大盤層級訊號，尤其外資期貨淨部位反映法人對隔日大盤方向的押注。
 */
function FuturesPanel({ futures }) {
    const { t } = useI18n();

    if (!futures || futures.enabled === false) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('watchlistAnalysis.futuresKicker')}</p>
                    <h2>{t('watchlistAnalysis.futuresTitle')}</h2>
                </div>
            </div>

            {!futures.available ? (
                <p className="field-hint">{t('watchlistAnalysis.futuresUnavailable')}</p>
            ) : (
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
                        gap: '0.75rem',
                    }}
                >
                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('watchlistAnalysis.futuresNearMonth')}</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.closeLabel')}</dt>
                            <dd style={{ margin: 0 }}>{formatNumber(futures.futures_close)}</dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.openInterest')}</dt>
                            <dd style={{ margin: 0 }}>{futures.futures_open_interest !== null ? t('watchlistAnalysis.contractsUnit', { value: Number(futures.futures_open_interest).toLocaleString('zh-TW') }) : '—'}</dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('watchlistAnalysis.netOiTitle')}</div>
                        <dl style={{ fontSize: '0.85rem', margin: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', gap: '0.2rem 0.5rem' }}>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.foreign')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.foreign_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.trust')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.trust_net_oi} /></dd>
                            <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.dealer')}</dt>
                            <dd style={{ margin: 0 }}><OiValue value={futures.dealer_net_oi} /></dd>
                        </dl>
                    </div>

                    <div style={{ border: '1px solid var(--border, rgba(128,128,128,0.25))', borderRadius: '0.75rem', padding: '0.85rem' }}>
                        <div style={{ fontWeight: 600, marginBottom: '0.4rem' }}>{t('watchlistAnalysis.optionPutCall')}</div>
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
                {t('watchlistAnalysis.futuresHint')}
            </p>
        </section>
    );
}

/**
 * 自選股逐檔資料層：報價、規則訊號、技術指標與籌碼摘要。
 */
function StockGrid({ stocks, omitted }) {
    const { t } = useI18n();

    if (!stocks || stocks.length === 0) {
        return null;
    }

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('watchlistAnalysis.stockGridKicker')}</p>
                    <h2>{t('watchlistAnalysis.stockGridTitle', { count: stocks.length })}</h2>
                </div>
            </div>

            {omitted > 0 ? (
                <p className="field-hint">{t('watchlistAnalysis.omittedNote', { count: omitted })}</p>
            ) : null}

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
                                        <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.macdHist')}</dt>
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
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.foreignDays', { days: stock.chip.days })}</dt>
                                                <dd style={{ margin: 0, color: changeColor(stock.chip.foreign_net_sum) }}>
                                                    {t('watchlistAnalysis.sharesUnit', { value: formatNumber(stock.chip.foreign_net_sum) })}
                                                </dd>
                                            </>
                                        ) : null}
                                        {stock.margin && stock.margin.usage_percent !== null ? (
                                            <>
                                                <dt style={{ color: 'var(--text-muted, #8a8a8a)' }}>{t('watchlistAnalysis.marginUsage')}</dt>
                                                <dd style={{ margin: 0 }}>{formatNumber(stock.margin.usage_percent)}%</dd>
                                            </>
                                        ) : null}
                                    </dl>
                                </>
                            ) : (
                                <div style={{ color: 'var(--text-muted, #8a8a8a)', marginTop: '0.5rem' }}>
                                    {t('watchlistAnalysis.insufficientHistory')}
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

    const payload = analysis.payload ?? { background: [], futures: null, stocks: [], omitted: 0 };
    const points = analysis.points ?? [];
    const symbols = analysis.related_symbols ?? [];
    const failed = analysis.status === 'failed';

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
            <article className="analysis-item">
                <div className="analysis-item__head">
                    <span className={`status-pill status-pill--${failed ? 'failed' : 'neutral'}`}>
                        {failed ? t('watchlistAnalysis.aiIncomplete') : t('watchlistAnalysis.reportBadge')}
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
                    {t('watchlistAnalysis.dataLayerNote', { dataAsOf: formatDateTime(analysis.data_as_of) })}
                </p>
            </article>

            <BackgroundGrid background={payload.background} />
            <FuturesPanel futures={payload.futures} />
            <StockGrid stocks={payload.stocks} omitted={payload.omitted ?? 0} />
        </div>
    );
}

function TriggerPanel({ providers, providerId, onProviderChange, watchlistSummary }) {
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
        form.post('/watchlists/analysis', { preserveScroll: true });
    };

    const count = watchlistSummary?.count ?? 0;

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('watchlistAnalysis.triggerKicker')}</p>
                    <h2>{t('watchlistAnalysis.triggerTitle')}</h2>
                </div>
                <Bot aria-hidden="true" size={22} />
            </div>

            <p className="field-hint">
                {t('watchlistAnalysis.triggerHintBase', { count })}
                {watchlistSummary?.omitted > 0 ? t('watchlistAnalysis.triggerHintOverLimit', { limit: watchlistSummary.limit }) : ''}
                {t('watchlistAnalysis.triggerHintTail')}
            </p>

            {providers.length === 0 ? (
                <SettingsPrompt />
            ) : count === 0 ? (
                <p className="news-settings-prompt">
                    {t('watchlistAnalysis.emptyWatchlistBefore')}
                    {' '}
                    <Link href="/watchlists">{t('watchlistAnalysis.emptyWatchlistLink')}</Link>
                    {' '}
                    {t('watchlistAnalysis.emptyWatchlistAfter')}
                </p>
            ) : (
                <form className="analysis-action" onSubmit={submit}>
                    <ModelPicker onChange={onProviderChange} providers={providers} value={providerId} />
                    <button className="button-secondary" disabled={form.processing} type="submit">
                        <Sparkles aria-hidden="true" size={18} />
                        <span>{t('watchlistAnalysis.generateButton')}</span>
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
    const { t } = useI18n();
    const providers = llmProviders ?? [];
    const fallback = defaultProvider(providers);

    const [selectedProviderId, setSelectedProviderId] = useState(fallback ? String(fallback.id) : '');

    const hasPending = analysis?.status === 'pending';
    const stalled = useAnalysisPolling(hasPending, ['analysis']);

    return (
        <AppShell title={t('nav.watchlistAnalysis')}>
            <section className="news-panel">
                <header className="news-header">
                    <p className="section-kicker">
                        <Moon aria-hidden="true" size={16} /> {t('watchlistAnalysis.headerKicker')}
                    </p>
                </header>

                {stalled ? (
                    <p className="queue-stalled-hint">
                        {t('watchlistAnalysis.stalledLead')}
                        <code>composer dev</code>
                        {t('watchlistAnalysis.stalledMid')}
                        <code>php artisan queue:work</code>
                        {t('watchlistAnalysis.stalledTail')}
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
                    <p className="news-empty">{t('watchlistAnalysis.emptyReport')}</p>
                )}
            </section>
        </AppShell>
    );
}
