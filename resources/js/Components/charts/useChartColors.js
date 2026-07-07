import { useEffect, useState } from 'react';

// CSS variable name per logical color key.
const VAR_BY_KEY = {
    positive: '--positive',
    neg: '--neg',
    accent: '--accent',
    muted: '--muted',
    border: '--border',
    surface: '--surface',
    surfaceStrong: '--surface-strong',
    text: '--text',
};

// Sensible fallbacks (warm/light theme) used when a CSS variable resolves empty
// (e.g. during SSR or before styles are applied).
const FALLBACK = {
    positive: '#246b4b',
    neg: '#c0392b',
    accent: '#b85c38',
    muted: '#75695e',
    border: '#e7d9c5',
    surface: '#fffaf2',
    surfaceStrong: '#ffffff',
    text: '#221d18',
};

function resolveColors() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return { ...FALLBACK };
    }

    const styles = getComputedStyle(document.documentElement);
    const colors = {};

    for (const key of Object.keys(VAR_BY_KEY)) {
        const value = styles.getPropertyValue(VAR_BY_KEY[key]).trim();
        colors[key] = value || FALLBACK[key];
    }

    return colors;
}

/**
 * Resolve theme colors from the document's CSS variables into real color
 * strings so Lightweight Charts (canvas options don't resolve CSS vars) gets
 * usable values rather than unresolved `var(...)`. Re-reads when the
 * `data-theme` attribute changes.
 *
 * @returns {{positive:string, neg:string, accent:string, muted:string, border:string, surface:string, surfaceStrong:string, text:string}}
 */
export default function useChartColors() {
    const [colors, setColors] = useState(() => resolveColors());

    useEffect(() => {
        if (typeof document === 'undefined') {
            return undefined;
        }

        // Resolve once on mount in case styles weren't ready at first render.
        setColors(resolveColors());

        const observer = new MutationObserver(() => {
            setColors(resolveColors());
        });

        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme'],
        });

        return () => observer.disconnect();
    }, []);

    return colors;
}
