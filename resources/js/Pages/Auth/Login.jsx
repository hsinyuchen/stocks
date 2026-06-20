import { useForm } from '@inertiajs/react';
import { LogIn, UserPlus } from 'lucide-react';

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
                <p className="section-kicker">Sign in</p>
                <h2>登入 Stock Platform</h2>
                <p>使用既有帳號進入市場雷達、觀察清單與 AI 分析工作區。</p>
            </div>
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label="Email"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="current-password"
                error={form.errors.password}
                label="Password"
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
                <span>Remember me</span>
            </label>
            <button className="button-primary" disabled={form.processing} type="submit">
                <LogIn aria-hidden="true" size={18} />
                <span>登入</span>
            </button>
        </form>
    );
}

function RegisterForm() {
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/register');
    };

    return (
        <form className="auth-card" onSubmit={submit}>
            <div>
                <p className="section-kicker">Create account</p>
                <h2>建立測試帳號</h2>
                <p>本機開發用帳號會自動建立預設偏好設定。</p>
            </div>
            <TextField
                autoComplete="name"
                error={form.errors.name}
                label="Name"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label="Email"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password}
                label="Password"
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password_confirmation}
                label="Confirm password"
                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                type="password"
                value={form.data.password_confirmation}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                <UserPlus aria-hidden="true" size={18} />
                <span>註冊並登入</span>
            </button>
        </form>
    );
}

export default function Login() {
    return (
        <main className="auth-page">
            <section className="auth-hero">
                <p className="section-kicker">Stock Market Analysis PWA</p>
                <h1>市場雷達登入</h1>
                <p>台股、美股、觀察清單、技術指標與 LLM 參考分析的本機工作台。</p>
            </section>
            <section className="auth-grid" aria-label="Authentication forms">
                <LoginForm />
                <RegisterForm />
            </section>
        </main>
    );
}
