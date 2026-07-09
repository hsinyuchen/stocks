import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Bell, Plus, Trash2 } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

const TYPE_OPTIONS = [
    { value: 'price_above', label: '價格高於' },
    { value: 'price_below', label: '價格低於' },
    { value: 'change_pct_above', label: '單日漲幅高於 (%)' },
    { value: 'change_pct_below', label: '單日跌幅低於 (%)' },
    { value: 'signal', label: '技術訊號' },
];

const TYPE_LABEL = Object.fromEntries(TYPE_OPTIONS.map((option) => [option.value, option.label]));

function describe(alert, signalRules) {
    if (alert.type === 'signal') {
        const rule = signalRules.find((entry) => entry.key === alert.signal_key);

        return `技術訊號：${rule ? rule.label : alert.signal_key}`;
    }

    return `${TYPE_LABEL[alert.type]} ${alert.threshold}`;
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' });
}

function AddAlertForm({ signalRules }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ symbol: '', type: 'price_above', threshold: '', signal_key: signalRules[0]?.key ?? '', note: '' });
    const isSignal = form.data.type === 'signal';

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => (data.type === 'signal'
            ? { symbol: data.symbol, type: data.type, signal_key: data.signal_key, note: data.note }
            : { symbol: data.symbol, type: data.type, threshold: data.threshold, note: data.note }))
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
                <span>新增警報</span>
            </button>
        );
    }

    return (
        <form className="alert-form" onSubmit={submit}>
            <label className="form-field">
                <span>標的</span>
                <input
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="例如 2330.TW、NVDA"
                    type="text"
                    value={form.data.symbol}
                />
                {form.errors.symbol ? <p className="field-error">{form.errors.symbol}</p> : null}
            </label>
            <label className="form-field">
                <span>條件</span>
                <select onChange={(event) => form.setData('type', event.target.value)} value={form.data.type}>
                    {TYPE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </select>
            </label>
            {isSignal ? (
                <label className="form-field">
                    <span>訊號</span>
                    <select onChange={(event) => form.setData('signal_key', event.target.value)} value={form.data.signal_key}>
                        {signalRules.map((rule) => (
                            <option key={rule.key} value={rule.key}>{rule.label}</option>
                        ))}
                    </select>
                    {form.errors.signal_key ? <p className="field-error">{form.errors.signal_key}</p> : null}
                </label>
            ) : (
                <label className="form-field">
                    <span>門檻{form.data.type.startsWith('change_pct') ? '（%，可負）' : ''}</span>
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
                <span>備註（選填）</span>
                <input maxLength="255" onChange={(event) => form.setData('note', event.target.value)} type="text" value={form.data.note} />
            </label>
            <div className="alert-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">新增</button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">取消</button>
            </div>
        </form>
    );
}

function AlertCard({ alert, signalRules, triggered }) {
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
                <Link href={`/stocks/search?symbol=${encodeURIComponent(alert.symbol)}`}>
                    <strong>{alert.symbol}</strong>
                </Link>
                <span className="alert-card__desc">{describe(alert, signalRules)}</span>
                {alert.note ? <small>{alert.note}</small> : null}
                {triggered ? (
                    <small className="alert-card__meta">
                        觸發於 {formatDate(alert.triggered_at)}
                        {alert.triggered_price !== null ? `，價格 ${alert.triggered_price}` : ''}
                    </small>
                ) : null}
            </div>
            <div className="alert-card__actions">
                {triggered ? (
                    <button className="button-secondary" onClick={reactivate} type="button">重新啟用</button>
                ) : null}
                <button disabled={removing} onClick={remove} title="刪除" type="button">
                    <Trash2 aria-hidden="true" size={16} />
                </button>
            </div>
        </article>
    );
}

export default function AlertsIndex({ active = [], triggered = [], signalRules = [] }) {
    return (
        <AppShell title="價格警報">
            <div className="alerts-page">
                <section className="stock-panel alerts-header">
                    <div>
                        <p className="section-kicker">
                            <Bell aria-hidden="true" size={16} /> 警報
                        </p>
                        <h2>價格警報</h2>
                        <p className="field-hint">
                            開啟儀表板或本頁時被動檢查（無背景排程，非即時）。命中後自動停用，可重新啟用。
                        </p>
                    </div>
                    <AddAlertForm signalRules={signalRules} />
                </section>

                {triggered.length > 0 ? (
                    <section className="stock-panel">
                        <p className="section-kicker">已觸發</p>
                        <div className="alert-list">
                            {triggered.map((alert) => (
                                <AlertCard alert={alert} key={alert.id} signalRules={signalRules} triggered />
                            ))}
                        </div>
                    </section>
                ) : null}

                <section className="stock-panel">
                    <p className="section-kicker">監控中</p>
                    {active.length === 0 ? (
                        <p className="dashboard-empty">目前沒有監控中的警報。</p>
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
