import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Bot,
    Layers,
    ListChecks,
    LogOut,
    Moon,
    Newspaper,
    ScanSearch,
    Search,
    Settings,
    Star,
    UserCog,
    Users,
    Wallet,
} from 'lucide-react';
import BusyOverlay from '../Components/BusyOverlay';
import HamburgerButton from '../Components/HamburgerButton';
import ThemeToggle from '../Components/ThemeToggle';
import LocaleToggle from '../Components/LocaleToggle';
import { useI18n } from '../i18n';

const baseMenuItems = [
    { href: '/dashboard', labelKey: 'nav.dashboard', icon: BarChart3 },
    { href: '/market/weight-analysis', labelKey: 'nav.weightAnalysis', icon: Layers },
    { href: '/news', labelKey: 'nav.news', icon: Newspaper },
    { href: '/watchlists', labelKey: 'nav.watchlists', icon: Star },
    { href: '/watchlists/analysis', labelKey: 'nav.watchlistAnalysis', icon: Moon },
    { href: '/stocks/search', labelKey: 'nav.stockSearch', icon: Search },
    { href: '/screener', labelKey: 'nav.screener', icon: ScanSearch },
    { href: '/portfolio', labelKey: 'nav.portfolio', icon: Wallet },
    { href: '/alerts', labelKey: 'nav.alerts', icon: Bell },
    { href: '/analyses', labelKey: 'nav.analyses', icon: Bot },
    { href: '/profile', labelKey: 'nav.profile', icon: UserCog },
    { href: '/settings', labelKey: 'nav.settings', icon: Settings },
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

export default function AppShell({ children, title }) {
    const { props, url } = usePage();
    const { t } = useI18n();
    const user = props.auth?.user;
    const heading = title ?? t('nav.dashboard');
    const menuItems = user?.is_admin
        ? [
            ...baseMenuItems,
            { href: '/admin/instruments', labelKey: 'nav.instruments', icon: ListChecks },
            { href: '/admin/users', labelKey: 'nav.users', icon: Users },
        ]
        : baseMenuItems;
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
            <Head title={heading} />
            {/* 放在 shell 外層：遮罩要蓋住側欄與內容，且不受任何頁面的排版影響。 */}
            <BusyOverlay />
            <div className="app-shell">
                <aside className={`sidebar ${isMenuOpen ? 'sidebar--open' : ''}`} aria-label={t('appShell.primaryNav')}>
                    <div className="brand">
                        <div className="brand__mark">S</div>
                        <div>
                            <p className="brand__name">{t('appShell.brandName')}</p>
                            <p className="brand__sub">{t('appShell.brandSub')}</p>
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
                                    <span>{t(item.labelKey)}</span>
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="sidebar__footer">
                        <div className="sidebar__toggles">
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <LocaleToggle />
                        </div>
                        <div className="user-chip">
                            <span>{user?.name?.charAt(0)?.toUpperCase() ?? 'U'}</span>
                            <div className="user-chip__body">
                                <strong>{user?.name ?? t('appShell.user')}</strong>
                                <small>{user?.profile?.preferred_market ?? 'TW_US'}</small>
                            </div>
                            <button
                                type="button"
                                className="icon-button user-chip__logout"
                                onClick={logout}
                                aria-label={t('appShell.logout')}
                                title={t('appShell.logout')}
                            >
                                <LogOut aria-hidden="true" size={18} />
                            </button>
                        </div>
                    </div>
                </aside>

                <button
                    type="button"
                    className={`shell-overlay ${isMenuOpen ? 'shell-overlay--visible' : ''}`}
                    aria-label={t('appShell.closeNav')}
                    onClick={() => setIsMenuOpen(false)}
                />

                <main className="shell-main">
                    <header className="topbar">
                        <div className="topbar__left">
                            <HamburgerButton isOpen={isMenuOpen} onClick={() => setIsMenuOpen((open) => !open)} />
                            <div>
                                <p className="topbar__eyebrow">{t('appShell.workspace')}</p>
                                <h1>{heading}</h1>
                            </div>
                        </div>
                        <div className="topbar__actions">
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <LocaleToggle />
                            <button type="button" className="icon-button" aria-label={t('appShell.notifications')} title={t('appShell.notifications')}>
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