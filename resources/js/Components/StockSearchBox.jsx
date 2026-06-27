import axios from 'axios';
import { useEffect, useId, useRef, useState } from 'react';
import { Loader2, Search } from 'lucide-react';

const marketLabels = {
    tw: '台股',
    us: '美股',
};

/**
 * Name/code stock autocomplete. Debounced lookups against /stocks/lookup with a
 * 台股/美股 toggle. Calls onSelect(result) — result is { symbol, name, market, exchange? }.
 */
export default function StockSearchBox({ market: initialMarket = 'tw', placeholder, onSelect }) {
    const [market, setMarket] = useState(initialMarket === 'us' ? 'us' : 'tw');
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);

    const rootRef = useRef(null);
    const listboxId = useId();

    // Debounced lookup. Ignores stale responses via an AbortController per effect run.
    useEffect(() => {
        const trimmed = query.trim();

        if (trimmed.length < 1) {
            setResults([]);
            setLoading(false);
            return undefined;
        }

        const controller = new AbortController();
        setLoading(true);

        const timer = setTimeout(() => {
            axios
                .get('/stocks/lookup', {
                    params: { q: trimmed, market },
                    signal: controller.signal,
                })
                .then((response) => {
                    const data = Array.isArray(response.data?.results) ? response.data.results : [];
                    setResults(data);
                    setOpen(true);
                    setLoading(false);
                })
                .catch((error) => {
                    if (axios.isCancel?.(error) || error?.name === 'CanceledError') {
                        return;
                    }
                    setResults([]);
                    setOpen(true);
                    setLoading(false);
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, market]);

    // Close on outside click.
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onPointerDown = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', onPointerDown);
        return () => document.removeEventListener('mousedown', onPointerDown);
    }, [open]);

    const switchMarket = (next) => {
        if (next !== market) {
            setMarket(next);
        }
    };

    const select = (result) => {
        setOpen(false);
        setQuery('');
        setResults([]);
        onSelect?.(result);
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    };

    const showDropdown = open && query.trim().length >= 1;

    return (
        <div className="search-box" ref={rootRef}>
            <div className="search-box__controls">
                <div className="segmented" role="group" aria-label="搜尋市場">
                    {['tw', 'us'].map((value) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={market === value}
                            onClick={() => switchMarket(value)}
                        >
                            {marketLabels[value]}
                        </button>
                    ))}
                </div>
                <div className="search-box__input">
                    <Search aria-hidden="true" size={18} className="search-box__icon" />
                    <input
                        type="search"
                        role="combobox"
                        aria-expanded={showDropdown}
                        aria-controls={listboxId}
                        aria-autocomplete="list"
                        autoComplete="off"
                        maxLength="64"
                        placeholder={placeholder ?? (market === 'tw' ? '輸入名稱或代號，例如 台積電 或 2330' : '輸入名稱或代號，例如 Nvidia 或 NVDA')}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        onFocus={() => {
                            if (query.trim().length >= 1) {
                                setOpen(true);
                            }
                        }}
                        onKeyDown={onKeyDown}
                    />
                    {loading ? (
                        <Loader2 aria-hidden="true" size={16} className="search-box__spinner" />
                    ) : null}
                </div>
            </div>

            {showDropdown ? (
                <ul className="search-box__results" id={listboxId} role="listbox">
                    {loading && results.length === 0 ? (
                        <li className="search-box__hint" role="presentation">搜尋中…</li>
                    ) : results.length === 0 ? (
                        <li className="search-box__hint" role="presentation">找不到符合的股票</li>
                    ) : (
                        results.map((result) => (
                            <li key={`${result.market}-${result.symbol}`} role="option" aria-selected="false">
                                <button
                                    type="button"
                                    className="search-box__item"
                                    onClick={() => select(result)}
                                >
                                    <strong>{result.symbol}</strong>
                                    <span className="search-box__name">{result.name}</span>
                                    <span className="search-box__badge">{marketLabels[market]}</span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            ) : null}
        </div>
    );
}
