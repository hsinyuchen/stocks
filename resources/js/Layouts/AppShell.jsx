import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Bot,
    LogOut,
    Newspaper,
    Search,
    Settings,
    Star,
} from 'lucide-react';
import HamburgerButton from '../Components/HamburgerButton';
import ThemeToggle from '../Components/ThemeToggle';

const menuItems = [
    { href: '/dashboard', label: '市場儀表板', icon: BarChart3 },
    { href: '/news', label: '即時新聞', icon: Newspaper },
    { href: '/watchlists', label: '自選清單', icon: Star },
    { href: '/stocks/search', label: '個股搜尋', icon: Search },
    { href: '/analyses', label: 'AI 分析紀錄', icon: Bot },
    { href: '/settings', label: '系統設定', icon: Settings },
];

function normalizeTheme(theme) {
    return theme === 'dark' ? 'dark' : 'warm';
}

function readStoredTheme() {
    try {
        return window.localStorage.getItem('stock-theme');
    } catch {
        return null;
    }
}

function writeStoredTheme(theme) {
    try {
        window.localStorage.setItem('stock-theme', theme);
    } catch {
        // Storage can be unavailable in private or locked-down browsing contexts.
    }
}

export default function AppShell({ children, title = '市場儀表板' }) {
    const { props, url } = usePage();
    const user = props.auth?.user;
    const currentPath = url.split(/[?#]/)[0];
    const initialTheme = useMemo(() => normalizeTheme(readStoredTheme() ?? user?.profile?.theme), [user?.profile?.theme]);
    const [theme, setTheme] = useState(() => initialTheme);
    const [isMenuOpen, setIsMenuOpen] = useState(false);

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        document
            .querySelector('meta[name="theme-color"]')
            ?.setAttribute('content', theme === 'dark' ? '#111111' : '#f5efe4');
        writeStoredTheme(theme);
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

    const logout = () => router.post('/logout');

    return (
        <>
            <Head title={title} />
            <div className="app-shell">
                <aside className={`sidebar ${isMenuOpen ? 'sidebar--open' : ''}`} aria-label="主要導覽">
                    <div className="brand">
                        <div className="brand__mark">S</div>
                        <div>
                            <p className="brand__name">股票分析平台</p>
                            <p className="brand__sub">台美市場研究中樞</p>
                        </div>
                    </div>

                    <nav className="nav-menu">
                        {menuItems.map((item) => {
                            const Icon = item.icon;
                            const isActive = currentPath === item.href || currentPath.startsWith(`${item.href}/`);

                            return (
                                <Link
                                    aria-current={isActive ? 'page' : undefined}
                                    className={`nav-menu__item ${isActive ? 'nav-menu__item--active' : ''}`}
                                    href={item.href}
                                    key={item.href}
                                    onClick={() => setIsMenuOpen(false)}
                                >
                                    <Icon aria-hidden="true" size={19} />
                                    <span>{item.label}</span>
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="sidebar__footer">
                        <ThemeToggle theme={theme} onToggle={toggleTheme} />
                        <div className="user-chip">
                            <span>{user?.name?.charAt(0)?.toUpperCase() ?? 'U'}</span>
                            <div className="user-chip__body">
                                <strong>{user?.name ?? '使用者'}</strong>
                                <small>{user?.profile?.preferred_market ?? 'TW_US'}</small>
                            </div>
                            <button
                                type="button"
                                className="icon-button user-chip__logout"
                                onClick={logout}
                                aria-label="登出"
                                title="登出"
                            >
                                <LogOut aria-hidden="true" size={18} />
                            </button>
                        </div>
                    </div>
                </aside>

                <button
                    type="button"
                    className={`shell-overlay ${isMenuOpen ? 'shell-overlay--visible' : ''}`}
                    aria-label="關閉導覽選單"
                    onClick={() => setIsMenuOpen(false)}
                />

                <main className="shell-main">
                    <header className="topbar">
                        <div className="topbar__left">
                            <HamburgerButton isOpen={isMenuOpen} onClick={() => setIsMenuOpen((open) => !open)} />
                            <div>
                                <p className="topbar__eyebrow">分析工作區</p>
                                <h1>{title}</h1>
                            </div>
                        </div>
                        <div className="topbar__actions">
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <button type="button" className="icon-button" aria-label="通知" title="通知">
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