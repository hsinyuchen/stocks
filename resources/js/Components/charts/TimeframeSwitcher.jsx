import { useI18n } from '../../i18n';

// label 改由 labelKey 於渲染時經 t() 取得（此陣列在元件外，取不到 t）。
const OPTIONS = [
    { value: 'daily', labelKey: 'charts.timeframeDaily' },
    { value: 'weekly', labelKey: 'charts.timeframeWeekly' },
    { value: 'monthly', labelKey: 'charts.timeframeMonthly' },
];

/**
 * 日/週/月時間框架切換 tab。純受控元件。
 * props：{ value, onChange, loading } —— loading 時禁用避免連點造成競態 fetch。
 */
export default function TimeframeSwitcher({ value, onChange, loading = false }) {
    const { t } = useI18n();

    return (
        <div className="segmented" role="group" aria-label={t('charts.timeframeGroup')}>
            {OPTIONS.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    disabled={loading}
                    onClick={() => onChange(option.value)}
                >
                    {t(option.labelKey)}
                </button>
            ))}
        </div>
    );
}
