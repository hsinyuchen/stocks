import { useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, LogIn, UserPlus } from 'lucide-react';
import { useI18n } from '../../i18n';
import LocaleToggle from '../../Components/LocaleToggle';

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

function LoginForm() {
    const { t } = useI18n();
    const form = useForm({
        email: '',
        password: '',
        remember: true,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/login');
    };

    return (
        <form className="auth-card" onSubmit={submit}>
            <div>
                <p className="section-kicker">{t('login.loginKicker')}</p>
                <h2>{t('login.loginHeading')}</h2>
                <p>{t('login.loginIntro')}</p>
            </div>
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label={t('login.email')}
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="current-password"
                error={form.errors.password}
                label={t('login.password')}
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <label className="settings-toggle">
                <input
                    checked={form.data.remember}
                    onChange={(event) => form.setData('remember', event.target.checked)}
                    type="checkbox"
                />
                <span>{t('login.remember')}</span>
            </label>
            <button className="button-primary" disabled={form.processing} type="submit">
                <LogIn aria-hidden="true" size={18} />
                <span>{t('login.logIn')}</span>
            </button>
        </form>
    );
}

function RegisterForm() {
    const { t } = useI18n();
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        // 送出後不會自動登入，成功時導回登入頁並帶 flash 訊息。
        form.post('/register', { onSuccess: () => form.reset() });
    };

    return (
        <form className="auth-card" onSubmit={submit}>
            <div>
                <p className="section-kicker">{t('login.registerKicker')}</p>
                <h2>{t('login.registerHeading')}</h2>
                {/* 審核制必須在送出前就講清楚，否則使用者會以為註冊完就能用，
                    然後在登入被擋時以為是密碼打錯。 */}
                <p>{t('login.registerIntro')}</p>
            </div>
            <TextField
                autoComplete="name"
                error={form.errors.name}
                label={t('login.name')}
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label={t('login.email')}
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password}
                label={t('login.password')}
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password_confirmation}
                label={t('login.passwordConfirmation')}
                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                type="password"
                value={form.data.password_confirmation}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                <UserPlus aria-hidden="true" size={18} />
                <span>{t('login.submitApplication')}</span>
            </button>
        </form>
    );
}

export default function Login({ registrationEnabled = true }) {
    const { t } = useI18n();
    // 申請送出後會導回本頁，成功訊息是使用者唯一的確認依據。
    const { flash } = usePage().props;

    return (
        <main className="auth-page">
            <div className="auth-topbar">
                <LocaleToggle />
            </div>
            <section className="auth-hero">
                <p className="section-kicker">{t('login.heroKicker')}</p>
                <h1>{t('login.heroHeading')}</h1>
                <p>{t('login.heroIntro')}</p>
            </section>
            {flash?.success ? (
                <p className="auth-flash" role="status">
                    <CheckCircle2 aria-hidden="true" size={18} />
                    {flash.success}
                </p>
            ) : null}
            <section className="auth-grid" aria-label={t('login.formsRegion')}>
                <LoginForm />
                {registrationEnabled ? <RegisterForm /> : null}
            </section>
        </main>
    );
}