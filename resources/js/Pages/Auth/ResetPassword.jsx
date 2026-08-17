import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useI18n } from '../../i18n';

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

export default function ResetPassword({ token, email }) {
    const { t } = useI18n();
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/reset-password');
    };

    return (
        <main className="auth-page">
            <section className="auth-grid" aria-label={t('resetPassword.regionLabel')}>
                <form className="auth-card" onSubmit={submit}>
                    <div>
                        <p className="section-kicker">{t('resetPassword.kicker')}</p>
                        <h2>{t('resetPassword.heading')}</h2>
                        <p>{t('resetPassword.account', { email })}</p>
                    </div>
                    <label className="form-field">
                        <span>{t('resetPassword.newPassword')}</span>
                        <input
                            autoComplete="new-password"
                            onChange={(event) => form.setData('password', event.target.value)}
                            type="password"
                            value={form.data.password}
                        />
                        <FieldError message={form.errors.password} />
                    </label>
                    <label className="form-field">
                        <span>{t('resetPassword.confirmNewPassword')}</span>
                        <input
                            autoComplete="new-password"
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                            type="password"
                            value={form.data.password_confirmation}
                        />
                        <FieldError message={form.errors.password_confirmation} />
                    </label>
                    <FieldError message={form.errors.email} />
                    <button className="button-primary" disabled={form.processing} type="submit">
                        <KeyRound aria-hidden="true" size={18} />
                        <span>{t('resetPassword.submit')}</span>
                    </button>
                </form>
            </section>
        </main>
    );
}
