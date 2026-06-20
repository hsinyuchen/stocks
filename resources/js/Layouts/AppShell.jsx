import { useEffect, useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Bot,
    Newspaper,
    Search,
    Settings,
    Star,
} from 'lucide-react';
import HamburgerButton from '../Components/HamburgerButton';
import ThemeToggle from '../Components/ThemeToggle';

const menuItems = [
    { label: '市場雷達', icon: BarChart3 },
    { label: '即時新聞', icon: Newspaper },
    { label: '觀察清單', icon: Star },
    { label: '個股搜尋', icon: Search },
    { label: 'AI 分析紀錄', icon: Bot },
    { label: '設定', icon: Settings },
];

function normalizeTheme(theme) {
    return theme === 'dark' ? 'dark' : 'warm';
}

export default function AppShell({ children, title = 'Dashboard' }) {
    const { props } = usePage();
    const user = props.auth?.user;
    const initialTheme = useMemo(
        () => normalizeTheme(user?.profile?.theme ?? window.localStorage.getItem('stock-theme')),
        [user?.profile?.theme],
    );
    const [theme, setTheme] = useState(initialTheme);
    const [isMenuOpen, setIsMenuOpen] = useState(false);

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        document
            .querySelector('meta[name="theme-color"]')
            ?.setAttribute('content', theme === 'dark' ? '#111111' : '#f5efe4');
        window.localStorage.setItem('stock-theme', theme);
    }, [theme]);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setIsMenuOpen(false);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const toggleTheme = () => setTheme((current) => (current === 'dark' ? 'warm' : 'dark'));

    return (
        <>
            <Head title={title} />
            <div className="app-shell">
                <aside className={`sidebar ${isMenuOpen ? 'sidebar--open' : ''}`} aria-label="Primary navigation">
                    <div className="brand">
                        <div className="brand__mark">S</div>
                        <div>
                            <p className="brand__name">Stock Platform</p>
                            <p className="brand__sub">市場工作台</p>
                        </div>
                    </div>

                    <nav className="nav-menu">
                        {menuItems.map((item) => {
                            const Icon = item.icon;

                            return (
                                <a className="nav-menu__item" href="#dashboard" key={item.label}>
                                    <Icon aria-hidden="true" size={19} />
                                    <span>{item.label}</span>
                                </a>
                            );
                        })}
                    </nav>

                    <div className="sidebar__footer">
                        <ThemeToggle theme={theme} onToggle={toggleTheme} />
                        <div className="user-chip">
                            <span>{user?.name?.charAt(0)?.toUpperCase() ?? 'U'}</span>
                            <div>
                                <strong>{user?.name ?? 'User'}</strong>
                                <small>{user?.profile?.preferred_market ?? 'TW_US'}</small>
                            </div>
                        </div>
                    </div>
                </aside>

                <button
                    type="button"
                    className={`shell-overlay ${isMenuOpen ? 'shell-overlay--visible' : ''}`}
                    aria-label="Close navigation menu"
                    onClick={() => setIsMenuOpen(false)}
                />

                <main className="shell-main">
                    <header className="topbar">
                        <div className="topbar__left">
                            <HamburgerButton isOpen={isMenuOpen} onClick={() => setIsMenuOpen((open) => !open)} />
                            <div>
                                <p className="topbar__eyebrow">Analysis Workspace</p>
                                <h1>市場雷達</h1>
                            </div>
                        </div>
                        <div className="topbar__actions">
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <button type="button" className="icon-button" aria-label="Notifications" title="Notifications">
                                <Bell aria-hidden="true" size={20} />
                            </button>
                        </div>
                    </header>

                    <section className="content-area">{children}</section>
                </main>
            </div>
        </>
    );
}
