import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, Plus, Save, Trash2, X } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';
import { useI18n } from '../../i18n';

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

function TextField({ error, label, ...props }) {
    return (
        <label className="form-field">
            <span>{label}</span>
            <input {...props} />
            <FieldError message={error} />
        </label>
    );
}

function CreateWatchlistForm() {
    const { t } = useI18n();
    const form = useForm({ name: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/watchlists', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form className="watchlist-create" onSubmit={submit}>
            <TextField
                error={form.errors.name}
                label={t('watchlists.createLabel')}
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder={t('watchlists.createPlaceholder')}
                type="text"
                value={form.data.name}
            />
            <button className="button-primary" disabled={form.processing} type="submit">
                <Plus aria-hidden="true" size={18} />
                <span>{t('watchlists.createButton')}</span>
            </button>
        </form>
    );
}

function RenameWatchlistForm({ watchlist }) {
    const { t } = useI18n();
    const form = useForm({ name: watchlist.name });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/watchlists/${watchlist.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <form className="watchlist-title-form" onSubmit={submit}>
            <TextField
                error={form.errors.name}
                label={t('watchlists.nameLabel')}
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <button className="icon-button" disabled={form.processing} title={t('watchlists.saveNameTitle')} type="submit">
                <Save aria-hidden="true" size={18} />
            </button>
        </form>
    );
}

function AddInstrumentForm({ watchlist }) {
    const { t } = useI18n();
    const form = useForm({
        symbol: '',
        name: '',
        market: '',
        note: '',
    });

    const onSelect = (result) => {
        // Carry the current note alongside the chosen stock and submit immediately.
        form.transform((data) => ({
            symbol: result.symbol,
            name: result.name,
            market: result.market,
            note: data.note,
        }));

        form.post(`/watchlists/${watchlist.id}/items`, {
            preserveScroll: true,
            onSuccess: () => form.reset('symbol', 'name', 'market', 'note'),
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <form className="instrument-form" onSubmit={(event) => event.preventDefault()}>
            <div className="form-field">
                <span>{t('watchlists.stockNameOrSymbol')}</span>
                <StockSearchBox market="tw" onSelect={onSelect} />
                <FieldError message={form.errors.symbol} />
                {form.errors.market ? <FieldError message={form.errors.market} /> : null}
            </div>
            <TextField
                error={form.errors.note}
                label={t('watchlists.noteLabel')}
                maxLength="255"
                onChange={(event) => form.setData('note', event.target.value)}
                placeholder={t('watchlists.notePlaceholder')}
                type="text"
                value={form.data.note}
            />
        </form>
    );
}

/**
 * 從自選清單加入投資組合。
 *
 * 不能做成一鍵加入：持倉需要股數與平均成本，沒有這兩個值就算不出損益，
 * 這是它與「加入自選清單」的本質差異——後者只是標記關注，前者是記錄部位。
 *
 * 沿用既有的 POST /portfolio，它已處理代號解析、重複檢查與幣別判定；
 * 另建平行端點只會讓兩套邏輯日後分歧。
 */
function AddToPortfolio({ instrument, held }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({ symbol: instrument.symbol, shares: '', avg_cost: '', note: '' });

    if (held) {
        return <span className="instrument-row__held">{t('watchlists.alreadyInPortfolio')}</span>;
    }

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

    if (! open) {
        return (
            <button className="instrument-row__add" onClick={() => setOpen(true)} type="button">
                <Briefcase aria-hidden="true" size={14} />
                {t('watchlists.addToPortfolio')}
            </button>
        );
    }

    return (
        <form className="instrument-row__portfolio" onSubmit={submit}>
            <label>
                <span>{t('watchlists.shares')}</span>
                <input
                    autoFocus
                    onChange={(event) => form.setData('shares', event.target.value)}
                    placeholder="1000"
                    step="0.0001"
                    type="number"
                    value={form.data.shares}
                />
            </label>
            <label>
                <span>{t('watchlists.avgCost')}</span>
                <input
                    onChange={(event) => form.setData('avg_cost', event.target.value)}
                    placeholder="580.5"
                    step="0.0001"
                    type="number"
                    value={form.data.avg_cost}
                />
            </label>
            <button className="button-secondary" disabled={form.processing} type="submit">
                {form.processing ? t('watchlists.addingInProgress') : t('watchlists.confirmAdd')}
            </button>
            <button onClick={() => { form.reset(); setOpen(false); }} type="button">{t('common.cancel')}</button>
            <FieldError message={form.errors.symbol ?? form.errors.shares ?? form.errors.avg_cost} />
        </form>
    );
}

function InstrumentRow({ item, watchlist, held }) {
    const { t } = useI18n();
    const instrument = item.instrument;

    const remove = () => {
        router.delete(`/watchlists/${watchlist.id}/items/${item.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <article className="instrument-row">
            {/* 只有代號與名稱包進連結，移除鈕留在外面——把整列做成連結會讓
                點擊刪除同時觸發導航，Link 內嵌 button 也是無效的 HTML。 */}
            <Link
                aria-label={t('watchlists.viewAnalysisAria', { symbol: instrument.symbol })}
                className="instrument-row__link"
                href={`/stocks/search?symbol=${encodeURIComponent(instrument.symbol)}`}
            >
                <strong>{instrument.symbol}</strong>
                <span>{instrument.name}</span>
            </Link>
            <div className="instrument-row__meta">
                <span>{instrument.market}</span>
                <span>{instrument.asset_type.toUpperCase()}</span>
                <span>{instrument.currency}</span>
                {instrument.exchange ? <span>{instrument.exchange}</span> : null}
            </div>
            {item.note ? <p>{item.note}</p> : <p className="muted-text">{t('watchlists.noNote')}</p>}
            <AddToPortfolio held={held} instrument={instrument} />
            <button className="icon-button" onClick={remove} title={t('watchlists.removeStockTitle')} type="button">
                <X aria-hidden="true" size={18} />
            </button>
        </article>
    );
}

function WatchlistCard({ watchlist, heldInstrumentIds }) {
    const { t } = useI18n();

    const destroy = () => {
        router.delete(`/watchlists/${watchlist.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <article className="watchlist-card">
            <header className="watchlist-card__header">
                <RenameWatchlistForm watchlist={watchlist} />
                <button className="icon-button icon-button--danger" onClick={destroy} title={t('watchlists.deleteListTitle')} type="button">
                    <Trash2 aria-hidden="true" size={18} />
                </button>
            </header>

            <div className="watchlist-items">
                {watchlist.items.length > 0 ? (
                    watchlist.items.map((item) => (
                        <InstrumentRow
                            held={heldInstrumentIds.includes(item.instrument.id)}
                            item={item}
                            key={item.id}
                            watchlist={watchlist}
                        />
                    ))
                ) : (
                    <div className="empty-state">
                        <strong>{t('watchlists.emptyItemsTitle')}</strong>
                        <span>{t('watchlists.emptyItemsHint')}</span>
                    </div>
                )}
            </div>

            <AddInstrumentForm watchlist={watchlist} />
        </article>
    );
}

export default function WatchlistsIndex({ watchlists = [], heldInstrumentIds = [] }) {
    const { t } = useI18n();

    return (
        <AppShell title={t('nav.watchlists')}>
            <div className="watchlists-page">
                <section className="watchlists-header">
                    <div>
                        <p className="section-kicker">{t('nav.watchlists')}</p>
                        <h2>{t('watchlists.manageByStrategy')}</h2>
                        <p>
                            {t('watchlists.pageDescription')}
                        </p>
                    </div>
                    <CreateWatchlistForm />
                </section>

                <section className="watchlists-stack">
                    {watchlists.length > 0 ? (
                        watchlists.map((watchlist) => (
                            <WatchlistCard
                                heldInstrumentIds={heldInstrumentIds}
                                key={watchlist.id}
                                watchlist={watchlist}
                            />
                        ))
                    ) : (
                        <div className="watchlist-card empty-state">
                            <strong>{t('watchlists.emptyListsTitle')}</strong>
                            <span>{t('watchlists.emptyListsHint')}</span>
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
