import { router, useForm, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

const linesToArray = (text) => text.split('\n').map((line) => line.trim()).filter(Boolean);
const arrayToLines = (value) => (value ?? []).join('\n');

const emptySector = { id: null, name: '', name_en: '', direction: 'neutral', symbols: '', curator_note: '' };

/**
 * 題材規則的新增／編輯共用表單。
 */
export default function TopicForm({ rule = null, domains = [], directions = [] }) {
    const { t } = useI18n();
    const { flash } = usePage().props;
    const isEdit = rule !== null;

    const form = useForm({
        key: rule?.key ?? '',
        label: rule?.label ?? '',
        label_en: rule?.label_en ?? '',
        curator_note: rule?.curator_note ?? '',
        is_active: rule?.is_active ?? true,
        keywords: arrayToLines(rule?.keywords),
        domains: rule?.domains ?? [],
        chain: arrayToLines(rule?.chain),
        chain_en: arrayToLines(rule?.chain_en),
        cues_forward: arrayToLines(rule?.direction_cues?.forward),
        cues_reverse: arrayToLines(rule?.direction_cues?.reverse),
        sectors: rule?.sectors?.map((s) => ({ ...s, symbols: arrayToLines(s.symbols) })) ?? [{ ...emptySector }],
        // 樂觀鎖：帶回進頁面時看到的 updated_at，controller 會比對 DB 現值。
        updated_at: rule?.updated_at ?? null,
    });

    const submit = (event) => {
        event.preventDefault();
        const transform = (data) => ({
            ...data,
            keywords: linesToArray(data.keywords),
            chain: linesToArray(data.chain),
            chain_en: linesToArray(data.chain_en),
            direction_cues: { forward: linesToArray(data.cues_forward), reverse: linesToArray(data.cues_reverse) },
            sectors: data.sectors.map((s) => ({ ...s, symbols: linesToArray(s.symbols) })),
        });

        if (isEdit) {
            form.transform(transform).patch(`/admin/topics/${rule.id}`);
        } else {
            form.transform(transform).post('/admin/topics');
        }
    };

    const updateSector = (index, patch) => {
        form.setData('sectors', form.data.sectors.map((s, i) => (i === index ? { ...s, ...patch } : s)));
    };

    const preview = usePage().props.flash?.previewResult ?? null;

    // 走 Inertia router 而非自行 fetch：app.blade.php 沒有輸出 csrf-token meta，
    // 手寫 fetch 得自己處理 XSRF cookie。preserveState 讓試跑後表單內容不被重置。
    const runPreview = () => {
        router.post('/admin/topics/preview', {
            ...form.data,
            keywords: linesToArray(form.data.keywords),
            chain: linesToArray(form.data.chain),
            direction_cues: { forward: linesToArray(form.data.cues_forward), reverse: linesToArray(form.data.cues_reverse) },
            sectors: form.data.sectors.map((s) => ({ ...s, symbols: linesToArray(s.symbols) })),
        }, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppShell title={t('adminTopics.formTitle')}>
            <div className="admin-page">
                <form onSubmit={submit}>
                    <section className="stock-panel">
                        <div className="panel-heading">
                            <div>
                                <p className="section-kicker">{t('adminTopics.kicker')}</p>
                                <h2>{isEdit ? t('adminTopics.editHeading') : t('adminTopics.createHeading')}</h2>
                            </div>
                        </div>

                        {flash?.warning ? <p className="form-warning">{flash.warning}</p> : null}
                        {flash?.success ? <p className="form-status">{flash.success}</p> : null}
                        {form.errors.rule ? <p className="field-error">{form.errors.rule}</p> : null}
                        {form.errors.updated_at ? <p className="field-error">{form.errors.updated_at}</p> : null}

                        <label className="form-field">
                            <span>{t('adminTopics.key')}</span>
                            <input
                                onChange={(e) => form.setData('key', e.target.value)}
                                placeholder="hormuz_oil"
                                readOnly={isEdit}
                                value={form.data.key}
                            />
                            {form.errors.key ? <p className="field-error">{form.errors.key}</p> : null}
                            {isEdit ? <p className="field-hint">{t('adminTopics.keyImmutable')}</p> : null}
                        </label>

                        <label className="form-field">
                            <span>{t('adminTopics.label')}</span>
                            <input onChange={(e) => form.setData('label', e.target.value)} value={form.data.label} />
                            {form.errors.label ? <p className="field-error">{form.errors.label}</p> : null}
                        </label>

                        <label className="form-field">
                            <span>{t('adminTopics.labelEn')}</span>
                            <input onChange={(e) => form.setData('label_en', e.target.value)} value={form.data.label_en} />
                        </label>

                        <label className="form-field">
                            <span>{t('adminTopics.keywords')}</span>
                            <textarea onChange={(e) => form.setData('keywords', e.target.value)} rows={6} value={form.data.keywords} />
                            {form.errors.keywords ? <p className="field-error">{form.errors.keywords}</p> : null}
                        </label>

                        <fieldset>
                            <legend>{t('adminTopics.domains')}</legend>
                            {domains.map((domain) => (
                                <label key={domain}>
                                    <input
                                        checked={form.data.domains.includes(domain)}
                                        onChange={(e) => form.setData('domains', e.target.checked
                                            ? [...form.data.domains, domain]
                                            : form.data.domains.filter((d) => d !== domain))}
                                        type="checkbox"
                                    />
                                    {domain}
                                </label>
                            ))}
                        </fieldset>

                        <label className="form-field">
                            <span>{t('adminTopics.chain')}</span>
                            <textarea onChange={(e) => form.setData('chain', e.target.value)} rows={4} value={form.data.chain} />
                            {form.errors.chain ? <p className="field-error">{form.errors.chain}</p> : null}
                        </label>

                        <label className="form-field">
                            <span>{t('adminTopics.chainEn')}</span>
                            <textarea onChange={(e) => form.setData('chain_en', e.target.value)} rows={4} value={form.data.chain_en} />
                        </label>

                        <label className="form-field">
                            <span>{t('adminTopics.cuesForward')}</span>
                            <textarea onChange={(e) => form.setData('cues_forward', e.target.value)} rows={3} value={form.data.cues_forward} />
                        </label>
                        <label className="form-field">
                            <span>{t('adminTopics.cuesReverse')}</span>
                            <textarea onChange={(e) => form.setData('cues_reverse', e.target.value)} rows={3} value={form.data.cues_reverse} />
                        </label>
                        <p className="field-hint">{t('adminTopics.cuesNote')}</p>

                        <label className="form-field">
                            <span>{t('adminTopics.curatorNote')}</span>
                            <textarea onChange={(e) => form.setData('curator_note', e.target.value)} rows={4} value={form.data.curator_note} />
                        </label>
                        <p className="field-hint">{t('adminTopics.curatorNoteHint')}</p>

                        <label>
                            <input
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                                type="checkbox"
                            />
                            {t('adminTopics.active')}
                        </label>

                        {form.data.sectors.map((sector, index) => (
                            <fieldset key={sector.id ?? `new-${index}`}>
                                <legend>{t('adminTopics.sector')} {index + 1}</legend>

                                <label className="form-field">
                                    <span>{t('adminTopics.sectorName')}</span>
                                    <input onChange={(e) => updateSector(index, { name: e.target.value })} value={sector.name} />
                                    {form.errors[`sectors.${index}.name`] ? <p className="field-error">{form.errors[`sectors.${index}.name`]}</p> : null}
                                </label>

                                <label className="form-field">
                                    <span>{t('adminTopics.sectorNameEn')}</span>
                                    <input onChange={(e) => updateSector(index, { name_en: e.target.value })} value={sector.name_en ?? ''} />
                                </label>

                                <label className="form-field">
                                    <span>{t('adminTopics.directionLabel')}</span>
                                    <select onChange={(e) => updateSector(index, { direction: e.target.value })} value={sector.direction}>
                                        {directions.map((direction) => (
                                            <option key={direction} value={direction}>{t(`adminTopics.direction.${direction}`)}</option>
                                        ))}
                                    </select>
                                    {form.errors[`sectors.${index}.direction`] ? <p className="field-error">{form.errors[`sectors.${index}.direction`]}</p> : null}
                                </label>

                                <label className="form-field">
                                    <span>{t('adminTopics.sectorSymbols')}</span>
                                    <textarea onChange={(e) => updateSector(index, { symbols: e.target.value })} placeholder="2330.TW" rows={3} value={sector.symbols} />
                                </label>

                                <label className="form-field">
                                    <span>{t('adminTopics.sectorNote')}</span>
                                    <textarea onChange={(e) => updateSector(index, { curator_note: e.target.value })} rows={2} value={sector.curator_note ?? ''} />
                                </label>

                                <button
                                    className="button-secondary"
                                    onClick={() => form.setData('sectors', form.data.sectors.filter((_, i) => i !== index))}
                                    type="button"
                                >
                                    {t('adminTopics.removeSector')}
                                </button>
                            </fieldset>
                        ))}

                        <button
                            className="button-secondary"
                            onClick={() => form.setData('sectors', [...form.data.sectors, { ...emptySector }])}
                            type="button"
                        >
                            {t('adminTopics.addSector')}
                        </button>

                        <div className="panel-heading">
                            <button className="button-primary" disabled={form.processing} type="submit">
                                {t('adminTopics.save')}
                            </button>
                            <button type="button" className="button-secondary" onClick={runPreview}>{t('adminTopics.preview')}</button>
                        </div>
                        {preview ? (
                            <div className="field-hint">
                                <p>{t('adminTopics.previewResult', { matched: preview.matched, scanned: preview.scanned })}</p>
                                <ul>{preview.samples.map((title) => <li key={title}>{title}</li>)}</ul>
                            </div>
                        ) : null}
                    </section>
                </form>
            </div>
        </AppShell>
    );
}
