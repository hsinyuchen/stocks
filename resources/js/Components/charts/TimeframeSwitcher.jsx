const OPTIONS = [
    { value: 'daily', label: '日' },
    { value: 'weekly', label: '週' },
    { value: 'monthly', label: '月' },
];

/**
 * 日/週/月時間框架切換 tab。純受控元件。
 * props：{ value, onChange, loading } —— loading 時禁用避免連點造成競態 fetch。
 */
export default function TimeframeSwitcher({ value, onChange, loading = false }) {
    return (
        <div className="segmented" role="group" aria-label="時間框架">
            {OPTIONS.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    disabled={loading}
                    onClick={() => onChange(option.value)}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
