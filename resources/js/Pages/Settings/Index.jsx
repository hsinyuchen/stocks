import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { KeyRound, Plus, Save, Star, Trash2 } from 'lucide-react';
import AppShell from '../../Layouts/AppShell';

const providerOptions = [
    { value: 'openai', label: 'OpenAI' },
    { value: 'gemini', label: 'Gemini' },
    { value: 'openrouter', label: 'OpenRouter' },
    { value: 'openai_compatible', label: 'Zeabur / OpenAI-compatible' },
    { value: 'ollama', label: 'Ollama' },
    { value: 'llamacpp', label: 'llama.cpp' },
];

const providerHints = {
    openai: 'OpenAI usually works without a custom base URL.',
    gemini: 'Gemini usually works without a custom base URL.',
    openrouter: 'OpenRouter base_url: https://openrouter.ai/api/v1',
    openai_compatible: 'Zeabur/OpenAI-compatible: use your deployed service /v1 endpoint.',
    ollama: 'Ollama remote: http://192.168.1.10:11434/v1 or the Ollama OpenAI-compatible endpoint.',
    llamacpp: 'llama.cpp remote: http://192.168.1.20:8080/v1',
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
            <span>Default provider</span>
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
                    label="Provider"
                    onChange={(event) => form.setData('provider_type', event.target.value)}
                    options={providerOptions}
                    value={selectedProvider}
                />
                <TextField
                    error={form.errors.display_name}
                    label="Display name"
                    maxLength="80"
                    onChange={(event) => form.setData('display_name', event.target.value)}
                    placeholder="Primary research model"
                    type="text"
                    value={form.data.display_name}
                />
                <TextField
                    error={form.errors.model}
                    label="Model"
                    maxLength="120"
                    onChange={(event) => form.setData('model', event.target.value)}
                    placeholder="gpt-5, gemini-2.5-pro, llama3.1"
                    type="text"
                    value={form.data.model}
                />
                <TextField
                    error={form.errors.base_url}
                    help="Optional for OpenAI/Gemini. Usually required for OpenAI-compatible and local providers."
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
                    help={setting?.has_api_key ? 'Stored key exists. Leave blank to keep it.' : 'Stored encrypted at rest. Leave blank if not needed.'}
                    label="API key"
                    maxLength="2048"
                    onChange={(event) => form.setData('api_key', event.target.value)}
                    placeholder={setting?.has_api_key ? 'Existing key preserved' : 'sk-...'}
                    type="password"
                    value={form.data.api_key}
                />
                <TextField
                    error={form.errors.timeout_seconds}
                    label="Timeout seconds"
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
                    label="Max tokens"
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
                    <p className="section-kicker">LLM providers</p>
                    <h2>Add provider settings</h2>
                    <p>Configure cloud, hosted OpenAI-compatible, or local model endpoints for analysis workflows.</p>
                </div>
                <button className="button-primary" disabled={form.processing} type="submit">
                    <Plus aria-hidden="true" size={18} />
                    <span>Create</span>
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
                        {setting.has_api_key ? 'Key stored' : 'No key'}
                    </span>
                    {setting.is_default ? (
                        <span className="default-badge">
                            <Star aria-hidden="true" size={15} />
                            Default
                        </span>
                    ) : (
                        <button className="button-secondary" disabled={form.processing} onClick={makeDefault} type="button">
                            <Star aria-hidden="true" size={18} />
                            <span>Set default</span>
                        </button>
                    )}
                    <button className="icon-button" disabled={form.processing} title="Save provider" type="submit">
                        <Save aria-hidden="true" size={18} />
                    </button>
                    <button className="icon-button icon-button--danger" onClick={destroy} title="Delete provider" type="button">
                        <Trash2 aria-hidden="true" size={18} />
                    </button>
                </div>
            </div>
                        <ProviderFormFields form={form} setting={setting} />
        </form>
    );
}

export default function SettingsIndex({ settings = [] }) {
    return (
        <AppShell title="Settings">
            <div className="settings-page">
                <CreateProviderForm />

                <section className="settings-stack" aria-label="Saved LLM provider settings">
                    {settings.length > 0 ? (
                        settings.map((setting) => (
                            <EditProviderForm key={setting.id} setting={setting} />
                        ))
                    ) : (
                        <div className="settings-provider empty-state">
                            <strong>No LLM providers configured</strong>
                            <span>Add OpenAI, Gemini, OpenRouter, Zeabur/OpenAI-compatible, Ollama, or llama.cpp settings.</span>
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
