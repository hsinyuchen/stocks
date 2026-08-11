import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Database, ExternalLink, KeyRound, Plus, Save, Star, Trash2 } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

const providerOptions = [
    { value: 'openai', label: 'OpenAI' },
    { value: 'anthropic', label: 'Claude (Anthropic)' },
    { value: 'gemini', label: 'Gemini / Google AI Studio' },
    { value: 'openrouter', label: 'OpenRouter' },
    { value: 'deepseek', label: 'DeepSeek' },
    { value: 'zeabur', label: 'Zeabur AI Hub' },
    { value: 'openai_compatible', label: 'OpenAI 相容（自訂端點）' },
    { value: 'ollama', label: 'Ollama（本地）' },
    { value: 'llamacpp', label: 'llama.cpp（本地）' },
    { value: 'lmstudio', label: 'LM Studio（本地）' },
];

const providerHints = {
    openai: 'OpenAI 通常不需要自訂 Base URL。',
    anthropic: 'Claude：通常不需自訂 Base URL；模型例如 claude-sonnet-4-6。',
    gemini: 'Gemini／Google AI Studio 是同一組 API，通常不需自訂 Base URL。',
    openrouter: 'OpenRouter Base URL: https://openrouter.ai/api/v1',
    deepseek: 'DeepSeek Base URL: https://api.deepseek.com/v1',
    zeabur: 'Zeabur AI Hub：填入你部署服務的 /v1 endpoint（必填）。',
    openai_compatible: 'OpenAI 相容服務：填入該服務的 /v1 endpoint（必填）。',
    ollama: '本地 Ollama 預設 http://localhost:11434/v1；遠端填區網位址。',
    llamacpp: '本地 llama.cpp 預設 http://localhost:8080/v1。',
    lmstudio: 'LM Studio 預設 http://localhost:1234/v1（需在 LM Studio 開啟 Local Server）。',
};

const emptyForm = {
    provider_type: 'openai',
    display_name: '',
    base_url: '',
    api_key: '',
    model: '',
    timeout_seconds: 60,
    temperature: 0.2,
    max_tokens: 1200,
    is_default: false,
};

function FieldError({ message }) {
    if (!message) {
        return null;
    }

    return <p className="field-error">{message}</p>;
}

function TextField({ error, help, label, ...props }) {
    return (
        <label className="form-field">
            <span>{label}</span>
            <input {...props} />
            {help ? <small>{help}</small> : null}
            <FieldError message={error} />
        </label>
    );
}

function SelectField({ error, help, label, options, ...props }) {
    return (
        <label className="form-field">
            <span>{label}</span>
            <select {...props}>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {help ? <small>{help}</small> : null}
            <FieldError message={error} />
        </label>
    );
}

function DefaultToggle({ checked, disabled, onChange }) {
    return (
        <label className="settings-toggle">
            <input
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                type="checkbox"
            />
            <span>設為預設供應器</span>
        </label>
    );
}

