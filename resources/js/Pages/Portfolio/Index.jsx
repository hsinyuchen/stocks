import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Trash2, Wallet } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import StockSearchBox from '../../Components/StockSearchBox';

/** 金額千分位；null（無報價）顯示佔位符。 */
function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return Number(value).toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
                <span>新增持倉</span>
            </button>
        );
    }

    return (
        <form className="portfolio-form" onSubmit={submit}>
            {/* 標的欄含兩個輸入（搜尋框、直接輸入代號），故用 div 而非 label：
                label 只能綁定單一控制項，包住兩者會讓點擊標題聚焦到錯的輸入框。 */}
            <div className="form-field">
                <span>標的</span>
                <StockSearchBox onSelect={(result) => form.setData('symbol', result.symbol)} />
                <input
                    aria-label="股票代號"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="或直接輸入代號，例如 2330.TW、NVDA"
                    type="text"
                    value={form.data.symbol}
                />
                {form.errors.symbol ? <p className="field-error">{form.errors.symbol}</p> : null}
            </div>
            <label className="form-field">
                <span>股數</span>
                <input
                    onChange={(event) => form.setData('shares', event.target.value)}
                    step="any"
                    type="number"
                    value={form.data.shares}
                />
                {form.errors.shares ? <p className="field-error">{form.errors.shares}</p> : null}
            </label>
            <label className="form-field">
                <span>平均成本（每股）</span>
                <input
                    onChange={(event) => form.setData('avg_cost', event.target.value)}
                    step="any"
                    type="number"
                    value={form.data.avg_cost}
                />
                {form.errors.avg_cost ? <p className="field-error">{form.errors.avg_cost}</p> : null}
            </label>
            <label className="form-field">
                <span>備註（選填）</span>
                <input
                    maxLength="255"
                    onChange={(event) => form.setData('note', event.target.value)}
                    type="text"
                    value={form.data.note}
                />
            </label>
            <div className="portfolio-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">新增</button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">取消</button>
            </div>
        </form>
    );
}

function HoldingRow({ holding }) {
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
                            <span>股數</span>
                            <input onChange={(e) => form.setData('shares', e.target.value)} step="any" type="number" value={form.data.shares} />
                            {form.errors.shares ? <p className="field-error">{form.errors.shares}</p> : null}
                        </label>
                        <label className="form-field">
                            <span>平均成本</span>
                            <input onChange={(e) => form.setData('avg_cost', e.target.value)} step="any" type="number" value={form.data.avg_cost} />
                            {form.errors.avg_cost ? <p className="field-error">{form.errors.avg_cost}</p> : null}
                        </label>
                        <label className="form-field">
                            <span>備註</span>
                            <input maxLength="255" onChange={(e) => form.setData('note', e.target.value)} type="text" value={form.data.note} />
                        </label>
                        <div className="portfolio-form__actions">
                            <button className="button-primary" disabled={form.processing} type="submit">儲存</button>
                            <button className="button-secondary" onClick={() => setEditing(false)} type="button">取消</button>
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
            <td>{money(holding.shares)}</td>
            <td>{money(holding.avg_cost)}</td>
            <td>{holding.price === null ? <span className="field-hint">報價暫無</span> : money(holding.price)}</td>
            <td>{money(holding.market_value)}</td>
            <td className={changeClass(holding.unrealized_pnl)}>{money(holding.unrealized_pnl)}</td>
            <td className={changeClass(holding.return_pct)}>{percent(holding.return_pct)}</td>
            <td><small>{formatDate(holding.as_of)}</small></td>
            <td><small>{holding.note ?? '—'}</small></td>
            <td className="portfolio-actions">
                {pendingRemove ? (
                    <>
                        <button className="button-danger" disabled={removing} onClick={remove} type="button">確認刪除</button>
                        <button className="button-secondary" disabled={removing} onClick={() => setPendingRemove(false)} type="button">取消</button>
                    </>
                ) : (
                    <>
                        <button onClick={() => setEditing(true)} title="編輯" type="button">編輯</button>
                        <button onClick={() => setPendingRemove(true)} title="刪除" type="button">
                            <Trash2 aria-hidden="true" size={16} />
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

function CurrencyGroup({ group, unavailableCount }) {
    const { subtotal } = group;

    return (
        <section className="stock-panel portfolio-group">
            <header className="portfolio-group__header">
                <div>
                    <p className="section-kicker">{group.currency} 計價</p>
                    <h2>
                        市值 {money(subtotal.market_value)}
                        {' '}
                        <span className={changeClass(subtotal.unrealized_pnl)}>
                            （{money(subtotal.unrealized_pnl)}／{percent(subtotal.return_pct)}）
                        </span>
                    </h2>
                    <p className="field-hint">成本 {money(subtotal.cost_basis)}</p>
                </div>
            </header>

            {unavailableCount > 0 ? (
                <p className="field-hint">{unavailableCount} 支無報價，未計入小計。</p>
            ) : null}

            <div className="portfolio-table-wrap">
                <table className="portfolio-table">
                    <thead>
                        <tr>
                            <th>標的</th>
                            <th>股數</th>
                            <th>均價</th>
                            <th>現價</th>
                            <th>市值</th>
                            <th>未實現損益</th>
                            <th>報酬率</th>
                            <th>資料時間</th>
                            <th>備註</th>
                            <th>操作</th>
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
    const unavailableSymbols = new Set(unavailable.map((entry) => entry.symbol));

    return (
        <AppShell title="投資組合">
            <div className="portfolio-page">
                <section className="stock-panel portfolio-header">
                    <div>
                        <p className="section-kicker">
                            <Wallet aria-hidden="true" size={16} /> 持倉
                        </p>
                        <h2>投資組合損益</h2>
                        <p className="field-hint">
                            依幣別分區顯示，不做匯率換算。損益以延遲行情計算，僅供參考，非券商對帳單。
                        </p>
                    </div>
                    <AddHoldingForm />
                </section>

                {groups.length === 0 ? (
                    <section className="stock-panel empty-state">
                        <strong>尚未加入持倉</strong>
                        <span>新增持倉後，這裡會顯示市值、未實現損益與報酬率。</span>
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
