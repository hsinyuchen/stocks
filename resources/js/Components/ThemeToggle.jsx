import { Moon, Sun } from 'lucide-react';

export default function ThemeToggle({ theme, onToggle }) {
    const isDark = theme === 'dark';

    return (
        <button
            type="button"
            className="theme-toggle"
            aria-label={isDark ? 'Switch to warm theme' : 'Switch to dark theme'}
            aria-pressed={isDark}
            onClick={onToggle}
            title={isDark ? 'Warm theme' : 'Dark theme'}
        >
            <span className="theme-toggle__track" aria-hidden="true">
                <span className="theme-toggle__thumb">
                    {isDark ? <Moon size={14} /> : <Sun size={14} />}
                </span>
            </span>
            <span>{isDark ? 'Dark' : 'Warm'}</span>
        </button>
    );
}
