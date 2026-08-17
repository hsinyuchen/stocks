import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, Plus, Trash2 } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

// 值 → i18n key 映射；render 端以 t(labelKey) 取當前語言字串。
const TYPE_OPTIONS = [
    { value: 'price_above', labelKey: 'alerts.typePriceAbove' },
    { value: 'price_below', labelKey: 'alerts.typePriceBelow' },
    { value: 'change_pct_above', labelKey: 'alerts.typeChangePctAbove' },
    { value: 'change_pct_below', labelKey: 'alerts.typeChangePctBelow' },
    { value: 'signal', labelKey: 'alerts.typeSignal' },
    { value: 'market_futures_flip', labelKey: 'alerts.typeMarketFuturesFlip' },
    { value: 'market_bearish_flip', labelKey: 'alerts.typeMarketBearishFlip' },
];

const TYPE_LABEL_KEY = Object.fromEntries(TYPE_OPTIONS.map((option) => [option.value, option.labelKey]));

const MARKET_TYPES = ['market_futures_flip', 'market_bearish_flip'];

const MARKET_TITLE_KEY = {
    market_futures_flip: 'alerts.marketTitleFutures',
    market_bearish_flip: 'alerts.marketTitleBearish',
};

const MARKET_HINT_KEY = {
    market_futures_flip: 'alerts.marketHintFutures',
    market_bearish_flip: 'alerts.marketHintBearish',
};

function describe(alert, signalRules, t) {
    if (alert.type === 'market_futures_flip') {
        return t('alerts.describeFuturesFlip');
    }

    if (alert.type === 'market_bearish_flip') {
        return t('alerts.describeBearishFlip');
    }

    if (alert.type === 'signal') {
        const rule = signalRules.find((entry) => entry.key === alert.signal_key);

        return t('alerts.describeSignal', { name: rule ? rule.label : alert.signal_key });
    }

    return `${t(TYPE_LABEL_KEY[alert.type])} ${alert.threshold}`;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' });
}

function AddAlertForm({ signalRules }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({ symbol: '', type: 'price_above', threshold: '', signal_key: signalRules[0]?.key ?? '', note: '' });
    const isSignal = form.data.type === 'signal';
    const isMarket = MARKET_TYPES.includes(form.data.type);

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => {
            if (MARKET_TYPES.includes(data.type)) {
                return { type: data.type, note: data.note };
            }

            return data.type === 'signal'
                ? { symbol: data.symbol, type: data.type, signal_key: data.signal_key, note: data.note }
                : { symbol: data.symbol, type: data.type, threshold: data.threshold, note: data.note };
        })
            .post('/alerts', {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    setOpen(false);
                },
            });
    };

    if (!open) {
        return (
            <button className="button-primary" onClick={() => setOpen(true)} type="button">
                <Plus aria-hidden="true" size={18} />
                <span>{t('alerts.addAlert')}</span>
            </button>
        );
    }

    return (
        <form className="alert-form" onSubmit={submit}>
            {isMarket ? null : (
                <label className="form-field">
                    <span>{t('alerts.fieldSymbol')}</span>
                    <input
                        onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                        placeholder={t('alerts.symbolPlaceholder')}
                        type="text"
                        value={form.data.symbol}
                    />
                    {form.errors.symbol ? <p className="field-error">{form.errors.symbol}</p> : null}
                </label>
            )}
            <label className="form-field">
                <span>{t('alerts.fieldType')}</span>
                <select onChange={(event) => form.setData('type', event.target.value)} value={form.data.type}>
                    {TYPE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{t(option.labelKey)}</option>
                    ))}
                </select>
                {form.errors.type ? <p className="field-error">{form.errors.type}</p> : null}
            </label>
            {isMarket ? (
                <p className="field-hint">{t(MARKET_HINT_KEY[form.data.type])}</p>
            ) : isSignal ? (
                <label className="form-field">
                    <span>{t('alerts.fieldSignal')}</span>
                    <select onChange={(event) => form.setData('signal_key', event.target.value)} value={form.data.signal_key}>
                        {signalRules.map((rule) => (
                            <option key={rule.key} value={rule.key}>{rule.label}</option>
                        ))}
                    </select>
                    {form.errors.signal_key ? <p className="field-error">{form.errors.signal_key}</p> : null}
                </label>
            ) : (
                <label className="form-field">
                    <span>{t('alerts.fieldThreshold')}{form.data.type.startsWith('change_pct') ? t('alerts.thresholdPctSuffix') : ''}</span>
                    <input
                        onChange={(event) => form.setData('threshold', event.target.value)}
                        step="any"
                        type="number"
                        value={form.data.threshold}
                    />
                    {form.errors.threshold ? <p className="field-error">{form.errors.threshold}</p> : null}
                </label>
            )}
            <label className="form-field">
                <span>{t('alerts.fieldNote')}</span>
                <input maxLength="255" onChange={(event) => form.setData('note', event.target.value)} type="text" value={form.data.note} />
            </label>
            <div className="alert-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">{t('alerts.submitAdd')}</button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">{t('common.cancel')}</button>
            </div>
        </form>
    );
}

