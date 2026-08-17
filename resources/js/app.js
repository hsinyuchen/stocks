import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { LocaleProvider } from './i18n';

const BRAND = { zh: '股票分析平台', en: 'Stock Analysis Platform' };

function storedLocale() {
    try {
        return window.localStorage.getItem('stock-locale') === 'en' ? 'en' : 'zh';
    } catch {
        return 'zh';
    }
}

createInertiaApp({
    title: (title) => {
        const brand = BRAND[storedLocale()];

        return title ? `${title} - ${brand}` : brand;
    },
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        const authUser = props.initialPage?.props?.auth?.user ?? null;

        createRoot(el).render(
            createElement(
                LocaleProvider,
                {
                    initialLocale: authUser?.profile?.locale ?? 'zh',
                    authenticated: Boolean(authUser),
                },
                createElement(App, props),
            ),
        );
    },
    progress: {
        color: '#b85c38',
    },
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
