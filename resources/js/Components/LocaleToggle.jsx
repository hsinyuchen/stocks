import { Languages } from 'lucide-react';
import { useI18n } from '../i18n';

export default function LocaleToggle() {
    const { locale, toggleLocale } = useI18n();
    const isEnglish = locale === 'en';

    return (
        <button
            type="button"
            className="theme-toggle"
            aria-label={isEnglish ? 'Switch to Chinese' : '切換為英文'}
            aria-pressed={isEnglish}
            onClick={toggleLocale}
            title={isEnglish ? '中文' : 'English'}
        >
            <span className="theme-toggle__track" aria-hidden="true">
                <span className="theme-toggle__thumb">
                    <Languages size={14} />
                </span>
            </span>
            <span>{isEnglish ? 'EN' : '中'}</span>
        </button>
    );
}
