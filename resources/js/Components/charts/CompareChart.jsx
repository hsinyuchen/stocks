import { LineSeries } from 'lightweight-charts';
import useLightweightChart from './useLightweightChart';
import { useI18n } from '../../i18n';

/**
 * 比較線的固定色盤（主 + 最多 4 支 = 5 色）。
 * 從主題 colors 派生，避免與 K 線漲跌色語意衝突（不用 positive/neg）。
 */
function palette(colors) {
    return [colors.accent, colors.text, colors.muted, colors.positive, colors.neg];
}

/**
 * 求所有 series 的第一個共同交易日（各 series 日期集合交集的最早日）。
 * 跨台股/美股/指數交易日不一致，用「共同可見區間第一個重疊交易日」為 0% 基準，
 * 避免各自用「各自第一根」造成比較失真（spec 決策）。
 * 無交集回傳 null。
 */
function firstCommonDate(seriesList) {
    const withData = seriesList.filter((series) => (series.candles?.length ?? 0) > 0);
    if (withData.length === 0) {
        return null;
    }

    let common = new Set(withData[0].candles.map((candle) => candle.time));
    for (let i = 1; i < withData.length; i++) {
        const dates = new Set(withData[i].candles.map((candle) => candle.time));
        common = new Set([...common].filter((date) => dates.has(date)));
    }

    if (common.size === 0) {
        return null;
    }

    return [...common].sort()[0];
}

/**
 * 將單一 series 正規化為自 baseDate 起的漲跌 %。
 * 以 baseDate 當日收盤為 0% 基準：pct = (close / baseClose - 1) * 100。
 * baseDate 之前的點丟棄；缺日不補值——直接略過，線自然連到下一點（gap 呈現，spec 允許）。
 */
function normalize(candles, baseDate) {
    const fromBase = candles.filter((candle) => candle.time >= baseDate);
    const baseClose = fromBase.find((candle) => candle.time === baseDate)?.close;

    if (baseClose === undefined || baseClose === 0) {
        return [];
    }

    return fromBase.map((candle) => ({
        time: candle.time,
        value: (candle.close / baseClose - 1) * 100,
    }));
}

/**
 * 多股正規化比較線圖：主 symbol + 最多 4 支疊加線，無 K 線、無主圖指標。
 *
 * props：
 * - baseSeries：{ symbol, candles }（candles 為 {time,...,close}[]）。
 * - compareSeries：Array<{ symbol, candles }>。
 *
 * 全部 series 以共同第一個交易日的收盤為 0% 基準，各自繪成一條 % 線。
 */
export default function CompareChart({ baseSeries, compareSeries = [] }) {
    const { t } = useI18n();
    const allSeries = [baseSeries, ...compareSeries].filter(
        (series) => series && (series.candles?.length ?? 0) > 0,
    );

    const containerRef = useLightweightChart((chart, colors) => {
        const baseDate = firstCommonDate(allSeries);
        if (baseDate === null) {
            return;
        }

        const colorSet = palette(colors);

        allSeries.forEach((series, index) => {
            const points = normalize(series.candles, baseDate);
            if (points.length === 0) {
                return;
            }

            const line = chart.addSeries(LineSeries, {
                color: colorSet[index % colorSet.length],
                lineWidth: 1.5,
                priceLineVisible: false,
                lastValueVisible: true,
                title: series.symbol,
                priceFormat: { type: 'percent' },
            });
            line.setData(points);
        });

        chart.timeScale().fitContent();
    }, [JSON.stringify(allSeries.map((series) => ({ symbol: series.symbol, candles: series.candles })))]);

    if (allSeries.length === 0 || firstCommonDate(allSeries) === null) {
        return (
            <div className="chart-container chart-container--empty">
                <span className="chart-empty">{t('charts.noCommonDate')}</span>
            </div>
        );
    }

    return <div ref={containerRef} className="chart-container" />;
}
