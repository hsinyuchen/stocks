import { useForm, usePage } from '@inertiajs/react';
import { KeyRound, SlidersHorizontal, UserCog } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';
import { useI18n } from '../../i18n';

// 主題標籤沿用共用 theme.* 命名空間，避免與其他頁面的主題文案分歧。
const THEMES = [
    { value: 'warm', labelKey: 'theme.warm' },
    { value: 'dark', labelKey: 'theme.dark' },
];

const MARKETS = [
    { value: 'TW', labelKey: 'profile.marketTw' },
    { value: 'US', labelKey: 'profile.marketUs' },
    { value: 'TW_US', labelKey: 'profile.marketTwUs' },
];

const LOCALES = [
    { value: 'zh', labelKey: 'profile.localeZh' },
    { value: 'en', labelKey: 'profile.localeEn' },
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
    const { t } = useI18n();
    const form = useForm({ name: account.name, email: account.email });

    const submit = (event) => {
        event.preventDefault();
        form.patch('/profile', { preserveScroll: true });
    };

    return (
        <form className="stock-panel profile-form" onSubmit={submit}>
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('profile.accountKicker')}</p>
                    <h2>{t('profile.accountHeading')}</h2>
                </div>
                <UserCog aria-hidden="true" size={22} />
            </div>
            <TextField
                autoComplete="name"
                error={form.errors.name}
                label={t('profile.name')}
                maxLength="255"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <TextField
                autoComplete="email"
                error={form.errors.email}
                hint={t('profile.emailHint')}
                label={t('profile.email')}
                maxLength="255"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                {t('profile.saveAccount')}
            </button>
        </form>
    );
}

function PasswordForm() {
    const { t } = useI18n();
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
                    <p className="section-kicker">{t('profile.securityKicker')}</p>
                    <h2>{t('profile.changePasswordHeading')}</h2>
                </div>
                <KeyRound aria-hidden="true" size={22} />
            </div>
            <TextField
                autoComplete="current-password"
                error={form.errors.current_password}
                label={t('profile.currentPassword')}
                onChange={(event) => form.setData('current_password', event.target.value)}
                type="password"
                value={form.data.current_password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password}
                hint={t('profile.newPasswordHint')}
                label={t('profile.newPassword')}
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password_confirmation}
                label={t('profile.confirmNewPassword')}
                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                type="password"
                value={form.data.password_confirmation}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                {t('profile.updatePassword')}
            </button>
        </form>
    );
}

function PreferencesForm({ preferences }) {
    const { t, setLocale } = useI18n();
    const form = useForm({ ...preferences });

    // 模組層級只存 value→key，實際顯示標籤在 render 時依當前語言解析。
    const themeOptions = THEMES.map((option) => ({ value: option.value, label: t(option.labelKey) }));
    const marketOptions = MARKETS.map((option) => ({ value: option.value, label: t(option.labelKey) }));
    const localeOptions = LOCALES.map((option) => ({ value: option.value, label: t(option.labelKey) }));

    const submit = (event) => {
        event.preventDefault();
        form.patch('/profile/preferences', { preserveScroll: true });
    };

    return (
        <form className="stock-panel profile-form" onSubmit={submit}>
            <div className="panel-heading">
                <div>
                    <p className="section-kicker">{t('profile.preferencesKicker')}</p>
                    <h2>{t('profile.preferencesHeading')}</h2>
                </div>
                <SlidersHorizontal aria-hidden="true" size={22} />
            </div>
            <SelectField
                error={form.errors.theme}
                label={t('profile.themeLabel')}
                onChange={(event) => form.setData('theme', event.target.value)}
                options={themeOptions}
                value={form.data.theme}
            />
            <SelectField
                error={form.errors.locale}
                label={t('profile.localeLabel')}
                onChange={(event) => {
                    // 同步更新 i18n 狀態（含 localStorage 與 DB），否則存了偏好但
                    // localStorage 仍是舊語言，重整後會被 localStorage 蓋回去。
                    form.setData('locale', event.target.value);
                    setLocale(event.target.value);
                }}
                options={localeOptions}
                value={form.data.locale}
            />
            <SelectField
                error={form.errors.preferred_market}
                label={t('profile.preferredMarketLabel')}
                onChange={(event) => form.setData('preferred_market', event.target.value)}
                options={marketOptions}
                value={form.data.preferred_market}
            />
            <SelectField
                error={form.errors.timezone}
                label={t('profile.timezoneLabel')}
                onChange={(event) => form.setData('timezone', event.target.value)}
                options={TIMEZONES}
                value={form.data.timezone}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                {t('profile.savePreferences')}
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
    const { t } = useI18n();
    const { flash } = usePage().props;

    return (
        <AppShell title={t('profile.pageTitle')}>
            <div className="profile-page">
                <section className="stock-search-header">
                    <div>
                        <p className="section-kicker">{t('profile.pageKicker')}</p>
                        <h2>{t('profile.pageHeading')}</h2>
                        <p>
                            {account.is_admin ? t('profile.adminBadge') : ''}
                            {t('profile.accountMeta', {
                                approved: formatDate(account.approved_at),
                                created: formatDate(account.created_at),
                            })}
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
