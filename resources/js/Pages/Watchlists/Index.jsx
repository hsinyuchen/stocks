import { router, useForm } from '@inertiajs/react';
import { Plus, Save, Trash2, X } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

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
                label="New watchlist"
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder="Core holdings"
                type="text"
                value={form.data.name}
            />
            <button className="button-primary" disabled={form.processing} type="submit">
                <Plus aria-hidden="true" size={18} />
                <span>Create</span>
            </button>
        </form>
    );
}

function RenameWatchlistForm({ watchlist }) {
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
                label="Watchlist name"
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <button className="icon-button" disabled={form.processing} title="Save name" type="submit">
                <Save aria-hidden="true" size={18} />
            </button>
        </form>
    );
}

function AddInstrumentForm({ watchlist }) {
    const form = useForm({
        symbol: '',
        note: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/watchlists/${watchlist.id}/items`, {
            preserveScroll: true,
            onSuccess: () => form.reset('symbol', 'note'),
        });
    };

    return (
        <form className="instrument-form" onSubmit={submit}>
            <div className="instrument-form__grid instrument-form__grid--compact">
                <TextField
                    error={form.errors.symbol}
                    label="Existing symbol"
                    maxLength="32"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="AAPL"
                    type="text"
                    value={form.data.symbol}
                />
                <button className="button-secondary" disabled={form.processing} type="submit">
                    <Plus aria-hidden="true" size={18} />
                    <span>Add stock</span>
                </button>
            </div>
            <TextField
                error={form.errors.note}
                label="Note"
                maxLength="255"
                onChange={(event) => form.setData('note', event.target.value)}
                placeholder="Earnings, valuation, or thesis note"
                type="text"
                value={form.data.note}
            />
        </form>
    );
}

function InstrumentRow({ item, watchlist }) {
    const instrument = item.instrument;

    const remove = () => {
        router.delete(`/watchlists/${watchlist.id}/items/${item.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <article className="instrument-row">
            <div>
                <strong>{instrument.symbol}</strong>
                <span>{instrument.name}</span>
            </div>
            <div className="instrument-row__meta">
                <span>{instrument.market}</span>
                <span>{instrument.asset_type.toUpperCase()}</span>
                <span>{instrument.currency}</span>
                {instrument.exchange ? <span>{instrument.exchange}</span> : null}
            </div>
            {item.note ? <p>{item.note}</p> : <p className="muted-text">No note</p>}
            <button className="icon-button" onClick={remove} title="Remove stock" type="button">
                <X aria-hidden="true" size={18} />
            </button>
        </article>
    );
}

function WatchlistCard({ watchlist }) {
    const destroy = () => {
        router.delete(`/watchlists/${watchlist.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <article className="watchlist-card">
            <header className="watchlist-card__header">
                <RenameWatchlistForm watchlist={watchlist} />
                <button className="icon-button icon-button--danger" onClick={destroy} title="Delete watchlist" type="button">
                    <Trash2 aria-hidden="true" size={18} />
                </button>
            </header>

            <div className="watchlist-items">
                {watchlist.items.length > 0 ? (
                    watchlist.items.map((item) => (
                        <InstrumentRow item={item} key={item.id} watchlist={watchlist} />
                    ))
                ) : (
                    <div className="empty-state">
                        <strong>No stocks yet</strong>
                        <span>Add the first symbol to start tracking this list.</span>
                    </div>
                )}
            </div>

            <AddInstrumentForm watchlist={watchlist} />
        </article>
    );
}

export default function WatchlistsIndex({ watchlists = [] }) {
    return (
        <AppShell title="Watchlists">
            <div className="watchlists-page">
                <section className="watchlists-header">
                    <div>
                        <p className="section-kicker">Watchlists</p>
                        <h2>Track symbols by strategy</h2>
                        <p>
                            Manage personal lists, add known instruments, and keep short notes for follow-up analysis.
                        </p>
                    </div>
                    <CreateWatchlistForm />
                </section>

                <section className="watchlists-stack">
                    {watchlists.length > 0 ? (
                        watchlists.map((watchlist) => (
                            <WatchlistCard
                                key={watchlist.id}
                                watchlist={watchlist}
                            />
                        ))
                    ) : (
                        <div className="watchlist-card empty-state">
                            <strong>No watchlists</strong>
                            <span>Create a watchlist to group stocks by market, thesis, or review cadence.</span>
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
