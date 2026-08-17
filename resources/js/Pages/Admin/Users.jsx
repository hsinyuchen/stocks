import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { KeyRound, ShieldCheck, ShieldOff, Trash2, UserPlus, UserX, UserCheck } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import Pagination from '../../Components/Pagination';
import { useI18n } from '../../i18n';

function formatDate(value) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '-'
        : date.toLocaleDateString('zh-TW', { dateStyle: 'medium' });
}

function CreateUserForm() {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', email: '', password: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/admin/users', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    if (!open) {
        return (
            <button className="button-primary" onClick={() => setOpen(true)} type="button">
                <UserPlus aria-hidden="true" size={18} />
                <span>{t('adminUsers.createUser')}</span>
            </button>
        );
    }

    return (
        <form className="admin-create-form" onSubmit={submit}>
            <label className="form-field">
                <span>{t('adminUsers.name')}</span>
                <input
                    onChange={(event) => form.setData('name', event.target.value)}
                    type="text"
                    value={form.data.name}
                />
                {form.errors.name ? <p className="field-error">{form.errors.name}</p> : null}
            </label>
            <label className="form-field">
                <span>{t('adminUsers.emailLabel')}</span>
                <input
                    onChange={(event) => form.setData('email', event.target.value)}
                    type="email"
                    value={form.data.email}
                />
                {form.errors.email ? <p className="field-error">{form.errors.email}</p> : null}
            </label>
            <label className="form-field">
                <span>{t('adminUsers.initialPassword')}</span>
                <input
                    onChange={(event) => form.setData('password', event.target.value)}
                    type="text"
                    value={form.data.password}
                />
                {form.errors.password ? <p className="field-error">{form.errors.password}</p> : null}
            </label>
            <div className="admin-create-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">
                    {t('adminUsers.create')}
                </button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">
                    {t('common.cancel')}
                </button>
            </div>
        </form>
    );
}

function DeleteConfirm({ user, onCancel }) {
    const { t } = useI18n();
    const [confirmEmail, setConfirmEmail] = useState('');

    const [submitting, setSubmitting] = useState(false);

    const submit = () => {
        if (submitting) {
            return;
        }

        setSubmitting(true);
        router.delete(`/admin/users/${user.id}`, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
        });
    };

    return (
        <div className="admin-delete-confirm">
            <p>
                {t('adminUsers.deleteWarningPre')} <strong>{user.email}</strong> {t('adminUsers.deleteWarningPost')}
            </p>
            <input
                onChange={(event) => setConfirmEmail(event.target.value)}
                placeholder={user.email}
                type="text"
                value={confirmEmail}
            />
            <div className="admin-create-form__actions">
                <button
                    className="button-danger"
                    disabled={confirmEmail !== user.email || submitting}
                    onClick={submit}
                    type="button"
                >
                    {t('adminUsers.permanentDelete')}
                </button>
                <button className="button-secondary" onClick={onCancel} type="button">
                    {t('common.cancel')}
                </button>
            </div>
        </div>
    );
}

