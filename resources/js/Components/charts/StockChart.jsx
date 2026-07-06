import CandlestickChart from './CandlestickChart';
import CompareChart from './CompareChart';

/**
 * 圖表薄殼：依 mode 派生選 CandlestickChart 或 CompareChart。
 *
 * mode = compareSymbols.length > 0 ? 'compare' : 'candles'。
 * 以 key={mode + tf} 包住強制重建 chart 實例，避免 K 線 ↔ 比較模式
 * 與時間框架切換時 series lifecycle 互相污染（spec 決策）。
 *
 * props：
 * - tf：目前時間框架（key 的一部分）。
 * - chartData：{ candles, indicators, timeframe }，K 線模式主資料，
 *   同時作為比較模式的主 series（symbol + candles）。
 * - compareSymbols：比較 symbol 陣列（決定 mode）。
 * - compareSeries：Array<{ symbol, candles }>，比較模式疊加線資料。
 * - visiblePanes：K 線模式副圖開關。
 */
export default function StockChart({
    tf,
    chartData,
    compareSymbols = [],
    compareSeries = [],
    visiblePanes = [],
}) {
    const mode = compareSymbols.length > 0 ? 'compare' : 'candles';

    return (
        <div key={`${mode}-${tf}`}>
            {mode === 'compare' ? (
                <CompareChart
                    baseSeries={{ symbol: chartData?.symbol, candles: chartData?.candles ?? [] }}
                    compareSeries={compareSeries}
                />
            ) : (
                <CandlestickChart chartData={chartData} visiblePanes={visiblePanes} />
            )}
        </div>
    );
}
