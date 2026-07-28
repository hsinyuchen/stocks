import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Plus, Upload } from 'lucide-react';
import { useState } from 'react';
import AppShell from '../../Layouts/AppShell';

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

/**
 * 匯入表單。
 *
 * 「全部取代」刻意不是真的清空重來：instruments 被 8 個表以 cascade 參照，
 * 其中自選清單、持倉、警報與已存分析屬使用者資料。UI 必須把這個保留行為講清楚，
 * 否則使用者會以為取代後清單就完全等於檔案內容。
 */
function ImportPanel() {
    const form = useForm({ file: null, mode: 'append' });
    const [fileName, setFileName] = useState('');

    const submit = (event) => {
        event.preventDefault();
        form.post('/admin/instruments/import', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                setFileName('');
            },
        });
    };

    return (
        <section className="stock-panel">
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">批次匯入</p>
                    <h2>上傳 CSV 或 Excel</h2>
                </div>
                <Upload aria-hidden="true" size={22} />
            </div>

            <form className="instrument-import" onSubmit={submit}>
                <label className="form-field">
                    <span>檔案（.csv / .xlsx，上限 5 MB）</span>
                    <input
                        accept=".csv,.txt,.xlsx"
                        onChange={(event) => {
                            form.setData('file', event.target.files[0] ?? null);
                            setFileName(event.target.files[0]?.name ?? '');
                        }}
                        type="file"
                    />
                    {fileName ? <small className="field-hint">{fileName}</small> : null}
                    <FieldError message={form.errors.file} />
                </label>

                <fieldset className="instrument-import__mode">
                    <legend>匯入方式</legend>
                    <label>
                        <input
                            checked={form.data.mode === 'append'}
                            onChange={() => form.setData('mode', 'append')}
                            type="radio"
                        />
                        <span>新增（既有代號直接跳過）</span>
                    </label>
                    <label>
                        <input
                            checked={form.data.mode === 'replace'}
                            onChange={() => form.setData('mode', 'replace')}
                            type="radio"
                        />
                        <span>全部取代</span>
                    </label>
                </fieldset>

                {form.data.mode === 'replace' ? (
                    <p className="instrument-import__warning">
                        <AlertTriangle aria-hidden="true" size={14} />
                        取代時會移除不在檔案中的標的，但<strong>被自選清單、持倉、警報或已存分析參照者一律保留</strong>。
                        隨標的清除的只有行情、籌碼與基本面快取，之後會自動重抓。
                    </p>
                ) : null}

                <p className="field-hint">
                    格式：第一欄代號、第二欄名稱。可含標題列（symbol / 代號、name / 名稱）。
                    市場、幣別、資產類型由代號自動推導；檔案內重複的代號只取第一筆。
                </p>

                <button className="button-primary" disabled={form.processing || !form.data.file} type="submit">
                    {form.processing ? '匯入中…' : '開始匯入'}
                </button>
            </form>
        </section>
    );
}

function AddForm() {
    const form = useForm({ symbol: '', name: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/admin/instruments', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form className="instrument-add" onSubmit={submit}>
            <label className="form-field">
                <span>代號</span>
                <input
                    maxLength="32"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder="2330.TW 或 NVDA"
                    value={form.data.symbol}
                />
                <FieldError message={form.errors.symbol} />
            </label>
            <label className="form-field">
                <span>名稱（可留空）</span>
                <input
                    maxLength="120"
                    onChange={(event) => form.setData('name', event.target.value)}
                    placeholder="台積電"
                    value={form.data.name}
                />
            </label>
            <button className="button-secondary" disabled={form.processing} type="submit">
                <Plus aria-hidden="true" size={16} />
                <span>新增</span>
            </button>
        </form>
    );
}

function InstrumentRow({ instrument }) {
    const [name, setName] = useState(instrument.name);
    const [editing, setEditing] = useState(false);

    const save = () => {
        router.patch(`/admin/instruments/${instrument.id}`, { name }, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const remove = () => {
        router.delete(`/admin/instruments/${instrument.id}`, { preserveScroll: true });
    };

    return (
        <tr>
            <td className="instrument-symbol">{instrument.symbol}</td>
            <td>
                {editing ? (
                    <input
                        autoFocus
                        maxLength="120"
                        onChange={(event) => setName(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && save()}
                        value={name}
                    />
                ) : (
                    instrument.name
                )}
            </td>
            <td>{instrument.market}</td>
            <td>{instrument.currency}</td>
            <td>
                {instrument.referenced ? (
                    <span className="instrument-flag" title="被自選清單、持倉、警報或已存分析參照，取代時會保留">使用中</span>
                ) : null}
            </td>
            <td className="instrument-actions">
                {editing ? (
                    <>
                        <button onClick={save} type="button">儲存</button>
                        <button onClick={() => { setName(instrument.name); setEditing(false); }} type="button">取消</button>
                    </>
                ) : (
                    <>
                        <button onClick={() => setEditing(true)} type="button">改名</button>
                        <button
                            disabled={instrument.referenced}
                            onClick={remove}
                            title={instrument.referenced ? '被使用者資料參照，無法刪除' : undefined}
                            type="button"
                        >
                            刪除
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function AdminInstruments({ instruments = { data: [], links: [] }, filters = {}, total = 0 }) {
    const { props } = usePage();
    const status = props.flash?.status;
    const [search, setSearch] = useState(filters.q ?? '');
    const [market, setMarket] = useState(filters.market ?? '');

    const applyFilters = (event) => {
        event.preventDefault();
        router.get('/admin/instruments', {
            ...(search ? { q: search } : {}),
            ...(market ? { market } : {}),
        }, { preserveScroll: true });
    };

    return (
        <AppShell title="標的清單">
            <div className="admin-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">標的清單</p>
                        <h2>維護全站共用的股票清單</h2>
                        <p>
                            共 {total} 檔。此清單為全站共用，行情、籌碼與基本面快取都掛在其下，
                            修改會影響所有使用者。個人自選股請至「自選清單」設定。
                        </p>
                    </div>
                </section>

                {status ? <p className="form-status">{status}</p> : null}

                <ImportPanel />

                <section className="stock-panel">
                    <div className="panel-heading">
                        <div>
                            <p className="section-kicker">單筆維護</p>
                            <h2>新增與編輯</h2>
                        </div>
                    </div>

                    <AddForm />

                    <form className="instrument-filter" onSubmit={applyFilters}>
                        <label className="form-field">
                            <span>搜尋</span>
                            <input
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="代號或名稱"
                                type="search"
                                value={search}
                            />
                        </label>
                        <label className="form-field">
                            <span>市場</span>
                            <select onChange={(event) => setMarket(event.target.value)} value={market}>
                                <option value="">全部</option>
                                <option value="TW">TW</option>
                                <option value="US">US</option>
                            </select>
                        </label>
                        <button className="button-secondary" type="submit">篩選</button>
                    </form>

                    <div className="chip-table-scroll">
                        <table className="chip-table instrument-table">
                            <thead>
                                <tr>
                                    <th scope="col">代號</th>
                                    <th scope="col">名稱</th>
                                    <th scope="col">市場</th>
                                    <th scope="col">幣別</th>
                                    <th scope="col">狀態</th>
                                    <th scope="col">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                {instruments.data.map((instrument) => (
                                    <InstrumentRow instrument={instrument} key={instrument.id} />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {instruments.data.length === 0 ? <p className="dashboard-empty">沒有符合條件的標的。</p> : null}
                </section>
            </div>
        </AppShell>
    );
}