function ProviderFormFields({ form, setting = null }) {
    const selectedProvider = form.data.provider_type;

    return (
        <>
            <div className="settings-form-grid">
                <SelectField
                    error={form.errors.provider_type}
                    help={providerHints[selectedProvider]}
                    label="供應器"
                    onChange={(event) => form.setData('provider_type', event.target.value)}
                    options={providerOptions}
                    value={selectedProvider}
                />
                <TextField
                    error={form.errors.display_name}
                    label="顯示名稱"
                    maxLength="80"
                    onChange={(event) => form.setData('display_name', event.target.value)}
                    placeholder="例如：主要研究模型"
                    type="text"
                    value={form.data.display_name}
                />
                <TextField
                    error={form.errors.model}
                    label="模型名稱"
                    maxLength="120"
                    onChange={(event) => form.setData('model', event.target.value)}
                    placeholder="gpt-5、gemini-2.5-pro、llama3.1"
                    type="text"
                    value={form.data.model}
                />
                <TextField
                    error={form.errors.base_url}
                    help="OpenAI/Gemini 通常可留空；OpenAI 相容服務與本地模型通常需要填寫。"
                    label="Base URL"
                    maxLength="255"
                    onChange={(event) => form.setData('base_url', event.target.value)}
                    placeholder="https://api.openai.com/v1"
                    type="url"
                    value={form.data.base_url}
                />
                <TextField
                    autoComplete="new-password"
                    error={form.errors.api_key}
                    help={setting?.has_api_key ? '已儲存金鑰。留空即可保留原金鑰。' : '會以加密方式儲存。若不需要金鑰可留空。'}
                    label="API Key"
                    maxLength="2048"
                    onChange={(event) => form.setData('api_key', event.target.value)}
                    placeholder={setting?.has_api_key ? '保留既有金鑰' : 'sk-...'}
                    type="password"
                    value={form.data.api_key}
                />
                <TextField
                    error={form.errors.timeout_seconds}
                    label="逾時秒數"
                    max="300"
                    min="5"
                    onChange={(event) => form.setData('timeout_seconds', event.target.value)}
                    type="number"
                    value={form.data.timeout_seconds}
                />
                <TextField
                    error={form.errors.temperature}
                    label="Temperature"
                    max="2"
                    min="0"
                    onChange={(event) => form.setData('temperature', event.target.value)}
                    step="0.01"
                    type="number"
                    value={form.data.temperature}
                />
                <TextField
                    error={form.errors.max_tokens}
                    label="最大 tokens"
                    max="32000"
                    min="128"
                    onChange={(event) => form.setData('max_tokens', event.target.value)}
                    type="number"
                    value={form.data.max_tokens}
                />
            </div>
            <DefaultToggle
                checked={Boolean(form.data.is_default)}
                disabled={form.processing}
                onChange={(checked) => form.setData('is_default', checked)}
            />
        </>
    );
}

/**
 * FinMind 台股資料源 token（每人一組）。填自己的 token 用自己的免費額度抓台股行情/
 * 籌碼；不填則沿用全站預設。抓回的資料仍全站共用，token 只決定用誰的額度抓。
 */
function FinMindPanel({ finmind }) {
    const form = useForm({ token: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/settings/finmind', {
            preserveScroll: true,
            onSuccess: () => form.reset('token'),
        });
    };

    const clear = () => {
        router.delete('/settings/finmind', { preserveScroll: true });
    };

    return (
        <form className="settings-panel" onSubmit={submit}>
            <div className="settings-panel__head">
                <div>
                    <p className="section-kicker">FinMind 台股資料源</p>
                    <h2>你的 FinMind API Token</h2>
                    <p>
                        填入自己的 FinMind token，台股行情、籌碼、基本面就會用你自己的免費額度抓取，
                        不再與其他使用者共用同一組額度而互相排擠。抓回的資料仍全站共用；不填則沿用全站預設 token。
                    </p>
                    <p>
                        還沒有 token？
                        {' '}
                        <a
                            href="https://finmindtrade.com/"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="settings-external-link"
                        >
                            前往 FinMind 官網註冊
                            <ExternalLink aria-hidden="true" size={14} />
                        </a>
                        {' '}
                        ，登入後在會員中心／API Token 頁面取得。
                    </p>
                </div>
                <button className="button-primary" disabled={form.processing} type="submit">
                    <Save aria-hidden="true" size={18} />
                    <span>儲存 Token</span>
                </button>
            </div>

            <div className="settings-form-grid">
                <TextField
                    autoComplete="new-password"
                    error={form.errors.token}
                    help={finmind?.has_token ? '已儲存 token。留空並儲存不會清除，請用「清除」按鈕移除。' : '會以加密方式儲存。留空則使用全站預設額度。'}
                    label="FinMind API Token"
                    maxLength="255"
                    onChange={(event) => form.setData('token', event.target.value)}
                    placeholder={finmind?.has_token ? '輸入新 token 以覆蓋既有設定' : '貼上你的 FinMind token'}
                    type="password"
                    value={form.data.token}
                />
            </div>

            <div className="settings-provider__actions">
                <span className={`key-status ${finmind?.has_token ? 'key-status--active' : ''}`}>
                    <Database aria-hidden="true" size={15} />
                    {finmind?.has_token ? '已設定個人 token' : '未設定，使用全站預設'}
                </span>
                {finmind?.has_token ? (
                    <button className="icon-button icon-button--danger" onClick={clear} title="清除 token" type="button">
                        <Trash2 aria-hidden="true" size={18} />
                    </button>
                ) : null}
            </div>
        </form>
    );
}

