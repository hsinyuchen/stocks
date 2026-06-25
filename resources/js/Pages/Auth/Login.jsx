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
                <p className="section-kicker">登入</p>
                <h2>登入股票分析平台</h2>
                <p>使用你的帳號進入市場儀表板、自選清單、個股搜尋與 AI 參考分析工作區。</p>
            </div>
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label="電子郵件"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="current-password"
                error={form.errors.password}
                label="密碼"
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
                <span>保持登入</span>
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
                <p className="section-kicker">建立帳號</p>
                <h2>建立新的研究帳號</h2>
                <p>註冊後會建立個人設定檔，可管理自選清單、主題色與 LLM 分析設定。</p>
            </div>
            <TextField
                autoComplete="name"
                error={form.errors.name}
                label="姓名"
                onChange={(event) => form.setData('name', event.target.value)}
                type="text"
                value={form.data.name}
            />
            <TextField
                autoComplete="email"
                error={form.errors.email}
                label="電子郵件"
                onChange={(event) => form.setData('email', event.target.value)}
                type="email"
                value={form.data.email}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password}
                label="密碼"
                onChange={(event) => form.setData('password', event.target.value)}
                type="password"
                value={form.data.password}
            />
            <TextField
                autoComplete="new-password"
                error={form.errors.password_confirmation}
                label="確認密碼"
                onChange={(event) => form.setData('password_confirmation', event.target.value)}
                type="password"
                value={form.data.password_confirmation}
            />
            <button className="button-secondary" disabled={form.processing} type="submit">
                <UserPlus aria-hidden="true" size={18} />
                <span>建立帳號</span>
            </button>
        </form>
    );
}

export default function Login() {
    return (
        <main className="auth-page">
            <section className="auth-hero">
                <p className="section-kicker">股票市場分析 PWA</p>
                <h1>台美股市研究入口</h1>
                <p>整合新聞、價格資料、技術指標、自選清單與 LLM 參考分析，作為投資研究與風險檢查的工作區。</p>
            </section>
            <section className="auth-grid" aria-label="登入與註冊表單">
                <LoginForm />
                <RegisterForm />
            </section>
        </main>
    );
}