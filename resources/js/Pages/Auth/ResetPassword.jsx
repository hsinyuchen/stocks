import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

export default function ResetPassword({ token, email }) {
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
            <section className="auth-grid" aria-label="重設密碼">
                <form className="auth-card" onSubmit={submit}>
                    <div>
                        <p className="section-kicker">重設密碼</p>
                        <h2>設定新密碼</h2>
                        <p>帳號：{email}</p>
                    </div>
                    <label className="form-field">
                        <span>新密碼</span>
                        <input
                            autoComplete="new-password"
                            onChange={(event) => form.setData('password', event.target.value)}
                            type="password"
                            value={form.data.password}
                        />
                        <FieldError message={form.errors.password} />
                    </label>
                    <label className="form-field">
                        <span>確認新密碼</span>
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
                        <span>重設密碼</span>
                    </button>
                </form>
            </section>
        </main>
    );
}
