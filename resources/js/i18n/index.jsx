import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import zh from './messages/zh';
import en from './messages/en';

const DICTIONARIES = { zh, en };
const STORAGE_KEY = 'stock-locale';
const FALLBACK_LOCALE = 'zh';

const LocaleContext = createContext(null);

function normalizeLocale(locale) {
    return locale === 'en' ? 'en' : 'zh';
}

function readStoredLocale() {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function writeStoredLocale(locale) {
    try {
        window.localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        // localStorage 在無痕/受限情境可能不可用，切換仍應可運作（僅不持久化）。
    }
}

/**
 * 依 dot-path 從字典取值。缺鍵時回退：目前語言 → 繁中 → key 本身。
 *
 * 回退到 key（而非空字串）是刻意的：漏翻的字串會在畫面上原樣露出對應 key，
 * 方便開發期一眼抓到缺漏，而不是變成空白。
 */
function lookup(dictionary, key) {
    return key.split('.').reduce((node, segment) => {
        if (node && typeof node === 'object' && segment in node) {
            return node[segment];
        }

        return undefined;
    }, dictionary);
}

function translate(locale, key, replacements) {
    let value = lookup(DICTIONARIES[locale], key);

    if (typeof value !== 'string') {
        value = lookup(DICTIONARIES[FALLBACK_LOCALE], key);
    }

    if (typeof value !== 'string') {
        return key;
    }

    if (replacements) {
        for (const [token, replacement] of Object.entries(replacements)) {
            value = value.replaceAll(`:${token}`, String(replacement));
        }
    }

    return value;
}

export function LocaleProvider({ children, initialLocale = FALLBACK_LOCALE, authenticated = false }) {
    // 初值優先序：localStorage（使用者本機最後選擇）> 伺服器帶來的 profile.locale > 繁中。
    const [locale, setLocale] = useState(() => normalizeLocale(readStoredLocale() ?? initialLocale));

    useEffect(() => {
        document.documentElement.lang = locale === 'en' ? 'en' : 'zh-Hant';
        writeStoredLocale(locale);
    }, [locale]);

    const changeLocale = useCallback(
        (next) => {
            const normalized = normalizeLocale(next);

            setLocale((current) => {
                if (current === normalized) {
                    return current;
                }

                // 登入者才回寫 DB，讓換裝置也能維持語言；訪客僅存 localStorage。
                if (authenticated) {
                    router.post(
                        '/profile/locale',
                        { locale: normalized },
                        { preserveScroll: true, preserveState: true },
                    );
                }

                return normalized;
            });
        },
        [authenticated],
    );

    const value = useMemo(
        () => ({
            locale,
            setLocale: changeLocale,
            toggleLocale: () => changeLocale(locale === 'en' ? 'zh' : 'en'),
            t: (key, replacements) => translate(locale, key, replacements),
        }),
        [locale, changeLocale],
    );

    return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

export function useI18n() {
    const context = useContext(LocaleContext);

    if (context === null) {
        // Provider 缺失時給無害回退，避免整頁崩潰；正式路徑一定包在 LocaleProvider 內。
        return {
            locale: FALLBACK_LOCALE,
            setLocale: () => {},
            toggleLocale: () => {},
            t: (key) => translate(FALLBACK_LOCALE, key),
        };
    }

    return context;
}
