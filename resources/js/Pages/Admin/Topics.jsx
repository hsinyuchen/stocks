import { router, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

function TopicRow({ rule, t }) {
    const remove = () => {
        router.delete(`/admin/topics/${rule.id}`, { preserveScroll: true });
    };

    return (
        <tr>
            <td>{rule.label}</td>
            <td><code>{rule.key}</code></td>
            <td>{rule.keyword_count}</td>
            <td>{rule.sector_count}</td>
            <td>{rule.is_active ? t('adminTopics.active') : t('adminTopics.disabled')}</td>
            <td>{rule.origin === 'seed' ? t('adminTopics.originSeed') : t('adminTopics.originManual')}</td>
            <td className="instrument-actions">
                {rule.origin === 'seed' ? (
                    <span title={t('adminTopics.seedCannotDelete')}>—</span>
                ) : (
                    <button onClick={remove} type="button">{t('common.delete')}</button>
                )}
            </td>
        </tr>
    );
}

export default function AdminTopics({ rules = [] }) {
    const { t } = useI18n();
    const { props } = usePage();
    const errors = props.errors ?? {};
    const flash = props.flash ?? {};

    return (
        <AppShell title={t('adminTopics.pageTitle')}>
            <div className="admin-page">
                <section className="stock-panel">
                    <div className="panel-heading">
                        <div>
                            <p className="section-kicker">{t('adminTopics.kicker')}</p>
                            <h2>{t('adminTopics.heading')}</h2>
                        </div>
                    </div>

                    {errors.rule ? <p className="field-error">{errors.rule}</p> : null}
                    {flash.success ? <p className="form-status">{flash.success}</p> : null}

                    <div className="chip-table-scroll">
                        <table className="chip-table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('adminTopics.label')}</th>
                                    <th scope="col">{t('adminTopics.key')}</th>
                                    <th scope="col">{t('adminTopics.keywordCount')}</th>
                                    <th scope="col">{t('adminTopics.sectorCount')}</th>
                                    <th scope="col">{t('adminTopics.state')}</th>
                                    <th scope="col">{t('adminTopics.origin')}</th>
                                    <th scope="col" />
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((rule) => (
                                    <TopicRow key={rule.id} rule={rule} t={t} />
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {rules.length === 0 ? <p className="dashboard-empty">{t('adminTopics.noResults')}</p> : null}
                </section>
            </div>
        </AppShell>
    );
}
