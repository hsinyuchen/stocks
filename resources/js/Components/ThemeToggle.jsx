import { Moon, Sun } from 'lucide-react';

export default function ThemeToggle({ theme, onToggle }) {
    const isDark = theme === 'dark';

    return (
        <button
            type="button"
            className="theme-toggle"
            aria-label={isDark ? '切換為米白色系' : '切換為深黑色系'}
            aria-pressed={isDark}
            onClick={onToggle}
            title={isDark ? '米白色系' : '深黑色系'}
        >
            <span className="theme-toggle__track" aria-hidden="true">
                <span className="theme-toggle__thumb">
                    {isDark ? <Moon size={14} /> : <Sun size={14} />}
                </span>
            </span>
            <span>{isDark ? '深黑' : '米白'}</span>
        </button>
    );
}