function AlertCard({ alert, signalRules, triggered }) {
    const { t } = useI18n();
    const [removing, setRemoving] = useState(false);

    const remove = () => {
        if (removing) {
            return;
        }

        setRemoving(true);
        router.delete(`/alerts/${alert.id}`, { preserveScroll: true, onFinish: () => setRemoving(false) });
    };

    const reactivate = () => {
        router.patch(`/alerts/${alert.id}/reactivate`, {}, { preserveScroll: true });
    };

    return (
        <article className={`alert-card${triggered ? ' alert-card--triggered' : ''}`}>
            <div>
                {alert.scope === 'market' ? (
                    <strong>{t(MARKET_TITLE_KEY[alert.type] ?? 'alerts.marketTitleFallback')}</strong>
                ) : (
                    <Link href={`/stocks/search?symbol=${encodeURIComponent(alert.symbol)}`}>
                        <strong>{alert.symbol}</strong>
                    </Link>
                )}
                <span className="alert-card__desc">{describe(alert, signalRules, t)}</span>
                {alert.note ? <small>{alert.note}</small> : null}
                {triggered ? (
                    <small className="alert-card__meta">
                        {t('alerts.triggeredAt', { time: formatDate(alert.triggered_at) })}
                        {alert.triggered_price !== null ? t('alerts.triggeredPrice', { price: alert.triggered_price }) : ''}
                    </small>
                ) : null}
            </div>
            <div className="alert-card__actions">
                {triggered ? (
                    <button className="button-secondary" onClick={reactivate} type="button">{t('alerts.reactivate')}</button>
                ) : null}
                <button disabled={removing} onClick={remove} title={t('common.delete')} type="button">
                    <Trash2 aria-hidden="true" size={16} />
                </button>
            </div>
        </article>
    );
}

export default function AlertsIndex({ active = [], triggered = [], signalRules = [] }) {
    const { t } = useI18n();

    return (
        <AppShell title={t('alerts.title')}>
            <div className="alerts-page">
                <section className="stock-panel alerts-header">
                    <div>
                        <p className="section-kicker">
                            <Bell aria-hidden="true" size={16} /> {t('alerts.kicker')}
                        </p>
                        <h2>{t('alerts.heading')}</h2>
                        <p className="field-hint">
                            {t('alerts.description')}
                        </p>
                    </div>
                    <AddAlertForm signalRules={signalRules} />
                </section>

                {triggered.length > 0 ? (
                    <section className="stock-panel">
                        <p className="section-kicker">{t('alerts.triggeredSection')}</p>
                        <div className="alert-list">
                            {triggered.map((alert) => (
                                <AlertCard alert={alert} key={alert.id} signalRules={signalRules} triggered />
                            ))}
                        </div>
                    </section>
                ) : null}

                <section className="stock-panel">
                    <p className="section-kicker">{t('alerts.activeSection')}</p>
                    {active.length === 0 ? (
                        <p className="dashboard-empty">{t('alerts.emptyActive')}</p>
                    ) : (
                        <div className="alert-list">
                            {active.map((alert) => (
                                <AlertCard alert={alert} key={alert.id} signalRules={signalRules} triggered={false} />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
