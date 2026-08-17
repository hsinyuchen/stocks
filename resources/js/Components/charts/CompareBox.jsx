import { useState } from 'react';
import { Plus, X } from 'lucide-react';
import StockSearchBox from '../StockSearchBox';
import { useI18n } from '../../i18n';

// 最多疊加 4 支比較標的（不含主 symbol）。
const MAX_COMPARE = 4;

// 內建常用指數快捷；比較框複用的 StockSearchBox 只搜個股，不含指數自動完成，
// 故提供 chip 讓使用者一鍵加入（symbol 直接送 by-symbol endpoint）。
// label 改由 labelKey 於渲染時經 t() 取得（此陣列在元件外，取不到 t）。
const INDEX_SHORTCUTS = [
    { symbol: '^TWII', labelKey: 'charts.indexTwii' },
    { symbol: '^IXIC', labelKey: 'charts.indexIxic' },
    { symbol: '^GSPC', labelKey: 'charts.indexGspc' },
];

/**
 * 比較標的輸入框：管理最多 4 支 compareSymbols。
 * 純受控元件——只回報 onAdd/onRemove，不碰 chart 狀態（spec 決策）。
 *
 * 輸入來源三擇一併存：
 * - StockSearchBox 個股自動完成（選取後加 result.symbol）。
 * - 純文字輸入：直接加原始 symbol，支援 ^TWII 等指數；前端僅 uppercase/trim，
 *   不驗證存在性——fetch 失敗時由父層透過 errors 標記，使用者可移除。
 * - 指數快捷 chip。
 *
 * props：
 * - symbols：目前比較 symbol 陣列（不含主 symbol）。
 * - errors：{ [symbol]: true } fetch 失敗標記。
 * - onAdd(symbol)、onRemove(symbol)。
 */
export default function CompareBox({ symbols = [], errors = {}, onAdd, onRemove }) {
    const { t } = useI18n();
    const [text, setText] = useState('');

    const full = symbols.length >= MAX_COMPARE;

    const add = (raw) => {
        const symbol = String(raw ?? '').trim().toUpperCase();
        if (symbol === '' || full || symbols.includes(symbol)) {
            return;
        }
        onAdd?.(symbol);
    };

    const submitText = (event) => {
        event.preventDefault();
        add(text);
        setText('');
    };

    return (
        <div className="compare-box">
            <div className="compare-box__heading">
                <p className="section-kicker">{t('charts.compareTargets')}</p>
                <span className="field-hint">{t('charts.compareHint', { count: MAX_COMPARE })}</span>
            </div>

            {symbols.length > 0 ? (
                <div className="compare-box__chips">
                    {symbols.map((symbol) => (
                        <span
                            className={`compare-chip${errors[symbol] ? ' compare-chip--error' : ''}`}
                            key={symbol}
                        >
                            <span>{symbol}</span>
                            {errors[symbol] ? <small>{t('charts.loadFailed')}</small> : null}
                            <button
                                aria-label={t('charts.removeSymbol', { symbol })}
                                onClick={() => onRemove?.(symbol)}
                                type="button"
                            >
                                <X aria-hidden="true" size={14} />
                            </button>
                        </span>
                    ))}
                </div>
            ) : null}

            {full ? null : (
                <>
                    <StockSearchBox onSelect={(result) => add(result.symbol)} />

                    <form className="compare-box__manual" onSubmit={submitText}>
                        <input
                            aria-label={t('charts.manualInputAria')}
                            maxLength="32"
                            onChange={(event) => setText(event.target.value)}
                            placeholder={t('charts.manualInputPlaceholder')}
                            type="text"
                            value={text}
                        />
                        <button className="button-secondary" disabled={text.trim() === ''} type="submit">
                            <Plus aria-hidden="true" size={16} />
                            <span>{t('charts.add')}</span>
                        </button>
                    </form>

                    <div className="compare-box__shortcuts">
                        {INDEX_SHORTCUTS.map((index) => (
                            <button
                                className="compare-shortcut"
                                disabled={symbols.includes(index.symbol)}
                                key={index.symbol}
                                onClick={() => add(index.symbol)}
                                type="button"
                            >
                                {t(index.labelKey)}
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
