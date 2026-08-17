import { Moon, Sun } from 'lucide-react';
import { useI18n } from '../i18n';

export default function ThemeToggle({ theme, onToggle }) {
    const { t } = useI18n();
    const isDark = theme === 'dark';

    return (
        <button
            type="button"
            className="theme-toggle"
            aria-label={isDark ? t('theme.toWarm') : t('theme.toDark')}
            aria-pressed={isDark}
            onClick={onToggle}
            title={isDark ? t('theme.warmTitle') : t('theme.darkTitle')}
        >
            <span className="theme-toggle__track" aria-hidden="true">
                <span className="theme-toggle__thumb">
                    {isDark ? <Moon size={14} /> : <Sun size={14} />}
                </span>
            </span>
            <span>{isDark ? t('theme.dark') : t('theme.warm')}</span>
        </button>
    );
}
