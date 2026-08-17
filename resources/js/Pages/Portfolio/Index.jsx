import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2, Wallet } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';
import { useI18n } from '../../i18n';

/** 金額千分位；null（無報價）顯示佔位符。 */
function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** 股數格式：碎股（如 0.0001 股）不能被 2 位小數金額格式器截成 0.00，
 *  故最多顯示 4 位、尾零去除，保留真實持有量。 */
function shares(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString('zh-TW', { maximumFractionDigits: 4 });
}

function percent(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    const num = Number(value);

    return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
}

function changeClass(value) {
    if (value === null || value === undefined || Number(value) === 0) {
        return '';
    }

    return Number(value) > 0 ? 'is-up' : 'is-down';
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-TW', { dateStyle: 'short', timeStyle: 'short' });
}

function AddHoldingForm() {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({ symbol: '', shares: '', avg_cost: '', note: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/portfolio', {
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
                <span>{t('portfolio.addHolding')}</span>
            </button>
        );
    }

    // StockSearchBox 內是 <input type="search">，本 form 又有 default submit button，
    // 依 HTML implicit submission 規則在搜尋框按 Enter 會直接送出新增持倉。
    // 搜尋框的 Enter 應留給搜尋（由 onSelect 回填 symbol），故在此攔截。
    const onKeyDown = (event) => {
        if (event.key === 'Enter' && event.target.type === 'search') {
            event.preventDefault();
        }
    };

    return (
        <form className="portfolio-form" onKeyDown={onKeyDown} onSubmit={submit}>
            {/* 標的欄含兩個輸入（搜尋框、直接輸入代號），故用 div 而非 label：
                label 只能綁定單一控制項，包住兩者會讓點擊標題聚焦到錯的輸入框。 */}
            <div className="form-field">
                <span>{t('portfolio.symbolLabel')}</span>
                <StockSearchBox onSelect={(result) => form.setData('symbol', result.symbol)} />
                <input
                    aria-label={t('portfolio.symbolAria')}
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder={t('portfolio.symbolPlaceholder')}
                    type="text"
                    value={form.data.symbol}
                />
                {form.errors.symbol ? <p className="field-error">{form.errors.symbol}</p> : null}
            </div>
            <label className="form-field">
                <span>{t('portfolio.sharesLabel')}</span>
                <input
                    onChange={(event) => form.setData('shares', event.target.value)}
                    step="any"
                    type="number"
                    value={form.data.shares}
                />
                {form.errors.shares ? <p className="field-error">{form.errors.shares}</p> : null}
            </label>
            <label className="form-field">
                <span>{t('portfolio.avgCostPerShareLabel')}</span>
                <input
                    onChange={(event) => form.setData('avg_cost', event.target.value)}
                    step="any"
                    type="number"
                    value={form.data.avg_cost}
                />
                {form.errors.avg_cost ? <p className="field-error">{form.errors.avg_cost}</p> : null}
            </label>
            <label className="form-field">
                <span>{t('portfolio.noteOptionalLabel')}</span>
                <input
                    maxLength="255"
                    onChange={(event) => form.setData('note', event.target.value)}
                    type="text"
                    value={form.data.note}
                />
            </label>
            <div className="portfolio-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">{t('portfolio.add')}</button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">{t('common.cancel')}</button>
            </div>
        </form>
    );
}

