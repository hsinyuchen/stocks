import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { KeyRound, ShieldCheck, ShieldOff, Trash2, UserPlus, UserX, UserCheck } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

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
                <span>建立使用者</span>
            </button>
        );
    }

    return (
        <form className="admin-create-form" onSubmit={submit}>
            <label className="form-field">
                <span>姓名</span>
                <input
                    onChange={(event) => form.setData('name', event.target.value)}
                    type="text"
                    value={form.data.name}
                />
                {form.errors.name ? <p className="field-error">{form.errors.name}</p> : null}
            </label>
            <label className="form-field">
                <span>電子郵件</span>
                <input
                    onChange={(event) => form.setData('email', event.target.value)}
                    type="email"
                    value={form.data.email}
                />
                {form.errors.email ? <p className="field-error">{form.errors.email}</p> : null}
            </label>
            <label className="form-field">
                <span>初始密碼（留空由系統產生）</span>
                <input
                    onChange={(event) => form.setData('password', event.target.value)}
                    type="text"
                    value={form.data.password}
                />
                {form.errors.password ? <p className="field-error">{form.errors.password}</p> : null}
            </label>
            <div className="admin-create-form__actions">
                <button className="button-primary" disabled={form.processing} type="submit">
                    建立
                </button>
                <button className="button-secondary" onClick={() => setOpen(false)} type="button">
                    取消
                </button>
            </div>
        </form>
    );
}

function DeleteConfirm({ user, onCancel }) {
    const [confirmEmail, setConfirmEmail] = useState('');

    const submit = () => {
        router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
    };

    return (
        <div className="admin-delete-confirm">
            <p>
                將永久刪除 <strong>{user.email}</strong> 與其全部自選清單、分析紀錄與 LLM 設定。
                輸入該使用者的 email 以確認：
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
                    disabled={confirmEmail !== user.email}
                    onClick={submit}
                    type="button"
                >
                    永久刪除
                </button>
                <button className="button-secondary" onClick={onCancel} type="button">
                    取消
                </button>
            </div>
        </div>
    );
}

function UserRow({ user, selfId, isLastActiveAdmin }) {
    const [deleting, setDeleting] = useState(false);
    const isSelf = user.id === selfId;
    const locked = isSelf || isLastActiveAdmin;
    const lockReason = isSelf ? '不能對自己操作' : '最後一位有效管理員';

    const act = (method, path, confirmText) => {
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }

        router[method](path, {}, { preserveScroll: true });
    };

    return (
        <>
            <tr>
                <td>
                    <strong>{user.name}</strong>
                    <br />
                    <small>{user.email}</small>
                </td>
                <td>{user.is_admin ? <span className="badge badge--admin">管理員</span> : '一般'}</td>
                <td>
                    {user.disabled_at
                        ? <span className="badge badge--disabled">停用</span>
                        : <span className="badge badge--active">正常</span>}
                </td>
                <td>{formatDate(user.created_at)}</td>
                <td>{user.watchlists_count}</td>
                <td>{user.analyses_count}</td>
                <td>{user.has_llm ? '✓' : '—'}</td>
                <td className="admin-row-actions">
                    {user.disabled_at ? (
                        <button
                            onClick={() => act('patch', `/admin/users/${user.id}/enable`)}
                            title="啟用"
                            type="button"
                        >
                            <UserCheck size={16} />
                        </button>
                    ) : (
                        <button
                            disabled={locked}
                            onClick={() => act('patch', `/admin/users/${user.id}/disable`, `停用 ${user.email}？`)}
                            title={locked ? lockReason : '停用'}
                            type="button"
                        >
                            <UserX size={16} />
                        </button>
                    )}
                    <button
                        disabled={user.is_admin && locked}
                        onClick={() => act(
                            'patch',
                            `/admin/users/${user.id}/role`,
                            user.is_admin ? `將 ${user.email} 降為一般使用者？` : `將 ${user.email} 升為管理員？`,
                        )}
                        title={user.is_admin && locked ? lockReason : (user.is_admin ? '降為一般' : '升為管理員')}
                        type="button"
                    >
                        {user.is_admin ? <ShieldOff size={16} /> : <ShieldCheck size={16} />}
                    </button>
                    <button
                        onClick={() => act('post', `/admin/users/${user.id}/reset-link`, `寄密碼重設信給 ${user.email}？`)}
                        title="寄密碼重設信"
                        type="button"
                    >
                        <KeyRound size={16} />
                    </button>
                    <button
                        disabled={locked}
                        onClick={() => setDeleting(true)}
                        title={locked ? lockReason : '刪除'}
                        type="button"
                    >
                        <Trash2 size={16} />
                    </button>
                </td>
            </tr>
            {deleting ? (
                <tr>
                    <td colSpan={8}>
                        <DeleteConfirm onCancel={() => setDeleting(false)} user={user} />
                    </td>
                </tr>
            ) : null}
        </>
    );
}

export default function AdminUsers({ users = { data: [], links: [] }, filters = {} }) {
    const { props } = usePage();
    const selfId = props.auth?.user?.id;
    const flash = props.flash ?? {};
    const [q, setQ] = useState(filters.q ?? '');

    const activeAdmins = users.data.filter((user) => user.is_admin && !user.disabled_at);
    const lastActiveAdminId = activeAdmins.length === 1 ? activeAdmins[0].id : null;

    const search = (event) => {
        event.preventDefault();
        router.get('/admin/users', q ? { q } : {}, { preserveState: true });
    };

    return (
        <AppShell title="使用者管理">
            <section className="stock-panel admin-users">
                <header className="admin-users__header">
                    <div>
                        <p className="section-kicker">總管理</p>
                        <h2>使用者管理</h2>
                    </div>
                    <CreateUserForm />
                </header>

                {flash.error ? <p className="field-error">{flash.error}</p> : null}
                {flash.success ? <p className="field-hint">{flash.success}</p> : null}
                {flash.generated_password ? (
                    <p className="admin-generated-password">
                        系統產生的初始密碼（僅顯示這一次）：<code>{flash.generated_password}</code>
                    </p>
                ) : null}

                <form className="admin-users__search" onSubmit={search}>
                    <input
                        onChange={(event) => setQ(event.target.value)}
                        placeholder="搜尋名稱或 email"
                        type="search"
                        value={q}
                    />
                    <button className="button-secondary" type="submit">搜尋</button>
                </form>

                <div className="admin-users__table-wrap">
                    <table className="admin-users__table">
                        <thead>
                            <tr>
                                <th>使用者</th>
                                <th>角色</th>
                                <th>狀態</th>
                                <th>註冊</th>
                                <th>自選</th>
                                <th>分析</th>
                                <th>LLM</th>
                                <th>操作</th>
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

                <nav className="admin-users__pagination">
                    {(users.links ?? []).map((link) => (
                        link.url ? (
                            <Link
                                className={link.active ? 'is-active' : ''}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                                href={link.url}
                                key={link.label}
                            />
                        ) : (
                            <span dangerouslySetInnerHTML={{ __html: link.label }} key={link.label} />
                        )
                    ))}
                </nav>
            </section>
        </AppShell>
    );
}
