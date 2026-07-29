import { useForm, usePage } from '@inertiajs/react';
import { KeyRound, SlidersHorizontal, UserCog } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

const THEMES = [
    { value: 'warm', label: '暖色' },
    { value: 'dark', label: '深色' },
];

const MARKETS = [
    { value: 'TW', label: '台股' },
    { value: 'US', label: '美股' },
    { value: 'TW_US', label: '台股＋美股' },
];

const TIMEZONES = ['Asia/Taipei', 'Asia/Tokyo', 'Asia/Shanghai', 'America/New_York', 'Europe/London', 'UTC'];

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

function TextField({ error, label, hint, ...props }) {
    return (
        <label className="form-field">
            <span>{label}</span>
            <input {...props} />
            {hint ? <small className="field-hint">{hint}</small> : null}
            <FieldError message={error} />
        </label>
    );
}

function SelectField({ error, label, options, ...props }) {
    return (
        <label className="form-field">
            <span>{label}</span>
            <select {...props}>
                {options.map((option) => (
                    <option key={option.value ?? option} value={option.value ?? option}>
                        {option.label ?? option}
                    </option>
                ))}
            </select>
            <FieldError message={error} />
        </label>
    );
}

function AccountForm({ account }) {
    const form = useForm({ name: account.name, email: account.email });

    const submit = (event) => {
        event.preventDefault();
        form.patch('/profile', { preserveScroll: true });
    };

    return (
        <form className="stock-panel profile-form" onSubmit={submit}>
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">帳號資料</p>
                    <h2>姓名與登入信箱</h2>
                </div>
                <UserCog aria-hidden="true" size={22} />
            </div>
            <TextField
                autoComplete="name"
                error={form.errors.name}
                label="姓名"
                maxLength="255"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <TextField
                autoComplete="email"
                error={form.errors.email}
                hint="這也是登入用的帳號，變更後請用新信箱登入。"
                label="電子郵件"
                maxLength="255"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                儲存帳號資料
            </button>
        </form>
    );
}

function PasswordForm() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.patch('/profile/password', {
            preserveScroll: true,
            // 密碼不留在記憶體裡，成功與否都清掉。
            onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <form className="stock-panel profile-form" onSubmit={submit}>
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">安全性</p>
                    <h2>變更密碼</h2>
                </div>
                <KeyRound aria-hidden="true" size={22} />
            </div>
            <TextField
                autoComplete="current-password"
                error={form.errors.current_password}
                label="目前密碼"
                onChange={(event) => form.setData('current_password', event.target.value)}
                type="password"
                value={form.data.current_password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password}
                hint="至少 8 個字元。變更後其他裝置的登入會失效。"
                label="新密碼"
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password_confirmation}
                label="確認新密碼"
                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                type="password"
                value={form.data.password_confirmation}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                更新密碼
            </button>
        </form>
    );
}

function PreferencesForm({ preferences }) {
    const form = useForm({ ...preferences });

    const submit = (event) => {
        event.preventDefault();
        form.patch('/profile/preferences', { preserveScroll: true });
    };

    return (
        <form className="stock-panel profile-form" onSubmit={submit}>
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">偏好設定</p>
                    <h2>顯示與市場</h2>
                </div>
                <SlidersHorizontal aria-hidden="true" size={22} />
            </div>
            <SelectField
                error={form.errors.theme}
                label="主題色"
                onChange={(event) => form.setData('theme', event.target.value)}
                options={THEMES}
                value={form.data.theme}
            />
            <SelectField
                error={form.errors.preferred_market}
                label="主要關注市場"
                onChange={(event) => form.setData('preferred_market', event.target.value)}
                options={MARKETS}
                value={form.data.preferred_market}
            />
            <SelectField
                error={form.errors.timezone}
                label="時區"
                onChange={(event) => form.setData('timezone', event.target.value)}
                options={TIMEZONES}
                value={form.data.timezone}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                儲存偏好設定
            </button>
        </form>
    );
}

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('zh-TW', { dateStyle: 'medium', timeStyle: 'short' });
}

export default function ProfileEdit({ account, preferences }) {
    const { flash } = usePage().props;

    return (
        <AppShell title="個人資料">
            <div className="profile-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">個人資料</p>
                        <h2>管理你的帳號</h2>
                        <p>
                            {account.is_admin ? '管理員帳號 · ' : ''}
                            核准於 {formatDate(account.approved_at)} · 建立於 {formatDate(account.created_at)}
                        </p>
                    </div>
                </section>

                {flash?.success ? <p className="profile-flash" role="status">{flash.success}</p> : null}
                {flash?.error ? <p className="profile-flash profile-flash--error" role="alert">{flash.error}</p> : null}

                <div className="profile-grid">
                    <AccountForm account={account} />
                    <PasswordForm />
                    <PreferencesForm preferences={preferences} />
                </div>
            </div>
        </AppShell>
    );
}
