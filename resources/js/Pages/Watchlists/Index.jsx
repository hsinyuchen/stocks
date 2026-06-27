import { router, useForm } from '@inertiajs/react';
import { Plus, Save, Trash2, X } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';

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
                label="新增清單"
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder="例如：核心持股"
                type="text"
                value={form.data.name}
            />
            <button className="button-primary" disabled={form.processing} type="submit">
                <Plus aria-hidden="true" size={18} />
                <span>建立</span>
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
                label="清單名稱"
                maxLength="80"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <button className="icon-button" disabled={form.processing} title="儲存名稱" type="submit">
                <Save aria-hidden="true" size={18} />
            </button>
        </form>
    );
}

function AddInstrumentForm({ watchlist }) {
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
                <span>股票名稱或代號</span>
                <StockSearchBox market="tw" onSelect={onSelect} />
                <FieldError message={form.errors.symbol} />
                {form.errors.market ? <FieldError message={form.errors.market} /> : null}
            </div>
            <TextField
                error={form.errors.note}
                label="備註"
                maxLength="255"
                onChange={(event) => form.setData('note', event.target.value)}
                placeholder="財報、估值、追蹤理由或風險提醒"
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
            {item.note ? <p>{item.note}</p> : <p className="muted-text">尚無備註</p>}
            <button className="icon-button" onClick={remove} title="移除股票" type="button">
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
                <button className="icon-button icon-button--danger" onClick={destroy} title="刪除清單" type="button">
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
                        <strong>尚未加入股票</strong>
                        <span>加入第一個股票代號，開始追蹤這份清單。</span>
                    </div>
                )}
            </div>

            <AddInstrumentForm watchlist={watchlist} />
        </article>
    );
}

export default function WatchlistsIndex({ watchlists = [] }) {
    return (
        <AppShell title="自選清單">
            <div className="watchlists-page">
                <section className="watchlists-header">
                    <div>
                        <p className="section-kicker">自選清單</p>
                        <h2>依策略管理追蹤標的</h2>
                        <p>
                            建立個人化清單，加入已建立的股票標的，並用簡短備註記錄後續分析重點。
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
                            <strong>尚無自選清單</strong>
                            <span>建立清單後，可依市場、投資假設或檢查週期分組追蹤股票。</span>
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}