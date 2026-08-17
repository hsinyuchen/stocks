import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Plus, Upload } from 'lucide-react';
import { useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import Pagination from '../../Components/Pagination';
import { useI18n } from '../../i18n';

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
    const { t } = useI18n();
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
                    <p className="section-kicker">{t('adminInstruments.importKicker')}</p>
                    <h2>{t('adminInstruments.uploadHeading')}</h2>
                </div>
                <Upload aria-hidden="true" size={22} />
            </div>

            <form className="instrument-import" onSubmit={submit}>
                <label className="form-field">
                    <span>{t('adminInstruments.fileLabel')}</span>
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
                    <legend>{t('adminInstruments.importMode')}</legend>
                    <label>
                        <input
                            checked={form.data.mode === 'append'}
                            onChange={() => form.setData('mode', 'append')}
                            type="radio"
                        />
                        <span>{t('adminInstruments.modeAppend')}</span>
                    </label>
                    <label>
                        <input
                            checked={form.data.mode === 'replace'}
                            onChange={() => form.setData('mode', 'replace')}
                            type="radio"
                        />
                        <span>{t('adminInstruments.modeReplace')}</span>
                    </label>
                </fieldset>

                {form.data.mode === 'replace' ? (
                    <p className="instrument-import__warning">
                        <AlertTriangle aria-hidden="true" size={14} />
                        {t('adminInstruments.replaceWarningPre')}<strong>{t('adminInstruments.replaceWarningStrong')}</strong>{t('adminInstruments.replaceWarningPost')}
                    </p>
                ) : null}

                <p className="field-hint">
                    {t('adminInstruments.formatHint')}
                </p>

                <button className="button-primary" disabled={form.processing || !form.data.file} type="submit">
                    {form.processing ? t('adminInstruments.importing') : t('adminInstruments.startImport')}
                </button>
            </form>
        </section>
    );
}

function AddForm() {
    const { t } = useI18n();
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
                <span>{t('adminInstruments.symbol')}</span>
                <input
                    maxLength="32"
                    onChange={(event) => form.setData('symbol', event.target.value.toUpperCase())}
                    placeholder={t('adminInstruments.symbolPlaceholder')}
                    value={form.data.symbol}
                />
                <FieldError message={form.errors.symbol} />
            </label>
            <label className="form-field">
                <span>{t('adminInstruments.nameOptional')}</span>
                <input
                    maxLength="120"
                    onChange={(event) => form.setData('name', event.target.value)}
                    placeholder={t('adminInstruments.namePlaceholder')}
                    value={form.data.name}
                />
            </label>
            <button className="button-secondary" disabled={form.processing} type="submit">
                <Plus aria-hidden="true" size={16} />
                <span>{t('adminInstruments.add')}</span>
            </button>
        </form>
    );
}

function InstrumentRow({ instrument }) {
    const { t } = useI18n();
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
                    <span className="instrument-flag" title={t('adminInstruments.referencedTitle')}>{t('adminInstruments.inUse')}</span>
                ) : null}
            </td>
            <td className="instrument-actions">
                {editing ? (
                    <>
                        <button onClick={save} type="button">{t('common.save')}</button>
                        <button onClick={() => { setName(instrument.name); setEditing(false); }} type="button">{t('common.cancel')}</button>
                    </>
                ) : (
                    <>
                        <button onClick={() => setEditing(true)} type="button">{t('adminInstruments.rename')}</button>
                        <button
                            disabled={instrument.referenced}
                            onClick={remove}
                            title={instrument.referenced ? t('adminInstruments.cannotDelete') : undefined}
                            type="button"
                        >
                            {t('common.delete')}
                        </button>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function AdminInstruments({ instruments = { data: [], links: [] }, filters = {}, total = 0 }) {
    const { t } = useI18n();
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
        <AppShell title={t('adminInstruments.pageTitle')}>
            <div className="admin-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">{t('adminInstruments.kicker')}</p>
                        <h2>{t('adminInstruments.heading')}</h2>
                        <p>
                            {t('adminInstruments.description', { count: total })}
                        </p>
                    </div>
                </section>

                {status ? <p className="form-status">{status}</p> : null}

                <ImportPanel />

                <section className="stock-panel">
                    <div className="panel-heading">
                        <div>
                            <p className="section-kicker">{t('adminInstruments.singleKicker')}</p>
                            <h2>{t('adminInstruments.addEditHeading')}</h2>
                        </div>
                    </div>

                    <AddForm />

                    <form className="instrument-filter" onSubmit={applyFilters}>
                        <label className="form-field">
                            <span>{t('common.search')}</span>
                            <input
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder={t('adminInstruments.searchPlaceholder')}
                                type="search"
                                value={search}
                            />
                        </label>
                        <label className="form-field">
                            <span>{t('adminInstruments.market')}</span>
                            <select onChange={(event) => setMarket(event.target.value)} value={market}>
                                <option value="">{t('adminInstruments.allMarkets')}</option>
                                <option value="TW">TW</option>
                                <option value="US">US</option>
                            </select>
                        </label>
                        <button className="button-secondary" type="submit">{t('adminInstruments.filter')}</button>
                    </form>

                    <div className="chip-table-scroll">
                        <table className="chip-table instrument-table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('adminInstruments.symbol')}</th>
                                    <th scope="col">{t('adminInstruments.name')}</th>
                                    <th scope="col">{t('adminInstruments.market')}</th>
                                    <th scope="col">{t('adminInstruments.currency')}</th>
                                    <th scope="col">{t('adminInstruments.status')}</th>
                                    <th scope="col">{t('adminInstruments.actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {instruments.data.map((instrument) => (
                                    <InstrumentRow instrument={instrument} key={instrument.id} />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {instruments.data.length === 0 ? <p className="dashboard-empty">{t('adminInstruments.noResults')}</p> : null}

                    <Pagination links={instruments.links} meta={instruments} />
                </section>
            </div>
        </AppShell>
    );
}