function UserRow({ user, selfId, isLastActiveAdmin }) {
    const { t } = useI18n();
    const [deleting, setDeleting] = useState(false);
    const [pendingAction, setPendingAction] = useState(null);
    const isSelf = user.id === selfId;
    const locked = isSelf || isLastActiveAdmin;
    const lockReason = isSelf ? t('adminUsers.lockSelf') : t('adminUsers.lockLastAdmin');

    const act = (method, path, confirmLabel) => {
        if (confirmLabel) {
            setPendingAction({ method, path, label: confirmLabel });
            return;
        }

        router[method](path, {}, { preserveScroll: true });
    };

    const [confirming, setConfirming] = useState(false);

    const confirmPendingAction = () => {
        if (confirming) {
            return;
        }

        setConfirming(true);
        const { method, path } = pendingAction;
        router[method](path, {}, {
            preserveScroll: true,
            onFinish: () => {
                setConfirming(false);
                setPendingAction(null);
            },
        });
    };

    return (
        <>
            <tr>
                <td>
                    <strong>{user.name}</strong>
                    <br />
                    <small>{user.email}</small>
                </td>
                <td>{user.is_admin ? <span className="badge badge--admin">{t('adminUsers.roleAdmin')}</span> : t('adminUsers.roleNormal')}</td>
                <td>
                    {/* 待審核優先於停用：未核准的帳號從來沒有被啟用過，
                        顯示成「正常」或「停用」都是錯的。 */}
                    {user.approved_at === null
                        ? <span className="badge badge--pending">{t('adminUsers.statusPending')}</span>
                        : user.disabled_at
                            ? <span className="badge badge--disabled">{t('adminUsers.statusDisabled')}</span>
                            : <span className="badge badge--active">{t('adminUsers.statusActive')}</span>}
                </td>
                <td>{formatDate(user.created_at)}</td>
                <td>{user.watchlists_count}</td>
                <td>{user.analyses_count}</td>
                <td>{user.has_llm ? '✓' : '—'}</td>
                <td className="admin-row-actions">
                    {/* 待審核的帳號只有兩個合理動作：放行或駁回。停用一個從未啟用
                        的帳號、或把它升為管理員，都只會讓狀態更難理解。 */}
                    {user.approved_at === null ? (
                        <>
                            <button
                                className="admin-approve"
                                onClick={() => act('patch', `/admin/users/${user.id}/approve`, t('adminUsers.confirmApprove', { email: user.email }))}
                                title={t('adminUsers.approve')}
                                type="button"
                            >
                                <UserCheck size={16} />
                            </button>
                            <button
                                onClick={() => act('delete', `/admin/users/${user.id}/reject`, t('adminUsers.confirmReject', { email: user.email }))}
                                title={t('adminUsers.rejectApplication')}
                                type="button"
                            >
                                <UserX size={16} />
                            </button>
                        </>
                    ) : user.disabled_at ? (
                        <button
                            onClick={() => act('patch', `/admin/users/${user.id}/enable`)}
                            title={t('adminUsers.enable')}
                            type="button"
                        >
                            <UserCheck size={16} />
                        </button>
                    ) : (
                        <button
                            disabled={locked}
                            onClick={() => act('patch', `/admin/users/${user.id}/disable`, t('adminUsers.confirmDisable', { email: user.email }))}
                            title={locked ? lockReason : t('adminUsers.disable')}
                            type="button"
                        >
                            <UserX size={16} />
                        </button>
                    )}
                    {/* 角色、密碼重設、刪除對還沒放行的申請都沒有意義——駁回鈕
                        本身就會刪掉它。 */}
                    {user.approved_at !== null ? (
                        <>
                            <button
                                disabled={user.is_admin && locked}
                                onClick={() => act(
                                    'patch',
                                    `/admin/users/${user.id}/role`,
                                    user.is_admin ? t('adminUsers.confirmDemote', { email: user.email }) : t('adminUsers.confirmPromote', { email: user.email }),
                                )}
                                title={user.is_admin && locked ? lockReason : (user.is_admin ? t('adminUsers.demote') : t('adminUsers.promote'))}
                                type="button"
                            >
                                {user.is_admin ? <ShieldOff size={16} /> : <ShieldCheck size={16} />}
                            </button>
                            <button
                                onClick={() => act('post', `/admin/users/${user.id}/reset-link`, t('adminUsers.confirmResetLink', { email: user.email }))}
                                title={t('adminUsers.sendResetLink')}
                                type="button"
                            >
                                <KeyRound size={16} />
                            </button>
                            <button
                                disabled={locked}
                                onClick={() => setDeleting(true)}
                                title={locked ? lockReason : t('common.delete')}
                                type="button"
                            >
                                <Trash2 size={16} />
                            </button>
                        </>
                    ) : null}
                </td>
            </tr>
            {deleting ? (
                <tr>
                    <td colSpan={8}>
                        <DeleteConfirm onCancel={() => setDeleting(false)} user={user} />
                    </td>
                </tr>
            ) : null}
            {pendingAction ? (
                <tr>
                    <td colSpan={8}>
                        <div className="admin-action-confirm">
                            <span>{pendingAction.label}</span>
                            <div className="admin-create-form__actions">
                                <button
                                    className="button-danger"
                                    disabled={confirming}
                                    onClick={confirmPendingAction}
                                    type="button"
                                >
                                    {t('common.confirm')}
                                </button>
                                <button
                                    className="button-secondary"
                                    disabled={confirming}
                                    onClick={() => setPendingAction(null)}
                                    type="button"
                                >
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
            ) : null}
        </>
    );
}

export default function AdminUsers({ users = { data: [], links: [] }, filters = {}, pendingCount = 0 }) {
    const { t } = useI18n();
    const { props } = usePage();
    const selfId = props.auth?.user?.id;
    const flash = props.flash ?? {};
    const [q, setQ] = useState(filters.q ?? '');

    // 未核准的管理員登不進來，不能算進「有效管理員」的人數。
    const activeAdmins = users.data.filter((user) => user.is_admin && !user.disabled_at && user.approved_at);
    const lastActiveAdminId = activeAdmins.length === 1 ? activeAdmins[0].id : null;

    const search = (event) => {
        event.preventDefault();
        router.get('/admin/users', q ? { q } : {}, { preserveState: true });
    };

    return (
        <AppShell title={t('adminUsers.pageTitle')}>
            <section className="stock-panel admin-users">
                <header className="admin-users__header">
                    <div>
                        <p className="section-kicker">{t('adminUsers.kicker')}</p>
                        <h2>{t('adminUsers.heading')}</h2>
                    </div>
                    <CreateUserForm />
                </header>

                {/* 待審核是唯一需要管理員主動處理的狀態，搜尋或翻頁時也要看得到。 */}
                {pendingCount > 0 ? (
                    <p className="admin-pending-banner" role="status">
                        {t('adminUsers.pendingBanner', { count: pendingCount })}
                    </p>
                ) : null}

                {flash.error ? <p className="field-error">{flash.error}</p> : null}
                {flash.success ? <p className="field-hint">{flash.success}</p> : null}
                {flash.generated_password ? (
                    <p className="admin-generated-password">
                        {t('adminUsers.generatedPasswordLabel')}<code>{flash.generated_password}</code>
                    </p>
                ) : null}

                <form className="admin-users__search" onSubmit={search}>
                    <input
                        onChange={(event) => setQ(event.target.value)}
                        placeholder={t('adminUsers.searchPlaceholder')}
                        type="search"
                        value={q}
                    />
                    <button className="button-secondary" type="submit">{t('common.search')}</button>
                </form>

                <div className="admin-users__table-wrap">
                    <table className="admin-users__table">
                        <thead>
                            <tr>
                                <th>{t('adminUsers.colUser')}</th>
                                <th>{t('adminUsers.colRole')}</th>
                                <th>{t('adminUsers.colStatus')}</th>
                                <th>{t('adminUsers.colRegistered')}</th>
                                <th>{t('adminUsers.colWatchlists')}</th>
                                <th>{t('adminUsers.colAnalyses')}</th>
                                <th>LLM</th>
                                <th>{t('adminUsers.colActions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((user) => (
                                <UserRow
                                    isLastActiveAdmin={user.id === lastActiveAdminId}
                                    key={user.id}
                                    selfId={selfId}
                                    user={user}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination links={users.links} meta={users} />
            </section>
        </AppShell>
    );
}