function CreateProviderForm() {
    const form = useForm(emptyForm);

    const submit = (event) => {
        event.preventDefault();
        form.post('/settings', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form className="settings-panel" onSubmit={submit}>
            <div className="settings-panel__head">
                <div>
                    <p className="section-kicker">LLM 供應器</p>
                    <h2>新增模型連線設定</h2>
                    <p>設定雲端、Zeabur / OpenAI 相容服務，或本地模型 endpoint，供個股分析流程使用。</p>
                </div>
                <button className="button-primary" disabled={form.processing} type="submit">
                    <Plus aria-hidden="true" size={18} />
                    <span>新增</span>
                </button>
            </div>
            <ProviderFormFields form={form} />
        </form>
    );
}

function EditProviderForm({ setting }) {
    const form = useForm({
        provider_type: setting.provider_type,
        display_name: setting.display_name,
        base_url: setting.base_url ?? '',
        api_key: '',
        model: setting.model,
        timeout_seconds: setting.timeout_seconds,
        temperature: setting.temperature,
        max_tokens: setting.max_tokens,
        is_default: Boolean(setting.is_default),
    });

    const submit = (event) => {
        event.preventDefault();
        form.patch(`/settings/${setting.id}`, {
            preserveScroll: true,
            onSuccess: () => form.reset('api_key'),
        });
    };

    useEffect(() => {
        form.setData('is_default', Boolean(setting.is_default));
    }, [setting.is_default]);

    const destroy = () => {
        router.delete(`/settings/${setting.id}`, {
            preserveScroll: true,
        });
    };

    const makeDefault = () => {
        router.patch(`/settings/${setting.id}/default`, {}, {
            preserveScroll: true,
        });
    };

    return (
        <form className="settings-provider" onSubmit={submit}>
            <div className="settings-provider__head">
                <div className="settings-provider__title">
                    <strong>{setting.display_name}</strong>
                    <span>{providerOptions.find((option) => option.value === setting.provider_type)?.label ?? setting.provider_type}</span>
                </div>
                <div className="settings-provider__actions">
                    <span className={`key-status ${setting.has_api_key ? 'key-status--active' : ''}`}>
                        <KeyRound aria-hidden="true" size={15} />
                        {setting.has_api_key ? '已儲存金鑰' : '尚無金鑰'}
                    </span>
                    {setting.is_default ? (
                        <span className="default-badge">
                            <Star aria-hidden="true" size={15} />
                            預設
                        </span>
                    ) : (
                        <button className="button-secondary" disabled={form.processing} onClick={makeDefault} type="button">
                            <Star aria-hidden="true" size={18} />
                            <span>設為預設</span>
                        </button>
                    )}
                    <button className="icon-button" disabled={form.processing} title="儲存供應器" type="submit">
                        <Save aria-hidden="true" size={18} />
                    </button>
                    <button className="icon-button icon-button--danger" onClick={destroy} title="刪除供應器" type="button">
                        <Trash2 aria-hidden="true" size={18} />
                    </button>
                </div>
            </div>
            <ProviderFormFields form={form} setting={setting} />
        </form>
    );
}

export default function SettingsIndex({ settings = [], finmind = { has_token: false, updated_at: null } }) {
    return (
        <AppShell title="系統設定">
            <div className="settings-page">
                <FinMindPanel finmind={finmind} />

                <CreateProviderForm />

                <section className="settings-stack" aria-label="已儲存的 LLM 供應器設定">
                    {settings.length > 0 ? (
                        settings.map((setting) => (
                            <EditProviderForm key={setting.id} setting={setting} />
                        ))
                    ) : (
                        <div className="settings-provider empty-state">
                            <strong>尚未設定 LLM 供應器</strong>
                            <span>新增 OpenAI、Gemini、OpenRouter、Zeabur / OpenAI 相容服務、Ollama 或 llama.cpp 設定。</span>
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}