function HoldingRow({ holding }) {
    const { t } = useI18n();
    const [editing, setEditing] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [pendingRemove, setPendingRemove] = useState(false);
    const form = useForm({
        shares: holding.shares,
        avg_cost: holding.avg_cost,
        note: holding.note ?? '',
    });

    const save = (event) => {
        event.preventDefault();
        form.patch(`/portfolio/${holding.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    // holdings 無 soft delete，刪除不可復原，故要求行內二次確認（沿用 Admin/Users 的 pendingAction 模式）。
    const remove = () => {
        if (removing) {
            return;
        }

        setRemoving(true);
        router.delete(`/portfolio/${holding.id}`, {
            preserveScroll: true,
            onFinish: () => {
                setRemoving(false);
                setPendingRemove(false);
            },
        });
    };

    if (editing) {
        return (
            <tr>
                <td colSpan={10}>
                    <form className="portfolio-form" onSubmit={save}>
                        <label className="form-field">
                            <span>{t('portfolio.sharesLabel')}</span>
                            <input onChange={(e) => form.setData('shares', e.target.value)} step="any" type="number" value={form.data.shares} />
                            {form.errors.shares ? <p className="field-error">{form.errors.shares}</p> : null}
                        </label>
                        <label className="form-field">
                            <span>{t('portfolio.avgCostLabel')}</span>
                            <input onChange={(e) => form.setData('avg_cost', e.target.value)} step="any" type="number" value={form.data.avg_cost} />
                            {form.errors.avg_cost ? <p className="field-error">{form.errors.avg_cost}</p> : null}
                        </label>
                        <label className="form-field">
                            <span>{t('portfolio.noteLabel')}</span>
                            <input maxLength="255" onChange={(e) => form.setData('note', e.target.value)} type="text" value={form.data.note} />
                        </label>
                        <div className="portfolio-form__actions">
                            <button className="button-primary" disabled={form.processing} type="submit">{t('common.save')}</button>
                            <button className="button-secondary" onClick={() => setEditing(false)} type="button">{t('common.cancel')}</button>
                        </div>
                    </form>
                </td>
            </tr>
        );
    }

    return (
        <tr>
            <td>
                <Link href={`/stocks/search?symbol=${encodeURIComponent(holding.symbol)}`}>
                    <strong>{holding.symbol}</strong>
                </Link>
                <br />
                <small>{holding.name}</small>
            </td>
            <td>{shares(holding.shares)}</td>
            <td>{money(holding.avg_cost)}</td>
            <td>{holding.price === null ? <span className="field-hint">{t('portfolio.quoteUnavailable')}</span> : money(holding.price)}</td>
            <td>{money(holding.market_value)}</td>
            <td className={changeClass(holding.unrealized_pnl)}>{money(holding.unrealized_pnl)}</td>
            <td className={changeClass(holding.return_pct)}>{percent(holding.return_pct)}</td>
            <td><small>{formatDate(holding.as_of)}</small></td>
            <td><small>{holding.note ?? '—'}</small></td>
            <td className="portfolio-actions">
                {pendingRemove ? (
                    <>
                        <button className="button-danger" disabled={removing} onClick={remove} type="button">{t('portfolio.confirmDelete')}</button>
                        <button className="button-secondary" disabled={removing} onClick={() => setPendingRemove(false)} type="button">{t('common.cancel')}</button>
                    </>
                ) : (
                    <>
                        <button onClick={() => setEditing(true)} title={t('portfolio.edit')} type="button">{t('portfolio.edit')}</button>
                        <button onClick={() => setPendingRemove(true)} title={t('common.delete')} type="button">
                            <Trash2 aria-hidden="true" size={16} />
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

function CurrencyGroup({ group, unavailableCount }) {
    const { t } = useI18n();
    const { subtotal } = group;

    return (
        <section className="stock-panel portfolio-group">
            <header className="portfolio-group__header">
                <div>
                    <p className="section-kicker">{t('portfolio.pricedIn', { currency: group.currency })}</p>
                    <h2>
                        {t('portfolio.marketValueLabel', { value: money(subtotal.market_value) })}
                        {' '}
                        <span className={changeClass(subtotal.unrealized_pnl)}>
                            （{money(subtotal.unrealized_pnl)}／{percent(subtotal.return_pct)}）
                        </span>
                    </h2>
                    <p className="field-hint">{t('portfolio.costBasisLabel', { value: money(subtotal.cost_basis) })}</p>
                </div>
            </header>

            {unavailableCount > 0 ? (
                <p className="field-hint">{t('portfolio.unavailableCount', { count: unavailableCount })}</p>
            ) : null}

            <div className="portfolio-table-wrap">
                <table className="portfolio-table">
                    <thead>
                        <tr>
                            <th>{t('portfolio.symbolLabel')}</th>
                            <th>{t('portfolio.sharesLabel')}</th>
                            <th>{t('portfolio.thAvgPrice')}</th>
                            <th>{t('portfolio.thCurrentPrice')}</th>
                            <th>{t('portfolio.thMarketValue')}</th>
                            <th>{t('portfolio.thUnrealizedPnl')}</th>
                            <th>{t('portfolio.thReturn')}</th>
                            <th>{t('portfolio.thAsOf')}</th>
                            <th>{t('portfolio.noteLabel')}</th>
                            <th>{t('portfolio.thActions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {group.holdings.map((holding) => (
                            <HoldingRow holding={holding} key={holding.id} />
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function PortfolioIndex({ groups = [], unavailable = [] }) {
    const { t } = useI18n();
    const unavailableSymbols = new Set(unavailable.map((entry) => entry.symbol));

    return (
        <AppShell title={t('portfolio.pageTitle')}>
            <div className="portfolio-page">
                <section className="stock-panel portfolio-header">
                    <div>
                        <p className="section-kicker">
                            <Wallet aria-hidden="true" size={16} /> {t('portfolio.holdingsKicker')}
                        </p>
                        <h2>{t('portfolio.portfolioPnlTitle')}</h2>
                        <p className="field-hint">
                            {t('portfolio.portfolioNote')}
                        </p>
                    </div>
                    <AddHoldingForm />
                </section>

                {groups.length === 0 ? (
                    <section className="stock-panel empty-state">
                        <strong>{t('portfolio.emptyTitle')}</strong>
                        <span>{t('portfolio.emptyDesc')}</span>
                    </section>
                ) : (
                    groups.map((group) => (
                        <CurrencyGroup
                            group={group}
                            key={group.currency}
                            unavailableCount={group.holdings.filter((h) => unavailableSymbols.has(h.symbol)).length}
                        />
                    ))
                )}
            </div>
        </AppShell>
    );
}
